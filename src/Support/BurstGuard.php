<?php

namespace Spokospace\OpsNotify\Support;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Stops one event from flooding the chat: an error loop can raise hundreds of messages that
 * differ only in an id, so dedupe does not catch them. The first `burst.max_per_event` of an
 * event in a window go out; the rest are held back and summed up in one digest message when
 * the window ends. The window is fixed and starts with the event's first message.
 *
 * A message is only held behind a digest that is already on the queue (claimDigest(),
 * digestScheduled()). Until then, and whenever the cache fails, messages go out as usual: a
 * held message that no digest reports would be lost without a trace.
 */
final class BurstGuard
{
    /** Seconds a digest claim waits for its digest to be queued. */
    private const PENDING_CLAIM = 60;

    /**
     * Seconds before the window's end from which nothing is held: the digest runs at the end,
     * and a message held later than it reads the count would be reported by no one.
     */
    private const END_MARGIN = 2;

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
     * over: past the limit, so the message is held if the window's digest is on the queue (see
     * claimDigest()). started_at: when the window started; it names the window's held count.
     *
     * @return array{over: bool, started_at: int, ends_at: Carbon}
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

        return [
            'over' => $count > $this->limit() && now()->getTimestamp() < $startedAt + $ttl - self::END_MARGIN,
            'started_at' => $startedAt,
            'ends_at' => Carbon::createFromTimestamp($startedAt)->addSeconds($ttl),
        ];
    }

    /**
     * True for the one message over the limit that queues the window's digest. The claim is
     * short until digestScheduled() makes it last, so a claim whose release failed cannot keep
     * the window from getting a digest for long.
     */
    public function claimDigest(string $event, int $windowStartedAt): bool
    {
        return Cache::add(self::claimKey($event, $windowStartedAt), 'pending', self::PENDING_CLAIM);
    }

    /** The digest is on the queue: from now on the window's messages over the limit are held. */
    public function digestScheduled(string $event, int $windowStartedAt): void
    {
        Cache::put(self::claimKey($event, $windowStartedAt), 'scheduled', $this->heldTtl());
    }

    public function isDigestScheduled(string $event, int $windowStartedAt): bool
    {
        return Cache::get(self::claimKey($event, $windowStartedAt)) === 'scheduled';
    }

    /** The digest could not be queued: the next message over the limit tries again. */
    public function releaseDigest(string $event, int $windowStartedAt): void
    {
        Cache::forget(self::claimKey($event, $windowStartedAt));
    }

    /** Counts one held message, for the window's digest. */
    public function countHeld(string $event, int $windowStartedAt): void
    {
        $key = self::heldKey($event, $windowStartedAt);

        Cache::add($key, 0, $this->heldTtl());
        $this->keepTtl($key, (int) Cache::increment($key), $this->heldTtl());
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
        return (int) Cache::get(self::heldKey($event, $windowStartedAt), 0)
            // Up to 1.3 every window shared one count. A digest queued before the upgrade still
            // finds it there. Remove once no 1.3 digest can be pending.
            + (int) Cache::get(self::key($event).':held', 0);
    }

    /** Clears that window's held count, once its digest is safely on the queue. */
    public function clearHeld(string $event, int $windowStartedAt): void
    {
        Cache::forget(self::heldKey($event, $windowStartedAt));
        Cache::forget(self::key($event).':held');
    }

    /**
     * One digest of a window at a time. A window can get two (a claim that expired while its
     * digest was being queued; a queue that delivers a job twice): the second must find the count
     * already cleared, not report it again.
     */
    public function digestLock(string $event, int $windowStartedAt): Lock
    {
        return Cache::lock(self::claimKey($event, $windowStartedAt).':lock', 30);
    }

    /**
     * Held counts and digest claims outlive their window, so a delayed or backed-up worker running
     * the digest late can still read them: as long as delivery itself is allowed to keep retrying.
     */
    private function heldTtl(): int
    {
        return $this->windowMinutes() * 60 + max(900, (int) config('ops-notify.rate_limit.give_up_after_minutes', 60) * 60);
    }

    private static function key(string $event): string
    {
        return 'ops-notify:burst:'.hash('xxh128', $event);
    }

    private static function claimKey(string $event, int $windowStartedAt): string
    {
        return self::key($event).':digest:'.$windowStartedAt;
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
