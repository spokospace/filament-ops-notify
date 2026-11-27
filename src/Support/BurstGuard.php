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
     * held: over the limit, do not send. first: the first one held in this window, so the caller
     * schedules the digest for ends_at. started_at: when the window started; it names the
     * window's held count.
     *
     * @return array{held: bool, first: bool, started_at: int, ends_at: Carbon}
     */
    public function hit(string $event): array
    {
        $key = self::key($event);
        $ttl = $this->windowMinutes() * 60;

        Cache::add("{$key}:start", now()->getTimestamp(), $ttl);
        Cache::add("{$key}:count", 0, $ttl);

        $count = (int) Cache::increment("{$key}:count");
        $this->keepTtl("{$key}:count", $count, $ttl);

        $startedAt = (int) Cache::get("{$key}:start", now()->getTimestamp());
        $held = $count > $this->limit();

        if ($held) {
            // Outlives the window, so a delayed or backed-up worker running the digest late can
            // still read it: give it as long as delivery itself is allowed to keep retrying.
            $heldTtl = $ttl + max(900, (int) config('ops-notify.rate_limit.give_up_after_minutes', 60) * 60);
            $heldKey = self::heldKey($event, $startedAt);
            Cache::add($heldKey, 0, $heldTtl);
            $this->keepTtl($heldKey, (int) Cache::increment($heldKey), $heldTtl);
        }

        return [
            'held' => $held,
            'first' => $count === $this->limit() + 1,
            'started_at' => $startedAt,
            'ends_at' => Carbon::createFromTimestamp($startedAt)->addSeconds($ttl),
        ];
    }

    /**
     * Starts $event's window over, held count included. For when the digest could not be
     * scheduled: the messages after this one go out, and the next one over the limit tries to
     * schedule the digest again, so nothing is held without a digest to report it.
     */
    public function reset(string $event): void
    {
        $key = self::key($event);

        $held = self::heldKey($event, (int) Cache::get("{$key}:start", 0));
        Cache::forget("{$key}:start");
        Cache::forget("{$key}:count");
        Cache::forget($held);
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
     * How many messages of $event were held back in the window that started at $windowStartedAt.
     * Read-only: the count is cleared with clearHeld() only once the digest has actually been
     * queued, so a digest job that fails and retries still sees the count instead of a 0 it
     * would exit on.
     */
    public function heldCount(string $event, int $windowStartedAt): int
    {
        return (int) Cache::get(self::heldKey($event, $windowStartedAt), 0);
    }

    /** Clears that window's held count, once its digest is safely on the queue. */
    public function clearHeld(string $event, int $windowStartedAt): void
    {
        Cache::forget(self::heldKey($event, $windowStartedAt));
    }

    private static function key(string $event): string
    {
        return 'ops-notify:burst:'.hash('xxh128', $event);
    }

    /**
     * One held counter per window. The counter outlives its window, so with one shared key the
     * next window of a running error loop would add to it before the first digest read it: that
     * digest would report the next window's messages too, and clearing it would lose them.
     */
    private static function heldKey(string $event, int $windowStartedAt): string
    {
        return self::key($event).':held:'.$windowStartedAt;
    }
}
