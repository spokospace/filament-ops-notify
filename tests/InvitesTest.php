<?php

use Filament\Facades\Filament;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spokospace\OpsNotify\Filament\OpsNotifyPlugin;
use Spokospace\OpsNotify\Filament\Pages\OpsNotifyInvitesPage;
use Spokospace\OpsNotify\Filament\Pages\OpsNotifyPage;
use Spokospace\OpsNotify\Models\OpsNotifyInvite;

function fakeInviteLink(): void
{
    Http::fake(['*/createChatInviteLink' => Http::response(['ok' => true, 'result' => ['invite_link' => 'https://t.me/+AbCdEf123']])]);
}

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs($this->admin());
    Http::fake([
        '*/getMe' => Http::response(['ok' => true, 'result' => ['username' => 'bot']]),
        '*/revokeChatInviteLink' => Http::response(['ok' => true, 'result' => ['invite_link' => 'https://t.me/+AbCdEf123']]),
    ]);
});

it('creates a single-use invite link that expires, and shows it for copying', function () {
    fakeInviteLink();
    $this->freezeTime();

    Livewire::test(OpsNotifyInvitesPage::class)
        ->callAction('createInvite', data: ['name' => 'Anna', 'valid_for' => 24, 'single_use' => true])
        ->assertHasNoActionErrors()
        ->assertActionMounted('showInvite');

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/createChatInviteLink')
        && $request['chat_id'] === '-1001'
        && $request['name'] === 'Anna'
        && $request['member_limit'] === 1
        && $request['expire_date'] === now()->addDay()->getTimestamp());

    $invite = OpsNotifyInvite::query()->sole();
    expect($invite->invite_link)->toBe('https://t.me/+AbCdEf123')
        ->and($invite->state())->toBe(OpsNotifyInvite::STATE_ACTIVE)
        // Stored encrypted: whoever has the link can join.
        ->and($invite->getRawOriginal('invite_link'))->not->toContain('t.me');
});

it('leaves out the member limit for a reusable link', function () {
    fakeInviteLink();
    Livewire::test(OpsNotifyInvitesPage::class)
        ->callAction('createInvite', data: ['name' => 'Team', 'valid_for' => 168, 'single_use' => false]);

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/createChatInviteLink') && ! isset($request['member_limit']));
    expect(OpsNotifyInvite::query()->sole()->member_limit)->toBeNull();
});

it('reports a Telegram refusal without storing anything', function () {
    Http::fake(['*/createChatInviteLink' => Http::response(['ok' => false, 'description' => 'Bad Request: not enough rights to manage chat invite link'], 400)]);

    Livewire::test(OpsNotifyInvitesPage::class)
        ->callAction('createInvite', data: ['name' => 'Anna', 'valid_for' => 24, 'single_use' => true])
        ->assertNotified('Telegram rejected the request');

    expect(OpsNotifyInvite::query()->count())->toBe(0);
});

it('revokes an active link', function () {
    $invite = OpsNotifyInvite::query()->create(['channel' => 'telegram', 'name' => 'Anna', 'invite_link' => 'https://t.me/+AbCdEf123', 'expires_at' => now()->addDay()]);

    Livewire::test(OpsNotifyInvitesPage::class)
        ->callTableAction('revoke', $invite)
        ->assertNotified('Invite link revoked');

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/revokeChatInviteLink') && $request['invite_link'] === 'https://t.me/+AbCdEf123');
    expect($invite->fresh()->state())->toBe(OpsNotifyInvite::STATE_REVOKED);
});

it('knows when a link has expired', function () {
    $invite = OpsNotifyInvite::query()->create(['channel' => 'telegram', 'name' => 'Old', 'invite_link' => 'https://t.me/+x', 'expires_at' => now()->subMinute()]);

    expect($invite->state())->toBe(OpsNotifyInvite::STATE_EXPIRED);
    Livewire::test(OpsNotifyInvitesPage::class)->assertTableActionHidden('revoke', $invite);
});

it('is for people who may manage, linked from the main page', function () {
    Livewire::test(OpsNotifyPage::class)->assertActionVisible('invites');

    OpsNotifyPlugin::get()->authorizeManagement(fn (): bool => false);

    expect(OpsNotifyInvitesPage::canAccess())->toBeFalse();
    Livewire::test(OpsNotifyPage::class)->assertActionHidden('invites');
});
