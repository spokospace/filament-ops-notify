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
use Spokospace\OpsNotify\Support\Locales;
use Spokospace\OpsNotify\Support\Trans;

/**
 * The settings slide-over on the plugin's page. Maps between SettingsStore values
 * (config-shaped: pattern-keyed arrays) and form state (repeater rows).
 */
class SettingsForm
{
    public function __construct(private readonly SettingsStore $store) {}

    /** @return array<int, mixed> */
    public function components(): array
    {
        $saved = $this->store->formValues();

        return [
            Section::make(Trans::get('settings.telegram'))
                ->columns(2)
                ->schema([
                    $this->locked(
                        TextInput::make('service')
                            ->label(Trans::get('settings.service'))
                            ->placeholder((string) config('app.name'))
                            ->maxLength(60)
                            ->columnSpanFull(),
                        Trans::get('settings.service_help'),
                    ),
                    $this->locked(
                        Select::make('locale')
                            ->label(Trans::get('settings.locale'))
                            ->options(Locales::options())
                            ->placeholder(Trans::get('settings.locale_default', ['locale' => config('app.locale')]))
                            ->columnSpanFull(),
                        Trans::get('settings.locale_help'),
                    ),
                    $this->locked(
                        TextInput::make('telegram_bot_token')
                            ->label(Trans::get('settings.bot_token'))
                            ->password()
                            ->autocomplete('off')
                            ->placeholder($this->store->hasStored('telegram_bot_token') ? Trans::get('settings.bot_token_saved') : '123456789:AA...')
                            ->columnSpanFull(),
                        in_array('telegram_bot_token', $this->store->unreadableSecrets(), true)
                            ? Trans::get('settings.bot_token_unreadable')
                            : null,
                    ),
                    $this->locked(
                        TextInput::make('telegram_chat_id')->label(Trans::get('settings.chat_id'))->placeholder('-1001234567890'),
                        Trans::get('settings.chat_id_help'),
                    ),
                    $this->locked(
                        $this->topicSelect('telegram_topic', 'telegram_topics')->label(Trans::get('settings.default_topic')),
                        Trans::get('settings.default_topic_help'),
                    ),
                    $this->locked(Toggle::make('enabled')->label(Trans::get('settings.enabled'))),
                ]),

            $this->summarized(
                Section::make(Trans::get('settings.topics'))->key('topics'),
                $saved,
                'telegram_topics',
                fn (array $row): string => trim(($row['name'] ?? '').' #'.($row['id'] ?? '')),
            )
                ->headerActions($this->store->isLocked('telegram_topics') ? [] : [
                    $this->createTopicAction(),
                    $this->importTopicsAction(),
                ])
                ->schema([
                    $this->locked(
                        Repeater::make('telegram_topics')
                            ->hiddenLabel()
                            ->schema([
                                TextInput::make('name')->label(Trans::get('settings.topic_name'))->required()->maxLength(128),
                                TextInput::make('id')->label(Trans::get('settings.topic_id'))->integer()->required(),
                            ])
                            ->columns(2)
                            ->defaultItems(0)
                            ->addActionLabel(Trans::get('settings.add_existing_topic')),
                        Trans::get('settings.topics_description'),
                    ),
                ]),

            $this->summarized(
                Section::make(Trans::get('settings.routing'))->key('routing'),
                $saved,
                'events',
                fn (array $row, array $topics): string => ($row['pattern'] ?? '')
                    .(filled($row['topic'] ?? null) ? ' → '.($topics[$row['topic']] ?? '#'.$row['topic']) : '')
                    .(($row['enabled'] ?? true) ? '' : ' ('.Trans::get('page.disabled').')'),
            )
                ->schema([
                    $this->locked(
                        Repeater::make('events')
                            ->hiddenLabel()
                            ->schema([
                                TextInput::make('pattern')->label(Trans::get('settings.pattern'))->required()->placeholder('inquiry.*'),
                                $this->topicSelect('topic', '../../telegram_topics')->label(Trans::get('settings.topic')),
                                Toggle::make('enabled')->label(Trans::get('page.enabled'))->default(true)->inline(false),
                            ])
                            ->columns(3)
                            ->defaultItems(0)
                            ->addActionLabel(Trans::get('settings.add_rule')),
                        Trans::get('settings.routing_description'),
                    ),
                ]),

            $this->summarized(
                Section::make(Trans::get('settings.forwarding'))->key('forwarding'),
                $saved,
                'forward_map',
                fn (array $row): string => ($row['title'] ?? '')
                    .(($row['forward'] ?? true) ? ' → '.($row['event'] ?? '') : ' ('.Trans::get('page.disabled').')'),
            )
                ->schema([
                    $this->locked(Toggle::make('forward_enabled')->label(Trans::get('settings.forward_enabled'))),
                    $this->locked(
                        Repeater::make('forward_map')
                            ->hiddenLabel()
                            ->schema([
                                TextInput::make('title')->label(Trans::get('table.title'))->required()->placeholder(Trans::get('settings.forward_title_placeholder')),
                                TextInput::make('event')
                                    ->label(Trans::get('table.event'))
                                    ->placeholder('inquiry.created')
                                    ->required(fn (Get $get): bool => (bool) $get('forward')),
                                Toggle::make('forward')->label(Trans::get('settings.forward'))->default(true)->inline(false),
                            ])
                            ->columns(3)
                            ->defaultItems(0)
                            ->addActionLabel(Trans::get('settings.add_rule')),
                        Trans::get('settings.forwarding_description'),
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
            ->placeholder(Trans::get('settings.general'))
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
            ->label(Trans::get('settings.create_topic'))
            ->icon(Heroicon::OutlinedPlus)
            ->schema([
                TextInput::make('name')->label(Trans::get('settings.topic_name'))->required()->maxLength(128)->placeholder(Trans::get('settings.topic_name_placeholder')),
                Select::make('color')->label(Trans::get('settings.icon_colour'))->options(TelegramChannel::topicColorOptions()),
            ])
            ->modalSubmitActionLabel(Trans::get('settings.create_in_telegram'))
            ->action(function (array $data, Get $get, Set $set): void {
                try {
                    $id = $this->telegram()->createForumTopic($data['name'], filled($data['color'] ?? null) ? (int) $data['color'] : null);
                } catch (ChannelException $e) {
                    Notification::make()->danger()->title(Trans::get('settings.topic_not_created'))->body($e->getMessage())->send();

                    return;
                }

                $this->addTopics($get, $set, [$id => $data['name']]);

                Notification::make()->success()
                    ->title(Trans::get('settings.topic_created', ['name' => $data['name'], 'id' => $id]))
                    ->body(Trans::get('settings.save_to_keep_it'))
                    ->send();
            });
    }

    private function importTopicsAction(): Action
    {
        return Action::make('importTopics')
            ->label(Trans::get('settings.import_topics'))
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->action(function (Get $get, Set $set): void {
                try {
                    $added = $this->addTopics($get, $set, $this->telegram()->seenTopics());
                } catch (ChannelException $e) {
                    Notification::make()->danger()->title(Trans::get('settings.import_failed'))->body($e->getMessage())->send();

                    return;
                }

                $added > 0
                    ? Notification::make()->success()->title(Trans::choice('settings.imported_topics', $added))->body(Trans::get('settings.save_to_keep_them'))->send()
                    : Notification::make()->warning()->title(Trans::get('settings.no_new_topics'))->body(Trans::get('settings.no_new_topics_help'))->send();
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
        return app(OpsNotifier::class)->telegram();
    }

    /** Disables a field whose value comes from .env/config, and says so. */
    /**
     * A section around one list: collapsed on load once the list has saved items, with the
     * items summarised in the header so they read without expanding. Empty, it stays open.
     *
     * @param  array<string, mixed>  $saved  SettingsStore::formValues()
     * @param  \Closure(array<string, mixed>, array<string, string>): string  $describe  Row and topic names by id.
     */
    private function summarized(Section $section, array $saved, string $list, \Closure $describe): Section
    {
        return $section
            ->collapsible()
            ->collapsed(filled($saved[$list] ?? null))
            ->description(function (Get $get) use ($list, $describe): ?string {
                $rows = array_filter((array) $get($list), 'is_array');
                $topics = collect((array) $get('telegram_topics'))->filter(fn (mixed $row): bool => is_array($row))->pluck('name', 'id')->all();

                return $rows === [] ? null : Str::limit(
                    collect($rows)->map(fn (array $row): string => $describe($row, $topics))->filter()->implode(' · '),
                    240,
                );
            });
    }

    private function locked(Field $field, ?string $hint = null): Field
    {
        $isLocked = $this->store->isLocked($field->getName());

        return $field
            ->disabled($isLocked)
            ->helperText($isLocked ? Trans::get('settings.locked') : $hint);
    }
}
