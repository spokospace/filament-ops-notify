<?php

use Filament\Actions\Action;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Field;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Text;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spokospace\OpsNotify\Enums\DeliveryStatus;
use Spokospace\OpsNotify\Filament\SettingsForm;
use Spokospace\OpsNotify\Models\OpsNotifyLog;
use Spokospace\OpsNotify\Settings\SettingsStore;
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

/** The seen-event tags under Routing, each as "label colour[ struck][: tooltip]". */
function seenTags(Actions $component): array
{
    return array_map(
        fn (Action $tag): string => $tag->getLabel().' '.$tag->getColor()
            .(isset($tag->getExtraAttributes()['style']) ? ' struck' : '')
            .(filled($tag->getTooltip()) ? ': '.$tag->getTooltip() : ''),
        array_values($component->getChildSchema()->getComponents()),
    );
}

/** @param  list<string>  $expected */
function hasSeenTags(array $expected): Closure
{
    return fn (Actions $component): bool => expect(seenTags($component))->toBe($expected) !== null;
}

function seenTag(string $event): TestAction
{
    return TestAction::make(SettingsForm::seenEventAction($event))->schemaComponent('routing.seen_event_tags', 'mountedActionSchema0');
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

it('says how many days the tags cover', function (int $days, string $locale, string $text) {
    config(['ops-notify.log.prune_after_days' => $days]);
    app()->setLocale($locale);
    seen('inquiry.created');

    settingsForm()->assertSchemaComponentExists('routing.seen_events', 'mountedActionSchema0', fn (Text $component): bool => str_starts_with($component->getContent(), $text));
})->with([
    [7, 'en', 'Events from the last 7 days, with their message counts.'],
    [1, 'en', 'Events from the last 1 day, with their message counts.'],
    [30, 'pl', 'Zdarzenia z ostatnich 30 dni z liczbą wiadomości.'],
]);

it('suggests nothing when the history table is missing', function () {
    Schema::drop('ops_notify_logs');

    expect(SeenEvents::counts())->toBe([]);
});

it('lists recent events in Settings as tags and marks the ones no rule matches', function () {
    config(['ops-notify.events' => ['inquiry.*' => ['topic' => '3'], 'build.*' => [], 'debug.*' => ['enabled' => false]]]);
    seen('inquiry.created', 2);
    seen('order_placed');
    seen('build.failed');
    seen('debug.dump');

    settingsForm()
        // Locked in the config: nothing to click, and no "click" in the lead text.
        ->assertSchemaComponentExists('routing.seen_events', 'mountedActionSchema0', fn (Text $text): bool => $text->getContent() === 'Events from the last 30 days, with their message counts.')
        ->assertSchemaComponentExists('routing.seen_event_tags', 'mountedActionSchema0', hasSeenTags([
            'inquiry.created (2) gray: → #3',
            'build.failed (1) warning: no topic in rules',
            'debug.dump (1) gray struck: Disabled',
            'order_placed (1) warning: no topic in rules',
        ]))
        ->assertActionDisabled(seenTag('order_placed'));
});

it('describes events the way routing treats config rules', function () {
    // Only false disables a rule; a config rule may also pick the channel.
    config(['ops-notify.events' => ['debug.*' => ['enabled' => 0, 'topic' => '5'], 'alerts.*' => ['channel' => 'second']]]);
    seen('debug.dump', 2);
    seen('alerts.disk');

    settingsForm()
        ->assertSchemaComponentExists('routing.seen_event_tags', 'mountedActionSchema0', hasSeenTags([
            'debug.dump (2) gray: → #5',
            'alerts.disk (1) warning: → second, no topic in rules',
        ]))
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

    settingsForm()->assertSchemaComponentExists('routing.seen_event_tags', 'mountedActionSchema0', fn (Actions $component): bool => array_slice(seenTags($component), -2) === [
        'busy.e30 (2) gray: → #3',
        'backup.failed (1) warning: no topic in rules',
    ] && count(seenTags($component)) === SeenEvents::SUGGESTED + 1);
});

it('starts a rule from the nearest prefix of an event', function () {
    expect(SeenEvents::rulePattern('inquiry.created'))->toBe('inquiry.*')
        ->and(SeenEvents::rulePattern('site.build.failed'))->toBe('site.build.*')
        ->and(SeenEvents::rulePattern('order_placed'))->toBe('order_placed')
        ->and(SeenEvents::rulePattern($long = str_repeat('a.', 60)))->toBe($long.'*');
});

it('adds a routing rule for an event when its tag is clicked, and keeps the row open', function () {
    seen('inquiry.created', 2);

    $form = settingsForm()
        ->assertSchemaComponentExists('routing.seen_events', 'mountedActionSchema0', fn (Text $text): bool => str_ends_with($text->getContent(), 'Click an event to add a routing rule for it.'))
        ->callAction(seenTag('inquiry.created'))
        ->assertNotified('Rule inquiry.* added: pick its topic and save the settings.')
        ->assertSchemaStateSet(function (array $state): void {
            expect(array_values($state['events']))->toHaveCount(1)
                ->and(array_values($state['events'])[0])->toMatchArray(['pattern' => 'inquiry.*', 'topic' => null, 'enabled' => true]);
        }, 'mountedActionSchema0');

    $key = array_key_first($form->instance()->mountedActionSchema0->getRawState()['events']);

    $form->assertSchemaComponentExists('routing.events', 'mountedActionSchema0', fn (Field $repeater): bool => ! $repeater->isCollapsed($repeater->getChildSchema($key)))
        ->assertSchemaComponentExists('routing.seen_event_tags', 'mountedActionSchema0', hasSeenTags(['inquiry.created (2) warning: no topic in rules']));

    // Saved, it is an ordinary rule.
    $path = $form->instance()->mountedActionSchema0->getStatePath();
    $form->set("{$path}.telegram_bot_token", '999:PANEL')
        ->set("{$path}.telegram_chat_id", '-1')
        ->set("{$path}.events.{$key}.topic", '3')
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect(app(SettingsStore::class)->formValues()['events'])->toBe(['inquiry.*' => ['topic' => '3', 'enabled' => true]]);
});

it('points to the rule that already matches an event instead of adding one below it', function () {
    seen('inquiry.created');

    $form = settingsForm();
    $path = $form->instance()->mountedActionSchema0->getStatePath().'.events';

    $form->set($path, ['row' => ['pattern' => '*', 'topic' => null, 'enabled' => true]])
        ->callAction(seenTag('inquiry.created'))
        ->assertNotified('* already matches inquiry.created: set the topic in that rule.')
        ->assertSchemaStateSet(function (array $state): void {
            expect(array_column($state['events'], 'pattern'))->toBe(['*']);
        }, 'mountedActionSchema0');
});

it('updates the tags as the rules are edited, before saving', function () {
    config(['ops-notify.events' => []]);
    seen('inquiry.created', 2);

    $form = settingsForm();
    $path = $form->instance()->mountedActionSchema0->getStatePath();

    $form->set("{$path}.telegram_topics", ['t' => ['id' => '3', 'name' => 'Inquiries']])
        ->set("{$path}.events", ['row' => ['pattern' => 'inquiry.*', 'topic' => null, 'enabled' => true]])
        ->assertSchemaComponentExists('routing.seen_event_tags', 'mountedActionSchema0', hasSeenTags(['inquiry.created (2) warning: no topic in rules']))
        ->set("{$path}.events.row.topic", '3')
        ->assertSchemaComponentExists('routing.seen_event_tags', 'mountedActionSchema0', hasSeenTags(['inquiry.created (2) gray: → Inquiries #3']))
        ->set("{$path}.events.row.enabled", false)
        ->assertSchemaComponentExists('routing.seen_event_tags', 'mountedActionSchema0', hasSeenTags(['inquiry.created (2) gray struck: Disabled']));

    $path .= '.events';

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
