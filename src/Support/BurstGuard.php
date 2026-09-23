<?php

namespace Spokospace\OpsNotify\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Stops one event from flooding the chat: an error loop can raise hundreds of messages that
 * differ only in an id, so dedupe does not catch them. The first `burst.max_per_event` of an
 * event in a window go out; the rest are held back and summed up in one digest message when
 * the window ends. The window is fixed and starts with the event's first message.
 */
final class BurstGuard
{
    public function enabled(): bool
    {
        return $this->limit() > 0 && $this->windowMinutes() > 0;
    }

    public function limit(): int
    {
        return (int) config('ops-notify.burst.max_per_event', 10);
    }

    public function windowMinutes(): int
    {
        return (int) config('ops-notify.burst.window_minutes', 5);
    }

    /**
     * Counts one message of $event.
     *
     * @return array{held: bool, first: bool, ends_at: Carbon} held: over the limit, do not send;
     *                                                         first: the first one held in
     *                                                         this window, so the caller
     *                                                         schedules the digest for ends_at.
     */
    public function hit(string $event): array
    {
        $key = self::key($event);
        $ttl = $this->windowMinutes() * 60;

        Cache::add("{$key}:start", now()->getTimestamp(), $ttl);
        Cache::add("{$key}:count", 0, $ttl);

        $count = (int) Cache::increment("{$key}:count");
        $endsAt = Carbon::createFromTimestamp((int) Cache::get("{$key}:start", now()->getTimestamp()))->addSeconds($ttl);
        $held = $count > $this->limit();

        if ($held) {
            // Outlives the window, so the digest that runs at its end can still read it.
            Cache::add("{$key}:held", 0, $ttl + 900);
            Cache::increment("{$key}:held");
        }

        return ['held' => $held, 'first' => $count === $this->limit() + 1, 'ends_at' => $endsAt];
    }

    /** How many messages of $event were held back, reset for the next window. */
    public function pullHeld(string $event): int
    {
        return (int) Cache::pull(self::key($event).':held', 0);
    }

    private static function key(string $event): string
    {
        return 'ops-notify:burst:'.hash('xxh128', $event);
    }
}
