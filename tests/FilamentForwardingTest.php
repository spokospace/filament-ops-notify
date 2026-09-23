<?php

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Spokospace\OpsNotify\Enums\Level;
use Spokospace\OpsNotify\Models\OpsNotifyLog;

beforeEach(function () {
    // Queue workers build URLs from APP_URL; the test request would otherwise say "localhost".
    URL::forceRootUrl('https://panel.test');
    URL::forceScheme('https');
});

/** Http::fake() stubs stack and the first match wins, so each test fakes Telegram exactly once. */
function fakeTelegram(int $status = 200, array $body = ['ok' => true, 'result' => ['message_id' => 1]]): void
{
    Http::fake(['api.telegram.org/*' => Http::response($body, $status)]);
}

function inquiryNotification(string $title = 'Nowe zapytanie o produkt'): Notification
{
    return Notification::make()
        ->title($title)
        ->body('Jan <b>pyta</b> o: Lampa &amp; kierunkowskaz')
        ->warning()
        ->actions([
            Action::make('view')->label('Odpowiedz')->url('/admin/garazyn/product-inquiries/5'),
            Action::make('noop')->label('No url'),
        ]);
}

it('forwards a filament database notification once, however many users get it', function () {
    fakeTelegram();

    inquiryNotification()->sendToDatabase([$this->admin(), $this->admin(), $this->admin()]);

    expect(DB::table('notifications')->count())->toBe(3);
    Http::assertSentCount(1);

    $request = Http::recorded()->sole()[0];
    expect($request['text'])
        ->toContain('Nowe zapytanie o produkt')
        ->toContain('Jan pyta o: Lampa &amp; kierunkowskaz')
        ->and($request['reply_markup']['inline_keyboard'])
        ->toBe([[['text' => 'Odpowiedz', 'url' => 'https://panel.test/admin/garazyn/product-inquiries/5']]]);

    $log = OpsNotifyLog::query()->sole();
    expect($log->event)->toBe('filament.notification')
        ->and($log->level)->toBe(Level::Warning);
});

it('forwards different notifications separately', function () {
    fakeTelegram();
    $admin = $this->admin();

    inquiryNotification('First')->sendToDatabase($admin);
    inquiryNotification('Second')->sendToDatabase($admin);

    Http::assertSentCount(2);
});

it('maps titles to events for routing and can drop some', function () {
    fakeTelegram();
    config([
        'ops-notify.forward_database_notifications.map' => [
            'Nowe zapytanie*' => 'inquiry.created',
            'Export*' => false,
        ],
        'ops-notify.events' => ['inquiry.*' => ['topic' => 7]],
    ]);
    $admin = $this->admin();

    inquiryNotification()->sendToDatabase($admin);
    Notification::make()->title('Export finished')->sendToDatabase($admin);

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => $request['message_thread_id'] === 7);
    expect(OpsNotifyLog::query()->sole()->event)->toBe('inquiry.created');
});

it('forwards only mapped titles when there is no default event', function () {
    fakeTelegram();
    config([
        'ops-notify.forward_database_notifications.default_event' => null,
        'ops-notify.forward_database_notifications.map' => ['Nowe zapytanie*' => 'inquiry.created'],
    ]);
    $admin = $this->admin();

    Notification::make()->title('Something else')->sendToDatabase($admin);
    inquiryNotification()->sendToDatabase($admin);

    expect(OpsNotifyLog::query()->pluck('event')->all())->toBe(['inquiry.created']);
});

it('does nothing when forwarding is disabled', function () {
    fakeTelegram();
    config(['ops-notify.forward_database_notifications.enabled' => false]);

    inquiryNotification()->sendToDatabase($this->admin());

    Http::assertNothingSent();
    expect(DB::table('notifications')->count())->toBe(1);
});

it('still stores the bell notification when telegram is down', function () {
    fakeTelegram(401, ['ok' => false, 'description' => 'Unauthorized']);

    inquiryNotification()->sendToDatabase($this->admin());

    expect(DB::table('notifications')->count())->toBe(1)
        ->and(OpsNotifyLog::query()->sole()->error)->toContain('Unauthorized');
});
