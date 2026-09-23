<?php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;
use Spokospace\OpsNotify\Exceptions\MessageSkipped;
use Spokospace\OpsNotify\Models\OpsNotifyLog;
use Spokospace\OpsNotify\OpsMessage;
use Spokospace\OpsNotify\OpsNotifyServiceProvider;

it('runs its migrations from vendor unless the app turns them off', function (bool $setting) {
    config(['ops-notify.run_migrations' => $setting]);

    $provider = new OpsNotifyServiceProvider(app());
    $provider->register();

    expect((fn () => $this->package->runsMigrations)->call($provider))->toBe($setting);
})->with([true, false]);

it('sends nothing from an app test suite unless it opts in', function () {
    Http::fake();
    config(['ops-notify.disable_in_tests' => true]);

    expect(OpsMessage::make('x')->send())->toBeNull()
        ->and(fn () => OpsMessage::make('x')->sendNow())->toThrow(MessageSkipped::class);

    Http::assertNothingSent();
    expect(OpsNotifyLog::query()->count())->toBe(0);
});

function pruneEvents(): array
{
    return collect(app(Schedule::class)->events())
        ->filter(fn (Event $event) => str_contains((string) $event->command, 'model:prune'))
        ->values()
        ->all();
}

it('schedules pruning of its own log', function () {
    $events = pruneEvents();

    expect($events)->toHaveCount(1)
        ->and($events[0]->expression)->toBe('45 2 * * *')
        ->and($events[0]->command)->toContain('OpsNotifyLog')
        ->and($events[0]->description)->toBe('ops-notify:prune-log');
});

it('leaves pruning to the app when prune_at is null', function () {
    config(['ops-notify.log.prune_at' => null]);
    app()->forgetInstance(Schedule::class);

    expect(pruneEvents())->toBe([]);
});
