<?php

use Illuminate\Cache\RateLimiting\Unlimited;
use Illuminate\Container\Container;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Queue\Middleware\RateLimited;
use Spokospace\OpsNotify\Jobs\SendOpsMessage;
use Spokospace\OpsNotify\OpsMessage;
use Spokospace\OpsNotify\Support\Destination;

function opsJob(string $channel = 'telegram'): SendOpsMessage
{
    return new SendOpsMessage(OpsMessage::make('error.thrown'), new Destination($channel, null));
}

it('limits each destination per second and per minute', function () {
    $limits = SendOpsMessage::limits(opsJob());

    expect($limits)->toHaveCount(2)
        ->and(array_map(fn ($limit) => [$limit->key, $limit->maxAttempts, $limit->decaySeconds], $limits))
        ->toBe([['telegram:second', 1, 1], ['telegram:minute', 20, 60]]);
});

it('can turn the limits off', function () {
    config(['ops-notify.rate_limit' => ['per_second' => 0, 'per_minute' => 0]]);

    expect(SendOpsMessage::limits(opsJob()))->toBeInstanceOf(Unlimited::class);
});

it('holds messages over the limit back instead of sending them', function () {
    config(['ops-notify.rate_limit.per_second' => 0, 'ops-notify.rate_limit.per_minute' => 2]);
    $sent = 0;

    foreach (range(1, 3) as $i) {
        $job = opsJob()->withFakeQueueInteractions();
        $job->middleware()[0]->handle($job, function () use (&$sent) {
            $sent++;
        });
    }

    expect($sent)->toBe(2);
    $job->assertReleased();
});

it('keeps separate budgets per destination', function () {
    config(['ops-notify.rate_limit.per_second' => 0, 'ops-notify.rate_limit.per_minute' => 1]);
    $sent = 0;

    foreach (['telegram', 'telegram_shop'] as $channel) {
        $job = opsJob($channel)->withFakeQueueInteractions();
        $job->middleware()[0]->handle($job, function () use (&$sent) {
            $sent++;
        });
    }

    expect($sent)->toBe(2);
});

it('does not throttle on the sync queue, where a release would lose the message', function () {
    $job = opsJob();
    $job->setJob(new SyncJob(Container::getInstance(), '{}', 'sync', 'default'));

    expect($job->middleware())->toBe([])
        ->and(opsJob()->withFakeQueueInteractions()->middleware()[0])->toBeInstanceOf(RateLimited::class);
});

it('gives up by time, so waiting for the rate limit does not use up the tries', function () {
    $job = opsJob();

    expect($job->retryUntil()->getTimestamp())->toBeGreaterThanOrEqual(now()->addMinutes(59)->getTimestamp())
        ->and(property_exists($job, 'tries'))->toBeFalse()
        ->and($job->maxExceptions)->toBe(5);
});
