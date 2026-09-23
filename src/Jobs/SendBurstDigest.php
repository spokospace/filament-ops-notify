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

    public function __construct(
        public string $event,
        public Destination $destination,
        public Level $level,
        public int $windowStartedAt,
    ) {}

    public function handle(OpsNotifier $notifier, BurstGuard $guard): void
    {
        if (($held = $guard->pullHeld($this->event)) === 0) {
            return;
        }

        $message = $notifier->inMessageLocale(fn (): OpsMessage => OpsMessage::make($this->event)
            ->level($this->level)
            ->title(Trans::choice('message.burst_title', $held, ['event' => $this->event, 'minutes' => $guard->windowMinutes()]))
            ->lines($this->topTitles()));

        $notifier->queue($message, $this->destination);
    }

    /** @return list<string> e.g. "12× Database connection lost", most frequent first. */
    private function topTitles(): array
    {
        if (! config('ops-notify.log.enabled')) {
            return [];
        }

        return OpsNotifyLog::query()
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
            ->all();
    }
}
