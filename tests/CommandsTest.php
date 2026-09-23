<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

it('sends a test notification', function () {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);

    $this->artisan('ops-notify:test', ['text' => 'hello'])
        ->expectsOutputToContain('Sent.')
        ->assertSuccessful();

    Http::assertSent(fn (Request $request) => str_contains($request['text'], 'hello'));
});

it('reports a telegram error', function () {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Unauthorized'], 401)]);

    $this->artisan('ops-notify:test')
        ->expectsOutputToContain('Unauthorized')
        ->assertFailed();
});

it('refuses when the event is disabled', function () {
    Http::fake();
    config(['ops-notify.events' => ['ops.*' => ['enabled' => false]]]);

    $this->artisan('ops-notify:test')->expectsOutputToContain('Nothing sent')->assertFailed();

    Http::assertNothingSent();
});

it('lists chats and forum topics the bot has seen', function () {
    Http::fake([
        '*/getMe' => Http::response(['ok' => true, 'result' => ['username' => 'polo_ops_bot']]),
        '*/getUpdates' => Http::response(['ok' => true, 'result' => [
            ['update_id' => 1, 'message' => [
                'chat' => ['id' => -1001, 'type' => 'supergroup', 'title' => 'Ops'],
                'message_thread_id' => 4,
                'is_topic_message' => true,
                'reply_to_message' => ['forum_topic_created' => ['name' => 'Errors']],
                'text' => '/ping',
            ]],
        ]]),
    ]);

    expect(Artisan::call('ops-notify:telegram-chats'))->toBe(0)
        ->and(Artisan::output())
        ->toContain('@polo_ops_bot')
        ->toMatch('/-1001\s+\|\s+supergroup\s+\|\s+Ops/')
        ->toMatch('/-1001\s+\|\s+4\s+\|\s+Errors/');
});
