<?php

use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Http\Client\Request;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Spokospace\OpsNotify\Models\OpsNotifyLog;
use Spokospace\OpsNotify\OpsMessage;

beforeEach(function () {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
});

class BuildFailed extends Notification
{
    public function via(object $notifiable): array
    {
        return ['ops'];
    }

    public function toOps(object $notifiable): OpsMessage
    {
        return OpsMessage::make('build.failed')->error()->title('Build failed')->field('Exit code', 1);
    }
}

class ImportFinished extends Notification
{
    public function via(object $notifiable): array
    {
        return ['ops'];
    }

    public function toOps(object $notifiable): FilamentNotification
    {
        return FilamentNotification::make()->title('Import finished')->success();
    }
}

it('delivers a laravel notification through the ops channel', function () {
    NotificationFacade::route('ops', 12)->notify(new BuildFailed);

    Http::assertSent(fn (Request $request) => str_contains($request['text'], 'Build failed')
        && $request['message_thread_id'] === 12);

    expect(OpsNotifyLog::query()->sole()->event)->toBe('build.failed');
});

it('accepts a channel and topic as the route', function () {
    NotificationFacade::route('ops', ['channel' => 'telegram', 'topic' => 4])->notify(new BuildFailed);

    Http::assertSent(fn (Request $request) => $request['message_thread_id'] === 4);
});

it('converts a filament notification returned from toOps', function () {
    $this->admin()->notify(new ImportFinished);

    Http::assertSent(fn (Request $request) => str_starts_with($request['text'], '✅ <b>[test-app] Import finished</b>'));

    expect(OpsNotifyLog::query()->sole()->event)->toBe('import_finished');
});
