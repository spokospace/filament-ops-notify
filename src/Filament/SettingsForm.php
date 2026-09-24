<?php

namespace Spokospace\OpsNotify\Filament;

use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Spokospace\OpsNotify\Channels\Telegram\TelegramChannel;
use Spokospace\OpsNotify\Channels\Telegram\TelegramFormatter;
use Spokospace\OpsNotify\Exceptions\ChannelException;
use Spokospace\OpsNotify\OpsNotifier;
use Spokospace\OpsNotify\Settings\SettingsStore;
use Spokospace\OpsNotify\Support\Button;
use Spokospace\OpsNotify\Support\Locales;
use Spokospace\OpsNotify\Support\MessageTemplate;
use Spokospace\OpsNotify\Support\PatternMap;
use Spokospace\OpsNotify\Support\RoutingRules;
use Spokospace\OpsNotify\Support\SeenEvents;
use Spokospace\OpsNotify\Support\Trans;

/**
 * The settings slide-over on the plugin's page. Maps between SettingsStore values
 * (config-shaped: pattern-keyed arrays) and form state (repeater rows).
 */
class SettingsForm
{
    /** The Routing section, relative to a field in a rule row (row → Rules list → section). */
    private const ROUTING_SECTION = '../../';

    public function __construct(private readonly SettingsStore $store) {}

