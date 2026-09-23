<?php

use Filament\Facades\Filament;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spokospace\OpsNotify\Enums\DeliveryStatus;
use Spokospace\OpsNotify\Filament\OpsNotifyPlugin;
use Spokospace\OpsNotify\Filament\Pages\OpsNotifyPage;
use Spokospace\OpsNotify\Models\OpsNotifyLog;
use Spokospace\OpsNotify\OpsMessage;
use Spokospace\OpsNotify\Tests\Fixtures\TestPanelProvider;

beforeEach(function () {
    TestPanelProvider::$authorized = true;
    Filament::setCurrentPanel('admin');
    $this->actingAs($this->admin());
});

it('shows status and the log of sent messages', function () {
    Http::fake([
        '*/getMe' => Http::response(['ok' => true, 'result' => ['username' => 'polo_ops_bot']]),
        '*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
    ]);
    OpsMessage::make('inquiry.created')->title('Visible in the log')->sendNow();

    Livewire::test(OpsNotifyPage::class)
        ->assertSuccessful()
        ->assertSee('Connected as @polo_ops_bot')
        ->assertCanSeeTableRecords(OpsNotifyLog::all())
        ->assertSee('Visible in the log');
});

it('sends a test message from the header action', function () {
    Http::fake([
        '*/getMe' => Http::response(['ok' => true, 'result' => ['username' => 'bot']]),
        '*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
    ]);

    Livewire::test(OpsNotifyPage::class)
        ->callAction('sendTest', data: ['text' => 'hello from the panel'])
        ->assertHasNoActionErrors()
        ->assertNotified('Sent');

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'sendMessage')
        && str_contains($request['text'], 'hello from the panel'));
});

it('reports a telegram error instead of failing', function () {
    Http::fake([
        '*/getMe' => Http::response(['ok' => false, 'description' => 'Unauthorized'], 401),
        '*/sendMessage' => Http::response(['ok' => false, 'description' => 'Unauthorized'], 401),
    ]);

    Livewire::test(OpsNotifyPage::class)
        ->assertSee('Unauthorized')
        ->callAction('sendTest', data: ['text' => 'x'])
        ->assertNotified('Not sent');
});

it('resends a failed message', function () {
    Http::fakeSequence('*/sendMessage')
        ->push(['ok' => false, 'description' => 'Bad Gateway'], 502)
        ->push(['ok' => true, 'result' => ['message_id' => 9]]);
    Http::fake(['*/getMe' => Http::response(['ok' => true, 'result' => ['username' => 'bot']])]);

    $failed = OpsMessage::make('build.failed')->send()->fresh();
    expect($failed->status)->toBe(DeliveryStatus::Failed);

    Livewire::test(OpsNotifyPage::class)
        ->callTableAction('resend', $failed)
        ->assertNotified('Sent');

    expect(OpsNotifyLog::query()->latest('id')->first()->status)->toBe(DeliveryStatus::Sent);
});

it('is called Spoko DashBot unless the app renames it', function () {
    expect(OpsNotifyPage::getNavigationLabel())->toBe('Spoko DashBot');

    OpsNotifyPlugin::get()->navigationLabel('Alerts');
    expect(OpsNotifyPage::getNavigationLabel())->toBe('Alerts');

    OpsNotifyPlugin::get()->navigationLabel(null);
    expect(OpsNotifyPage::getNavigationLabel())->toBe('Spoko DashBot');
});

it('is hidden from users the plugin does not authorize', function () {
    TestPanelProvider::$authorized = false;

    expect(OpsNotifyPage::canAccess())->toBeFalse();
});
