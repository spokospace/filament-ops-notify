<?php

namespace Spokospace\OpsNotify\Tests\Fixtures;

use Filament\Panel;
use Filament\PanelProvider;
use Spokospace\OpsNotify\Filament\OpsNotifyPlugin;

class TestPanelProvider extends PanelProvider
{
    public static bool $authorized = true;

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('admin')
            ->path('admin')
            ->default()
            ->plugin(OpsNotifyPlugin::make()->authorize(fn (): bool => static::$authorized));
    }
}
