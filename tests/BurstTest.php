<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Spokospace\OpsNotify\Enums\DeliveryStatus;
use Spokospace\OpsNotify\Enums\Level;
use Spokospace\OpsNotify\Jobs\SendBurstDigest;
use Spokospace\OpsNotify\Jobs\SendOpsMessage;
use Spokospace\OpsNotify\Models\OpsNotifyLog;
use Spokospace\OpsNotify\OpsMessage;
use Spokospace\OpsNotify\OpsNotifier;
use Spokospace\OpsNotify\Support\BurstGuard;
use Spokospace\OpsNotify\Support\Destination;

beforeEach(function () {
    config(['queue.default' => 'database', 'ops-notify.burst' => ['max_per_event' => 2, 'window_minutes' => 5]]);
    Queue::fake();
});

function burst(int $count, string $event = 'error.thrown', string $title = 'Boom'): void
{
    foreach (range(1, $count) as $i) {
        OpsMessage::make($event)->error()->title($title)->send();
    }
}

it('sends the first messages of an event and holds the rest back', function () {
    burst(5);

    Queue::assertPushed(SendOpsMessage::class, 2);
    expect(OpsNotifyLog::query()->where('status', DeliveryStatus::Suppressed)->count())->toBe(3);
});

it('schedules one digest for the end of the window', function () {
    $this->freezeTime();
    burst(5);

    Queue::assertPushed(SendBurstDigest::class, 1);
    Queue::assertPushed(SendBurstDigest::class, fn (SendBurstDigest $job): bool => $job->event === 'error.thrown'
        && $job->level === Level::Error
        && $job->delay->getTimestamp() === now()->addMinutes(5)->getTimestamp());
});

it('counts each event separately', function () {
    burst(2, 'error.thrown');
    burst(2, 'inquiry.created');

    Queue::assertPushed(SendOpsMessage::class, 4);
    Queue::assertNotPushed(SendBurstDigest::class);
});

it('sums the held messages up in the digest, most frequent title first', function () {
    burst(2);
    burst(3, title: 'Database connection lost');
    burst(1, title: 'Queue timeout');

    $digest = Queue::pushed(SendBurstDigest::class)->first();
    Queue::fake();
    $digest->handle(app(OpsNotifier::class), app(BurstGuard::class));

    Queue::assertPushed(SendOpsMessage::class, function (SendOpsMessage $job): bool {
        return $job->message->title === '4 more "error.thrown" messages were held back in 5 minutes'
            && $job->message->lines === ['3× Database connection lost', '1× Queue timeout']
            && $job->message->level === Level::Error;
    });
});

it('sends no digest when nothing was held back', function () {
    $digest = new SendBurstDigest('error.thrown', new Destination('telegram', null), Level::Error, now()->getTimestamp());
    $digest->handle(app(OpsNotifier::class), app(BurstGuard::class));

    Queue::assertNothingPushed();
});

it('is off on the sync queue and when the limit is 0', function (array $config) {
    config($config);
    Http::fake(['*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);

    burst(4);

    expect(OpsNotifyLog::query()->where('status', DeliveryStatus::Suppressed)->count())->toBe(0);
    Queue::assertNotPushed(SendBurstDigest::class);
})->with([
    'sync queue' => [['queue.default' => 'sync']],
    'limit 0' => [['ops-notify.burst.max_per_event' => 0]],
]);
