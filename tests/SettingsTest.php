<?php

use Filament\Facades\Filament;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Spokospace\OpsNotify\ChannelManager;
use Spokospace\OpsNotify\Exceptions\MessageSkipped;
use Spokospace\OpsNotify\Filament\Pages\OpsNotifyPage;
use Spokospace\OpsNotify\Models\OpsNotifySetting;
use Spokospace\OpsNotify\OpsMessage;
use Spokospace\OpsNotify\OpsNotifier;
use Spokospace\OpsNotify\Settings\SettingsStore;

beforeEach(function () {
    // Nothing from .env, so every field is editable in the panel.
    config([
        'ops-notify.channels.telegram.bot_token' => null,
        'ops-notify.channels.telegram.chat_id' => null,
    ]);
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1, 'username' => 'panel_polo_bot']])]);
});

function store(): SettingsStore
{
    return app(SettingsStore::class);
}

/** Simulates a new PHP process: config as loaded from files (no overlay) and fresh singletons. */
function freshProcess(): void
{
    config([
        'ops-notify.channels.telegram.bot_token' => null,
        'ops-notify.channels.telegram.chat_id' => null,
        'ops-notify.channels.telegram.topic' => null,
    ]);

    foreach ([SettingsStore::class, ChannelManager::class, OpsNotifier::class] as $singleton) {
        app()->forgetInstance($singleton);
    }
}

it('sends with the token and chat saved in the panel', function () {
    store()->save(['telegram_bot_token' => '999:PANEL', 'telegram_chat_id' => '-100555', 'telegram_topic' => '3']);

    OpsMessage::make('x')->sendNow();

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/bot999:PANEL/')
        && $request['chat_id'] === '-100555'
        && $request['message_thread_id'] === 3);
});

it('stores the token encrypted and never returns it to the form', function () {
    store()->save(['telegram_bot_token' => '999:PANEL']);

    $raw = OpsNotifySetting::query()->where('key', 'telegram_bot_token')->value('value');

    expect($raw)->not->toContain('999:PANEL')
        ->and(Cache::get(SettingsStore::CACHE_KEY)['telegram_bot_token'])->not->toContain('999:PANEL')
        ->and(store()->formValues()['telegram_bot_token'])->toBeNull()
        ->and(store()->hasStored('telegram_bot_token'))->toBeTrue();
});

it('keeps the saved token when the field is left empty', function () {
    store()->save(['telegram_bot_token' => '999:PANEL', 'telegram_chat_id' => '-1']);
    store()->save(['telegram_bot_token' => null, 'telegram_chat_id' => '-2']);

    OpsMessage::make('x')->sendNow();

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '999:PANEL') && $request['chat_id'] === '-2');
});

it('lets .env win and ignores the stored value', function () {
    config(['ops-notify.channels.telegram.bot_token' => '111:ENV']);
    $store = new SettingsStore;

    $store->save(['telegram_bot_token' => '999:PANEL', 'telegram_chat_id' => '-1']);

    expect($store->isLocked('telegram_bot_token'))->toBeTrue()
        ->and($store->isLocked('telegram_chat_id'))->toBeFalse()
        ->and(OpsNotifySetting::query()->where('key', 'telegram_bot_token')->exists())->toBeFalse()
        ->and(config('ops-notify.channels.telegram.bot_token'))->toBe('111:ENV');
});

it('prefixes messages with the service name saved in the panel', function () {
    config(['ops-notify.service' => null, 'app.name' => 'Panel']);
    freshProcess();

    expect(store()->formValues()['service'])->toBe('Panel');

    store()->save(['telegram_bot_token' => '999:PANEL', 'telegram_chat_id' => '-1', 'service' => 'panel.polo.blue']);
    OpsMessage::make('x')->title('Hello')->sendNow();

    Http::assertSent(fn (Request $request) => str_contains($request['text'], '<b>[panel.polo.blue] Hello</b>'));
    expect(store()->formValues()['service'])->toBe('panel.polo.blue');
});

