<?php

namespace Spokospace\OpsNotify\Filament;

use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;
use Spokospace\OpsNotify\Channels\Telegram\TelegramChannel;
use Spokospace\OpsNotify\Exceptions\ChannelException;
use Spokospace\OpsNotify\OpsNotifier;
use Spokospace\OpsNotify\Settings\SettingsStore;
use Spokospace\OpsNotify\Support\Locales;
use Spokospace\OpsNotify\Support\PatternMap;
use Spokospace\OpsNotify\Support\SeenEvents;
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
        $seenEvents = SeenEvents::counts();

        // One line per list item: the section header lists them, and each collapsed row shows its own.
        $describeTopic = fn (array $row): string => trim(($row['name'] ?? '').' #'.($row['id'] ?? ''));
        $describeRule = fn (array $row, array $topics): string => ($row['pattern'] ?? '')
            .(filled($row['topic'] ?? null) ? ' → '.($topics[$row['topic']] ?? '#'.$row['topic']) : '')
            .(($row['enabled'] ?? true) ? '' : ' ('.Trans::get('page.disabled').')');
        $describeForward = fn (array $row): string => ($row['title'] ?? '')
            .(($row['forward'] ?? true) ? ' → '.($row['event'] ?? '') : ' ('.Trans::get('page.disabled').')');

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
                $describeTopic,
            )
                ->headerActions($this->store->isLocked('telegram_topics') ? [] : [
                    $this->createTopicAction(),
                    $this->importTopicsAction(),
                ])
                ->schema([
                    $this->locked(
                        $this->compact(Repeater::make('telegram_topics'), 'id', $describeTopic)
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
                $describeRule,
            )
                ->schema([
                    $this->locked(
                        $this->compact(Repeater::make('events'), 'pattern', $describeRule)
                            ->hiddenLabel()
                            ->schema([
                                TextInput::make('pattern')
                                    ->label(Trans::get('settings.pattern'))
                                    ->required()
                                    ->placeholder('inquiry.*')
                                    ->datalist(SeenEvents::patterns($seenEvents))
                                    // Refreshes the "seen recently" line below, which marks unmatched events.
                                    ->live(onBlur: true),
                                // Live for the same line: it marks rules without a topic and disabled rules.
                                $this->topicSelect('topic', '../../telegram_topics')->label(Trans::get('settings.topic'))->live(),
                                Toggle::make('enabled')->label(Trans::get('page.enabled'))->default(true)->inline(false)->live(),
                            ])
                            ->columns(3)
                            ->defaultItems(0)
                            ->addActionLabel(Trans::get('settings.add_rule')),
                        Trans::get('settings.routing_description'),
                    ),
                    Text::make(fn (Get $get): string => $this->describeSeenEvents($seenEvents, $get('events')))
                        ->key('seen_events')
                        ->visible($seenEvents !== []),
                ]),

            $this->summarized(
                Section::make(Trans::get('settings.forwarding'))->key('forwarding'),
                $saved,
                'forward_map',
                $describeForward,
            )
                ->schema([
                    $this->locked(Toggle::make('forward_enabled')->label(Trans::get('settings.forward_enabled'))),
                    $this->locked(
                        $this->compact(Repeater::make('forward_map'), 'title', $describeForward)
                            ->hiddenLabel()
                            ->schema([
                                TextInput::make('title')
                                    ->label(Trans::get('table.title'))
                                    ->required()
                                    ->placeholder(Trans::get('settings.forward_title_placeholder'))
                                    ->datalist(SeenEvents::titles()),
                                TextInput::make('event')
                                    ->label(Trans::get('table.event'))
                                    ->placeholder('inquiry.created')
                                    ->datalist(array_keys($seenEvents))
                                    ->required(fn (Get $get): bool => (bool) $get('forward')),
                                Toggle::make('forward')->label(Trans::get('settings.forward'))->default(true)->inline(false),
                            ])
                            ->columns(3)
                            ->defaultItems(0)
                            ->addActionLabel(Trans::get('settings.add_rule')),
                        Trans::get('settings.forwarding_description'),
                    ),
                ]),

            Section::make(Trans::get('settings.laravel_forwarding'))
                ->key('laravel_forwarding')
                ->description(Trans::get('settings.laravel_forwarding_description'))
                ->collapsible()
                ->collapsed(! ($saved['forward_other_enabled'] ?? false))
                ->schema([
                    $this->locked(Toggle::make('forward_other_enabled')->label(Trans::get('settings.laravel_forward_enabled'))->live()),
                    $this->locked(
                        CheckboxList::make('forward_other_channels')
                            ->label(Trans::get('settings.laravel_forward_channels'))
                            // Laravel's channel names; the app's own custom channels can go in the config.
                            ->options(['mail' => 'mail', 'database' => 'database', 'broadcast' => 'broadcast', 'vonage' => 'vonage (SMS)', 'slack' => 'slack'])
                            ->columns(3)
                            ->visible(fn (Get $get): bool => (bool) $get('forward_other_enabled')),
                        Trans::get('settings.laravel_forward_channels_help'),
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
            'events' => collect(self::rows($values['events'] ?? null))
                ->map(fn (array $rule, string $pattern): array => [
                    'pattern' => $pattern,
                    'topic' => $rule['topic'] ?? null,
                    'enabled' => $rule['enabled'] ?? true,
                ])->values()->all(),
            'forward_map' => collect(self::rows($values['forward_map'] ?? null))
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
            $data['telegram_topics'] = collect(self::rows($data['telegram_topics'] ?? null))
                ->filter(fn (array $row): bool => filled($row['id'] ?? null))
                ->map(fn (array $row): array => ['id' => trim((string) $row['id']), 'name' => trim((string) ($row['name'] ?? ''))])
                ->unique('id')
                ->values()
                ->all();
        }

        if (array_key_exists('events', $data)) {
            $data['events'] = collect(self::rows($data['events'] ?? null))
                ->mapWithKeys(fn (array $row): array => [trim($row['pattern']) => array_filter([
                    'topic' => filled($row['topic'] ?? null) ? (string) $row['topic'] : null,
                    'enabled' => (bool) ($row['enabled'] ?? true),
                ], fn (mixed $value): bool => $value !== null)])
                ->all();
        }

        if (array_key_exists('forward_map', $data)) {
            $data['forward_map'] = collect(self::rows($data['forward_map'] ?? null))
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
     * "Seen in the last 30 days: inquiry.created (12) · order_placed (3, no topic in rules)".
     * Marks the events that the rules in the form give no topic (no match, or a rule without a
     * topic; the message's own topic or the default one applies) or do not send at all (a
     * disabled rule).
     *
     * @param  array<string, int>  $counts  SeenEvents::counts()
     */
    private function describeSeenEvents(array $counts, mixed $rules): string
    {
        // Pattern => rule, the shape PatternMap and OpsNotifier::destinationFor() work with.
        $map = collect(self::rows($rules))
            ->filter(fn (mixed $row): bool => is_array($row) && filled($row['pattern'] ?? null))
            ->mapWithKeys(fn (array $row): array => [trim((string) $row['pattern']) => $row])
            ->all();

        $events = collect($counts)->map(function (int $count, string|int $event) use ($map): string {
            $key = PatternMap::firstKey($map, (string) $event);
            $rule = $key === null ? [] : $map[$key];

            $note = match (true) {
                ! ($rule['enabled'] ?? true) => Trans::get('page.disabled'),
                blank($rule['topic'] ?? null) => Trans::get('settings.seen_default'),
                default => null,
            };

            return "{$event} ({$count}".($note === null ? '' : ", {$note}").')';
        });

        return Trans::get('settings.seen_events', ['days' => SeenEvents::days(), 'events' => $events->implode(' · ')]);
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
                    Notification::make()->danger()->title(Trans::get('settings.topic_not_created'))->body($this->telegram()->explain($e, 'manage_topics'))->send();

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
                $topics = self::topicNames($get);

                return $rows === [] ? null : Str::limit(
                    collect($rows)->map(fn (array $row): string => $describe($row, $topics))->filter()->implode(' · '),
                    240,
                );
            });
    }

    /**
     * Saved rows collapse to one line with their data ("inquiry.* → Inquiries #3"); a row that is
     * new, or not filled in yet, stays open for editing. Click a row to expand it.
     *
     * @param  string  $required  The field that marks a row as filled in.
     * @param  \Closure(array<string, mixed>, array<string, string>): string  $describe  Row and topic names by id.
     */
    private function compact(Repeater $repeater, string $required, \Closure $describe): Repeater
    {
        return $repeater
            ->collapsible()
            ->collapsed(fn (?Schema $item): bool => filled($item?->getStateSnapshot()[$required] ?? null))
            ->itemLabel(fn (array $state, Get $get): ?string => filled($state[$required] ?? null)
                ? $describe($state, self::topicNames($get))
                : null);
    }

    /**
     * Topic names by id from the form's Topics list, for labels.
     *
     * @return array<string, string>
     */
    private static function topicNames(Get $get): array
    {
        return collect(self::rows($get('telegram_topics')))
            ->filter(fn (mixed $row): bool => is_array($row) && filled($row['id'] ?? null))
            ->mapWithKeys(fn (array $row): array => [(string) $row['id'] => (string) ($row['name'] ?? '')])
            ->all();
    }

    /**
     * A stored or submitted list as rows. Settings are decoded JSON and form state is loose,
     * so anything that is not an array (a hand-edited or corrupted value) becomes no rows.
     *
     * @return array<array-key, mixed>
     */
    private static function rows(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /** Disables a field whose value comes from .env/config, and says so. */
    private function locked(Field $field, ?string $hint = null): Field
    {
        $isLocked = $this->store->isLocked($field->getName());

        return $field
            ->disabled($isLocked)
            ->helperText($isLocked ? Trans::get('settings.locked') : $hint);
    }
}
