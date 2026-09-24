<?php

use Filament\Forms\Components\Field;
use Filament\Schemas\Components\Text;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spokospace\OpsNotify\Enums\DeliveryStatus;
use Spokospace\OpsNotify\Models\OpsNotifyLog;
use Spokospace\OpsNotify\Support\SeenEvents;
use Spokospace\OpsNotify\Support\TitleRules;

function seen(string $event, int $times = 1, ?string $title = null, int $daysAgo = 0, DeliveryStatus $status = DeliveryStatus::Sent): void
{
    for ($i = 0; $i < $times; $i++) {
        $log = OpsNotifyLog::query()->create([
            'channel' => 'telegram',
            'event' => $event,
            'title' => $title,
            'level' => 'info',
            'payload' => [],
            'status' => $status,
        ]);
        $log->forceFill(['created_at' => now()->subDays($daysAgo)])->save();
    }
}

function seenLine(string $events): Closure
{
    return fn (Text $text): bool => $text->getContent() === 'Seen since '.SeenEvents::since()->isoFormat('LL').': '.$events;
}

it('counts the events of the last 30 days, most frequent first', function () {
    seen('error.thrown');
    seen('inquiry.created', 3);
    seen('build.failed', 5, daysAgo: 31);

    expect(SeenEvents::counts())->toBe(['inquiry.created' => 3, 'error.thrown' => 1]);
});

it('does not count messages held back during a burst', function () {
    seen('error.thrown', 2);
    seen('error.thrown', 50, status: DeliveryStatus::Suppressed);

    expect(SeenEvents::counts())->toBe(['error.thrown' => 2]);
});

it('adds the wildcard form of each prefix', function () {
    expect(SeenEvents::patterns(['inquiry.created' => 3, 'inquiry.updated' => 2, 'site.build.failed' => 1, 'order_placed' => 1]))
        ->toBe(['inquiry.created', 'inquiry.*', 'inquiry.updated', 'site.build.failed', 'site.build.*', 'site.*', 'order_placed']);
});

it('offers an event name the log cut short only as a prefix pattern', function () {
    $event = 'app.'.str_repeat('x', 116);

    expect(SeenEvents::isTruncated($event))->toBeTrue()
        ->and(SeenEvents::patterns([$event => 1]))->toBe([$event.'*', 'app.*']);
});

it('suggests titles of bell notifications no title rule matches', function () {
    seen('filament.notification', 2, 'Export completed');
    seen('filament.notification', 1, 'New comment');
    seen('inquiry.created', 4, 'New inquiry');

    expect(SeenEvents::titles())->toBe(['Export completed', 'New comment']);

    config(['ops-notify.forward_database_notifications.default_event' => null]);

    expect(SeenEvents::titles())->toBe([]);
});

it('stops suggesting a title once a rule matches it', function () {
    seen('filament.notification', 2, 'Export completed');
    seen('filament.notification', 1, 'New comment');
    config(['ops-notify.forward_database_notifications.map' => ['Export*' => 'export.done']]);

    expect(SeenEvents::titles())->toBe(['New comment']);
});

it('does not suggest burst digest titles', function () {
    seen('filament.notification', 3, 'New comment');
    seen('filament.notification', 1, '12 more "New comment" messages were held back in 5 minutes');
    OpsNotifyLog::query()->where('title', 'like', '12 more%')->update(['payload' => [OpsNotifyLog::DIGEST => true]]);

    expect(SeenEvents::titles())->toBe(['New comment']);
});

it('suggests a title the log cut short as a pattern that matches the full title', function () {
    $title = str_repeat('Long title ', 30);
    seen('filament.notification', 1, Str::limit($title, 250));

    $suggested = SeenEvents::titles()[0];

    expect($suggested)->toEndWith('*')
        ->and(TitleRules::ruleFor($title, [$suggested => 'long.title']))->toBe($suggested);
});

it('matches title rules against the plain title the log shows', function () {
    $map = ['New inquiry & quote' => 'inquiry.created'];

    expect(TitleRules::ruleFor('<p>New inquiry &amp; quote</p>', $map))->toBe('New inquiry & quote')
        ->and(TitleRules::ruleFor('Other', $map))->toBeNull();
});

it('reads no further back than the log is kept', function () {
    config(['ops-notify.log.prune_after_days' => 7]);
    seen('inquiry.created', daysAgo: 8);
    seen('order_placed', daysAgo: 6);

    expect(SeenEvents::days())->toBe(7)
        ->and(SeenEvents::counts())->toBe(['order_placed' => 1]);
});