    /** @return array<int, mixed> */
    public function components(): array
    {
        $saved = $this->store->formValues();
        $seenEvents = SeenEvents::counts();
        $eventsLocked = $this->store->isLocked('events');
        $patterns = SeenEvents::patterns(SeenEvents::suggested($seenEvents));

        // One line per list item: the section header lists them, and each collapsed row shows its own.
        $describeTopic = fn (array $row): string => trim(($row['name'] ?? '').' #'.($row['id'] ?? ''));
        $describeRule = fn (array $row, array $topics): string => ($row['pattern'] ?? '')
            .(filled($row['topic'] ?? null) ? ' → '.($topics[$row['topic']] ?? '#'.$row['topic']) : '')
            .(($row['enabled'] ?? true) ? '' : ' ('.Trans::get('page.disabled').')');
        $describeForward = fn (array $row): string => ($row['title'] ?? '')
            .(($row['forward'] ?? true) ? ' → '.($row['event'] ?? '') : ' ('.Trans::get('page.disabled').')');
        $describeTemplate = fn (array $row): string => ($row['pattern'] ?? '')
            .(filled($row['title'] ?? null) ? ' → '.$row['title'] : '');

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
                            ->schema(array_map($this->refreshesRouting(...), [
                                TextInput::make('pattern')
                                    ->label(Trans::get('settings.pattern'))
                                    ->required()
                                    ->placeholder('inquiry.*')
                                    ->datalist($patterns)
                                    ->distinct()
                                    ->live(onBlur: true),
                                $this->topicSelect('topic', '../../telegram_topics')->label(Trans::get('settings.topic'))->live(),
                                Toggle::make('enabled')->label(Trans::get('page.enabled'))->default(true)->inline(false)->live(),
                            ]))
                            ->columns(3)
                            ->defaultItems(0)
                            ->addActionLabel(Trans::get('settings.add_rule')),
                        Trans::get('settings.routing_description'),
                    ),
                    Text::make(Trans::choice('settings.seen_recent', SeenEvents::days())
                        .($eventsLocked ? '' : ' '.Trans::get('settings.seen_click')))
                        ->key('seen_events')
                        ->visible($seenEvents !== []),
                    Actions::make(fn (Get $get): array => $this->seenEventTags($seenEvents, $eventsLocked, $get))
                        ->key('seen_event_tags')
                        ->visible($seenEvents !== []),
                ]),

            $this->summarized(
                Section::make(Trans::get('settings.templates'))->key('templates'),
                $saved,
                'templates',
                $describeTemplate,
            )
                ->schema([
                    $this->locked(
                        $this->compact(Repeater::make('templates'), 'pattern', $describeTemplate)
                            ->hiddenLabel()
                            ->schema([
                                TextInput::make('pattern')
                                    ->label(Trans::get('settings.pattern'))
                                    ->required()
                                    ->placeholder('inquiry.*')
                                    ->datalist($patterns)
                                    ->distinct()
                                    ->columnSpanFull(),
                                // A new row starts as the default layout, spelled out: ":title" and ":body".
                                // Saving them unchanged stores nothing (MessageTemplate::fromArray()).
                                TextInput::make('title')
                                    ->label(Trans::get('settings.template_title'))
                                    ->default(':title')
                                    ->placeholder(':title')
                                    ->maxLength(500)
                                    ->helperText(Trans::get('settings.template_placeholders', ['placeholders' => implode(' ', MessageTemplate::PLACEHOLDERS)]))
                                    ->columnSpanFull(),
                                Textarea::make('body')
                                    ->label(Trans::get('settings.template_body'))
                                    ->default(':body')
                                    ->placeholder(':body')
                                    ->rows(3)
                                    ->maxLength(3000)
                                    ->helperText(Trans::get('settings.template_body_help'))
                                    ->visible(fn (Get $get): bool => (bool) $get('show_body'))
                                    ->columnSpanFull(),
                                TagsInput::make('fields')
                                    ->label(Trans::get('settings.template_fields'))
                                    ->helperText(Trans::get('settings.template_fields_help'))
                                    ->visible(fn (Get $get): bool => (bool) $get('show_fields'))
                                    ->columnSpanFull(),
                                Toggle::make('show_body')->label(Trans::get('settings.template_show_body'))->default(true)->inline(false)->live(),
                                Toggle::make('show_fields')->label(Trans::get('settings.template_show_fields'))->default(true)->inline(false)->live(),
                                Toggle::make('hashtag')->label(Trans::get('settings.template_hashtag'))->default(true)->inline(false),
                                Toggle::make('service')->label(Trans::get('settings.template_service'))->default(true)->inline(false),
                            ])
                            ->columns(4)
                            ->defaultItems(0)
                            ->extraItemActions([$this->previewTemplateAction()])
                            ->addActionLabel(Trans::get('settings.add_template')),
                        Trans::get('settings.templates_description'),
                    ),
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
                                    ->datalist(SeenEvents::titles())
                                    ->distinct(),
                                TextInput::make('event')
                                    ->label(Trans::get('table.event'))
                                    ->placeholder('inquiry.created')
                                    ->datalist(array_keys(SeenEvents::suggested($seenEvents)))
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
                    // As routing reads it: a config rule's 'enabled' => 0 still sends.
                    'enabled' => RoutingRules::sends($rule),
                ])->values()->all(),
            'templates' => collect(self::rows($values['templates'] ?? null))
                ->map(function (mixed $data, string|int $pattern): array {
                    $template = MessageTemplate::fromArray($data);

                    return [
                        'pattern' => (string) $pattern,
                        'title' => $template->title ?? ':title',
                        'body' => $template->body === null ? ':body' : ($template->body ?: null),
                        'show_body' => $template->body !== false,
                        'fields' => $template->fields ?? [],
                        'show_fields' => $template->fields !== [],
                        'hashtag' => $template->hashtag,
                        'service' => $template->service,
                    ];
                })->values()->all(),
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
            $data['events'] = self::keyed($data['events'] ?? null, 'pattern', fn (array $row): array => array_filter([
                'topic' => filled($row['topic'] ?? null) ? (string) $row['topic'] : null,
                'enabled' => (bool) ($row['enabled'] ?? true),
            ], fn (mixed $value): bool => $value !== null));
        }

        if (array_key_exists('templates', $data)) {
            $data['templates'] = self::keyed($data['templates'] ?? null, 'pattern', fn (array $row): array => self::template($row)->toArray());
        }

        if (array_key_exists('forward_map', $data)) {
            $data['forward_map'] = self::keyed($data['forward_map'] ?? null, 'title', fn (array $row): string|false => ($row['forward'] ?? true) ? trim((string) $row['event']) : false);
        }

        foreach (['telegram_chat_id', 'telegram_topic'] as $key) {
            if (isset($data[$key])) {
                $data[$key] = trim((string) $data[$key]);
            }
        }

        return $data;
    }

    /**
     * The events seen recently, one tag each with its count: grey when a rule gives it a topic
     * (the tooltip names it), amber when the rules give it none (no match, or a rule without a
     * topic; the message's own topic or the default one applies), struck through when a disabled
     * rule drops it. The tooltip also names the channel a rule sends to. Lists the most frequent
     * events and, past those, only the marked ones: a rare event that no rule routes matters most.
     * A click adds a rule for the event (addRuleFor()).
     *
     * @param  array<string, int>  $counts  SeenEvents::counts()
     * @return list<Action>
     */
    private function seenEventTags(array $counts, bool $locked, Get $get): array
    {
        // The rules in effect: the form's, or the config's when they are locked there (a config
        // rule may also set a channel, which the form has no field for).
        $rules = $locked ? (array) config('ops-notify.events', []) : $this->formRules($get('events'));
        $topics = self::topicNames($get);
        $suggested = SeenEvents::suggested($counts);
        [$disabled, $noTopic] = [Trans::get('page.disabled'), Trans::get('settings.seen_default')];
        $tags = [];

        foreach ($counts as $event => $count) {
            $event = (string) $event;
            $truncated = SeenEvents::isTruncated($event);
            $rule = RoutingRules::ruleFor($rules, $event);
            $topic = filled($rule['topic'] ?? null) ? (string) $rule['topic'] : null;
            $channel = filled($rule['channel'] ?? null) ? '→ '.$rule['channel'] : null;
            $sends = RoutingRules::sends($rule);

            // What the rules do with the event. Unmarked: a rule gives it a topic, or the log cut
            // the name short and an exact rule for the full name cannot be checked.
            $mark = match (true) {
                $rule === [] && $truncated => null,
                ! $sends => $disabled,
                $topic === null => $noTopic,
                default => null,
            };

            if ($mark === null && $channel === null && ! isset($suggested[$event])) {
                continue;
            }

            $notes = array_filter([
                $channel,
                $mark ?? ($topic !== null ? '→ '.TelegramChannel::formatTopic($topic, $topics[$topic] ?? null) : null),
            ]);

            $tags[] = Action::make(self::seenEventAction($event))
                ->badge()
                ->label(($truncated ? $event.'…' : $event).' ('.$count.')')
                // Amber for no topic; a disabled one is struck through instead.
                ->color($mark !== null && $sends ? 'warning' : 'gray')
                ->tooltip($notes === [] ? null : implode(', ', $notes))
                ->extraAttributes($sends ? [] : ['style' => 'text-decoration: line-through'])
                ->disabled($locked)
                ->action(fn (Get $get, Set $set) => $this->addRuleFor($event, $get, $set));
        }

        return $tags;
    }

    /** A seen event's tag action. Named after the event alone: the click has to find it again. */
    public static function seenEventAction(string $event): string
    {
        return 'seenEvent'.hash('xxh128', $event);
    }

    /**
     * Adds a routing rule for a seen event, its pattern filled in (SeenEvents::rulePattern())
     * and its row open to pick a topic. The first matching rule wins, so a new rule below one
     * that already matches the event would never apply: that one is named instead.
     */
    private function addRuleFor(string $event, Get $get, Set $set): void
    {
        $rows = self::rows($get('events'));

        if (($existing = PatternMap::firstKey($this->formRules($rows), $event)) !== null) {
            Notification::make()->warning()
                ->title(Trans::get('settings.seen_rule_exists', ['pattern' => $existing, 'event' => $event]))
                ->send();

            return;
        }

        $pattern = SeenEvents::rulePattern($event);
        // "new" keeps the row open (compact()). No field reads it, so it is never saved.
        $rows[(string) Str::uuid()] = ['pattern' => $pattern, 'topic' => null, 'enabled' => true, 'new' => true];
        $set('events', $rows);

        Notification::make()->success()
            ->title(Trans::get('settings.seen_rule_added', ['pattern' => $pattern]))
            ->body(Trans::get('settings.save_to_keep_it'))
            ->send();
    }

    /**
     * The form's rule rows as routing reads them: pattern => rule, rows without a pattern aside.
     *
     * @return array<string, mixed>
     */
    private function formRules(mixed $rows): array
    {
        return $this->toSettings(['events' => $rows])['events'];
    }

    /**
     * The template a form row describes. A part switched off is false (body) or [] (fields); an
     * empty field list with the toggle on means all fields.
     *
     * @param  array<string, mixed>  $row
     */
    private static function template(array $row): MessageTemplate
    {
        $fields = self::rows($row['fields'] ?? null);

        return MessageTemplate::fromArray([
            'title' => $row['title'] ?? null,
            'body' => ($row['show_body'] ?? true) ? str_replace("\r\n", "\n", (string) ($row['body'] ?? '')) : false,
            'fields' => ($row['show_fields'] ?? true) ? ($fields === [] ? null : $fields) : [],
            'hashtag' => (bool) ($row['hashtag'] ?? true),
            'service' => (bool) ($row['service'] ?? true),
        ]);
    }

    /**
     * Renders a template row against the latest logged message of an event it matches, as the
     * chat would show it. Works on the unsaved form, and says when a template above it matches
     * that event first (that one would be used instead).
     */
    private function previewTemplateAction(): Action
    {
        return Action::make('previewTemplate')
            ->label(Trans::get('settings.preview'))
            ->icon(Heroicon::OutlinedEye)
            ->color('gray')
            ->modalHeading(Trans::get('settings.preview'))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(Trans::get('invites.close'))
            ->modalContent(function (array $arguments, Repeater $component, Get $get): Htmlable {
                $earlier = [];
                $service = filled($get('service')) ? trim((string) $get('service')) : null;

                foreach (self::rows($component->getState()) as $key => $row) {
                    $row = self::rows($row);

                    if ((string) $key === (string) ($arguments['item'] ?? '')) {
                        return $this->preview($row, $earlier, $service);
                    }

                    $earlier[] = trim((string) ($row['pattern'] ?? ''));
                }

                return new HtmlString('');
            });
    }

    /**
     * @param  array<string, mixed>  $row  The template row.
     * @param  list<string>  $earlier  Patterns of the rows above it.
     * @param  string|null  $service  The Service name as typed in the form; null = the saved one.
     */
    private function preview(array $row, array $earlier, ?string $service): Htmlable
    {
        $pattern = trim((string) ($row['pattern'] ?? ''));
        $note = fn (string $text, string $color = 'gray'): string => '<p style="color: var(--'.$color.'-500); margin-bottom: .75rem">'.e($text).'</p>';

        if ($pattern === '') {
            return new HtmlString($note(Trans::get('settings.preview_no_pattern')));
        }

        if (($log = SeenEvents::latestMessage($pattern)) === null) {
            return new HtmlString($note(Trans::get('settings.preview_none', ['pattern' => $pattern, 'date' => SeenEvents::since()->isoFormat('LL')])));
        }

        $this->store->apply();
        $message = $log->toMessage();

        // The formatter escapes every part and adds only <b> tags, so its output is safe HTML.
        $text = app(OpsNotifier::class)->inMessageLocale(fn (): string => (new TelegramFormatter)->format(
            $message,
            $service ?? config('ops-notify.service'),
            template: self::template($row),
        ));

        $first = PatternMap::firstKey(array_fill_keys(array_filter($earlier), true), $log->event);
        $buttons = implode('', array_map(
            fn (Button $button): string => '<span style="display: inline-block; margin: .5rem .5rem 0 0; padding: .25rem .75rem; border-radius: .5rem; border: 1px solid var(--gray-300)">'.e($button->label).'</span>',
            $message->buttons,
        ));

        return new HtmlString(
            $note(Trans::get('settings.preview_from', ['event' => $log->event, 'date' => $log->created_at?->isoFormat('LLL')]))
            .($first !== null ? $note(Trans::get('settings.preview_shadowed', ['pattern' => $first, 'event' => $log->event]), 'warning') : '')
            .'<div style="white-space: pre-wrap; overflow-wrap: anywhere; padding: .75rem; border-radius: .5rem; border: 1px solid var(--gray-200)">'.$text.'</div>'
            .$buttons,
        );
    }

    /**
     * A rule field whose changes refresh the seen-event tags, which mark unmatched events, rules
     * without a topic and disabled rules. Only the Routing section re-renders: the tags, the row
     * labels and the header. The field decides how live it is (a text field on blur).
     */
    private function refreshesRouting(Field $field): Field
    {
        return $field->partiallyRenderComponentsAfterStateUpdated([self::ROUTING_SECTION]);
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
     * @param  Closure(array<string, mixed>, array<string, string>): string  $describe  Row and topic names by id.
     */
    private function summarized(Section $section, array $saved, string $list, Closure $describe): Section
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
     * @param  Closure(array<string, mixed>, array<string, string>): string  $describe  Row and topic names by id.
     */
    private function compact(Repeater $repeater, string $required, Closure $describe): Repeater
    {
        return $repeater
            ->collapsible()
            // A row flagged "new" (added from a seen event, see addRuleFor()) is filled in but
            // still needs its other fields: it stays open too.
            ->collapsed(function (?Schema $item) use ($required): bool {
                $row = $item?->getStateSnapshot() ?? [];

                return filled($row[$required] ?? null) && ! ($row['new'] ?? false);
            })
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

    /**
     * Repeater rows as a config map: the trimmed $field of each row is the key, $value($row)
     * the value. The first matching key wins wherever these maps are read, so of two rows with
     * the same key only the upper one could ever apply: it is the one kept, as the preview
     * says. The form's distinct() rule reports the duplicate before it gets this far.
     *
     * @param  Closure(array<string, mixed>): mixed  $value
     * @return array<string, mixed>
     */
    private static function keyed(mixed $rows, string $field, Closure $value): array
    {
        return collect(self::rows($rows))
            ->filter(fn (mixed $row): bool => is_array($row) && filled($row[$field] ?? null))
            ->unique(fn (array $row): string => trim((string) $row[$field]))
            ->mapWithKeys(fn (array $row): array => [trim((string) $row[$field]) => $value($row)])
            ->all();
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
