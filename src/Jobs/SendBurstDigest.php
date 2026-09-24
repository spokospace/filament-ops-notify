<?php

namespace Spokospace\OpsNotify\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
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
        if (($held = $guard->pullHeld($this->key ?? $this->event)) === 0) {
            return;
        }

        $message = $notifier->inMessageLocale(fn (): OpsMessage => OpsMessage::make($this->event)
            ->level($this->level)
            // A template written for the event's own messages (":field.Name") would empty the digest.
            ->withoutTemplate()
            ->title(Trans::choice('message.burst_title', $held, ['event' => $this->title ?? $this->event, 'minutes' => $guard->windowMinutes()]))
            // Counted per title, every held message had this title: no breakdown to list.
            ->lines($this->title === null ? $this->topTitles() : []));

        $notifier->queue($message, $this->destination, digest: true);
    }

    /** @return list<string> e.g. "12× Database connection lost", most frequent first. */
    private function topTitles(): array
    {
        if (! config('ops-notify.log.enabled')) {
            return [];
        }

        return array_values(OpsNotifyLog::query()
            ->where('event', $this->event)
            ->where('status', DeliveryStatus::Suppressed)
            ->where('created_at', '>=', date('Y-m-d H:i:s', $this->windowStartedAt))
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
