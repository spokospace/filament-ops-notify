<?php

namespace Spokospace\OpsNotify\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Jobs\SyncJob;
use Spokospace\OpsNotify\Exceptions\ChannelException;
use Spokospace\OpsNotify\Models\OpsNotifyLog;
use Spokospace\OpsNotify\OpsMessage;
use Spokospace\OpsNotify\OpsNotifier;
use Spokospace\OpsNotify\Support\Destination;
use Throwable;

class SendOpsMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

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
