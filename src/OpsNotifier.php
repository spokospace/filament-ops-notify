<?php

namespace Spokospace\OpsNotify;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spokospace\OpsNotify\Enums\DeliveryStatus;
use Spokospace\OpsNotify\Exceptions\ChannelException;
use Spokospace\OpsNotify\Exceptions\MessageSkipped;
use Spokospace\OpsNotify\Jobs\SendOpsMessage;
use Spokospace\OpsNotify\Models\OpsNotifyLog;
use Spokospace\OpsNotify\Support\Destination;
use Spokospace\OpsNotify\Support\PatternMap;
use Throwable;

class OpsNotifier
{
    public function __construct(private readonly ChannelManager $channels) {}

    public function channels(): ChannelManager
    {
        return $this->channels;
    }

    /**
     * Queue a message. Never throws: a broken notification setup must not break the request
     * that triggered it (saving an inquiry, finishing a build). Problems are logged as warnings.
     *
     * @return OpsNotifyLog|null The log row as queued, or null when skipped or logging is off.
     */
    public function send(OpsMessage $message): ?OpsNotifyLog
    {
        $log = null;

        try {
            if (! $destination = $this->destinationFor($message)) {
                return null;
            }

            $log = $this->createLog($message, $destination);

            // On the sync queue a failed delivery throws right here; the job has already marked
            // the row failed, so the caller still gets it back.
            SendOpsMessage::dispatch($message, $destination, $log)
                ->onConnection(config('ops-notify.queue.connection'))
                ->onQueue(config('ops-notify.queue.name'));
        } catch (Throwable $e) {
            Log::warning('[ops-notify] Could not send "'.$message->event.'": '.$e->getMessage());
        }

        return $log;
    }

    /**
     * Deliver synchronously, bypassing the queue.
     *
     * @throws MessageSkipped when notifications are disabled globally or for this event.
     * @throws ChannelException when the channel rejects the message.
     */
    public function sendNow(OpsMessage $message): ?OpsNotifyLog
    {
        $destination = $this->destinationFor($message) ?? throw MessageSkipped::for($message->event);

        $log = $this->createLog($message, $destination);

        try {
            $this->deliver($message, $destination, $log);
        } catch (ChannelException $e) {
            $this->markFailed($log, $e->getMessage());

            throw $e;
        }

        return $log;
    }

    /**
     * Where the message goes, or null when it must not be sent at all
     * (notifications disabled globally or for this event).
     */
    public function destinationFor(OpsMessage $message): ?Destination
    {
        if (! config('ops-notify.enabled')) {
            return null;
        }

        $events = (array) config('ops-notify.events', []);
        $key = PatternMap::firstKey($events, $message->event);
        $rule = $key === null ? [] : (array) $events[$key];

        if (($rule['enabled'] ?? true) === false) {
            return null;
        }

        $topic = $message->topic ?? $rule['topic'] ?? null;

        return new Destination(
            channel: $message->channel ?? $rule['channel'] ?? $this->channels->defaultChannel(),
            topic: filled($topic) ? (string) $topic : null,
        );
    }

    /** @internal Called by the queued job and sendNow(). */
    public function deliver(OpsMessage $message, Destination $destination, ?OpsNotifyLog $log): void
    {
        try {
            $externalId = $this->channels->channel($destination->channel)->send($message, $destination);
        } catch (ChannelException $e) {
            $log?->increment('attempts', 1, ['error' => $e->getMessage()]);

            throw $e;
        }

        $log?->increment('attempts', 1, [
            'status' => DeliveryStatus::Sent,
            'external_id' => $externalId,
            'sent_at' => now(),
            'error' => null,
        ]);
    }

    /** @internal */
    public function markFailed(?OpsNotifyLog $log, string $error): void
    {
        $log?->update(['status' => DeliveryStatus::Failed, 'error' => $error]);
    }

    private function createLog(OpsMessage $message, Destination $destination): ?OpsNotifyLog
    {
        if (! config('ops-notify.log.enabled')) {
            return null;
        }

        return OpsNotifyLog::query()->create([
            'channel' => $destination->channel,
            'topic' => $destination->topic,
            'event' => Str::limit($message->event, 120, ''),
            'level' => $message->level,
            'title' => filled($message->title) ? Str::limit($message->title, 250) : null,
            'body' => $message->body() ?: null,
            'payload' => $message->toArray(),
            'status' => DeliveryStatus::Queued,
        ]);
    }
}
