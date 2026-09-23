<?php

namespace Spokospace\OpsNotify\Filament;

use Filament\Actions\Action;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;
use Spokospace\OpsNotify\Channels\Telegram\TelegramChannel;
use Spokospace\OpsNotify\Exceptions\ChannelException;
use Spokospace\OpsNotify\OpsNotifier;
use Spokospace\OpsNotify\Settings\SettingsStore;

/**
 * The settings slide-over on the Ops notifications page. Maps between SettingsStore values
 * (config-shaped: pattern-keyed arrays) and form state (repeater rows).
 */
class SettingsForm
{
    public function __construct(private readonly SettingsStore $store) {}

    /** @return array<int, mixed> */
    public function components(): array
    {
        return [
            Section::make('Telegram')
                ->columns(2)
                ->schema([
                    $this->locked(
                        TextInput::make('service')
                            ->label('Service name')
                            ->placeholder((string) config('app.name'))
                            ->maxLength(60)
                            ->columnSpanFull(),
                        'Prefixes every message, e.g. [panel.polo.blue]. Empty = the app name.',
                    ),
                    $this->locked(
                        TextInput::make('telegram_bot_token')
                            ->label('Bot token')
                            ->password()
                            ->autocomplete('off')
                            ->placeholder($this->store->hasStored('telegram_bot_token') ? 'Saved; leave empty to keep it' : '123456789:AA...')
                            ->columnSpanFull(),
                        in_array('telegram_bot_token', $this->store->unreadableSecrets(), true)
                            ? 'The saved token cannot be decrypted (APP_KEY changed). Enter it again.'
                            : null,
                    ),
                    $this->locked(
                        TextInput::make('telegram_chat_id')->label('Chat id')->placeholder('-1001234567890'),
                        'php artisan ops-notify:telegram-chats lists it.',
                    ),
                    $this->locked(
                        $this->topicSelect('telegram_topic', 'telegram_topics')->label('Default topic'),
                        'For events without a topic of their own. Empty = General.',
                    ),
                    $this->locked(Toggle::make('enabled')->label('Notifications enabled')),
                ]),

            Section::make('Topics')
                ->key('topics')
                ->description('Forum topics of the chat. "Create topic" creates it in Telegram (the bot needs the "Manage topics" admin right; save the token and chat id first). Removing a topic here does not delete it in Telegram.')
                ->collapsible()
                ->headerActions($this->store->isLocked('telegram_topics') ? [] : [
                    $this->createTopicAction(),
                    $this->importTopicsAction(),
                ])
                ->schema([
                    $this->locked(
                        Repeater::make('telegram_topics')
                            ->hiddenLabel()
                            ->schema([
                                TextInput::make('name')->required()->maxLength(128),
                                TextInput::make('id')->label('Topic id')->integer()->required(),
                            ])
                            ->columns(2)
                            ->defaultItems(0)
                            ->addActionLabel('Add existing topic'),
                    ),
                ]),

            Section::make('Event routing')
                ->description('First matching pattern wins, e.g. inquiry.* or build.failed.')
                ->collapsible()
                ->schema([
                    $this->locked(
                        Repeater::make('events')
                            ->hiddenLabel()
                            ->schema([
                                TextInput::make('pattern')->required()->placeholder('inquiry.*'),
                                $this->topicSelect('topic', '../../telegram_topics')->label('Topic'),
                                Toggle::make('enabled')->default(true)->inline(false),
                            ])
                            ->columns(3)
                            ->defaultItems(0)
                            ->addActionLabel('Add rule'),
                    ),
                ]),

            Section::make('Filament notifications')
                ->description('Bell notifications forwarded to the ops channel. Rules match the title; first match wins.')
                ->collapsible()
                ->schema([
                    $this->locked(Toggle::make('forward_enabled')->label('Forward bell notifications')),
                    $this->locked(
                        Repeater::make('forward_map')
                            ->hiddenLabel()
                            ->schema([
                                TextInput::make('title')->required()->placeholder('Nowe zapytanie*'),
                                TextInput::make('event')
                                    ->placeholder('inquiry.created')
                                    ->required(fn (Get $get): bool => (bool) $get('forward')),
                                Toggle::make('forward')->default(true)->inline(false),
                            ])
                            ->columns(3)
                            ->defaultItems(0)
                            ->addActionLabel('Add rule'),
                    ),
                ]),
        ];
    }

