<?php

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spokospace\OpsNotify\Filament\OpsNotifyPlugin;
use Spokospace\OpsNotify\Filament\Pages\OpsNotifyPage;
use Spokospace\OpsNotify\OpsMessage;
use Spokospace\OpsNotify\Tests\Fixtures\User;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs($this->admin());
    Http::fake(['*' => Http::response(['ok' => false, 'description' => 'Bad Gateway'], 502)]);

    // The test panel authorizes with a closure; these tests exercise the defaults.
    OpsNotifyPlugin::get()->authorize(null)->authorizeManagement(null);
});

it('is closed outside the local environment until the app says who may use it', function () {
    expect(OpsNotifyPage::canAccess())->toBeFalse()
        ->and(OpsNotifyPage::canManage())->toBeFalse();

    app()['env'] = 'local';

    expect(OpsNotifyPage::canAccess())->toBeTrue();
});

it('lets the viewOpsNotify gate decide who opens the page', function () {
    Gate::define('viewOpsNotify', fn ($user): bool => str_starts_with($user->email, 'admin'));

    expect(OpsNotifyPage::canAccess())->toBeTrue();

    $this->actingAs(User::query()->create(['name' => 'Guest', 'email' => 'guest@test.pl', 'password' => 'x']));

    expect(OpsNotifyPage::canAccess())->toBeFalse();
});

it('lets whoever may view also manage, unless manageOpsNotify says otherwise', function () {
    Gate::define('viewOpsNotify', fn (): bool => true);
    expect(OpsNotifyPage::canManage())->toBeTrue();

    Gate::define('manageOpsNotify', fn (): bool => false);
    expect(OpsNotifyPage::canManage())->toBeFalse();
});

// Hidden here means blocked too: Filament treats a hidden or unauthorized action as disabled,
// and a disabled action cannot be mounted or called.
it('hides the managing actions from view-only users', function () {
    Gate::define('viewOpsNotify', fn (): bool => true);
    Gate::define('manageOpsNotify', fn (): bool => false);
    $failed = OpsMessage::make('build.failed')->send()->fresh();

    Livewire::test(OpsNotifyPage::class)
        ->assertOk()
        ->assertActionHidden('settings')
        ->assertActionHidden('botProfile')
        ->assertActionHidden('sendTest')
        ->assertTableActionHidden('resend', $failed);
});

it('prefers the plugin closures over the gates', function () {
    Gate::define('viewOpsNotify', fn (): bool => false);
    Gate::define('manageOpsNotify', fn (): bool => false);

    OpsNotifyPlugin::get()
        ->authorize(fn (): bool => true)
        ->authorizeManagement(fn (): bool => true);

    expect(OpsNotifyPage::canAccess())->toBeTrue()
        ->and(OpsNotifyPage::canManage())->toBeTrue();
});