it('suggests nothing when the history table is missing', function () {
    Schema::drop('ops_notify_logs');

    expect(SeenEvents::counts())->toBe([]);
});

it('lists recent events in Settings and marks the ones no rule matches', function () {
    config(['ops-notify.events' => ['inquiry.*' => ['topic' => '3'], 'build.*' => [], 'debug.*' => ['enabled' => false]]]);
    seen('inquiry.created', 2);
    seen('order_placed');
    seen('build.failed');
    seen('debug.dump');

    settingsForm()->assertSchemaComponentExists('routing.seen_events', 'mountedActionSchema0', seenLine(
        'inquiry.created (2) · build.failed (1, no topic in rules) · debug.dump (1, Disabled) · order_placed (1, no topic in rules)',
    ));
});

it('describes events the way routing treats config rules', function () {
    // Only false disables a rule; a config rule may also pick the channel.
    config(['ops-notify.events' => ['debug.*' => ['enabled' => 0, 'topic' => '5'], 'alerts.*' => ['channel' => 'second']]]);
    seen('debug.dump', 2);
    seen('alerts.disk');

    settingsForm()
        ->assertSchemaComponentExists('routing.seen_events', 'mountedActionSchema0', seenLine(
            'debug.dump (2) · alerts.disk (1, → second, no topic in rules)',
        ))
        ->assertSchemaStateSet(function (array $state): void {
            expect(array_column($state['events'], 'enabled'))->toBe([true, true]);
        }, 'mountedActionSchema0');
});

it('lists rare events past the most frequent ones only when they are marked', function () {
    config(['ops-notify.events' => ['busy.*' => ['topic' => '3'], 'rare.*' => ['topic' => '4']]]);

    foreach (range(1, SeenEvents::SUGGESTED) as $i) {
        seen(sprintf('busy.e%02d', $i), 2);
    }

    seen('rare.routed');
    seen('backup.failed');

    settingsForm()->assertSchemaComponentExists('routing.seen_events', 'mountedActionSchema0', fn (Text $text): bool => str_contains($text->getContent(), 'busy.e30 (2) · backup.failed (1, no topic in rules)')
        && ! str_contains($text->getContent(), 'rare.routed'));
});

it('updates the seen line as the rules are edited, before saving', function () {
    config(['ops-notify.events' => []]);
    seen('inquiry.created', 2);

    $form = settingsForm();
    $path = $form->instance()->mountedActionSchema0->getStatePath().'.events';

    $form->set($path, ['row' => ['pattern' => 'inquiry.*', 'topic' => null, 'enabled' => true]])
        ->set("{$path}.row.topic", '3')
        ->assertSchemaComponentExists('routing.seen_events', 'mountedActionSchema0', seenLine('inquiry.created (2)'))
        ->set("{$path}.row.enabled", false)
        ->assertSchemaComponentExists('routing.seen_events', 'mountedActionSchema0', seenLine('inquiry.created (2, Disabled)'));

    // The browser sends these changes right away, and gets back the Routing section alone.
    foreach (['pattern', 'topic', 'enabled'] as $field) {
        $form->assertSchemaComponentExists("routing.events.row.{$field}", 'mountedActionSchema0', fn (Field $component): bool => $component->isLive()
            && collect($component->getComponentsToPartiallyRenderAfterStateUpdated())
                ->map(fn (string $key): ?string => $component->getLivewire()->getSchemaComponent($component->resolveRelativeKey($key), withHidden: true)?->getKey())
                ->all() === ['mountedActionSchema0.routing']);
    }
});

it('previews from any logged message the pattern matches, cached counts and held-back messages aside', function () {
    seen('rare.event', status: DeliveryStatus::Suppressed);
    expect(SeenEvents::counts())->toBe([]); // Cached now, without the held-back message.

    seen('inquiry.created');
    seen('orderx.created');

    expect(SeenEvents::latestMessage('rare.*')?->event)->toBe('rare.event')
        ->and(SeenEvents::latestMessage('inquiry.*')?->event)->toBe('inquiry.created')
        ->and(SeenEvents::latestMessage('inquiry.created')?->event)->toBe('inquiry.created')
        // LIKE would read order_* as "order, any character, anything"; the templates would not.
        ->and(SeenEvents::latestMessage('order_*'))->toBeNull()
        ->and(SeenEvents::latestMessage('nothing.*'))->toBeNull();
});
