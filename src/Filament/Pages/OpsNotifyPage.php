<?php

namespace Spokospace\OpsNotify\Filament\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
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
use Spokospace\OpsNotify\Contracts\ReportsStatus;
use Spokospace\OpsNotify\Enums\DeliveryStatus;
use Spokospace\OpsNotify\Enums\Level;
use Spokospace\OpsNotify\Exceptions\MessageSkipped;
use Spokospace\OpsNotify\Filament\OpsNotifyPlugin;
use Spokospace\OpsNotify\Filament\SettingsForm;
use Spokospace\OpsNotify\Models\OpsNotifyLog;
use Spokospace\OpsNotify\OpsMessage;
use Spokospace\OpsNotify\OpsNotifier;
use Spokospace\OpsNotify\Settings\SettingsStore;
use Throwable;
use UnitEnum;

class OpsNotifyPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $slug = 'ops-notify';

    protected static ?string $title = 'Ops notifications';

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

    protected function getHeaderActions(): array
    {
        return [
            Action::make('settings')
                ->label('Settings')
                ->icon(Heroicon::OutlinedCog6Tooth)
                ->color('gray')
                ->slideOver()
                ->fillForm(fn (): array => app(SettingsForm::class)->fill())
                ->schema(fn (): array => app(SettingsForm::class)->components())
                ->action(function (array $data): void {
                    app(SettingsStore::class)->save(app(SettingsForm::class)->toSettings($data));

                    Notification::make()->success()->title('Settings saved')->send();
                }),

            Action::make('sendTest')
                ->label('Send test')
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->schema([
                    Textarea::make('text')
                        ->label('Message')
                        ->default('If you can read this, notifications work.')
                        ->required()
                        ->maxLength(1000),
                ])
                ->modalSubmitActionLabel('Send')
                ->action(function (array $data): void {
                    $message = OpsMessage::make('ops.test')
                        ->title('Test notification')
                        ->line($data['text'])
                        ->field('Sent by', auth()->user()?->email);

                    $this->deliverNow($message);
                }),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Status')
                ->columns(4)
                ->schema([
                    TextEntry::make('service')
                        ->state(function (): string {
                            app(SettingsStore::class)->apply();

                            return (string) config('ops-notify.service');
                        }),
                    TextEntry::make('enabled')
                        ->badge()
                        ->state(fn (): string => app(OpsNotifier::class)->isEnabled() ? 'Enabled' : 'Disabled')
                        ->color(fn (string $state): string => $state === 'Enabled' ? 'success' : 'danger'),
                    TextEntry::make('channel')
                        ->state(fn (): string => app(OpsNotifier::class)->channels()->defaultChannel()),
                    TextEntry::make('connection')
                        ->state(fn (): string => $this->connectionStatus()),
                ]),
            EmbeddedTable::make(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(OpsNotifyLog::query())
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('When')
                    ->since()
                    ->dateTimeTooltip()
                    ->sortable(),
                TextColumn::make('event')
                    ->searchable(),
                TextColumn::make('level')
                    ->badge(),
                TextColumn::make('title')
                    ->description(fn (OpsNotifyLog $record): ?string => $record->body ? Str::limit($record->body, 120) : null)
                    ->wrap()
                    ->searchable(),
                TextColumn::make('status')
                    ->badge()
                    ->tooltip(fn (OpsNotifyLog $record): ?string => $record->error),
                TextColumn::make('channel')
                    ->formatStateUsing(fn (OpsNotifyLog $record): string => $record->channel.($record->topic ? ' #'.$record->topic : ''))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('attempts')
                    ->numeric()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options(DeliveryStatus::class),
                SelectFilter::make('level')->options(Level::class),
            ])
            ->recordActions([
                Action::make('resend')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->visible(fn (OpsNotifyLog $record): bool => $record->status === DeliveryStatus::Failed)
                    ->action(function (OpsNotifyLog $record): void {
                        if (! $this->deliverNow($record->toMessage(), $resent)) {
                            return;
                        }

                        // Hides Resend on the original row, so it is not sent twice.
                        $record->update([
                            'status' => DeliveryStatus::Resent,
                            'error' => $resent ? "Resent as #{$resent->id}" : 'Resent',
                        ]);
                    }),
            ])
            ->emptyStateHeading('No notifications sent yet');
    }

    /** Shows the outcome as a Filament notification; never throws. */
    private function deliverNow(OpsMessage $message, ?OpsNotifyLog &$log = null): bool
    {
        try {
            $log = app(OpsNotifier::class)->sendNow($message);
        } catch (MessageSkipped $e) {
            Notification::make()->warning()->title('Not sent')->body($e->getMessage())->send();

            return false;
        } catch (Throwable $e) {
            Notification::make()->danger()->title('Not sent')->body($e->getMessage())->send();

            return false;
        }

        Notification::make()->success()->title('Sent')->send();

        return true;
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
            $channel->isConfigured() => 'Configured',
            default => 'Not configured',
        };
    }
}
