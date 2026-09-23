<?php

namespace Spokospace\OpsNotify;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Traits\Localizable;
use Spokospace\OpsNotify\Channels\Telegram\TelegramChannel;
use Spokospace\OpsNotify\Enums\DeliveryStatus;
use Spokospace\OpsNotify\Exceptions\ChannelException;
use Spokospace\OpsNotify\Exceptions\MessageSkipped;
use Spokospace\OpsNotify\Jobs\SendBurstDigest;
use Spokospace\OpsNotify\Jobs\SendOpsMessage;
use Spokospace\OpsNotify\Models\OpsNotifyLog;
use Spokospace\OpsNotify\Settings\SettingsStore;
use Spokospace\OpsNotify\Support\BurstGuard;
use Spokospace\OpsNotify\Support\Destination;
use Spokospace\OpsNotify\Support\PatternMap;
use Spokospace\OpsNotify\Support\QueueStatus;
use Throwable;

class OpsNotifier
{
    use Localizable;

    public function __construct(
        private readonly ChannelManager $channels,
        private readonly SettingsStore $settings,
    ) {}

    public function isEnabled(): bool
    {
        if (config('ops-notify.disable_in_tests', true) && app()->runningUnitTests()) {
            return false;
        }

        $this->settings->apply();

        return (bool) config('ops-notify.enabled');
    }

    public function channels(): ChannelManager
    {
        return $this->channels;
    }

    /**
     * The default channel as a Telegram channel, for Telegram-only features (topics, bot profile).
     *
     * @throws ChannelException when the default channel is not a Telegram driver.
     */
    public function telegram(): TelegramChannel
    {
        return $this->channels->telegram();
    }

    /** Language of the text the package puts into messages: ops-notify.locale, else the app's. */
    public function locale(): string
    {
        $this->settings->apply();

        return (string) (config('ops-notify.locale') ?: config('app.locale'));
    }

    /**
     * Runs $callback in the messages' locale. Use it when building a message from package
     * strings, so a message is never half in the viewer's language and half in the chat's.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function inMessageLocale(callable $callback): mixed
    {
        return $this->withLocale($this->locale(), $callback);
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

            if ($this->heldBack($message, $destination, $log)) {
                return $log;
            }

            $log = $this->createLog($message, $destination);

            // On the sync queue a failed delivery throws right here; the job has already marked
            // the row failed, so the caller still gets it back.
            $this->dispatch($message, $destination, $log);
        } catch (Throwable $e) {
            Log::warning('[ops-notify] Could not send "'.$message->event.'": '.$e->getMessage());
        }

        return $log;
    }

    /**
     * @internal Queues a message that has already passed routing and the burst guard, such as
     *           a burst digest. May throw on the sync queue.
     */
    public function queue(OpsMessage $message, Destination $destination): ?OpsNotifyLog
    {
        $log = $this->createLog($message, $destination);
        $this->dispatch($message, $destination, $log);

        return $log;
    }

    /**
     * Deliver synchronously, bypassing the queue.
     *
     * @throws MessageSkipped when notifications are disabled globally or for this event.
     * @throws ChannelException when the channel rejects the message.
     * @throws Throwable on misconfiguration (unknown channel or driver); the log row is marked failed.
     */
    public function sendNow(OpsMessage $message): ?OpsNotifyLog
    {
        $destination = $this->destinationFor($message) ?? throw MessageSkipped::for($message->event);

        $log = $this->createLog($message, $destination);

        try {
            $this->deliver($message, $destination, $log);
        } catch (Throwable $e) {
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
        if (! $this->isEnabled()) {
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
            // Rendering adds package text ("and N more fields"): use the chat's language, not
            // whatever locale this worker or request happens to run in.
            $externalId = $this->inMessageLocale(
                fn (): string => $this->channels->channel($destination->channel)->send($message, $destination),
            );
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

    /**
     * During a burst, logs the message as Suppressed instead of sending it, and schedules the
     * digest for the end of the window. Not on the sync queue, which cannot delay the digest.
     */
    private function heldBack(OpsMessage $message, Destination $destination, ?OpsNotifyLog &$log): bool
    {
        $guard = app(BurstGuard::class);

        if (! $guard->enabled() || app(QueueStatus::class)->isSync()) {
            return false;
        }

        // Bell notifications without a title rule all share the default event name, so a build
        // notification and a new comment would count as one burst. Count those per title.
        $perTitle = $message->event === config('ops-notify.forward_database_notifications.default_event') && filled($message->title);
        $key = $perTitle ? $message->event.'|'.$message->title : $message->event;
        $burst = $guard->hit($key);

        if (! $burst['held']) {
            return false;
        }

        $log = $this->createLog($message, $destination, DeliveryStatus::Suppressed);

        if ($burst['first']) {
            $startedAt = $burst['ends_at']->copy()->subMinutes($guard->windowMinutes())->getTimestamp();

            SendBurstDigest::dispatch($message->event, $destination, $message->level, $startedAt, $key, $perTitle ? $message->title : null)
                ->delay($burst['ends_at'])
                ->onConnection(config('ops-notify.queue.connection'))
                ->onQueue(config('ops-notify.queue.name'));
        }

        return true;
    }

    private function dispatch(OpsMessage $message, Destination $destination, ?OpsNotifyLog $log): void
    {
        SendOpsMessage::dispatch($message, $destination, $log)
            ->onConnection(config('ops-notify.queue.connection'))
            ->onQueue(config('ops-notify.queue.name'));
    }

    private function createLog(OpsMessage $message, Destination $destination, DeliveryStatus $status = DeliveryStatus::Queued): ?OpsNotifyLog
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
            // TEXT holds 64 KB; a stack trace could overflow it and fail the whole send.
            'body' => Str::limit($message->body(), 10000) ?: null,
            'payload' => $message->toArray(),
            'status' => $status,
        ]);
    }
}
