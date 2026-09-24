<?php

namespace Spokospace\OpsNotify\Filament\Pages;

use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Spokospace\OpsNotify\Channels\Telegram\TelegramChannel;
use Spokospace\OpsNotify\Exceptions\ChannelException;
use Spokospace\OpsNotify\Filament\OpsNotifyPlugin;
use Spokospace\OpsNotify\Models\OpsNotifyInvite;
use Spokospace\OpsNotify\OpsNotifier;
use Spokospace\OpsNotify\Support\Trans;

/**
 * Invite links to the ops chat: create a link for one person, copy it, revoke it. The Bot API
 * cannot add people to a group, and bots never see phone numbers, so a link is the way in.
 */
class OpsNotifyInvitesPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $slug = 'ops-notify/invites';

    protected static bool $shouldRegisterNavigation = false;

    /** Hours a new link stays valid, offered in the form. */
    private const VALIDITY = [1 => 'invites.hour', 24 => 'invites.day', 168 => 'invites.week'];

    public function getTitle(): string|Htmlable
    {
        return Trans::get('invites.title');
    }

    public static function canAccess(): bool
    {
        return OpsNotifyPlugin::get()->isAuthorized() && OpsNotifyPlugin::get()->canManage();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('createInvite')
                ->label(Trans::get('invites.create'))
                ->icon(Heroicon::OutlinedLink)
                ->modalDescription(Trans::get('invites.rights_help'))
                ->schema([
                    TextInput::make('name')
                        ->label(Trans::get('invites.name'))
                        ->helperText(Trans::get('invites.name_help'))
                        ->required()
                        ->maxLength(32),
                    Select::make('valid_for')
                        ->label(Trans::get('invites.valid_for'))
                        ->options(array_map(fn (string $key): string => Trans::get($key), self::VALIDITY))
                        ->default(24)
                        ->required()
                        ->selectablePlaceholder(false),
                    Toggle::make('single_use')
                        ->label(Trans::get('invites.single_use'))
                        ->helperText(Trans::get('invites.single_use_help'))
                        ->default(true),
                ])
                ->modalSubmitActionLabel(Trans::get('invites.create'))
                ->action(function (array $data): void {
                    $hours = array_key_exists((int) $data['valid_for'], self::VALIDITY) ? (int) $data['valid_for'] : 24;
                    $expiresAt = now()->addHours($hours);
                    $memberLimit = ($data['single_use'] ?? true) ? 1 : null;

                    $link = $this->telegram(fn (TelegramChannel $telegram): string => $telegram->createInviteLink((string) $data['name'], $expiresAt, $memberLimit));

                    if ($link === null) {
                        return;
                    }

                    $invite = OpsNotifyInvite::query()->create([
                        'channel' => app(OpsNotifier::class)->channels()->defaultChannel(),
                        'name' => (string) $data['name'],
                        'invite_link' => $link,
                        'member_limit' => $memberLimit,
                        'expires_at' => $expiresAt,
                        'created_by' => data_get(auth()->user(), 'email'),
                    ]);

                    $this->replaceMountedAction('showInvite', ['invite' => $invite->getKey()]);
                }),
        ];
    }

    /** Shown right after creating: the link, ready to copy. */
    public function showInviteAction(): Action
    {
        return Action::make('showInvite')
            ->modalHeading(Trans::get('invites.created'))
            ->modalDescription(Trans::get('invites.copy_help'))
            ->schema(fn (array $arguments): array => [
                TextEntry::make('link')
                    ->label(Trans::get('invites.link'))
                    ->state(is_numeric($id = $arguments['invite'] ?? null)
                        ? OpsNotifyInvite::query()->whereKey((int) $id)->first()?->invite_link
                        : null)
                    ->copyable()
                    ->copyMessage(Trans::get('invites.copied')),
            ])
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(Trans::get('invites.close'));
    }

    /**
     * Runs one Telegram call; on a refusal, says why (which admin right is missing, if that is
     * the reason) and returns null.
     *
     * @template T
     *
     * @param  Closure(TelegramChannel): T  $call
     * @return T|null
     */
    private function telegram(Closure $call): mixed
    {
        $telegram = app(OpsNotifier::class)->telegram();

        try {
            return $call($telegram);
        } catch (ChannelException $e) {
            Notification::make()->danger()->title(Trans::get('invites.failed'))->body($telegram->explain($e, 'invite_users'))->send();

            return null;
        }
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(OpsNotifyInvite::query())
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('name')
                    ->label(Trans::get('invites.name'))
                    ->searchable(),
                TextColumn::make('invite_link')
                    ->label(Trans::get('invites.link'))
                    ->limit(28)
                    ->copyable(fn (OpsNotifyInvite $record): bool => $record->state() === OpsNotifyInvite::STATE_ACTIVE)
                    ->copyMessage(Trans::get('invites.copied')),
                TextColumn::make('state')
                    ->label(Trans::get('invites.state'))
                    ->badge()
                    ->state(fn (OpsNotifyInvite $record): string => $record->state())
                    ->formatStateUsing(fn (string $state): string => Trans::get("invites.{$state}"))
                    ->color(fn (string $state): string => match ($state) {
                        OpsNotifyInvite::STATE_ACTIVE => 'success',
                        OpsNotifyInvite::STATE_REVOKED => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('expires_at')
                    ->label(Trans::get('invites.expires'))
                    ->since()
                    ->dateTimeTooltip(),
                TextColumn::make('created_at')
                    ->label(Trans::get('table.when'))
                    ->since()
                    ->dateTimeTooltip()
                    ->sortable(),
                TextColumn::make('created_by')
                    ->label(Trans::get('invites.created_by'))
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                Action::make('revoke')
                    ->label(Trans::get('invites.revoke'))
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (OpsNotifyInvite $record): bool => $record->state() === OpsNotifyInvite::STATE_ACTIVE)
                    ->action(function (OpsNotifyInvite $record): void {
                        if ($this->telegram(function (TelegramChannel $telegram) use ($record): bool {
                            $telegram->revokeInviteLink($record->invite_link);

                            return true;
                        }) === null) {
                            return;
                        }

                        $record->update(['revoked_at' => now()]);
                        Notification::make()->success()->title(Trans::get('invites.revoked_notice'))->send();
                    }),
            ])
            ->emptyStateHeading(Trans::get('invites.empty'));
    }
}
