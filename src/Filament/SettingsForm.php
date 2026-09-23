<?php

namespace Spokospace\OpsNotify\Filament;

use Filament\Forms\Components\Field;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
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
                        TextInput::make('telegram_topic')->label('Default topic id')->integer(),
                        'Forum topic for events without their own.',
                    ),
                    $this->locked(Toggle::make('enabled')->label('Notifications enabled')),
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
                                TextInput::make('topic')->label('Topic id')->integer(),
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

    /** Disables a field whose value comes from .env/config, and says so. */
    private function locked(Field $field, ?string $hint = null): Field
    {
        $isLocked = $this->store->isLocked($field->getName());

        return $field
            ->disabled($isLocked)
            ->helperText($isLocked ? 'Set in .env or config/ops-notify.php, change it there.' : $hint);
    }
}
