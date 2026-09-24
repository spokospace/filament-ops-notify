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
 * @phpstan-type TriedRow array{template: MessageTemplate, message: OpsMessage, log: OpsNotifyLog|null, service: string, parts: array<string, mixed>}
 */
class SettingsForm
{
    /** The Routing section, relative to a field in a rule row (row → Rules list → section). */
    private const ROUTING_SECTION = '../../';

    /** The Routing section, relative to a field in a topic row (row → Topics list → section → form). */
    private const ROUTING_FROM_TOPIC = '../../../'.self::ROUTING_KEY;

    /** The Routing section's key. */
    private const ROUTING_KEY = 'routing';

    /** The Templates list, relative to a field in a template row (row → list). */
    private const TEMPLATES_LIST = '../';

    /** The form root, relative to a field in a template row (row → list → root). */
    private const FORM_ROOT = '../../';

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

    /** @var array<string, TriedRow> What a row's fields and preview show, by row and service, see tried(). */
    private array $tried = [];

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
                                // Every change re-renders the list: the message under each row, and
                                // the chips and result lines, which come from that message.
                                $this->refreshesTemplates(TextInput::make('pattern')
                                    ->label(Trans::get('settings.pattern'))
                                    ->required()
                                    ->placeholder('inquiry.*')
                                    ->datalist($patterns)
                                    ->distinct()
                                    ->live(onBlur: true))
                                    ->columnSpanFull(),
                                // A new row starts as an example template in the message language, so
                                // the text shows what is fixed and what a placeholder is.
                                $this->refreshesTemplates(TextInput::make('title')
                                    ->label(Trans::get('settings.template_title'))
                                    ->default(fn (): string => $this->messageText('settings.template_default_title'))
                                    ->placeholder(':title')
                                    ->maxLength(500)
                                    ->live(onBlur: true))
                                    ->aboveContent(fn (Get $get): Htmlable => $this->placeholderChips($get))
                                    // helperText() is a Text in belowContent too, so the hint keeps its look.
                                    ->belowContent(fn (Get $get): array => [$this->renderedPart('title', $get), Text::make(Trans::get('settings.template_placeholders'))])
                                    ->columnSpanFull(),
                                $this->refreshesTemplates(Textarea::make('body')
                                    ->label(Trans::get('settings.template_body'))
                                    ->default(fn (): string => $this->messageText('settings.template_default_body'))
                                    ->placeholder(':body')
                                    ->rows(3)
                                    ->maxLength(3000)
                                    ->live(onBlur: true))
                                    ->aboveContent(fn (Get $get): Htmlable => $this->placeholderChips($get))
                                    ->belowContent(fn (Get $get): array => [$this->renderedPart('body', $get), Text::make(Trans::get('settings.template_body_help'))])
                                    ->visible(fn (Get $get): bool => (bool) $get('show_body'))
                                    ->columnSpanFull(),
                                $this->refreshesTemplates(TagsInput::make('fields')
                                    ->label(Trans::get('settings.template_fields'))
                                    ->helperText(Trans::get('settings.template_fields_help'))
                                    ->live())
                                    ->visible(fn (Get $get): bool => (bool) $get('show_fields'))
                                    ->columnSpanFull(),
                                ...array_map(fn (string $name): Field => $this->refreshesTemplates(
                                    Toggle::make($name)->label(Trans::get("settings.template_{$name}"))->default(true)->inline(false)->live(),
                                ), ['show_body', 'show_fields', 'hashtag', 'service']),
                                Text::make(fn (Get $get, Text $component): Htmlable => $this->preview($get, (string) str($component->getContainer()->getStatePath())->afterLast('.')))
                                    ->key(self::TEMPLATE_PREVIEW)
                                    ->columnSpanFull(),
                            ])
                            ->columns(4)
                            ->defaultItems(0)
                            ->extraItemActions([$this->defaultTemplateAction()])
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
                ->map(fn (mixed $data, string|int $pattern): array => self::row((string) $pattern, MessageTemplate::fromArray($data)))
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
        $topics = self::topicNames($get);
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
                    'topic' => self::topicLabel($rule, self::topicNames($get)),
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

    /** Resets a row to the default layout: ":title", ":body", every field and every part on. */
    private function defaultTemplateAction(): Action
    {
        return Action::make('defaultTemplate')
            ->label(Trans::get('settings.template_default'))
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('gray')
            ->action(function (array $arguments, Repeater $component): void {
                $key = (string) ($arguments['item'] ?? '');

                if (array_key_exists($key, self::rows($component->getRawState()))) {
                    $component->getChildSchema($key)?->fill(self::row((string) (self::rows($component->getRawItemState($key))['pattern'] ?? ''), new MessageTemplate));
                }
            });
    }

    /**
     * A template in form shape: a row of the Templates list. A part switched off is show_body or
     * show_fields false, and the default title and body are spelled out, so a field is never
     * blank. The one place that knows the row's keys: template() reads them back.
     *
     * @return array<string, mixed>
     */
    private static function row(string $pattern, MessageTemplate $template): array
    {
        return [
            'pattern' => $pattern,
            'title' => $template->title ?? MessageTemplate::DEFAULT_TITLE,
            'body' => $template->body === null ? MessageTemplate::DEFAULT_BODY : ($template->body ?: null),
            'show_body' => $template->body !== false,
            'fields' => $template->fields ?? [],
            'show_fields' => $template->fields !== [],
            'hashtag' => $template->hashtag,
            'service' => $template->service,
        ];
    }

    /** @return array<string, mixed> The row a field's Get sees. */
    private function rowFrom(Get $get): array
    {
        $row = [];

        foreach (array_keys(self::row('', new MessageTemplate)) as $key) {
            $row[$key] = $get($key);
        }

        return $row;
    }

    /** A package string in the message language, e.g. the example template of a new row. */
    private function messageText(string $key): string
    {
        return app(OpsNotifier::class)->inMessageLocale(fn (): string => Trans::get($key));
    }

    /**
     * What a row's fields and preview show: the row as a template, the message it is tried on
     * and the title and body as the chat would show them. Memoised per row and Service name,
     * since the two result lines and the preview of a row all need it.
     *
     * @return TriedRow
     */
    private function tried(Get $get): array
    {
        $row = $this->rowFrom($get);
        $service = $this->serviceName($get);

        return $this->tried[serialize([$row, $service])] ??= (function () use ($row, $service): array {
            [$message, $log] = $this->example((string) ($row['pattern'] ?? ''));
            $template = self::template($row);

            return [
                'template' => $template,
                'message' => $message,
                'log' => $log,
                'service' => $service,
                'parts' => app(OpsNotifier::class)->inMessageLocale(fn () => (new TelegramFormatter)->parts($message, $service, $template)),
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
    private function placeholderChips(Get $get): Htmlable
    {
        $chips = array_map(
            fn (string $token): string => '<button type="button" class="fi-badge fi-size-sm fi-color fi-color-gray" x-on:click="'.e(self::INSERT_PLACEHOLDER).'" data-token="'.e($token).'">'.e($token).'</button>',
            MessageTemplate::placeholders($this->tried($get)['message']),
        );

        return new HtmlString('<div style="display: flex; flex-wrap: wrap; gap: .375rem; margin-bottom: .375rem">'.implode('', $chips).'</div>');
    }

    /** The row's title or body rendered on the message it is tried on, shown under the field. */
    private function renderedPart(string $part, Get $get): Text
    {
        return Text::make(new HtmlString('<span style="white-space: pre-wrap; overflow-wrap: anywhere">→ '.e($this->tried($get)['parts'][$part]).'</span>'))->size('sm');
    }

    /** The Service name as typed in the form, or the saved one when the field is blank. */
    private function serviceName(Get $get): string
    {
        $service = $get(self::FORM_ROOT.'service');

        return filled($service) ? trim((string) $service) : (string) config('ops-notify.service');
    }

    /**
     * The row rendered on the message it is tried on, as the chat would show it: where that
     * message comes from, a warning when a row above matches its event first (that one would
     * be used instead), then the message.
     *
     * @param  string  $key  The row's key in the Templates list.
     */
    private function preview(Get $get, string $key): Htmlable
    {
        ['message' => $message, 'log' => $log, 'template' => $template, 'service' => $service] = $this->tried($get);
        $pattern = trim((string) $get('pattern'));
        $note = fn (string $text, string $color = 'gray'): string => '<p style="color: var(--'.$color.'-500); margin-bottom: .75rem">'.e($text).'</p>';

        // The formatter escapes every part and adds only <b> tags, so its output is safe HTML.
        $text = app(OpsNotifier::class)->inMessageLocale(fn (): string => (new TelegramFormatter)->format($message, $service, template: $template));

        $rows = self::rows($get(self::TEMPLATES_LIST));
        $position = array_search($key, array_map(strval(...), array_keys($rows)), true);
        $earlier = array_map(fn (mixed $row): string => trim((string) (self::rows($row)['pattern'] ?? '')), array_slice($rows, 0, $position === false ? 0 : $position));
        $first = $log === null ? null : PatternMap::firstKey(array_fill_keys(array_filter($earlier), true), $log->event);
        $source = match (true) {
            $pattern === '' => Trans::get('settings.preview_no_pattern'),
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
     * A template field whose changes re-render the Templates list alone: the chips, the result
     * lines and the message under every row. The list, not the row: a key inside a repeater row
     * cannot be resolved while the form is being filled.
     */
    private function refreshesTemplates(Field $field): Field
    {
        return $field->partiallyRenderComponentsAfterStateUpdated([self::TEMPLATES_LIST]);
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