it('falls back to the app name when no service name is set', function () {
    config(['ops-notify.service' => null, 'app.name' => 'Panel']);
    freshProcess();
    store()->save(['telegram_bot_token' => '999:PANEL', 'telegram_chat_id' => '-1']);

    OpsMessage::make('x')->title('Hello')->sendNow();

    Http::assertSent(fn (Request $request) => str_contains($request['text'], '<b>[Panel] Hello</b>'));
});

it('can disable notifications from the panel', function () {
    store()->save(['telegram_bot_token' => '999:PANEL', 'telegram_chat_id' => '-1', 'enabled' => false]);

    expect(fn () => OpsMessage::make('x')->sendNow())->toThrow(MessageSkipped::class);
});

it('routes events with rules saved in the panel', function () {
    store()->save([
        'telegram_bot_token' => '999:PANEL',
        'telegram_chat_id' => '-1',
        'events' => ['inquiry.*' => ['topic' => '8'], 'noisy.*' => ['enabled' => false]],
    ]);

    OpsMessage::make('inquiry.created')->sendNow();

    Http::assertSent(fn (Request $request) => $request['message_thread_id'] === 8);
    expect(fn () => OpsMessage::make('noisy.thing')->sendNow())->toThrow(MessageSkipped::class);
});

it('keeps working from cache when the settings table is gone', function () {
    store()->save(['telegram_bot_token' => '999:PANEL', 'telegram_chat_id' => '-1']);
    Schema::drop('ops_notify_settings');
    freshProcess();

    OpsMessage::make('x')->sendNow();

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '999:PANEL'));
});

it('picks up changes saved by another process without a restart', function () {
    store()->save(['telegram_bot_token' => '999:OLD', 'telegram_chat_id' => '-1']);
    OpsMessage::make('x')->sendNow();

    // The web request saves new settings; this long-running worker only sees the cache change.
    OpsNotifySetting::query()->where('key', 'telegram_bot_token')
        ->update(['value' => Crypt::encryptString(json_encode('999:NEW'))]);
    Cache::forget(SettingsStore::CACHE_KEY);
    Cache::forever(SettingsStore::VERSION_KEY, 'bumped-by-web');

    OpsMessage::make('y')->sendNow();

    expect(Http::recorded()->last()[0]->url())->toContain('999:NEW');
});

it('reports a token that cannot be decrypted after an APP_KEY change', function () {
    store()->save(['telegram_bot_token' => '999:PANEL', 'telegram_chat_id' => '-1']);

    Crypt::swap(new Encrypter(str_repeat('z', 32), 'AES-256-CBC'));
    freshProcess();

    expect(store()->unreadableSecrets())->toBe(['telegram_bot_token'])
        ->and(config('ops-notify.channels.telegram.bot_token'))->toBeNull();
});

it('saves settings from the filament page', function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs($this->admin());

    Livewire::test(OpsNotifyPage::class)
        ->callAction('settings', data: [
            'telegram_bot_token' => '999:PANEL',
            'telegram_chat_id' => '-100555',
            'telegram_topic' => '4',
            'enabled' => true,
            'events' => [['pattern' => 'inquiry.*', 'topic' => '7', 'enabled' => true]],
            'forward_enabled' => true,
            'forward_map' => [
                ['title' => 'Nowe zapytanie*', 'event' => 'inquiry.created', 'forward' => true],
                ['title' => 'Export*', 'event' => null, 'forward' => false],
            ],
        ])
        ->assertHasNoActionErrors()
        ->assertNotified('Settings saved');

    expect(store()->formValues())->toMatchArray([
        'telegram_chat_id' => '-100555',
        'telegram_topic' => '4',
        'events' => ['inquiry.*' => ['topic' => '7', 'enabled' => true]],
        'forward_map' => ['Nowe zapytanie*' => 'inquiry.created', 'Export*' => false],
    ])->and(config('ops-notify.channels.telegram.bot_token'))->toBe('999:PANEL');

    expect(DB::table('ops_notify_settings')->count())->toBe(7);
});
