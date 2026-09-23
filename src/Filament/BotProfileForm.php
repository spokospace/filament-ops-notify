<?php

namespace Spokospace\OpsNotify\Filament;

use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ViewField;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Arr;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Spokospace\OpsNotify\Channels\Telegram\TelegramChannel;
use Spokospace\OpsNotify\Exceptions\ChannelException;
use Spokospace\OpsNotify\OpsNotifier;
use Spokospace\OpsNotify\Support\BotAvatars;
use Spokospace\OpsNotify\Support\Trans;
use Throwable;

/**
 * The "Bot profile" slide-over: the bot's avatar, name and descriptions, read from and written
 * to Telegram directly (they live there, not in the package settings).
 */
class BotProfileForm
{
    private const PROFILE_FIELDS = ['name', 'short_description', 'description'];

    /** @return array<int, mixed> */
    public function components(): array
    {
        $isCustom = fn (Get $get): bool => $get('avatar') === BotAvatars::CUSTOM;

        return [
            Section::make(Trans::get('profile.avatar'))
                ->key('avatar')
                ->description(Trans::get('profile.avatar_help'))
                ->headerActions([$this->removeAvatarAction()])
                ->schema([
                    ViewField::make('avatar')
                        ->hiddenLabel()
                        ->view('ops-notify::forms.avatar-picker')
                        ->viewData([
                            'avatars' => collect(BotAvatars::keys())->mapWithKeys(fn (string $key): array => [$key => route('ops-notify.avatar', $key)])->all(),
                            'keepLabel' => Trans::get('profile.keep'),
                            'customLabel' => Trans::get('profile.custom'),
                            'customValue' => BotAvatars::CUSTOM,
                        ]),
                    // Never stored: the temporary upload is read, sent to Telegram and left to
                    // Livewire's own cleanup, so a cancelled form leaves nothing behind.
                    FileUpload::make('avatar_upload')
                        ->label(Trans::get('profile.upload'))
                        ->helperText(Trans::get('profile.upload_help'))
                        ->visible($isCustom)
                        ->required($isCustom)
                        ->storeFiles(false)
                        ->avatar()
                        // avatar() adds a 1:1 dimensions rule; drop it (see below).
                        ->imageAspectRatio(null)
                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                        ->maxSize(5120)
                        // "cover" at 640x640 fills and crops in the browser, so the upload is already
                        // square. No imageAspectRatio() rule on purpose: BotAvatars::toJpeg()
                        // centre-crops anything that still arrives non-square.
                        ->automaticallyResizeImagesMode('cover')
                        ->automaticallyResizeImagesToWidth((string) BotAvatars::SIZE)
                        ->automaticallyResizeImagesToHeight((string) BotAvatars::SIZE),
                ]),

            Section::make(Trans::get('profile.details'))
                ->schema([
                    TextInput::make('name')
                        ->label(Trans::get('profile.name'))
                        ->maxLength(64),
                    Textarea::make('short_description')
                        ->label(Trans::get('profile.short_description'))
                        ->helperText(Trans::get('profile.short_description_help'))
                        ->maxLength(120)
                        ->rows(2),
                    Textarea::make('description')
                        ->label(Trans::get('profile.description'))
                        ->helperText(Trans::get('profile.description_help'))
                        ->maxLength(512)
                        ->rows(4),
                    // Telegram's values when the form opened, so saving does not fetch them again.
                    Hidden::make('original'),
                ]),
        ];
    }

    /** @return array<string, mixed> The current profile, read from Telegram. */
    public function fill(): array
    {
        try {
            $profile = $this->telegram()->getProfile();
        } catch (Throwable $e) {
            Notification::make()->warning()->title(Trans::get('profile.load_failed'))->body($e->getMessage())->send();

            return ['avatar' => null];
        }

        return ['avatar' => null, ...$profile, 'original' => $profile];
    }

    /** @param  array<string, mixed>  $data */
    public function apply(array $data): void
    {
        try {
            $telegram = $this->telegram();
            // The profile as loaded when the form opened (a hidden field), so only real changes are sent.
            $original = is_array($loaded = $data['original'] ?? null) ? [
                'name' => (string) ($loaded['name'] ?? ''),
                'short_description' => (string) ($loaded['short_description'] ?? ''),
                'description' => (string) ($loaded['description'] ?? ''),
            ] : null;
            $changed = $telegram->updateProfile(Arr::only($data, self::PROFILE_FIELDS), $original);

            if (filled($jpeg = $this->avatarJpeg($data['avatar'] ?? null, $data['avatar_upload'] ?? null))) {
                $telegram->setProfilePhoto($jpeg);
                $changed[] = 'avatar';
            }
        } catch (Throwable $e) {
            Notification::make()->danger()->title(Trans::get('profile.failed'))->body($e->getMessage())->send();

            return;
        }

        $changed === []
            ? Notification::make()->info()->title(Trans::get('profile.nothing_changed'))->send()
            : Notification::make()->success()->title(Trans::get('profile.updated'))->send();
    }

    /** The chosen avatar as JPEG, or null to leave the current one. */
    private function avatarJpeg(?string $choice, mixed $upload): ?string
    {
        $upload = is_array($upload) ? Arr::first($upload) : $upload;

        return match (true) {
            $choice === BotAvatars::CUSTOM && $upload instanceof TemporaryUploadedFile => BotAvatars::toJpeg((string) $upload->get()),
            filled($choice) && BotAvatars::exists((string) $choice) => (string) file_get_contents(BotAvatars::path((string) $choice)),
            default => null,
        };
    }

    private function removeAvatarAction(): Action
    {
        return Action::make('removeAvatar')
            ->label(Trans::get('profile.remove_avatar'))
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->requiresConfirmation()
            ->action(function (): void {
                try {
                    $this->telegram()->removeProfilePhoto();
                } catch (ChannelException $e) {
                    Notification::make()->danger()->title(Trans::get('profile.failed'))->body($e->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title(Trans::get('profile.avatar_removed'))->send();
            });
    }

    private function telegram(): TelegramChannel
    {
        return app(OpsNotifier::class)->telegram();
    }
}
