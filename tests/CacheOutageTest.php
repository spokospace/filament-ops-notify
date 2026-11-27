<?php

use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Spokospace\OpsNotify\ChannelManager;
use Spokospace\OpsNotify\Enums\DeliveryStatus;
use Spokospace\OpsNotify\Filament\Pages\OpsNotifyInvitesPage;
use Spokospace\OpsNotify\Filament\Pages\OpsNotifyPage;
use Spokospace\OpsNotify\Jobs\SendOpsMessage;
use Spokospace\OpsNotify\Models\OpsNotifySetting;
use Spokospace\OpsNotify\OpsMessage;
use Spokospace\OpsNotify\OpsNotifier;
use Spokospace\OpsNotify\Settings\SettingsStore;
use Spokospace\OpsNotify\Tests\Fixtures\BrokenCacheStore;

beforeEach(function () {
    // Nothing from .env: the bot token, chat and routing come from the panel only.
    config([
        'ops-notify.channels.telegram.bot_token' => null,
        'ops-notify.channels.telegram.chat_id' => null,
    ]);
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1, 'username' => 'panel_bot']])]);

    app(SettingsStore::class)->save([
        'telegram_bot_token' => '999:PANEL',
        'telegram_chat_id' => '-100555',
        'events' => ['redis.*' => ['topic' => '7']],
    ]);
});

/**
 * Redis goes down, and a new process starts (a web request, a worker restarted during the
 * outage): config as loaded from files, nothing read from the settings yet.
 */
function cacheGoesDown(): void
{
    Cache::extend('broken', fn () => Cache::repository(new BrokenCacheStore));
    config([
        'cache.stores.broken' => ['driver' => 'broken'],
        'cache.default' => 'broken',
        'ops-notify.channels.telegram.bot_token' => null,
        'ops-notify.channels.telegram.chat_id' => null,
        'ops-notify.events' => [],
    ]);

    foreach ([SettingsStore::class, ChannelManager::class, OpsNotifier::class] as $singleton) {
        app()->forgetInstance($singleton);
    }
}

it('sends with the bot token and chat saved in the panel while the cache is down', function () {
    cacheGoesDown();

    $log = OpsMessage::make('redis.down')->title('Redis is down')->send();

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/bot999:PANEL/')
        && $request['chat_id'] === '-100555'
        && $request['message_thread_id'] === 7);
    expect($log->fresh()->status)->toBe(DeliveryStatus::Sent);
});

it('queues and logs a message on a database queue while the cache is down', function () {
    config(['queue.default' => 'database']);
    Queue::fake();
    cacheGoesDown();

    // Past the burst limit too: with the cache down the guard cannot count, so nothing is held.
    foreach (range(1, 12) as $i) {
        $log = OpsMessage::make('redis.down')->title('Redis is down')->field('Attempt', (string) $i)->send();

        expect($log?->status)->toBe(DeliveryStatus::Queued);
    }

    Queue::assertPushed(SendOpsMessage::class, 12);
    // Routed by the rule saved in the panel.
    Queue::assertPushed(SendOpsMessage::class, fn (SendOpsMessage $job): bool => $job->destination->topic === '7');
});

it('renders the Ops Notify page while the cache is down, with the error on the connection line', function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs($this->admin());
    cacheGoesDown();

    Livewire::test(OpsNotifyPage::class)
        ->assertOk()
        ->assertSee(BrokenCacheStore::ERROR);
});

it('forwards a bell notification while the cache is down', function () {
    $admin = $this->admin();
    cacheGoesDown();

    Notification::make()->title('Build failed')->sendToDatabase($admin);

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/bot999:PANEL/')
        && str_contains($request['text'], 'Build failed'));
});

it('changes nothing when a settings save cannot reach the cache', function () {
    cacheGoesDown();

    // Applied now and reverted when the cache comes back with the old rows would be worse.
    expect(fn () => app(SettingsStore::class)->save(['telegram_chat_id' => '-100999']))->toThrow(RuntimeException::class)
        ->and(OpsNotifySetting::query()->where('key', 'telegram_chat_id')->value('value'))->toBe('"-100555"');
});

it('explains a missing admin right while the cache is down', function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs($this->admin());
    Http::swap(new Factory);
    Http::fake(['*/createChatInviteLink' => Http::response(['ok' => false, 'description' => 'Bad Request: not enough rights to manage chat invite link'], 400)]);
    cacheGoesDown();
    // Filament keeps its toasts in a cache too; with that one down no toast can show at all. Here
    // only the package's cache is down, as when an app gives Filament a store of its own.
    config(['filament.cache_store' => 'array']);

    Livewire::test(OpsNotifyInvitesPage::class)
        ->callAction('createInvite', data: ['name' => 'Anna', 'valid_for' => 24, 'single_use' => true])
        ->assertNotified(Notification::make()
            ->danger()
            ->title('Telegram rejected the request')
            ->body('The bot lacks the "Invite users via link" admin right. In Telegram: group → Administrators → the bot → turn it on, then try again.'));
});
