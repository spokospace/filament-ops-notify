<?php

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spokospace\OpsNotify\Filament\Pages\OpsNotifyPage;
use Spokospace\OpsNotify\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

/** The Settings slide-over, opened by an admin whose bot has every right. */
function settingsForm(): mixed
{
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['status' => 'creator', 'username' => 'bot']])]);
    Filament::setCurrentPanel('admin');
    test()->actingAs(test()->admin());

    return Livewire::test(OpsNotifyPage::class)->mountAction('settings');
}