    /** @return array<string, mixed> */
    public function fill(): array
    {
        $values = $this->store->formValues();

        return [
            ...$values,
            'events' => collect($values['events'] ?? [])
                ->map(fn (array $rule, string $pattern): array => [
                    'pattern' => $pattern,
                    'topic' => $rule['topic'] ?? null,
                    'enabled' => $rule['enabled'] ?? true,
                ])->values()->all(),
            'forward_map' => collect($values['forward_map'] ?? [])
                ->map(fn (string|false $event, string $title): array => [
                    'title' => $title,
                    'event' => $event ?: null,
                    'forward' => $event !== false,
                ])->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data  Form state. Disabled (locked) fields are absent.
     * @return array<string, mixed> Values for SettingsStore::save().
     */
    public function toSettings(array $data): array
    {
        if (array_key_exists('telegram_topics', $data)) {
            $data['telegram_topics'] = collect($data['telegram_topics'] ?? [])
                ->filter(fn (array $row): bool => filled($row['id'] ?? null))
                ->map(fn (array $row): array => ['id' => trim((string) $row['id']), 'name' => trim((string) ($row['name'] ?? ''))])
                ->unique('id')
                ->values()
                ->all();
        }

        if (array_key_exists('events', $data)) {
            $data['events'] = collect($data['events'] ?? [])
                ->mapWithKeys(fn (array $row): array => [trim($row['pattern']) => array_filter([
                    'topic' => filled($row['topic'] ?? null) ? (string) $row['topic'] : null,
                    'enabled' => (bool) ($row['enabled'] ?? true),
                ], fn (mixed $value): bool => $value !== null)])
                ->all();
        }

        if (array_key_exists('forward_map', $data)) {
            $data['forward_map'] = collect($data['forward_map'] ?? [])
                ->mapWithKeys(fn (array $row): array => [
                    trim($row['title']) => ($row['forward'] ?? true) ? trim((string) $row['event']) : false,
                ])
                ->all();
        }

        foreach (['telegram_chat_id', 'telegram_topic'] as $key) {
            if (isset($data[$key])) {
                $data[$key] = trim((string) $data[$key]);
            }
        }

        return $data;
    }

    /**
     * Topic picker fed by the Topics list in the same form. A value that is not in the list
     * (e.g. set before the list existed) stays selectable as "#id".
     */
    private function topicSelect(string $name, string $topicsPath): Select
    {
        return Select::make($name)
            ->placeholder('General')
            ->options(function (Get $get, mixed $state) use ($topicsPath): array {
                $options = collect((array) $get($topicsPath))
                    ->filter(fn (mixed $topic): bool => is_array($topic) && filled($topic['id'] ?? null))
                    ->mapWithKeys(fn (array $topic): array => [
                        (string) $topic['id'] => TelegramChannel::formatTopic((string) $topic['id'], $topic['name'] ?? null),
                    ])
                    ->all();

                if (filled($state) && ! isset($options[(string) $state])) {
                    $options[(string) $state] = TelegramChannel::formatTopic((string) $state, null);
                }

                return $options;
            });
    }

    private function createTopicAction(): Action
    {
        return Action::make('createTopic')
            ->label('Create topic')
            ->icon(Heroicon::OutlinedPlus)
            ->schema([
                TextInput::make('name')->required()->maxLength(128)->placeholder('Komentarze'),
                Select::make('color')->label('Icon colour')->options(TelegramChannel::TOPIC_COLORS),
            ])
            ->modalSubmitActionLabel('Create in Telegram')
            ->action(function (array $data, Get $get, Set $set): void {
                try {
                    $id = $this->telegram()->createForumTopic($data['name'], filled($data['color'] ?? null) ? (int) $data['color'] : null);
                } catch (ChannelException $e) {
                    Notification::make()->danger()->title('Topic not created')->body($e->getMessage())->send();

                    return;
                }

                $this->addTopics($get, $set, [$id => $data['name']]);

                Notification::make()->success()->title("Topic \"{$data['name']}\" created (#{$id})")->body('Save the settings to keep it in the list.')->send();
            });
    }

    private function importTopicsAction(): Action
    {
        return Action::make('importTopics')
            ->label('Import from Telegram')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->action(function (Get $get, Set $set): void {
                try {
                    $added = $this->addTopics($get, $set, $this->telegram()->seenTopics());
                } catch (ChannelException $e) {
                    Notification::make()->danger()->title('Import failed')->body($e->getMessage())->send();

                    return;
                }

                $added > 0
                    ? Notification::make()->success()->title("Imported {$added} topic(s)")->body('Save the settings to keep them.')->send()
                    : Notification::make()->warning()->title('No new topics seen')->body('Send /ping@your_bot in each topic, then import again.')->send();
            });
    }

    /**
     * Appends topics to the form's Topics list, skipping ids already there (but filling in a
     * missing name).
     *
     * @param  array<string, string>  $topics  id => name
     * @return int Number of topics added.
     */
    private function addTopics(Get $get, Set $set, array $topics): int
    {
        $rows = (array) ($get('telegram_topics') ?? []);
        $keyById = collect($rows)->mapWithKeys(fn (array $row, string|int $key): array => [(string) ($row['id'] ?? '') => $key])->all();
        $added = 0;

        foreach ($topics as $id => $name) {
            if (isset($keyById[(string) $id])) {
                $key = $keyById[(string) $id];
                $rows[$key]['name'] = filled($rows[$key]['name'] ?? null) ? $rows[$key]['name'] : $name;

                continue;
            }

            $rows[(string) Str::uuid()] = ['name' => $name, 'id' => (string) $id];
            $added++;
        }

        $set('telegram_topics', $rows);

        return $added;
    }

    private function telegram(): TelegramChannel
    {
        return app(OpsNotifier::class)->channels()->telegram('telegram');
    }

    /** Disables a field whose value comes from .env/config, and says so. */
    private function locked(Field $field, ?string $hint = null): Field
    {
        $isLocked = $this->store->isLocked($field->getName());

        return $field
            ->disabled($isLocked)
            ->helperText($isLocked ? 'Set in .env or config/ops-notify.php, change it there.' : $hint);
    }
}
