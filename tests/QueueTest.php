<?php

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Spokospace\OpsNotify\Enums\DeliveryStatus;
use Spokospace\OpsNotify\Filament\Pages\OpsNotifyPage;
use Spokospace\OpsNotify\Jobs\SendOpsMessage;
use Spokospace\OpsNotify\Models\OpsNotifyLog;
use Spokospace\OpsNotify\OpsMessage;
use Spokospace\OpsNotify\Support\QueueStatus;

function queueStatus(): QueueStatus
{
    return app(QueueStatus::class);
}

function useHorizon(array $supervisor, array $defaults = []): void
{
    config([
        'queue.default' => 'redis',
        'horizon.defaults' => $defaults,
        'horizon.environments' => ['production' => [], 'test*' => ['supervisor-1' => $supervisor]],
    ]);
}

it('says the sync queue delivers immediately', function () {
    expect(queueStatus()->isSync())->toBeTrue()
        ->and(queueStatus()->summary())->toBe('Immediately (sync queue)')
        ->and(queueStatus()->warning())->toBeNull();
});

it('names the connection and queue the jobs go to', function () {
    config(['queue.default' => 'database', 'ops-notify.queue.name' => 'ops']);

    expect(queueStatus()->summary())->toBe('database · ops');
});

it('knows whether a horizon supervisor works the queue', function () {
    useHorizon(['connection' => 'redis', 'queue' => ['default', 'emails']]);
    expect(queueStatus()->coveredByHorizon())->toBeTrue();

    config(['ops-notify.queue.name' => 'ops']);
    expect(queueStatus()->coveredByHorizon())->toBeFalse()
        ->and(queueStatus()->warning())->toContain('No Horizon supervisor works the "ops" queue on "redis"');
});

it('merges horizon defaults into the environment like horizon does', function () {
    useHorizon(['maxProcesses' => 3], defaults: ['supervisor-1' => ['connection' => 'redis', 'queue' => ['ops']]]);
    config(['ops-notify.queue.name' => 'ops']);

    expect(queueStatus()->coveredByHorizon())->toBeTrue();
});

it('skips the horizon checks for other drivers', function () {
    config(['queue.default' => 'database', 'horizon.environments' => ['test*' => []]]);

    expect(queueStatus()->coveredByHorizon())->toBeNull()
        ->and(queueStatus()->horizon())->toBeNull();
});

it('warns when messages sit in the queue', function () {
    config(['queue.default' => 'database']);
    OpsNotifyLog::query()->create(['channel' => 'telegram', 'event' => 'x', 'level' => 'info', 'payload' => [], 'status' => DeliveryStatus::Queued])
        ->forceFill(['created_at' => now()->subMinutes(10)])->save();

    expect(queueStatus()->stuck())->toBe(1)
        ->and(queueStatus()->warning())->toBe('1 message has been queued for over 5 minutes. Is a queue worker running?');
});

describe('page', function () {
    beforeEach(function () {
        Filament::setCurrentPanel('admin');
        $this->actingAs($this->admin());
        Http::fake(['*/getMe' => Http::response(['ok' => true, 'result' => ['username' => 'bot']])]);
    });

    it('shows where messages go', function () {
        config(['queue.default' => 'database']);

        Livewire::test(OpsNotifyPage::class)->assertSee('database · default');
    });

    it('sends the test through the queue when a worker handles it', function () {
        config(['queue.default' => 'database']);
        Queue::fake();

        Livewire::test(OpsNotifyPage::class)
            ->callAction('sendTest', data: ['text' => 'hello', 'via_queue' => true])
            ->assertNotified('Queued');

        Queue::assertPushed(SendOpsMessage::class);
        expect(OpsNotifyLog::query()->sole()->status)->toBe(DeliveryStatus::Queued);
    });

    it('can still send the test right away', function () {
        config(['queue.default' => 'database']);
        Queue::fake();
        Http::fake(['*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);

        Livewire::test(OpsNotifyPage::class)
            ->callAction('sendTest', data: ['text' => 'hello', 'via_queue' => false])
            ->assertNotified('Sent');

        Queue::assertNothingPushed();
    });

    it('resends through the queue', function () {
        Http::fake(['*/sendMessage' => Http::response(['ok' => false, 'description' => 'Bad Gateway'], 502)]);
        $failed = OpsMessage::make('build.failed')->send()->fresh();

        config(['queue.default' => 'database']);
        Queue::fake();

        Livewire::test(OpsNotifyPage::class)
            ->callTableAction('resend', $failed)
            ->assertNotified('Queued');

        Queue::assertPushed(SendOpsMessage::class);
        expect($failed->fresh()->status)->toBe(DeliveryStatus::Resent);
    });
});
