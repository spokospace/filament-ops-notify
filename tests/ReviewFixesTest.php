<?php

use Filament\Facades\Filament;
use Illuminate\Http\Client\Request;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Livewire\Livewire;
use Spokospace\OpsNotify\Enums\DeliveryStatus;
use Spokospace\OpsNotify\Exceptions\ChannelException;
use Spokospace\OpsNotify\Filament\Pages\OpsNotifyPage;
use Spokospace\OpsNotify\Models\OpsNotifyLog;
use Spokospace\OpsNotify\OpsMessage;
use Spokospace\OpsNotify\Settings\SettingsStore;

class DeployFailed extends Notification
{
    public function via(object $notifiable): array
    {
        return ['ops'];
    }

    public function toOps(object $notifiable): OpsMessage
    {
        return OpsMessage::make('build.failed')->error()->title('Deploy failed');
    }
}

it('sends one message when an ops notification goes to several users', function () {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);

    NotificationFacade::send([$this->admin(), $this->admin(), $this->admin()], new DeployFailed);

    Http::assertSentCount(1);
});

it('delivers without buttons when telegram rejects a button url', function () {
    Http::fakeSequence('api.telegram.org/*')
        ->push(['ok' => false, 'description' => 'Bad Request: BUTTON_URL_INVALID'], 400)
        ->push(['ok' => true, 'result' => ['message_id' => 5]]);

    $log = OpsMessage::make('inquiry.created')->title('New inquiry')->button('Open', 'http://localhost/admin/x')->sendNow();

    expect($log->status)->toBe(DeliveryStatus::Sent);

    $retry = Http::recorded()->last()[0];
    expect($retry->data())->not->toHaveKey('reply_markup')
        ->and($retry['text'])->toContain('<b>Open:</b> http://localhost/admin/x');
});

it('does not retry without buttons for other errors', function () {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Bad Request: chat not found'], 400)]);

    expect(fn () => OpsMessage::make('x')->button('Open', 'https://x.test')->sendNow())->toThrow(ChannelException::class);

    Http::assertSentCount(1);
});

it('marks the row failed on a misconfigured channel instead of leaving it queued', function () {
    Http::fake();
    config(['ops-notify.events' => ['x' => ['channel' => 'slack']]]);

    expect(fn () => OpsMessage::make('x')->sendNow())->toThrow(InvalidArgumentException::class);

    expect(OpsNotifyLog::query()->sole())
        ->status->toBe(DeliveryStatus::Failed)
        ->error->toContain('slack');
});

it('shows a misconfiguration on the page instead of a server error', function () {
    Http::fake(['*/getMe' => Http::response(['ok' => true, 'result' => ['username' => 'bot']])]);
    config(['ops-notify.events' => ['ops.test' => ['channel' => 'slack']]]);
    Filament::setCurrentPanel('admin');
    $this->actingAs($this->admin());

    Livewire::test(OpsNotifyPage::class)
        ->callAction('sendTest', data: ['text' => 'x'])
        ->assertNotified('Not sent');
});

it('truncates a huge body in the log so the insert cannot fail', function () {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);

    $log = OpsMessage::make('error.x')->line(str_repeat('stack frame ', 20000))->sendNow();

    expect(mb_strlen($log->body))->toBeLessThanOrEqual(10003);
});

it('shows the .env value in a locked settings field', function () {
    config(['ops-notify.enabled' => false, 'ops-notify.channels.telegram.chat_id' => '-100ENV']);
    $store = new SettingsStore;

    expect($store->formValues())
        ->enabled->toBeFalse()
        ->telegram_chat_id->toBe('-100ENV');
});

it('does not change the bot update filter when discovering chats', function () {
    Http::fake([
        '*/getMe' => Http::response(['ok' => true, 'result' => ['username' => 'bot']]),
        '*/getUpdates' => Http::response(['ok' => true, 'result' => []]),
    ]);

    $this->artisan('ops-notify:telegram-chats')->assertSuccessful();

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/getUpdates') && ! isset($request['allowed_updates']));
});

it('marks the original row resent so it cannot be resent twice', function () {
    Http::fakeSequence('*/sendMessage')
        ->push(['ok' => false, 'description' => 'Bad Gateway'], 502)
        ->push(['ok' => true, 'result' => ['message_id' => 9]]);
    Http::fake(['*/getMe' => Http::response(['ok' => true, 'result' => ['username' => 'bot']])]);
    Filament::setCurrentPanel('admin');
    $this->actingAs($this->admin());

    $failed = OpsMessage::make('build.failed')->send()->fresh();

    Livewire::test(OpsNotifyPage::class)
        ->callTableAction('resend', $failed)
        ->assertNotified('Sent')
        ->assertTableActionHidden('resend', $failed->fresh());

    $new = OpsNotifyLog::query()->latest('id')->first();
    expect($failed->fresh())
        ->status->toBe(DeliveryStatus::Resent)
        ->error->toBe("Resent as #{$new->id}");
});

it('marks a rate-limited message failed on the sync queue instead of losing it', function () {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Too Many Requests', 'parameters' => ['retry_after' => 5]], 429)]);

    $log = OpsMessage::make('x')->send()->fresh();

    expect($log->status)->toBe(DeliveryStatus::Failed)
        ->and($log->error)->toContain('Too Many Requests');
});
