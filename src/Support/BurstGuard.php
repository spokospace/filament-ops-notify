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
        $this->keepTtl("{$key}:count", $count, $ttl);

        $endsAt = Carbon::createFromTimestamp((int) Cache::get("{$key}:start", now()->getTimestamp()))->addSeconds($ttl);
        $held = $count > $this->limit();

        if ($held) {
            // Outlives the window, so a delayed or backed-up worker running the digest late can
            // still read it: give it as long as delivery itself is allowed to keep retrying.
            $heldTtl = $ttl + max(900, (int) config('ops-notify.rate_limit.give_up_after_minutes', 60) * 60);
            Cache::add("{$key}:held", 0, $heldTtl);
            $this->keepTtl("{$key}:held", (int) Cache::increment("{$key}:held"), $heldTtl);
        }

        return ['held' => $held, 'first' => $count === $this->limit() + 1, 'ends_at' => $endsAt];
    }

    /**
     * Re-arms the TTL when increment() just revived the key. Cache::add + Cache::increment is not
     * atomic: if the key expires between them, Redis INCR recreates it with no expiry, the counter
     * never resets and the event is suppressed forever. A revived key always comes back at 1, so
     * that is exactly when the window's TTL has to be restored.
     */
    private function keepTtl(string $key, int $count, int $ttl): void
    {
        if ($count === 1) {
            Cache::put($key, 1, $ttl);
        }
    }

    /**
     * How many messages of $event were held back. Read-only: the count is cleared with
     * clearHeld() only once the digest has actually been queued, so a digest job that fails and
     * retries still sees the count instead of a 0 it would exit on.
     */
    public function heldCount(string $event): int
    {
        return (int) Cache::get(self::key($event).':held', 0);
    }

    /** Resets the held counter for the next window, once its digest is safely on the queue. */
    public function clearHeld(string $event): void
    {
        Cache::forget(self::key($event).':held');
    }

    private static function key(string $event): string
    {
        return 'ops-notify:burst:'.hash('xxh128', $event);
    }
}
