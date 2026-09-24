<?php

namespace Spokospace\OpsNotify\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Spokospace\OpsNotify\Enums\DeliveryStatus;
use Spokospace\OpsNotify\Enums\Level;
use Spokospace\OpsNotify\Models\OpsNotifyLog;
use Spokospace\OpsNotify\OpsMessage;
use Spokospace\OpsNotify\OpsNotifier;
use Spokospace\OpsNotify\Support\BurstGuard;
use Spokospace\OpsNotify\Support\Destination;
use Spokospace\OpsNotify\Support\Trans;

/**
 * Runs when a burst window ends: one message that says how many messages of the event were
 * held back, with their most frequent titles, sent where the event's messages go.
 */
class SendBurstDigest implements ShouldQueue
{
    use Queueable;

    /** Titles listed in the digest. */
    private const TOP_TITLES = 5;

    /** The held count is only cleared once the digest is queued, so a failed run can retry it. */
    public int $tries = 3;

    /**
     * @param  string|null  $key  The burst counter's key, when it is not just the event name.
     * @param  string|null  $title  Set when the burst was counted per title (bell notifications
     *                              without a title rule): the digest names the title instead.
     */
    public function __construct(
        public string $event,
        public Destination $destination,
        public Level $level,
        public int $windowStartedAt,
        public ?string $key = null,
        public ?string $title = null,
    ) {}

    public function handle(OpsNotifier $notifier, BurstGuard $guard): void
    {
        $event = $this->key ?? $this->event;

        if (($held = $guard->heldCount($event)) === 0) {
            return;
        }

        $windowEndsAt = $this->windowStartedAt + $guard->windowMinutes() * 60;

        $message = $notifier->inMessageLocale(fn (): OpsMessage => OpsMessage::make($this->event)
            ->level($this->level)
            // A template written for the event's own messages (":field.Name") would empty the digest.
            ->withoutTemplate()
            ->title(Trans::choice('message.burst_title', $held, ['event' => $this->title ?? $this->event, 'minutes' => $guard->windowMinutes()]))
            // Counted per title, every held message had this title: no breakdown to list.
            ->lines($this->title === null ? $this->topTitles($windowEndsAt) : []));

        $notifier->queue($message, $this->destination, digest: true);

        // Only now the digest is on the queue: clearing earlier would make a failed retry read 0.
        $guard->clearHeld($event);
    }

    /** @return list<string> e.g. "12× Database connection lost", most frequent first. */
    private function topTitles(int $windowEndsAt): array
    {
        if (! config('ops-notify.log.enabled')) {
            return [];
        }

        return array_values(OpsNotifyLog::query()
            // createLog() stores the event truncated to EVENT_LENGTH; match it, or a long event
            // name would find none of its own rows.
            ->where('event', Str::limit($this->event, OpsNotifyLog::EVENT_LENGTH, ''))
            ->where('channel', $this->destination->channel)
            ->when(
                $this->destination->topic === null,
                fn ($query) => $query->whereNull('topic'),
                fn ($query) => $query->where('topic', $this->destination->topic),
            )
            ->where('status', DeliveryStatus::Suppressed)
            ->where('created_at', '>=', date('Y-m-d H:i:s', $this->windowStartedAt))
            // Upper bound, so a late digest does not count rows from the next window.
            ->where('created_at', '<=', date('Y-m-d H:i:s', $windowEndsAt))
            ->whereNotNull('title')
            ->selectRaw('title, count(*) as total')
            ->groupBy('title')
            ->orderByDesc('total')
            ->limit(self::TOP_TITLES)
            ->get()
            ->map(fn (OpsNotifyLog $row): string => $row->getAttribute('total').'× '.$row->title)
            ->all());
    }
}
