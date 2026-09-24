<?php

use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spokospace\OpsNotify\ChannelManager;
use Spokospace\OpsNotify\Channels\Telegram\TelegramChannel;
use Spokospace\OpsNotify\Exceptions\ChannelException;
use Spokospace\OpsNotify\Filament\Pages\OpsNotifyInvitesPage;
use Spokospace\OpsNotify\Filament\Pages\OpsNotifyPage;

function telegram(): TelegramChannel
{
    return app(ChannelManager::class)->telegram();
}

function fakeMember(array $member): void
{
    Http::fake([
        '*/getMe' => Http::response(['ok' => true, 'result' => ['username' => 'bot']]),
        '*/getChatMember' => Http::response(['ok' => true, 'result' => $member]),
    ]);
}

it('asks Telegram about the bot itself, using the id from its token', function () {
    fakeMember(['status' => 'administrator', 'can_manage_topics' => true, 'can_invite_users' => true]);

    telegram()->rights();

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/getChatMember')
        && $request['chat_id'] === '-1001'
        && $request['user_id'] === 123);
});

it('sums the rights up in one line', function (array $member, string $text, bool $ok) {
    fakeMember($member);

    expect(telegram()->rightsSummary())->toBe(['text' => $text, 'ok' => $ok]);
})->with([
    'admin with both' => [['status' => 'administrator', 'can_manage_topics' => true, 'can_invite_users' => true], 'All rights', true],
    'creator' => [['status' => 'creator'], 'All rights', true],
    'admin missing one' => [['status' => 'administrator', 'can_manage_topics' => true, 'can_invite_users' => false], 'Missing: Invite users via link', false],
    'plain member' => [['status' => 'member'], 'Not an admin of the chat', false],
    'removed' => [['status' => 'kicked'], 'Not in the chat', false],
]);

it('says when it cannot check', function () {
    Http::fake(['*/getChatMember' => Http::response(['ok' => false, 'description' => 'Bad Request: chat not found'], 400)]);

    expect(telegram()->rightsSummary())->toBe(['text' => 'Could not check: Telegram API 400: Bad Request: chat not found', 'ok' => false]);
});

it('caches the answer for a minute', function () {
    fakeMember(['status' => 'creator']);

    telegram()->rights();
    telegram()->rights();

    Http::assertSentCount(1);
});

it('turns a missing-right refusal into instructions and rechecks the rights', function () {
    fakeMember(['status' => 'administrator', 'can_manage_topics' => true, 'can_invite_users' => false]);
    telegram()->rights();

    $explained = telegram()->explain(new ChannelException('Telegram API 400: Bad Request: not enough rights to manage chat invite link'), 'invite_users');

    expect($explained)->toBe('The bot lacks the "Invite users via link" admin right. In Telegram: group → Administrators → the bot → turn it on, then try again.');

    telegram()->rights();
    Http::assertSentCount(2);
});

it('passes other refusals through unchanged', function () {
    expect(telegram()->explain(new ChannelException('Telegram API 400: Bad Request: chat not found'), 'invite_users'))
        ->toBe('Telegram API 400: Bad Request: chat not found');
});

describe('page', function () {
    beforeEach(function () {
        Filament::setCurrentPanel('admin');
        $this->actingAs($this->admin());
    });

    it('shows the bot rights in the status', function () {
        fakeMember(['status' => 'administrator', 'can_manage_topics' => true, 'can_invite_users' => false]);

        Livewire::test(OpsNotifyPage::class)->assertSee('Missing: Invite users via link');
    });

    it('explains a missing invite right when creating a link', function () {
        Http::fake([
            '*/getChatMember' => Http::response(['ok' => true, 'result' => ['status' => 'creator']]),
            '*/createChatInviteLink' => Http::response(['ok' => false, 'description' => 'Bad Request: not enough rights to manage chat invite link'], 400),
        ]);

        Livewire::test(OpsNotifyInvitesPage::class)
            ->callAction('createInvite', data: ['name' => 'Anna', 'valid_for' => 24, 'single_use' => true])
            ->assertNotified(Notification::make()
                ->danger()
                ->title('Telegram rejected the request')
                ->body('The bot lacks the "Invite users via link" admin right. In Telegram: group → Administrators → the bot → turn it on, then try again.'));
    });
});
