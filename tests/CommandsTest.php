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

it('pages past 100 older updates to find a ping in a busy group', function () {
    $chatter = array_map(fn (int $id): array => ['update_id' => $id, 'message' => [
        'chat' => ['id' => -1001, 'type' => 'supergroup', 'title' => 'Ops', 'is_forum' => true],
        'text' => 'hello',
    ]], range(1, 100));

    Http::fake([
        '*/getMe' => Http::response(['ok' => true, 'result' => ['username' => 'polo_ops_bot']]),
        '*/getUpdates' => Http::sequence()
            ->push(['ok' => true, 'result' => $chatter])
            ->push(['ok' => true, 'result' => [['update_id' => 101, 'message' => [
                'chat' => ['id' => -1001, 'type' => 'supergroup', 'title' => 'Ops', 'is_forum' => true],
                'message_thread_id' => 3,
                'is_topic_message' => true,
                'reply_to_message' => ['forum_topic_created' => ['name' => 'Inquiries']],
            ]]]]),
    ]);

    expect(Artisan::call('ops-notify:telegram-chats'))->toBe(0)
        ->and(Artisan::output())
        ->toMatch('/-1001\s+\|\s+supergroup\s+\|\s+Ops\s+\|\s+yes/')
        ->toMatch('/-1001\s+\|\s+3\s+\|\s+Inquiries/');

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/getUpdates') && ($request->data()['offset'] ?? null) === 101);
});

it('remembers topics from earlier runs, since paging confirms old updates', function () {
    Http::fake([
        '*/getMe' => Http::response(['ok' => true, 'result' => ['username' => 'polo_ops_bot']]),
        '*/getUpdates' => Http::sequence()
            ->push(['ok' => true, 'result' => [['update_id' => 1, 'message' => [
                'chat' => ['id' => -1001, 'type' => 'supergroup', 'title' => 'Ops'],
                'message_thread_id' => 3,
                'is_topic_message' => true,
                'reply_to_message' => ['forum_topic_created' => ['name' => 'Inquiries']],
            ]]]])
            ->push(['ok' => true, 'result' => []]),
    ]);

    Artisan::call('ops-notify:telegram-chats');
    Artisan::call('ops-notify:telegram-chats');

    expect(Artisan::output())->toMatch('/-1001\s+\|\s+3\s+\|\s+Inquiries/');
});

it('points from a group to the supergroup it became when Topics were turned on', function () {
    Http::fake([
        '*/getMe' => Http::response(['ok' => true, 'result' => ['username' => 'polo_ops_bot']]),
        '*/getUpdates' => Http::response(['ok' => true, 'result' => [
            ['update_id' => 1, 'my_chat_member' => ['chat' => ['id' => -4512, 'type' => 'group', 'title' => 'Ops']]],
            ['update_id' => 2, 'message' => ['chat' => ['id' => -4512, 'type' => 'group', 'title' => 'Ops'], 'migrate_to_chat_id' => -1001]],
            ['update_id' => 3, 'message' => ['chat' => ['id' => -1001, 'type' => 'supergroup', 'title' => 'Ops', 'is_forum' => true], 'migrate_from_chat_id' => -4512]],
        ]]),
    ]);

    expect(Artisan::call('ops-notify:telegram-chats'))->toBe(0)
        ->and(Artisan::output())
        ->toContain('Chat -4512 ("Ops") became supergroup -1001 when Topics were turned on. Use -1001.')
        ->toContain('/ping@polo_ops_bot');
});

it('does not call an unrelated group outdated', function () {
    Http::fake([
        '*/getMe' => Http::response(['ok' => true, 'result' => ['username' => 'polo_ops_bot']]),
        '*/getUpdates' => Http::response(['ok' => true, 'result' => [
            ['update_id' => 1, 'message' => ['chat' => ['id' => -4512, 'type' => 'group', 'title' => 'Family']]],
            ['update_id' => 2, 'message' => ['chat' => ['id' => -1001, 'type' => 'supergroup', 'title' => 'Ops', 'is_forum' => true]]],
        ]]),
    ]);

    Artisan::call('ops-notify:telegram-chats');

    expect(Artisan::output())->not->toContain('became supergroup');
});

it('keeps the pages it read when a later page fails', function () {
    $page = array_map(fn (int $id): array => ['update_id' => $id, 'message' => [
        'chat' => ['id' => -1001, 'type' => 'supergroup', 'title' => 'Ops'],
        'message_thread_id' => 3,
        'is_topic_message' => true,
        'reply_to_message' => ['forum_topic_created' => ['name' => 'Inquiries']],
    ]], range(1, 100));

    Http::fake([
        '*/getMe' => Http::response(['ok' => true, 'result' => ['username' => 'polo_ops_bot']]),
        '*/getUpdates' => Http::sequence()
            ->push(['ok' => true, 'result' => $page])
            ->push(['ok' => false, 'description' => 'Bad Gateway'], 502)
            ->push(['ok' => true, 'result' => []]),
    ]);

    // The failed second request already confirmed page one on Telegram's side.
    expect(Artisan::call('ops-notify:telegram-chats'))->toBe(1)
        ->and(Artisan::call('ops-notify:telegram-chats'))->toBe(0)
        ->and(Artisan::output())->toMatch('/-1001\s+\|\s+3\s+\|\s+Inquiries/');
});
