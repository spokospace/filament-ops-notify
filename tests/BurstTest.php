<?php

use Illuminate\Cache\ArrayStore;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Testing\Fakes\QueueFake;
use Spokospace\OpsNotify\Enums\DeliveryStatus;
use Spokospace\OpsNotify\Enums\Level;
use Spokospace\OpsNotify\Jobs\SendBurstDigest;
use Spokospace\OpsNotify\Jobs\SendOpsMessage;
use Spokospace\OpsNotify\Models\OpsNotifyLog;
use Spokospace\OpsNotify\OpsMessage;
use Spokospace\OpsNotify\OpsNotifier;
use Spokospace\OpsNotify\Support\BurstGuard;
use Spokospace\OpsNotify\Support\Destination;
use Spokospace\OpsNotify\Support\SeenEvents;

beforeEach(function () {
    config(['queue.default' => 'database', 'ops-notify.burst' => ['max_per_event' => 2, 'window_minutes' => 5]]);
    Queue::fake();
});

function burst(int $count, string $event = 'error.thrown', string $title = 'Boom'): void
{
    foreach (range(1, $count) as $i) {
        // A real burst differs in some detail (an id, a timestamp); identical messages are deduped.
        OpsMessage::make($event)->error()->title($title)->field('Request', uniqid())->send();
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

it('counts bell notifications without a title rule per title', function () {
    burst(3, 'filament.notification', 'Build completed');
    burst(2, 'filament.notification', 'New comment');

    // 2 builds and 2 comments go out; only the third build is held.
    Queue::assertPushed(SendOpsMessage::class, 4);
    Queue::assertPushed(SendBurstDigest::class, 1);

    $digest = Queue::pushed(SendBurstDigest::class)->first();
    Queue::fake();
    $digest->handle(app(OpsNotifier::class), app(BurstGuard::class));

    Queue::assertPushed(SendOpsMessage::class, fn (SendOpsMessage $job): bool => $job->message->event === 'filament.notification'
        && $job->message->title === '1 more "Build completed" message was held back in 5 minutes'
        && $job->message->lines === []);
});

it('marks the digest in the log, so Settings does not suggest its title', function () {
    burst(3, 'filament.notification', 'Build completed');

    $digest = Queue::pushed(SendBurstDigest::class)->first();
    $digest->handle(app(OpsNotifier::class), app(BurstGuard::class));

    $marked = OpsNotifyLog::query()->where('payload->'.OpsNotifyLog::DIGEST, true)->pluck('title')->all();

    expect($marked)->toBe(['1 more "Build completed" message was held back in 5 minutes'])
        ->and(SeenEvents::titles())->toBe(['Build completed']);
});

it('still counts named events as a whole, whatever the titles', function () {
    burst(2, 'error.thrown', 'Boom');
    burst(1, 'error.thrown', 'Other');

    Queue::assertPushed(SendOpsMessage::class, 2);
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

it('counts and digests each destination separately', function () {
    // error.thrown floods topic 1; the same event is quiet on topic 2.
    foreach (range(1, 5) as $i) {
        OpsMessage::make('error.thrown')->error()->title('Boom')->topic('1')->field('r', uniqid())->send();
    }
    OpsMessage::make('error.thrown')->error()->title('Boom')->topic('2')->field('r', uniqid())->send();

    // Topic 2's single message is sent, not suppressed by topic 1's burst.
    expect(OpsNotifyLog::query()->where('topic', '2')->where('status', DeliveryStatus::Suppressed)->count())->toBe(0)
        ->and(OpsNotifyLog::query()->where('topic', '1')->where('status', DeliveryStatus::Suppressed)->count())->toBe(3);

    // One digest, targeting the flooded destination.
    Queue::assertPushed(SendBurstDigest::class, 1);
    Queue::assertPushed(SendBurstDigest::class, fn (SendBurstDigest $job): bool => $job->destination->topic === '1');
});

it('lists the held titles even when the event name is longer than the log column', function () {
    $event = 'error.'.str_repeat('x', 200); // longer than OpsNotifyLog::EVENT_LENGTH (120)
    burst(3, $event, 'Database connection lost');

    $digest = Queue::pushed(SendBurstDigest::class)->first();
    Queue::fake();
    $digest->handle(app(OpsNotifier::class), app(BurstGuard::class));

    // topTitles() matches the truncated event the log actually stored, so the title still shows.
    Queue::assertPushed(SendOpsMessage::class, fn (SendOpsMessage $job): bool => $job->message->lines === ['1× Database connection lost']);
});

it('does not count another destination\'s rows in the digest titles', function () {
    burst(3, 'error.thrown', 'Boom'); // topic-less: 1 held on the default destination

    // A suppressed row of the same event on another topic must not leak into this digest.
    OpsNotifyLog::query()->create([
        'channel' => 'telegram', 'topic' => '99', 'event' => 'error.thrown', 'level' => Level::Error,
        'title' => 'Elsewhere', 'status' => DeliveryStatus::Suppressed,
    ]);

    $digest = Queue::pushed(SendBurstDigest::class)->first();
    Queue::fake();
    $digest->handle(app(OpsNotifier::class), app(BurstGuard::class));

    Queue::assertPushed(SendOpsMessage::class, fn (SendOpsMessage $job): bool => $job->message->lines === ['1× Boom']);
});

it('still posts the digest when the worker runs it long after the window', function () {
    $this->freezeTime();
    burst(3); // 1 held, digest scheduled for the window end

    $digest = Queue::pushed(SendBurstDigest::class)->first();
    Queue::fake();

    // Backed-up worker: the digest runs 35 minutes after a 5-minute window.
    $this->travel(35)->minutes();
    $digest->handle(app(OpsNotifier::class), app(BurstGuard::class));

    Queue::assertPushed(SendOpsMessage::class, 1);
});

it('keeps the held count when the digest job fails, so a retry still reports it', function () {
    burst(3); // 1 held
    $digest = Queue::pushed(SendBurstDigest::class)->first();
    Queue::fake();

    $failing = Mockery::mock(app(OpsNotifier::class))->makePartial();
    $failing->shouldReceive('queue')->once()->andThrow(new RuntimeException('db down'));

    expect(fn () => $digest->handle($failing, app(BurstGuard::class)))->toThrow(RuntimeException::class)
        ->and(app(BurstGuard::class)->heldCount($digest->key ?? $digest->event, $digest->windowStartedAt))->toBe(1);

    // The retry, with a working notifier, still posts the summary.
    $digest->handle(app(OpsNotifier::class), app(BurstGuard::class));
    Queue::assertPushed(SendOpsMessage::class, 1);
});

it('schedules the digest even if logging the suppressed row fails', function () {
    burst(2); // fills the window to the limit; nothing held yet

    // Break the log table so the next (suppressed) insert throws.
    Schema::drop('ops_notify_logs');

    // The 3rd message is the first held: the digest is scheduled before the row is logged, so a
    // broken insert cannot cost it.
    OpsMessage::make('error.thrown')->error()->title('Boom')->field('r', uniqid())->send();

    Queue::assertPushed(SendBurstDigest::class, 1);
});

/** A queue that refuses the first $refusals burst digests, then works. */
function refuseDigests(int $refusals = 1): void
{
    Queue::swap(new class(app(), $refusals) extends QueueFake
    {
        public function __construct($app, public int $refusals)
        {
            parent::__construct($app);
        }

        public function later($delay, $job, $data = '', $queue = null): mixed
        {
            if ($job instanceof SendBurstDigest && $this->refusals-- > 0) {
                throw new RuntimeException('queue down');
            }

            return parent::later($delay, $job, $data, $queue);
        }
    });
}

/** Messages held back, and how many of them the queued digests will report. */
function heldAndReported(): array
{
    return [
        OpsNotifyLog::query()->where('status', DeliveryStatus::Suppressed)->count(),
        Queue::pushed(SendBurstDigest::class)->sum(fn (SendBurstDigest $digest): int => app(BurstGuard::class)->heldCount($digest->key ?? $digest->event, $digest->windowStartedAt)),
    ];
}

it('lets the window through and tries again when the digest cannot be scheduled', function () {
    refuseDigests();

    burst(2 + 5);

    // #3 could not get a digest, so it went out; #4 queued the digest that covers #4 to #7.
    expect(heldAndReported())->toBe([4, 4]);
    Queue::assertPushed(SendOpsMessage::class, 3);
    Queue::assertPushed(SendBurstDigest::class, 1);
});

it('sends rather than holds while another worker is still queuing the digest', function () {
    burst(2);
    $guard = app(BurstGuard::class);
    $key = 'telegram||error.thrown';

    // Worker A is over the limit and claims the digest; before its dispatch fails, B's message
    // comes in. B cannot know whether a digest will come, so it sends.
    $a = $guard->hit($key);
    expect($guard->claimDigest($key, $a['started_at']))->toBeTrue();
    burst(1);
    $guard->releaseDigest($key, $a['started_at']);

    // The next message over the limit queues the digest and is held behind it.
    burst(1);

    expect(heldAndReported())->toBe([1, 1]);
    Queue::assertPushed(SendOpsMessage::class, 3);
});

it('sends rather than holds behind a claim whose release failed, until the claim expires', function () {
    refuseDigests();
    // The cache fails on releasing the claim too, then recovers.
    $flaky = new class extends ArrayStore
    {
        public bool $failing = true;

        public function forget($key)
        {
            if ($this->failing && str_contains($key, ':digest:')) {
                throw new RuntimeException('cache down');
            }

            return parent::forget($key);
        }
    };
    Cache::extend('flaky', fn () => Cache::repository($flaky));
    config(['cache.stores.flaky' => ['driver' => 'flaky'], 'cache.default' => 'flaky']);

    burst(2 + 3); // #3 claims and fails; #4 and #5 find a stale claim

    expect(heldAndReported())->toBe([0, 0]);
    Queue::assertPushed(SendOpsMessage::class, 5);

    $flaky->failing = false;
    $this->travel(61)->seconds();
    burst(2); // the claim expired: #6 queues the digest, #7 is held too

    expect(heldAndReported())->toBe([2, 2]);
});

it('sends a message that arrives as the window ends instead of holding it past its digest', function () {
    $this->travelTo(Carbon::parse('2026-11-27 12:00:00.800'));
    burst(3); // the window started at 12:00:00, so its digest runs from 12:05:00

    $this->travelTo(Carbon::parse('2026-11-27 12:04:59.000'));
    burst(1);

    Queue::assertPushed(SendOpsMessage::class, 3);
    expect(heldAndReported())->toBe([1, 1]);
});

it('reports a window once, even when it got two digests', function () {
    burst(4); // 2 held
    $digest = Queue::pushed(SendBurstDigest::class)->sole();
    Queue::fake();

    // The twin runs while the first digest holds the lock: it leaves the count to that one.
    $lock = app(BurstGuard::class)->digestLock($digest->key, $digest->windowStartedAt);
    $lock->get();
    (clone $digest)->handle(app(OpsNotifier::class), app(BurstGuard::class));
    Queue::assertNothingPushed();
    $lock->release();

    $digest->handle(app(OpsNotifier::class), app(BurstGuard::class));
    (clone $digest)->handle(app(OpsNotifier::class), app(BurstGuard::class));

    Queue::assertPushed(SendOpsMessage::class, 1);
});

it('sends a message whose digest took until the end of the window to queue, instead of holding it', function () {
    $this->travelTo(Carbon::parse('2026-11-27 12:00:00'));
    burst(2);

    // The dispatch of the digest is slow: it returns 3 s before the end, inside the margin.
    Queue::swap(new class(app()) extends QueueFake
    {
        public function later($delay, $job, $data = '', $queue = null): mixed
        {
            test()->travelTo(Carbon::parse('2026-11-27 12:04:59'));

            return parent::later($delay, $job, $data, $queue);
        }
    });
    test()->travelTo(Carbon::parse('2026-11-27 12:04:56'));
    burst(1);

    Queue::assertPushed(SendOpsMessage::class, 1);
    expect(heldAndReported())->toBe([0, 0]);
});

it('reports a count held before the upgrade, when every window shared one key', function () {
    burst(3); // 1 held under the new per-window key
    $digest = Queue::pushed(SendBurstDigest::class)->first();
    Cache::put('ops-notify:burst:'.hash('xxh128', $digest->key).':held', 4, 600);
    Queue::fake();

    $digest->handle(app(OpsNotifier::class), app(BurstGuard::class));

    Queue::assertPushed(SendOpsMessage::class, fn (SendOpsMessage $job): bool => $job->message->title === '5 more "error.thrown" messages were held back in 5 minutes');
    expect(app(BurstGuard::class)->heldCount($digest->key, $digest->windowStartedAt))->toBe(0);
});

it('gives each window its own held count when the next window starts before the digest runs', function () {
    $this->freezeTime();
    burst(5); // window 1: 3 held

    // The error loop goes on: window 2 fills up before window 1's digest has run.
    $this->travel(6)->minutes();
    burst(4); // window 2: 2 held

    [$first, $second] = Queue::pushed(SendBurstDigest::class)->all();
    Queue::fake();
    $first->handle(app(OpsNotifier::class), app(BurstGuard::class));
    $second->handle(app(OpsNotifier::class), app(BurstGuard::class));

    expect(Queue::pushed(SendOpsMessage::class)->map(fn (SendOpsMessage $job): ?string => $job->message->title)->all())->toBe([
        '3 more "error.thrown" messages were held back in 5 minutes',
        '2 more "error.thrown" messages were held back in 5 minutes',
    ]);
});

it('re-arms the burst counter TTL after a bare increment revives it', function () {
    // The full race is Redis-specific (INCR revives an expired key with no TTL); here we assert
    // the guard restores the window TTL whenever increment() comes back at 1.
    $cache = Cache::partialMock();
    $cache->shouldReceive('add')->andReturnTrue();
    $cache->shouldReceive('get')->andReturn(now()->getTimestamp());
    $cache->shouldReceive('increment')->andReturn(1);
    $cache->shouldReceive('put')->once()
        ->withArgs(fn (string $key, int $value, int $ttl): bool => str_ends_with($key, ':count') && $value === 1 && $ttl === 300)
        ->andReturnTrue();

    app(BurstGuard::class)->hit('error.thrown');
});
