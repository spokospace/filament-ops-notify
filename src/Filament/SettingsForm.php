<?php

namespace Spokospace\OpsNotify\Filament;

use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Fieldset;
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
use Spokospace\OpsNotify\Enums\SeenEventStatus;
use Spokospace\OpsNotify\Exceptions\ChannelException;
use Spokospace\OpsNotify\Models\OpsNotifyLog;
use Spokospace\OpsNotify\OpsMessage;
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
 *
 * @phpstan-type TriedRow array{template: MessageTemplate, message: OpsMessage, log: OpsNotifyLog|null, service: string, parts: array<string, mixed>, hashtag: string}
 */
class SettingsForm
{
    /** The Routing section, relative to a field in a rule row (row → Rules list → section). */
    private const ROUTING_SECTION = '../../';

    /** The Routing section, relative to a field in a topic row (row → Topics list → section → form). */
    private const ROUTING_FROM_TOPIC = '../../../'.self::ROUTING_KEY;

    /** The Routing section's key. */
    private const ROUTING_KEY = 'routing';

    /** The fields of a template editor, see row(). The pattern comes from the row or the scope. */
    private const ROW_KEYS = ['title_mode', 'title', 'body_mode', 'body', 'fields_mode', 'fields', 'hashtag', 'service'];

    /** The key of the rendered message under a template row. */
    private const TEMPLATE_PREVIEW = 'preview';

