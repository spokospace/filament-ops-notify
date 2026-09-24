<?php

use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Filament\Schemas\Components\Section;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spokospace\OpsNotify\ChannelManager;
use Spokospace\OpsNotify\Channels\Telegram\TelegramChannel;
use Spokospace\OpsNotify\Filament\Pages\OpsNotifyPage;
use Spokospace\OpsNotify\Filament\SettingsForm;
use Spokospace\OpsNotify\Settings\SettingsStore;

function telegramChannel(): TelegramChannel
{
    return app(ChannelManager::class)->channel('telegram');
}

it('creates a forum topic and returns its id', function () {
    Http::fake(['*/createForumTopic' => Http::response(['ok' => true, 'result' => ['message_thread_id' => 12, 'name' => 'Komentarze']])]);

    expect(telegramChannel()->createForumTopic('Komentarze', 7322096))->toBe('12');

    Http::assertSent(fn (Request $request) => $request['chat_id'] === '-1001'
        && $request['name'] === 'Komentarze'
        && $request['icon_color'] === 7322096);
});

it('drops an icon colour telegram would reject', function () {
    Http::fake(['*/createForumTopic' => Http::response(['ok' => true, 'result' => ['message_thread_id' => 5]])]);

    telegramChannel()->createForumTopic('X', 123);

    Http::assertSent(fn (Request $request) => ! isset($request['icon_color']));
});

it('lists the topics of the configured chat that the bot has seen', function () {
    Http::fake(['*/getUpdates' => Http::response(['ok' => true, 'result' => [
        ['message' => ['chat' => ['id' => -1001, 'type' => 'supergroup'], 'message_thread_id' => 3, 'is_topic_message' => true,
            'reply_to_message' => ['forum_topic_created' => ['name' => 'Zapytania']]]],
        ['message' => ['chat' => ['id' => -1001, 'type' => 'supergroup'], 'message_thread_id' => 3, 'is_topic_message' => true]],
        ['message' => ['chat' => ['id' => -999, 'type' => 'supergroup'], 'message_thread_id' => 7, 'is_topic_message' => true]],
    ]])]);

    expect(telegramChannel()->seenTopics())->toBe(['3' => 'Zapytania']);
});

it('labels topics by name for the log table', function () {
    config(['ops-notify.channels.telegram.topics' => [['id' => '3', 'name' => 'Zapytania']]]);
    app(ChannelManager::class)->forgetChannels();

    expect(telegramChannel()->topicLabel('3'))->toBe('Zapytania #3')
        ->and(telegramChannel()->topicLabel('8'))->toBe('#8');
});

it('saves the topics list and normalises it', function () {
    $form = app(SettingsForm::class);

    app(SettingsStore::class)->save($form->toSettings([
        'telegram_topics' => [
            'a' => ['name' => ' Zapytania ', 'id' => 3],
            'b' => ['name' => 'Duplicate', 'id' => '3'],
            'c' => ['name' => 'No id', 'id' => null],
            'd' => ['name' => 'Buildy', 'id' => '4'],
        ],
    ]));

    expect(config('ops-notify.channels.telegram.topics'))->toBe([
        ['id' => '3', 'name' => 'Zapytania'],
        ['id' => '4', 'name' => 'Buildy'],
    ]);
});

describe('settings slide-over', function () {
    beforeEach(function () {
        Filament::setCurrentPanel('admin');
        $this->actingAs($this->admin());
        Http::fake([
            '*/getMe' => Http::response(['ok' => true, 'result' => ['username' => 'bot']]),
            '*/createForumTopic' => Http::response(['ok' => true, 'result' => ['message_thread_id' => 9]]),
            '*/getUpdates' => Http::response(['ok' => true, 'result' => [
                ['message' => ['chat' => ['id' => -1001], 'message_thread_id' => 3, 'is_topic_message' => true,
                    'reply_to_message' => ['forum_topic_created' => ['name' => 'Zapytania']]]],
            ]]),
        ]);
    });

    it('creates a topic in telegram and adds it to the list', function () {
        Livewire::test(OpsNotifyPage::class)
            ->mountAction('settings')
            ->callAction(TestAction::make('createTopic')->schemaComponent('topics', 'mountedActionSchema0'), data: ['name' => 'Komentarze'])
            ->assertNotified('Topic "Komentarze" created (#9)')
            ->callMountedAction()
            ->assertHasNoActionErrors();

        expect(config('ops-notify.channels.telegram.topics'))->toBe([['id' => '9', 'name' => 'Komentarze']]);
    });

    it('collapses saved lists and summarises them in the section header', function () {
        app(SettingsStore::class)->save([
            'telegram_topics' => [['id' => '3', 'name' => 'Zapytania'], ['id' => '2', 'name' => 'Błędy']],
            'events' => ['inquiry.*' => ['topic' => '3'], 'build.*' => ['enabled' => false]],
        ]);

        $section = fn (bool $collapsed, ?string $summary): Closure => fn (Section $section): bool => $section->isCollapsed() === $collapsed && $section->getDescription() === $summary;

        // Lists with items start collapsed and summarised; the empty forwarding list stays open.
        Livewire::test(OpsNotifyPage::class)
            ->mountAction('settings')
            ->assertSchemaComponentExists('topics', checkComponentUsing: $section(true, 'Zapytania #3 · Błędy #2'))
            ->assertSchemaComponentExists('routing', checkComponentUsing: $section(true, 'inquiry.* → Zapytania · build.* (Disabled)'))
            ->assertSchemaComponentExists('forwarding', checkComponentUsing: $section(false, null));
    });

    it('collapses each saved row to a one-line label', function () {
        app(SettingsStore::class)->save([
            'telegram_topics' => [['id' => '3', 'name' => 'Zapytania']],
            'events' => ['inquiry.*' => ['topic' => '3'], 'build.*' => ['enabled' => false]],
        ]);

        $rows = fn (array $expected): Closure => function (Repeater $repeater) use ($expected): bool {
            $actual = [];
            foreach ($repeater->getItems() as $key => $item) {
                $actual[] = [(string) $repeater->getItemLabel($key), $repeater->isCollapsed($item)];
            }

            return $actual === $expected;
        };

        Livewire::test(OpsNotifyPage::class)
            ->mountAction('settings')
            ->assertSchemaComponentExists('topics.telegram_topics', checkComponentUsing: $rows([['Zapytania #3', true]]))
            ->assertSchemaComponentExists('routing.events', checkComponentUsing: $rows([
                ['inquiry.* → Zapytania', true],
                ['build.* (Disabled)', true],
            ]));
    });

    it('keeps a new, empty row open for editing', function () {
        Livewire::test(OpsNotifyPage::class)
            ->mountAction('settings')
            ->fillForm(['events' => [['pattern' => null, 'topic' => null, 'enabled' => true]]], 'mountedActionSchema0')
            ->assertSchemaComponentExists('routing.events', checkComponentUsing: function (Repeater $repeater): bool {
                $item = collect($repeater->getItems())->first();

                return $item !== null && ! $repeater->isCollapsed($item);
            });
    });

    it('imports topics the bot has seen', function () {
        Livewire::test(OpsNotifyPage::class)
            ->mountAction('settings')
            ->callAction(TestAction::make('importTopics')->schemaComponent('topics', 'mountedActionSchema0'))
            ->assertNotified('Imported 1 topic')
            ->callMountedAction();

        expect(config('ops-notify.channels.telegram.topics'))->toBe([['id' => '3', 'name' => 'Zapytania']]);
    });
});
