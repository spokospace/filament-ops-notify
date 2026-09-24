<?php

namespace Spokospace\OpsNotify\Filament\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Str;
use Spokospace\OpsNotify\Contracts\LabelsTopics;
use Spokospace\OpsNotify\Contracts\ReportsStatus;
use Spokospace\OpsNotify\Enums\DeliveryStatus;
use Spokospace\OpsNotify\Enums\Level;
use Spokospace\OpsNotify\Exceptions\MessageSkipped;
use Spokospace\OpsNotify\Filament\BotProfileForm;
use Spokospace\OpsNotify\Filament\OpsNotifyPlugin;
use Spokospace\OpsNotify\Filament\SettingsForm;
use Spokospace\OpsNotify\Models\OpsNotifyLog;
use Spokospace\OpsNotify\OpsMessage;
use Spokospace\OpsNotify\OpsNotifier;
use Spokospace\OpsNotify\Settings\SettingsStore;
use Spokospace\OpsNotify\Support\QueueStatus;
use Spokospace\OpsNotify\Support\Trans;
use Throwable;
use UnitEnum;

class OpsNotifyPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $slug = 'ops-notify';

    public static function getNavigationLabel(): string
    {
        return OpsNotifyPlugin::get()->getNavigationLabel();
    }

    public function getTitle(): string|Htmlable
    {
        return OpsNotifyPlugin::get()->getNavigationLabel();
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return OpsNotifyPlugin::get()->getNavigationGroup();
    }

    public static function getNavigationSort(): ?int
    {
        return OpsNotifyPlugin::get()->getNavigationSort();
    }

    public static function getNavigationIcon(): string|BackedEnum|Htmlable|null
    {
        return OpsNotifyPlugin::get()->getNavigationIcon();
    }

    public static function canAccess(): bool
    {
        return OpsNotifyPlugin::get()->isAuthorized();
    }

    /** Settings, Bot profile, Send test and Resend; everyone who can open the page sees the rest. */
    public static function canManage(): bool
    {
        return OpsNotifyPlugin::get()->canManage();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('settings')
                ->authorize(fn (): bool => static::canManage())
                ->label(Trans::get('actions.settings'))
                ->icon(Heroicon::OutlinedCog6Tooth)
                ->color('gray')
                ->slideOver()
                ->modalSubmitActionLabel(Trans::get('actions.save'))
                ->fillForm(fn (): array => app(SettingsForm::class)->fill())
                ->schema(fn (): array => app(SettingsForm::class)->components())
                ->action(function (array $data): void {
                    app(SettingsStore::class)->save(app(SettingsForm::class)->toSettings($data));

                    Notification::make()->success()->title(Trans::get('actions.settings_saved'))->send();
                }),

            Action::make('botProfile')
                ->authorize(fn (): bool => static::canManage())
                ->label(Trans::get('profile.title'))
                ->icon(Heroicon::OutlinedUserCircle)
                ->color('gray')
                ->slideOver()
                ->visible(fn (): bool => $this->telegramIsConfigured())
                ->modalSubmitActionLabel(Trans::get('profile.apply'))
                ->fillForm(fn (): array => app(BotProfileForm::class)->fill())
                ->schema(fn (): array => app(BotProfileForm::class)->components())
                ->action(fn (array $data) => app(BotProfileForm::class)->apply($data)),

            Action::make('invites')
                ->authorize(fn (): bool => static::canManage())
                ->label(Trans::get('invites.title'))
                ->icon(Heroicon::OutlinedLink)
                ->color('gray')
                ->visible(fn (): bool => $this->telegramIsConfigured())
                ->url(fn (): string => OpsNotifyInvitesPage::getUrl()),

            Action::make('sendTest')
                ->authorize(fn (): bool => static::canManage())
                ->label(Trans::get('actions.send_test'))
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->schema([
                    Textarea::make('text')
                        ->label(Trans::get('actions.message'))
                        ->default(Trans::get('actions.test_default_text'))
                        ->required()
                        ->maxLength(1000),
                    // Hidden on the sync queue, where "through the queue" and "right away" are the same.
                    Toggle::make('via_queue')
                        ->label(Trans::get('actions.via_queue'))
                        ->helperText(Trans::get('actions.via_queue_help'))
                        ->default(true)
                        ->visible(fn (): bool => ! app(QueueStatus::class)->isSync()),
                ])
                ->modalSubmitActionLabel(Trans::get('actions.send'))
                ->action(function (array $data): void {
                    $message = app(OpsNotifier::class)->inMessageLocale(fn (): OpsMessage => OpsMessage::make('ops.test')
                        ->title(Trans::get('message.test_title'))
                        ->line($data['text'])
                        // Any Authenticatable: not every user model has an email attribute.
                        ->field(Trans::get('message.sent_by'), data_get(auth()->user(), 'email')));

                    ($data['via_queue'] ?? false) ? $this->queue($message) : $this->deliverNow($message);
                }),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(Trans::get('page.status'))
                ->columns(['default' => 1, 'sm' => 2, 'xl' => 5])
                ->schema([
                    TextEntry::make('service')
                        ->label(Trans::get('page.service'))
                        ->state(function (): string {
                            app(SettingsStore::class)->apply();

                            return (string) config('ops-notify.service');
                        }),
                    TextEntry::make('enabled')
                        ->label(Trans::get('page.enabled'))
                        ->badge()
                        ->state(fn (): bool => app(OpsNotifier::class)->isEnabled())
                        ->formatStateUsing(fn (bool $state): string => Trans::get($state ? 'page.enabled' : 'page.disabled'))
                        ->color(fn (bool $state): string => $state ? 'success' : 'danger'),
                    TextEntry::make('channel')
                        ->label(Trans::get('page.channel'))
                        ->state(fn (): string => app(OpsNotifier::class)->channels()->defaultChannel()),
                    TextEntry::make('connection')
                        ->label(Trans::get('page.connection'))
                        ->state(fn (): string => $this->connectionStatus()),
                    TextEntry::make('delivery')
                        ->label(Trans::get('page.delivery'))
                        ->state(fn (): string => app(QueueStatus::class)->summary()),
                    TextEntry::make('delivery_warning')
                        ->hiddenLabel()
                        ->columnSpanFull()
                        ->icon(Heroicon::OutlinedExclamationTriangle)
                        ->iconColor('warning')
                        ->state(fn (): ?string => app(QueueStatus::class)->warning())
                        ->visible(fn (?string $state): bool => filled($state)),
                ]),
            EmbeddedTable::make(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(OpsNotifyLog::query())
            ->defaultSort('id', 'desc')
            // Watch queued rows turn Sent (or Failed) without reloading; idle otherwise.
            ->poll(fn (): ?string => OpsNotifyLog::query()->where('status', DeliveryStatus::Queued)->exists() ? '5s' : null)
            ->columns([
                TextColumn::make('created_at')
                    ->label(Trans::get('table.when'))
                    ->since()
                    ->dateTimeTooltip()
                    ->sortable(),
                TextColumn::make('event')
                    ->label(Trans::get('table.event'))
                    ->searchable(),
                TextColumn::make('level')
                    ->label(Trans::get('table.level'))
                    ->badge(),
                TextColumn::make('title')
                    ->label(Trans::get('table.title'))
                    ->description(fn (OpsNotifyLog $record): ?string => $record->body ? Str::limit($record->body, 120) : null)
                    ->wrap()
                    ->searchable(),
                TextColumn::make('status')
                    ->label(Trans::get('table.status'))
                    ->badge()
                    ->tooltip(fn (OpsNotifyLog $record): ?string => match (true) {
                        $record->status === DeliveryStatus::Resent && filled($record->error) => Trans::get('table.resent_as', ['id' => $record->error]),
                        $record->status === DeliveryStatus::Suppressed => Trans::get('table.suppressed_help'),
                        default => $record->error,
                    }),
                TextColumn::make('channel')
                    ->label(Trans::get('table.channel_topic'))
                    ->formatStateUsing(fn (OpsNotifyLog $record): string => $record->channel.($record->topic ? ' · '.$this->topicLabel($record->channel, $record->topic) : ''))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('attempts')
                    ->label(Trans::get('table.attempts'))
                    ->numeric()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->label(Trans::get('table.status'))->options(DeliveryStatus::class),
                SelectFilter::make('level')->label(Trans::get('table.level'))->options(Level::class),
            ])
            ->recordActions([
                Action::make('resend')
                    ->authorize(fn (): bool => static::canManage())
                    ->label(Trans::get('actions.resend'))
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->visible(fn (OpsNotifyLog $record): bool => $record->status === DeliveryStatus::Failed)
                    ->action(function (OpsNotifyLog $record): void {
                        // Same path as real messages: through the queue unless it is sync.
                        $sent = app(QueueStatus::class)->isSync()
                            ? $this->deliverNow($record->toMessage(), $resent)
                            : $this->queue($record->toMessage(), $resent);

                        if (! $sent) {
                            return;
                        }

                        // Hides Resend on the original row, so it is not sent twice. The new row's id
                        // is stored bare and put into words when displayed, in the viewer's language.
                        $record->update([
                            'status' => DeliveryStatus::Resent,
                            'error' => $resent ? (string) $resent->id : null,
                        ]);
                    }),
            ])
            ->emptyStateHeading(Trans::get('table.empty'));
    }

    /** Sends right away and shows the outcome as a Filament notification; never throws. */
    private function deliverNow(OpsMessage $message, ?OpsNotifyLog &$log = null): bool
    {
        try {
            $log = app(OpsNotifier::class)->sendNow($message);
        } catch (MessageSkipped $e) {
            Notification::make()->warning()->title(Trans::get('actions.not_sent'))->body($e->getMessage())->send();

            return false;
        } catch (Throwable $e) {
            Notification::make()->danger()->title(Trans::get('actions.not_sent'))->body($e->getMessage())->send();

            return false;
        }

        Notification::make()->success()->title(Trans::get('actions.sent'))->send();

        return true;
    }

    /** Queues the message like any real one, so the worker is tested too; never throws. */
    private function queue(OpsMessage $message, ?OpsNotifyLog &$log = null): bool
    {
        $notifier = app(OpsNotifier::class);

        if ($notifier->destinationFor($message) === null) {
            Notification::make()->warning()->title(Trans::get('actions.not_sent'))->body(MessageSkipped::for($message->event)->getMessage())->send();

            return false;
        }

        // send() logs and swallows its own errors; the row it returns shows where the message is.
        $log = $notifier->send($message);

        Notification::make()->success()->title(Trans::get('actions.queued'))->body(Trans::get('actions.queued_body'))->send();

        return true;
    }

    /** Asks the row's channel for a readable topic name, e.g. "Inquiries #3". */
    private function topicLabel(string $channel, string $id): string
    {
        try {
            $resolved = app(OpsNotifier::class)->channels()->channel($channel);
        } catch (Throwable) {
            return "#{$id}";
        }

        return $resolved instanceof LabelsTopics ? $resolved->topicLabel($id) : "#{$id}";
    }

    /** The bot profile can only be edited when the Telegram channel has a token and chat. */
    private function telegramIsConfigured(): bool
    {
        try {
            return app(OpsNotifier::class)->telegram()->isConfigured();
        } catch (Throwable) {
            return false;
        }
    }

    private function connectionStatus(): string
    {
        try {
            $channel = app(OpsNotifier::class)->channels()->channel();
        } catch (Throwable $e) {
            return $e->getMessage();
        }

        return match (true) {
            $channel instanceof ReportsStatus => $channel->status(),
            $channel->isConfigured() => Trans::get('page.configured'),
            default => Trans::get('page.not_configured'),
        };
    }
}