    /**
     * What a placeholder chip does in the browser: puts its token into the field at the cursor
     * and lets the field's own blur update re-render the row.
     */
    private const INSERT_PLACEHOLDER = <<<'JS'
        const input = $el.closest('.fi-fo-field').querySelector('input, textarea');
        const start = input.selectionStart ?? input.value.length;
        const before = input.value.slice(0, start);
        input.value = before + (before !== '' && !/\s$/.test(before) ? ' ' : '') + $el.dataset.token + input.value.slice(input.selectionEnd ?? start);
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('blur', { bubbles: true }));
        input.focus();
        JS;

    /** @var list<string>|null The event names of the log window, read once per request. */
    private ?array $events = null;

    private ?OpsMessage $sample = null;

    /** @var array<string, array{OpsMessage, OpsNotifyLog|null}> The message a pattern's rows are tried on, see example(). */
    private array $examples = [];

    /** @var array<string, TriedRow> What a row's fields and preview show, by row and service, see triedRow(). */
    private array $tried = [];

    /** @var array<string, string> Package strings in the message language, by key, see messageText(). */
    private array $texts = [];

    /** @var array<string, array{array<string, string>, list<string>}> The rule and event groups of patternOptions(), by the rules and topics they come from. */
    private array $patternGroups = [];

    public function __construct(private readonly SettingsStore $store) {}

    /** @return array<int, mixed> */
    public function components(): array
    {
        $saved = $this->store->formValues();
        $seenEvents = SeenEvents::counts();
        $eventsLocked = $this->store->isLocked('events');
        // The saved rules, or the config's when they are locked there (a config rule may also set
        // a channel, which the form has no field for).
        $savedRules = (array) ($saved['events'] ?? []);
        $listedEvents = self::listedSeenEvents($seenEvents, $savedRules);
        $patterns = SeenEvents::patterns(SeenEvents::suggested($seenEvents));

        // One line per list item: the section header lists them, and each collapsed row shows its own.
        $describeTopic = fn (array $row): string => trim(($row['name'] ?? '').' #'.($row['id'] ?? ''));
        $describeRule = fn (array $row, array $topics): string => ($row['pattern'] ?? '')
            .(filled($row['topic'] ?? null) ? ' → '.($topics[$row['topic']] ?? '#'.$row['topic']) : '')
            .(($row['enabled'] ?? true) ? '' : ' ('.Trans::get('page.disabled').')');
        $describeForward = fn (array $row): string => ($row['title'] ?? '')
            .(($row['forward'] ?? true) ? ' → '.($row['event'] ?? '') : ' ('.Trans::get('page.disabled').')');
        $describeTemplate = fn (array $row): string => filled($row['pattern'] ?? null) ? $row['pattern'].' → '.$this->describedTitle((string) $row['pattern'], $row) : '';

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
                            ->placeholder(Trans::get('settings.locale_default', ['locale' => config('app.locale')])),
                        Trans::get('settings.locale_help'),
                    ),
                    $this->locked(
                        TextInput::make('telegram_bot_token')
                            ->label(Trans::get('settings.bot_token'))
                            ->password()
                            ->autocomplete('off')
                            ->placeholder($this->store->hasStored('telegram_bot_token') ? Trans::get('settings.bot_token_saved') : '123456789:AA...'),
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
                            // The seen-event tags and the rule rows name the topics.
                            ->schema([
                                $this->refreshesRouting(TextInput::make('name')->label(Trans::get('settings.topic_name'))->required()->maxLength(128)->live(onBlur: true), self::ROUTING_FROM_TOPIC),
                                $this->refreshesRouting(TextInput::make('id')->label(Trans::get('settings.topic_id'))->integer()->required()->live(onBlur: true), self::ROUTING_FROM_TOPIC),
                            ])
                            ->columns(2)
                            ->defaultItems(0)
                            ->addActionLabel(Trans::get('settings.add_existing_topic')),
                        Trans::get('settings.topics_description'),
                    ),
                ]),

            $this->summarized(
                Section::make(Trans::get('settings.routing'))->key(self::ROUTING_KEY),
                $saved,
                'events',
                $describeRule,
            )
                ->schema([
                    $this->locked(
                        $this->compact(Repeater::make('events'), 'pattern', $describeRule, openUntil: 'topic')
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
                    Actions::make(fn (Get $get): array => $this->seenEventTags($listedEvents, $seenEvents, $eventsLocked ? $savedRules : null, $get))
                        ->key('seen_event_tags')
                        ->visible($seenEvents !== []),
                ]),

            $this->templatesSection($saved, $patterns, $describeTemplate),

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
        $templates = self::rows($values['templates'] ?? null);

        return [
            ...$values,
            'events' => collect(self::rows($values['events'] ?? null))
                ->map(fn (array $rule, string $pattern): array => [
                    'pattern' => $pattern,
                    'topic' => $rule['topic'] ?? null,
                    // As routing reads it: a config rule's 'enabled' => 0 still sends.
                    'enabled' => RoutingRules::sends($rule),
                ])->values()->all(),
            // The "*" template is the default look, edited on its own; the rest are exceptions.
            'template_default' => array_diff_key($this->row('*', MessageTemplate::fromArray($templates['*'] ?? null)), ['pattern' => true]),
            'templates' => collect($templates)
                ->except('*')
                ->map(fn (mixed $data, string|int $pattern): array => $this->row((string) $pattern, MessageTemplate::fromArray($data)))
                ->values()
                ->all(),
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

        if (array_key_exists('templates', $data) || array_key_exists('template_default', $data)) {
            $templates = self::keyed($data['templates'] ?? null, 'pattern', fn (array $row): array => self::template($row)->toArray());
            // The default look goes last, after the exceptions: the first matching key wins.
            $default = self::template(self::rows($data['template_default'] ?? null))->toArray();
            unset($data['template_default']);
            $data['templates'] = $default === [] ? $templates : [...$templates, '*' => $default];
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
     * The seen events that get a tag: the most frequent ones and, past those, the ones the saved
     * rules give no topic or drop, or send to another channel: a rare event that no rule routes
     * matters most. Up to SeenEvents::SUGGESTED of those, to keep the section light. Fixed for
     * as long as the form is open, so a click on a tag always finds its action, however the
     * rules have been edited since.
     *
     * @param  array<string, int>  $counts  SeenEvents::counts()
     * @param  array<array-key, mixed>  $rules  The saved rules, pattern => rule.
     * @return list<string>
     */
    private static function listedSeenEvents(array $counts, array $rules): array
    {
        $suggested = array_map(strval(...), array_keys(SeenEvents::suggested($counts)));

        $listed = $suggested;

        foreach (array_slice(array_keys($counts), count($suggested)) as $event) {
            $rule = SeenEvents::ruleFor($rules, (string) $event);

            if (SeenEvents::status($rule, (string) $event) !== SeenEventStatus::Routed || filled($rule['channel'] ?? null)) {
                $listed[] = (string) $event;
            }

            if (count($listed) === 2 * SeenEvents::SUGGESTED) {
                break;
            }
        }

        return $listed;
    }

    /**
     * The listed events, one tag each with its count and what the rules do with it
     * (SeenEventStatus): grey when a rule gives it a topic (the tooltip names it), amber when the
     * rules give it none (the message's own topic or the default one applies), struck through
     * when a disabled rule drops it. The tag names the state and the channel a rule sends to. It
     * follows the rules as edited; a click adds a rule for the event (addRuleFor()).
     *
     * @param  list<string>  $events  listedSeenEvents()
     * @param  array<string, int>  $counts  SeenEvents::counts()
     * @param  array<array-key, mixed>|null  $lockedRules  The config's rules, when they are locked there.
     * @return list<Action>
     */
    private function seenEventTags(array $events, array $counts, ?array $lockedRules, Get $get): array
    {
        $rules = $lockedRules ?? $this->formRules($get('events'));
        $topics = self::topicNames($get('telegram_topics'));
        $tags = [];

        foreach ($events as $event) {
            $rule = SeenEvents::ruleFor($rules, $event);
            $status = SeenEvents::status($rule, $event);
            $topic = $status === SeenEventStatus::Routed
                ? '→ '.self::topicLabel($rule, $topics)
                : null;
            $notes = array_filter([
                filled($rule['channel'] ?? null) ? '→ '.$rule['channel'] : null,
                $status->note(),
            ]);
            $label = (SeenEvents::isTruncated($event) ? $event.'…' : $event).' ('.implode(', ', [$counts[$event], ...$notes]).')';

            $tags[] = Action::make(self::seenEventAction($event))
                ->badge()
                ->label($label)
                ->color($status->getColor())
                ->tooltip($topic)
                // The topic is in the tooltip alone: a screen reader reads the whole description.
                ->extraAttributes(array_filter([
                    'aria-label' => $topic === null ? null : $label.', '.$topic,
                    'style' => $status === SeenEventStatus::Disabled ? 'text-decoration: line-through' : null,
                ]))
                ->disabled($lockedRules !== null)
                ->action(fn (Get $get, Set $set) => $this->addRuleFor($event, array_keys($counts), $get, $set));
        }

        return $tags;
    }

    /**
     * A rule's topic by its name from the form's Topics list: "Inquiries #3".
     *
     * @param  array<string, mixed>  $rule
     * @param  array<string, string>  $topics  topicNames()
     */
    private static function topicLabel(array $rule, array $topics): string
    {
        $id = (string) ($rule['topic'] ?? '');

        return TelegramChannel::formatTopic($id, $topics[$id] ?? null);
    }

    /** A seen event's tag action. Named after the event alone: the click has to find it again. */
    public static function seenEventAction(string $event): string
    {
        return 'seenEvent'.hash('xxh128', $event);
    }

    /**
     * Adds a routing rule for a seen event, its pattern filled in (SeenEvents::rulePattern())
     * and its row open to pick a topic. The first matching rule wins, so a new rule below one
     * that already matches the event would never apply: that one is named instead, with what it
     * does. The notice also names the other seen events the new pattern takes over.
     *
     * @param  list<array-key>  $seen  The seen events, SeenEvents::counts()'s keys.
     */
    private function addRuleFor(string $event, array $seen, Get $get, Set $set): void
    {
        $rows = self::rows($get('events'));
        $rules = $this->formRules($rows);

        if (($existing = SeenEvents::ruleKey($rules, $event)) !== null) {
            $rule = (array) $rules[$existing];
            $status = SeenEvents::status($rule, $event);

            Notification::make()
                ->status($status === SeenEventStatus::Routed ? 'info' : 'warning')
                ->title(Trans::get(match ($status) {
                    SeenEventStatus::Routed => 'settings.seen_rule_routed',
                    SeenEventStatus::Disabled => 'settings.seen_rule_disabled',
                    default => 'settings.seen_rule_exists',
                }, [
                    'pattern' => $existing,
                    'event' => $event,
                    'topic' => self::topicLabel($rule, self::topicNames($get('telegram_topics'))),
                ]))
                ->send();

            return;
        }

        $pattern = SeenEvents::rulePattern($event);
        // "new" keeps the row open until it has a topic (compact()). No field reads it, and
        // toSettings() does not keep it.
        $rows[(string) Str::uuid()] = ['pattern' => $pattern, 'topic' => null, 'enabled' => true, 'new' => true];
        $set('events', $rows);

        // Events no rule matches yet that the new pattern catches too: the rules above it keep theirs.
        $others = collect($seen)
            ->map(strval(...))
            ->filter(fn (string $other): bool => $other !== $event && Str::is($pattern, $other) && SeenEvents::ruleKey($rules, $other) === null);

        Notification::make()->success()
            ->title(Trans::get('settings.seen_rule_added', ['pattern' => $pattern]))
            ->body(implode(' ', array_filter([
                $others->isEmpty() ? null : Trans::get('settings.seen_rule_also', [
                    'events' => $others->take(5)->implode(', ').($others->count() > 5 ? ', …' : ''),
                    'event' => $event,
                ]),
                Trans::get('settings.save_to_keep_it'),
            ])))
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
     * Message templates: how every message looks (the "*" template, edited without a pattern),
     * then exceptions for some events. Both are edited on the message itself: the message as it
     * will look comes first, and a part changes only once it is switched to "Change it".
     *
     * @param  array<string, mixed>  $saved  SettingsStore::formValues()
     * @param  list<string>  $patterns  SeenEvents::patterns()
     * @param  Closure(array<string, mixed>): string  $describe  A row's one-line summary.
     */
    private function templatesSection(array $saved, array $patterns, Closure $describe): Section
    {
        $locked = $this->store->isLocked('templates');

        return $this->summarized(
            Section::make(Trans::get('settings.templates'))->key('templates'),
            $saved,
            'templates',
            $describe,
            fn (Get $get): string => self::template(self::rows($get('template_default')))->toArray() === []
                ? ''
                : Trans::get('settings.template_default_look').' → '.$this->describedTitle('*', self::rows($get('template_default'))),
        )
            ->schema([
                Text::make(Trans::get('settings.templates_description')),
                Fieldset::make(Trans::get('settings.template_default_look'))
                    ->key('default')
                    ->statePath('template_default')
                    ->columns(1)
                    ->disabled($locked)
                    ->schema([
                        ...$this->templateEditor(TemplateScope::DefaultLook, $locked),
                        ...($locked ? [Text::make(Trans::get('settings.locked'))] : []),
                    ]),
                Section::make(Trans::get('settings.template_exceptions'))
                    ->key('exceptions')
                    ->collapsible()
                    ->collapsed(filled($saved['templates'] ?? null))
                    ->schema([
                        $this->locked(
                            $this->compact(Repeater::make('templates'), 'pattern', $describe)
                                ->hiddenLabel()
                                ->schema([
                                    // Which messages: a routing rule, named by its topic, an event
                                    // seen recently, or a pattern from config. A change re-renders
                                    // the list: it picks the message the row is tried on, and it can
                                    // shadow the rows below.
                                    $this->refreshesTemplates(Select::make('pattern')
                                        ->label(Trans::get('settings.template_which'))
                                        ->options(fn (Get $get): array => $this->patternOptions($get, $patterns))
                                        ->searchable()
                                        ->required()
                                        ->distinct()
                                        ->live(), TemplateScope::Exception),
                                    ...$this->templateEditor(TemplateScope::Exception, $locked),
                                ])
                                ->columns(1)
                                ->defaultItems(0)
                                ->addActionLabel(Trans::get('settings.add_template')),
                            Trans::get('settings.template_exceptions_description'),
                        ),
                    ]),
            ]);
    }

    /**
     * The editor of one template, on the message it renders: the message as it will look, then
     * each part with "Keep as it is / Change it / Leave it out". The title and text fields, with
     * their placeholder chips and result lines, appear only for a part being changed.
     *
     * @return list<Component>
     */
    private function templateEditor(TemplateScope $scope, bool $locked): array
    {
        $mode = fn (string $part, string $label, string ...$modes): Field => $this->refreshesTemplates(
            Radio::make("{$part}_mode")
                ->label(Trans::get("settings.template_{$label}"))
                ->options(array_combine($modes, array_map(fn (string $mode): string => Trans::get("settings.template_mode_{$mode}"), $modes)))
                ->default($modes[0])
                ->inline()
                ->inlineLabel()
                ->live(),
            $scope,
        );
        // A field for a part being changed: an example to start from, chips that insert a
        // placeholder, the result underneath, and a hint. helperText() is a Text in belowContent
        // too, so the hint keeps its look.
        $changed = fn (Field $field, string $part, string $hint): Field => $this->refreshesTemplates(
            $field
                ->hiddenLabel()
                ->default(fn (): string => $this->messageText("settings.template_default_{$part}"))
                ->live(onBlur: true)
                ->visible(fn (Get $get): bool => $get("{$part}_mode") === 'change')
                ->aboveContent(fn (Get $get): Htmlable => $this->placeholderChips($get, $scope))
                ->belowContent(fn (Get $get): array => [$this->renderedPart($part, $get, $scope), Text::make(Trans::get("settings.{$hint}"))]),
            $scope,
        );

        return [
            Text::make(fn (Get $get, Text $component): Htmlable => $this->preview($get, $scope, (string) str($component->getContainer()->getStatePath())->afterLast('.')))
                ->key(self::TEMPLATE_PREVIEW),
            $mode('title', 'title', 'keep', 'change'),
            $changed(TextInput::make('title')->maxLength(500), 'title', 'template_placeholders'),
            $mode('body', 'text', 'keep', 'change', 'leave_out'),
            $changed(Textarea::make('body')->rows(3)->maxLength(3000), 'body', 'template_body_help'),
            $mode('fields', 'fields', 'all', 'choose', 'leave_out'),
            $this->refreshesTemplates(CheckboxList::make('fields')
                ->hiddenLabel()
                ->options(fn (Get $get): array => $this->fieldOptions($get, $scope))
                ->helperText(fn (Get $get): ?string => $this->fieldOptions($get, $scope) === [] ? Trans::get('settings.template_no_fields') : null)
                ->columns(3)
                ->live()
                ->visible(fn (Get $get): bool => $get('fields_mode') === 'choose'), $scope),
            $this->refreshesTemplates(Checkbox::make('hashtag')
                ->label(fn (Get $get): string => Trans::get('settings.template_hashtag_shown', ['hashtag' => $this->tried($get, $scope)['hashtag']]))
                ->default(true)
                ->live(), $scope),
            $this->refreshesTemplates(Checkbox::make('service')
                ->label(fn (Get $get): string => Trans::get('settings.template_service_shown', ['service' => '['.$this->serviceName($get, $scope).']']))
                ->default(true)
                ->live(), $scope),
            Actions::make([
                Action::make('defaultTemplate')
                    ->label(Trans::get('settings.template_default'))
                    ->link()
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->action(function (Set $set): void {
                        foreach (array_diff_key($this->row('', new MessageTemplate), ['pattern' => true]) as $key => $value) {
                            $set($key, $value);
                        }
                    }),
            ])->key('defaults')->hidden($locked),
        ];
    }

    /**
     * Which messages an exception is for, grouped: the routing rules, named by their topic
     * ("Inquiries #3 · inquiry.*"), then the events seen recently. The current value stays
     * selectable when it is neither (a pattern from config). The groups are the same for every
     * row, so they are built once per request.
     *
     * @param  list<string>  $patterns
     * @return array<string, array<string, string>>
     */
    private function patternOptions(Get $get, array $patterns): array
    {
        $root = TemplateScope::Exception->root();
        $groups = $this->patternGroups[serialize([$get("{$root}events"), $get("{$root}telegram_topics")])] ??= (function () use ($get, $root, $patterns): array {
            $topics = self::topicNames($get("{$root}telegram_topics"));
            $rules = [];

            foreach (array_filter(self::rows($get("{$root}events")), 'is_array') as $rule) {
                if (filled($pattern = trim((string) ($rule['pattern'] ?? '')))) {
                    $topic = filled($rule['topic'] ?? null)
                        ? TelegramChannel::formatTopic((string) $rule['topic'], $topics[(string) $rule['topic']] ?? null)
                        : Trans::get('settings.general');
                    $rules[$pattern] = "{$topic} · {$pattern}";
                }
            }

            return [$rules, array_values(array_diff($patterns, array_keys($rules)))];
        })();
        [$rules, $events] = $groups;
        $current = trim((string) $get('pattern'));

        if ($current !== '' && ! isset($rules[$current]) && ! in_array($current, $events, true)) {
            $events[] = $current;
        }

        return array_filter([
            Trans::get('settings.template_by_rule') => $rules,
            Trans::get('settings.template_by_event') => array_combine($events, $events),
        ]);
    }

    /**
     * The fields to choose from: those of the message the row is tried on, and any already
     * chosen, so a saved choice never disappears.
     *
     * @return array<string, string>
     */
    private function fieldOptions(Get $get, TemplateScope $scope): array
    {
        $labels = array_values(array_unique(array_filter([
            ...array_map(strval(...), array_keys($this->tried($get, $scope)['message']->fields)),
            ...array_map(strval(...), self::rows($get('fields'))),
        ])));

        return array_combine($labels, $labels);
    }

    /**
     * The template a form row describes.
     *
     * @param  array<string, mixed>  $row
     */
    private static function template(array $row): MessageTemplate
    {
        return MessageTemplate::fromArray([
            'title' => ($row['title_mode'] ?? 'keep') === 'change' ? $row['title'] ?? null : null,
            'body' => match ($row['body_mode'] ?? 'keep') {
                'leave_out' => false,
                'change' => str_replace("\r\n", "\n", (string) ($row['body'] ?? '')),
                default => null,
            },
            'fields' => match ($row['fields_mode'] ?? 'all') {
                'leave_out' => [],
                'choose' => array_values(array_filter(array_map(strval(...), self::rows($row['fields'] ?? null)))),
                default => null,
            },
            'hashtag' => (bool) ($row['hashtag'] ?? true),
            'service' => (bool) ($row['service'] ?? true),
        ]);
    }

    /**
     * A template in form shape (ROW_KEYS). A part that is not changed keeps the example text of
     * a new row in its hidden field, so switching it to "Change it" starts from an example, not
     * from a blank. template() reads the row back.
     *
     * @return array<string, mixed>
     */
    private function row(string $pattern, MessageTemplate $template): array
    {
        return [
            'pattern' => $pattern,
            'title_mode' => $template->title === null ? 'keep' : 'change',
            'title' => $template->title ?? $this->messageText('settings.template_default_title'),
            'body_mode' => match (true) {
                $template->body === false => 'leave_out',
                is_string($template->body) => 'change',
                default => 'keep',
            },
            'body' => is_string($template->body) ? $template->body : $this->messageText('settings.template_default_body'),
            'fields_mode' => match (true) {
                $template->fields === null => 'all',
                $template->fields === [] => 'leave_out',
                default => 'choose',
            },
            'fields' => $template->fields ?? [],
            'hashtag' => $template->hashtag,
            'service' => $template->service,
        ];
    }

    /** @return array<string, mixed> The row a field's Get sees, its pattern from the scope. */
    private function rowFrom(Get $get, TemplateScope $scope): array
    {
        $row = ['pattern' => $scope->pattern($get)];

        foreach (self::ROW_KEYS as $key) {
            $row[$key] = $get($key);
        }

        return $row;
    }

    /**
     * A row's title as the chat shows it, for the collapsed row and the section header.
     *
     * @param  array<string, mixed>  $row
     */
    private function describedTitle(string $pattern, array $row): string
    {
        return Str::limit($this->triedRow(['pattern' => $pattern] + $row, (string) config('ops-notify.service'))['parts']['title'], 60);
    }

    /** A package string in the message language, e.g. the example template of a new row. */
    private function messageText(string $key): string
    {
        return $this->texts[$key] ??= app(OpsNotifier::class)->inMessageLocale(fn (): string => Trans::get($key));
    }

    /**
     * What a row's fields and preview show: the row as a template, the message it is tried on,
     * the title and body as the chat would show them, and the event's #hashtag.
     *
     * @return TriedRow
     */
    private function tried(Get $get, TemplateScope $scope): array
    {
        return $this->triedRow($this->rowFrom($get, $scope), $this->serviceName($get, $scope));
    }

    /**
     * Memoised per row and Service name, since every part of a row asks for it, and the row's
     * label and the section header too.
     *
     * @param  array<string, mixed>  $row
     * @return TriedRow
     */
    private function triedRow(array $row, string $service): array
    {
        return $this->tried[serialize([$row, $service])] ??= (function () use ($row, $service): array {
            [$message, $log] = $this->example((string) ($row['pattern'] ?? ''));
            $template = self::template($row);
            $formatter = new TelegramFormatter;

            return [
                'template' => $template,
                'message' => $message,
                'log' => $log,
                'service' => $service,
                'parts' => app(OpsNotifier::class)->inMessageLocale(fn () => $formatter->parts($message, $service, $template)),
                'hashtag' => '#'.$formatter->hashtag($message->event),
            ];
        })();
    }

    /**
     * The message a row is tried on: the latest logged message of an event the pattern matches,
     * or a sample message in the message language while nothing matching is logged. Memoised per
     * pattern, and the event names of the log window are read once per request.
     *
     * @return array{OpsMessage, OpsNotifyLog|null}
     */
    private function example(string $pattern): array
    {
        $pattern = trim($pattern);

        return $this->examples[$pattern] ??= (function () use ($pattern): array {
            $this->store->apply();
            $this->events ??= SeenEvents::recentEvents();
            $this->sample ??= $this->sampleMessage();
            $log = $pattern === '' ? null : SeenEvents::latestMessage($pattern, $this->events);

            return [$log?->toMessage() ?? $this->sample, $log];
        })();
    }

    private function sampleMessage(): OpsMessage
    {
        return app(OpsNotifier::class)->inMessageLocale(fn (): OpsMessage => OpsMessage::make('inquiry.created')
            ->title(Trans::get('settings.sample_title'))
            ->line(Trans::get('settings.sample_body'))
            ->field(Trans::get('settings.sample_name'), 'Anna')
            ->field(Trans::get('settings.sample_email'), 'anna@example.com'));
    }

    /**
     * Buttons above a template field that insert a placeholder: the fixed ones, then one per
     * field of the message the row is tried on, so they show what the event carries. They work
     * in the browser (INSERT_PLACEHOLDER); a token is data, escaped into an attribute.
     */
    private function placeholderChips(Get $get, TemplateScope $scope): Htmlable
    {
        $chips = array_map(
            fn (string $token): string => '<button type="button" class="fi-badge fi-size-sm fi-color fi-color-gray" x-on:click="'.e(self::INSERT_PLACEHOLDER).'" data-token="'.e($token).'">'.e($token).'</button>',
            MessageTemplate::placeholders($this->tried($get, $scope)['message']),
        );

        return new HtmlString('<div style="display: flex; flex-wrap: wrap; gap: .375rem; margin-bottom: .375rem">'.implode('', $chips).'</div>');
    }

    /** The row's title or body rendered on the message it is tried on, shown under the field. */
    private function renderedPart(string $part, Get $get, TemplateScope $scope): Text
    {
        return Text::make(new HtmlString('<span style="white-space: pre-wrap; overflow-wrap: anywhere">→ '.e($this->tried($get, $scope)['parts'][$part]).'</span>'))->size('sm');
    }

    /** The Service name as typed in the form, or the saved one when the field is blank. */
    private function serviceName(Get $get, TemplateScope $scope): string
    {
        $service = $get($scope->root().'service');

        return filled($service) ? trim((string) $service) : (string) config('ops-notify.service');
    }

    /**
     * The row rendered on the message it is tried on, as the chat would show it: where that
     * message comes from, a warning when another template matches its event first (that one
     * would be used instead), then the message.
     *
     * @param  string  $key  The row's key in the exceptions list; the default look has none.
     */
    private function preview(Get $get, TemplateScope $scope, string $key): Htmlable
    {
        ['message' => $message, 'log' => $log, 'template' => $template, 'service' => $service] = $this->tried($get, $scope);
        $pattern = $scope->pattern($get);
        $note = fn (string $text, string $color = 'gray'): string => '<p style="color: var(--'.$color.'-500); margin-bottom: .75rem">'.e($text).'</p>';

        // The formatter escapes every part and adds only <b> tags, so its output is safe HTML.
        $text = app(OpsNotifier::class)->inMessageLocale(fn (): string => (new TelegramFormatter)->format($message, $service, template: $template));

        // The exception rows above shadow this one; every exception shadows the default look.
        $rows = self::rows($get($scope->exceptions()));
        $above = $scope === TemplateScope::Exception ? array_slice($rows, 0, (int) array_search($key, array_map(strval(...), array_keys($rows)), true)) : $rows;
        $shadows = array_filter(array_map(fn (mixed $row): string => trim((string) (self::rows($row)['pattern'] ?? '')), $above));
        $first = $log === null ? null : PatternMap::firstKey(array_fill_keys($shadows, true), $log->event);
        $source = match (true) {
            $pattern === '' => Trans::get('settings.preview_no_pattern'),
            $log === null && $pattern === '*' => Trans::get('settings.preview_sample_any', ['date' => SeenEvents::since()->isoFormat('LL')]),
            $log === null => Trans::get('settings.preview_sample', ['pattern' => $pattern, 'date' => SeenEvents::since()->isoFormat('LL')]),
            default => Trans::get('settings.preview_from', ['event' => $log->event, 'date' => $log->created_at?->isoFormat('LLL')]),
        };
        $buttons = implode('', array_map(
            fn (Button $button): string => '<span style="display: inline-block; margin: .5rem .5rem 0 0; padding: .25rem .75rem; border-radius: .5rem; border: 1px solid var(--gray-300)">'.e($button->label).'</span>',
            $message->buttons,
        ));

        return new HtmlString(
            '<p style="font-weight: 600; margin-bottom: .25rem">'.e(Trans::get('settings.preview')).'</p>'
            .$note($source)
            .($first !== null ? $note(Trans::get('settings.preview_shadowed', ['pattern' => $first, 'event' => $log->event]), 'warning') : '')
            .'<div style="white-space: pre-wrap; overflow-wrap: anywhere; padding: .75rem; border-radius: .5rem; border: 1px solid var(--gray-200)">'.$text.'</div>'
            .$buttons,
        );
    }

    /**
     * A rule field whose changes refresh the seen-event tags, which mark unmatched events, rules
     * without a topic and disabled rules. Only the Routing section re-renders: the tags, the row
     * labels and the header. The field decides how live it is (a text field on blur).
     *
     * @param  string  $section  The Routing section, relative to the field.
     */
    private function refreshesRouting(Field $field, string $section = self::ROUTING_SECTION): Field
    {
        return $field->partiallyRenderComponentsAfterStateUpdated([$section]);
    }

    /**
     * A template field whose changes re-render its editor's surroundings alone: the section for
     * the default look, the exceptions list for a row (TemplateScope::refreshTarget()).
     */
    private function refreshesTemplates(Field $field, TemplateScope $scope): Field
    {
        return $field->partiallyRenderComponentsAfterStateUpdated([$scope->refreshTarget()]);
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
     * @param  Closure(Get): string|null  $first  An item before the rows, e.g. the default look; '' for none.
     */
    private function summarized(Section $section, array $saved, string $list, Closure $describe, ?Closure $first = null): Section
    {
        return $section
            ->collapsible()
            ->collapsed(filled($saved[$list] ?? null))
            ->description(function (Get $get) use ($list, $describe, $first): ?string {
                $rows = array_filter((array) $get($list), 'is_array');
                $topics = self::topicNames($get('telegram_topics'));
                $items = collect([$first === null ? '' : $first($get), ...array_map(fn (array $row): string => $describe($row, $topics), $rows)])->filter();

                return $items->isEmpty() ? null : Str::limit($items->implode(' · '), 240);
            });
    }

    /**
     * Saved rows collapse to one line with their data ("inquiry.* → Inquiries #3"); a row that is
     * new, or not filled in yet, stays open for editing. Click a row to expand it.
     *
     * @param  string  $required  The field that marks a row as filled in.
     * @param  string|null  $openUntil  The field a row flagged "new" still needs.
     * @param  Closure(array<string, mixed>, array<string, string>): string  $describe  Row and topic names by id.
     */
    private function compact(Repeater $repeater, string $required, Closure $describe, ?string $openUntil = null): Repeater
    {
        return $repeater
            ->collapsible()
            // A row flagged "new" (added for the user, see addRuleFor()) is filled in but still
            // needs its $openUntil field: it stays open until that is filled too.
            ->collapsed(function (?Schema $item) use ($required, $openUntil): bool {
                $row = $item?->getStateSnapshot() ?? [];

                return filled($row[$required] ?? null)
                    && ! (($row['new'] ?? false) && $openUntil !== null && blank($row[$openUntil] ?? null));
            })
            ->itemLabel(fn (array $state, Get $get): ?string => filled($state[$required] ?? null)
                ? $describe($state, self::topicNames($get('telegram_topics')))
                : null);
    }

    /**
     * Topic names by id from the form's Topics list, for labels.
     *
     * @return array<string, string>
     */
    private static function topicNames(mixed $rows): array
    {
        return collect(self::rows($rows))
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
