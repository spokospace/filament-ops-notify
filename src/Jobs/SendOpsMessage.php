<?php

namespace Spokospace\OpsNotify\Jobs;

use DateTimeInterface;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Cache\RateLimiting\Unlimited;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Queue\Middleware\RateLimited;
use Spokospace\OpsNotify\Exceptions\ChannelException;
use Spokospace\OpsNotify\Models\OpsNotifyLog;
use Spokospace\OpsNotify\OpsMessage;
use Spokospace\OpsNotify\OpsNotifier;
use Spokospace\OpsNotify\Support\Destination;
use Throwable;

class SendOpsMessage implements ShouldQueue
{
    use Queueable;

    /** Named rate limiter, registered by the service provider. */
    public const RATE_LIMITER = 'ops-notify';

    /**
     * Real failures (timeouts, 5xx) allowed before the job gives up. Waiting for the rate limit
     * or for Telegram's retry_after is not a failure, so the job is bounded by time instead
     * (retryUntil), not by a number of tries.
     */
    public int $maxExceptions = 5;

    /** The log row was pruned before the job ran; nothing left to report to. */
    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public OpsMessage $message,
        public Destination $destination,
        public ?OpsNotifyLog $log = null,
    ) {
        $this->afterCommit();
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 30, 120, 300];
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(max(1, (int) config('ops-notify.rate_limit.give_up_after_minutes', 60)));
    }

    /**
     * Keeps a burst inside Telegram's limits instead of bouncing off them with 429s. Not on the
     * sync queue: there release() does nothing, so a throttled message would simply be lost.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return $this->job instanceof SyncJob ? [] : [new RateLimited(self::RATE_LIMITER)];
    }

    /**
     * The limits for one destination: config ops-notify.rate_limit, keyed by channel (a channel
     * posts to one chat, and topics of that chat share Telegram's per-group budget).
     *
     * @return list<Limit>|Unlimited
     */
    public static function limits(self $job): array|Unlimited
    {
        $key = $job->destination->channel;
        $limits = [];

        if (($perSecond = (int) config('ops-notify.rate_limit.per_second', 1)) > 0) {
            $limits[] = Limit::perSecond($perSecond)->by("{$key}:second");
        }

        if (($perMinute = (int) config('ops-notify.rate_limit.per_minute', 20)) > 0) {
            $limits[] = Limit::perMinute($perMinute)->by("{$key}:minute");
        }

        return $limits === [] ? Limit::none() : $limits;
    }

    public function handle(OpsNotifier $notifier): void
    {
        try {
            $notifier->deliver($this->message, $this->destination, $this->log);
        } catch (ChannelException $e) {
            if ($e->permanent) {
                $this->fail($e);

                return;
            }

            // release() is a no-op on the sync queue; there the exception below marks the row failed.
            if ($e->retryAfter !== null && ! $this->job instanceof SyncJob) {
                $this->release($e->retryAfter);

                return;
            }

            throw $e;
        }
    }

    public function failed(?Throwable $e): void
    {
        app(OpsNotifier::class)->markFailed($this->log, $e?->getMessage() ?? 'Job failed');
    }
}
