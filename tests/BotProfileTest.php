<?php

use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spokospace\OpsNotify\ChannelManager;
use Spokospace\OpsNotify\Filament\Pages\OpsNotifyPage;
use Spokospace\OpsNotify\Support\BotAvatars;

function fakeBotProfile(array $overrides = []): void
{
    Http::fake([
        '*/getMe' => Http::response(['ok' => true, 'result' => ['username' => 'shop_bot']]),
        '*/getMyName' => Http::response(['ok' => true, 'result' => ['name' => 'Shop Bot']]),
        '*/getMyShortDescription' => Http::response(['ok' => true, 'result' => ['short_description' => 'Ops alerts']]),
        '*/getMyDescription' => Http::response(['ok' => true, 'result' => ['description' => '']]),
        '*' => Http::response(['ok' => true, 'result' => true]),
        ...$overrides,
    ]);
}

function sentMethods(): array
{
    return Http::recorded()->map(fn ($pair) => basename($pair[0]->url()))->all();
}

it('ships preset avatars as 640x640 jpegs with thumbnails', function () {
    expect(BotAvatars::keys())->toBe(['flat', 'headset', 'helmet', 'mascot', 'pixel', 's-helmet', 's-visor', 'space-headset']);

    foreach (BotAvatars::keys() as $key) {
        [$width, $height, $type] = getimagesize(BotAvatars::path($key));
        expect([$width, $height, $type])->toBe([640, 640, IMAGETYPE_JPEG])
            ->and(file_exists(BotAvatars::path($key, thumbnail: true)))->toBeTrue();
    }
});

it('serves preset thumbnails and rejects unknown keys', function () {
    $this->get(route('ops-notify.avatar', 's-helmet'))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg');

    $this->get('/ops-notify/avatars/nope.jpg')->assertNotFound();
});

it('converts an uploaded png into a square jpeg', function () {
    $png = imagecreatetruecolor(800, 500);
    ob_start();
    imagepng($png);
    $jpeg = BotAvatars::toJpeg((string) ob_get_clean());

    [$width, $height, $type] = getimagesizefromstring($jpeg);
    expect([$width, $height, $type])->toBe([640, 640, IMAGETYPE_JPEG]);
})->skip(! function_exists('imagecreatetruecolor'), 'GD is not installed');

it('updates only the profile fields that changed', function () {
    fakeBotProfile();

    $changed = app(ChannelManager::class)->telegram()->updateProfile([
        'name' => 'Shop Bot',               // unchanged
        'short_description' => 'Ops alerts for the shop',
        'description' => '',                // unchanged
    ]);

    expect($changed)->toBe(['short_description'])
        ->and(sentMethods())->not->toContain('setMyName', 'setMyDescription')
        ->and(sentMethods())->toContain('setMyShortDescription');
});

it('uploads the profile photo as multipart', function () {
    fakeBotProfile();

    app(ChannelManager::class)->telegram()->setProfilePhoto('JPEGDATA');

    Http::assertSent(function (Request $request) {
        if (! str_ends_with($request->url(), '/setMyProfilePhoto')) {
            return false;
        }

        $parts = collect($request->data())->keyBy('name');

        return $request->isMultipart()
            && json_decode($parts['photo']['contents'], true) === ['type' => 'static', 'photo' => 'attach://avatar']
            && $parts['avatar']['contents'] === 'JPEGDATA';
    });
});

describe('bot profile slide-over', function () {
    beforeEach(function () {
        Filament::setCurrentPanel('admin');
        $this->actingAs($this->admin());
    });

    it('loads the profile from telegram and applies a preset avatar and a new name', function () {
        fakeBotProfile();

        Livewire::test(OpsNotifyPage::class)
            ->mountAction('botProfile')
            ->assertSchemaStateSet(['name' => 'Shop Bot', 'short_description' => 'Ops alerts'], 'mountedActionSchema0')
            ->fillForm(['avatar' => 's-visor', 'name' => 'Shop Ops'], 'mountedActionSchema0')
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('Bot profile updated');

        expect(sentMethods())->toContain('setMyName', 'setMyProfilePhoto')
            ->not->toContain('setMyShortDescription', 'setMyDescription')
            // The values loaded when the form opened are reused on save, not fetched again.
            ->and(array_count_values(sentMethods())['getMyName'])->toBe(1);
    });

    it('uploads a custom image as the avatar', function () {
        fakeBotProfile();

        Livewire::test(OpsNotifyPage::class)
            ->mountAction('botProfile')
            ->fillForm([
                'avatar' => BotAvatars::CUSTOM,
                'avatar_upload' => UploadedFile::fake()->image('logo.png', 800, 500),
            ], 'mountedActionSchema0')
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('Bot profile updated');

        Http::assertSent(function (Request $request) {
            if (! str_ends_with($request->url(), '/setMyProfilePhoto')) {
                return false;
            }

            $jpeg = collect($request->data())->firstWhere('name', 'avatar')['contents'];

            return array_slice(getimagesizefromstring($jpeg), 0, 3) === [640, 640, IMAGETYPE_JPEG];
        });
    })->skip(! function_exists('imagecreatetruecolor'), 'GD is not installed');

    it('says so when nothing changed', function () {
        fakeBotProfile();

        Livewire::test(OpsNotifyPage::class)
            ->callAction('botProfile', data: ['avatar' => null, 'name' => 'Shop Bot', 'short_description' => 'Ops alerts', 'description' => ''])
            ->assertNotified('Nothing to change');

        expect(sentMethods())->not->toContain('setMyProfilePhoto', 'setMyName');
    });

    it('removes the avatar', function () {
        fakeBotProfile();

        Livewire::test(OpsNotifyPage::class)
            ->mountAction('botProfile')
            ->callAction(TestAction::make('removeAvatar')->schemaComponent('avatar', 'mountedActionSchema0'))
            ->assertNotified('Avatar removed');

        expect(sentMethods())->toContain('removeMyProfilePhoto');
    });

    it('is hidden until telegram is configured', function () {
        config(['ops-notify.channels.telegram.bot_token' => null]);
        app(ChannelManager::class)->forgetChannels();

        Livewire::test(OpsNotifyPage::class)->assertActionHidden('botProfile');
    });
});
