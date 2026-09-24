<?php

use Filament\Facades\Filament;
use Filament\Schemas\Components\Text;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Spokospace\OpsNotify\Enums\DeliveryStatus;
use Spokospace\OpsNotify\Filament\Pages\OpsNotifyPage;
use Spokospace\OpsNotify\Models\OpsNotifyLog;
use Spokospace\OpsNotify\Support\SeenEvents;

function seen(string $event, int $times = 1, ?string $title = null, int $daysAgo = 0): void
{
    for ($i = 0; $i < $times; $i++) {
        $log = OpsNotifyLog::query()->create([
            'channel' => 'telegram',
            'event' => $event,
            'title' => $title,
            'level' => 'info',
            'payload' => [],
            'status' => DeliveryStatus::Sent,
        ]);
        $log->forceFill(['created_at' => now()->subDays($daysAgo)])->save();
    }
}

it('counts the events of the last 30 days, most frequent first', function () {
    seen('error.thrown');
    seen('inquiry.created', 3);
    seen('build.failed', 5, daysAgo: 31);

    expect(SeenEvents::counts())->toBe(['inquiry.created' => 3, 'error.thrown' => 1]);
});

it('adds the wildcard form of each prefix', function () {
    expect(SeenEvents::patterns(['inquiry.created' => 3, 'inquiry.updated' => 2, 'site.build.failed' => 1, 'order_placed' => 1]))
        ->toBe(['inquiry.created', 'inquiry.*', 'inquiry.updated', 'site.build.failed', 'site.build.*', 'site.*', 'order_placed']);
});

it('suggests titles of bell notifications no title rule renamed', function () {
    seen('filament.notification', 2, 'Export completed');
    seen('filament.notification', 1, 'New comment');
    seen('inquiry.created', 4, 'New inquiry');

    expect(SeenEvents::titles())->toBe(['Export completed', 'New comment']);

    config(['ops-notify.forward_database_notifications.default_event' => null]);

    expect(SeenEvents::titles())->toBe([]);
});

it('reads no further back than the log is kept', function () {
    config(['ops-notify.log.prune_after_days' => 7]);
    seen('inquiry.created', daysAgo: 8);

    expect(SeenEvents::days())->toBe(7)
        ->and(SeenEvents::counts())->toBe([]);
});

it('suggests nothing when the history table is missing', function () {
    Schema::drop('ops_notify_logs');

    expect(SeenEvents::counts())->toBe([]);
});

it('lists recent events in Settings and marks the ones no rule matches', function () {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['status' => 'creator', 'username' => 'bot']])]);
    config(['ops-notify.events' => ['inquiry.*' => ['topic' => '3'], 'build.*' => [], 'debug.*' => ['enabled' => false]]]);
    seen('inquiry.created', 2);
    seen('order_placed');
    seen('build.failed');
    seen('debug.dump');

    Filament::setCurrentPanel('admin');
    $this->actingAs($this->admin());

    Livewire::test(OpsNotifyPage::class)
        ->mountAction('settings')
        ->assertSchemaComponentExists('routing.seen_events', 'mountedActionSchema0', fn (Text $text): bool => $text->getContent()
            === 'Seen in the last 30 days: inquiry.created (2) · build.failed (1, no topic in rules) · debug.dump (1, Disabled) · order_placed (1, no topic in rules)');
});
