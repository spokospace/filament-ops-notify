<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Spokospace\OpsNotify\Enums\DeliveryStatus;
use Spokospace\OpsNotify\Exceptions\ChannelException;
use Spokospace\OpsNotify\Exceptions\MessageSkipped;
use Spokospace\OpsNotify\Facades\OpsNotify;
use Spokospace\OpsNotify\Jobs\SendOpsMessage;
use Spokospace\OpsNotify\Models\OpsNotifyLog;
use Spokospace\OpsNotify\OpsMessage;

function telegramOk(int $messageId = 77): array
{
    return ['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => $messageId]])];
}

it('sends a message to telegram and logs it as sent', function () {
    Http::fake(telegramOk(77));

    $log = OpsMessage::make('inquiry.created')
        ->title('New inquiry')
        ->button('Open', 'https://panel.test/inquiries/1')
        ->topic(12)
        ->sendNow();

    Http::assertSent(function (Request $request) {
        return $request->url() === 'https://api.telegram.org/bot123:SECRET/sendMessage'
            && $request['chat_id'] === '-1001'
            && $request['message_thread_id'] === 12
            && $request['parse_mode'] === 'HTML'
            && $request['reply_markup']['inline_keyboard'][0][0] === ['text' => 'Open', 'url' => 'https://panel.test/inquiries/1'];
    });

    expect($log->status)->toBe(DeliveryStatus::Sent)
        ->and($log->external_id)->toBe('77')
        ->and($log->topic)->toBe('12')
        ->and($log->attempts)->toBe(1)
        ->and($log->sent_at)->not->toBeNull();
});

it('queues messages and delivers them through the job', function () {
    Http::fake(telegramOk());

    $log = OpsNotify::send(OpsMessage::make('build.finished')->success());

    expect($log->fresh()->status)->toBe(DeliveryStatus::Sent);
    Http::assertSentCount(1);
});

it('dispatches on the configured queue', function () {
    Queue::fake();
    config(['ops-notify.queue.name' => 'notifications']);

    OpsMessage::make('build.started')->send();

    Queue::assertPushedOn('notifications', SendOpsMessage::class);
    expect(OpsNotifyLog::query()->sole()->status)->toBe(DeliveryStatus::Queued);
});

it('routes by the first matching event pattern', function () {
    Http::fake(telegramOk());
    config(['ops-notify.events' => [
        'inquiry.*' => ['topic' => 5],
        '*' => ['topic' => 9],
    ]]);

    OpsMessage::make('inquiry.created')->sendNow();
    OpsMessage::make('build.finished')->sendNow();
    OpsMessage::make('inquiry.created')->topic(1)->sendNow();

    expect(OpsNotifyLog::query()->orderBy('id')->pluck('topic')->all())->toBe(['5', '9', '1']);
});

it('uses the channel default topic when the event has none', function () {
    Http::fake(telegramOk());
    config(['ops-notify.channels.telegram.topic' => '3']);

    OpsMessage::make('anything')->sendNow();

    Http::assertSent(fn (Request $request) => $request['message_thread_id'] === 3);
});

it('sends nothing for disabled events or when globally disabled', function () {
    Http::fake();
    config(['ops-notify.events' => ['noisy.*' => ['enabled' => false]]]);

    expect(OpsMessage::make('noisy.thing')->send())->toBeNull();

    config(['ops-notify.enabled' => false]);

    expect(fn () => OpsMessage::make('inquiry.created')->sendNow())->toThrow(MessageSkipped::class);

    Http::assertNothingSent();
    expect(OpsNotifyLog::query()->count())->toBe(0);
});

it('marks permanent failures as failed and rethrows from sendNow', function () {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Bad Request: chat not found'], 400)]);

    expect(fn () => OpsMessage::make('x')->sendNow())
        ->toThrow(ChannelException::class, 'Telegram API 400: Bad Request: chat not found');

    $log = OpsNotifyLog::query()->sole();
    expect($log->status)->toBe(DeliveryStatus::Failed)
        ->and($log->error)->toContain('chat not found');
});

it('never throws from send(), even when delivery fails', function () {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Unauthorized'], 401)]);

    $log = OpsMessage::make('x')->send()->fresh();

    expect($log->status)->toBe(DeliveryStatus::Failed)
        ->and($log->error)->toContain('Unauthorized');
});

it('reads retry_after from a rate limit response', function () {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Too Many Requests', 'parameters' => ['retry_after' => 17]], 429)]);

    try {
        OpsMessage::make('x')->sendNow();
    } catch (ChannelException $e) {
        expect($e->retryAfter)->toBe(17)->and($e->permanent)->toBeFalse();

        return;
    }

    $this->fail('Expected a ChannelException.');
});

it('does not leak the bot token when telegram is unreachable', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 28 for https://api.telegram.org/bot123:SECRET/sendMessage'));

    try {
        OpsMessage::make('x')->sendNow();
    } catch (ChannelException $e) {
        expect($e->getMessage())->not->toContain('SECRET')->toContain('bot***');
        expect(OpsNotifyLog::query()->sole()->error)->not->toContain('SECRET');

        return;
    }

    $this->fail('Expected a ChannelException.');
});

it('fails fast when the channel is not configured', function () {
    Http::fake();
    config(['ops-notify.channels.telegram.bot_token' => null]);
    OpsNotify::channels()->forgetChannels();

    expect(fn () => OpsMessage::make('x')->sendNow())->toThrow(ChannelException::class, 'not configured');
    Http::assertNothingSent();
});

it('rebuilds the original message from a log row', function () {
    Http::fake(telegramOk());

    $log = OpsMessage::make('inquiry.created')->warning()->title('T')->line('B')->field('F', 1)->button('Open', 'https://x.test')->sendNow();

    expect($log->toMessage()->toArray())->toBe($log->payload);
});

it('prunes old log rows', function () {
    config(['ops-notify.log.prune_after_days' => 30]);
    Http::fake(telegramOk());

    OpsMessage::make('old')->sendNow();
    OpsNotifyLog::query()->update(['created_at' => now()->subDays(31)]);
    OpsMessage::make('new')->sendNow();

    $this->artisan('model:prune', ['--model' => [OpsNotifyLog::class]])->assertSuccessful();

    expect(OpsNotifyLog::query()->pluck('event')->all())->toBe(['new']);
});
