<?php

namespace Spokospace\OpsNotify;

use Illuminate\Notifications\ChannelManager as NotificationChannelManager;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Spokospace\OpsNotify\Commands\DiscoverTelegramChatsCommand;
use Spokospace\OpsNotify\Commands\SendTestCommand;
use Spokospace\OpsNotify\Listeners\ForwardFilamentDatabaseNotification;
use Spokospace\OpsNotify\Notifications\OpsChannel;

class OpsNotifyServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('ops-notify')
            ->hasConfigFile()
            // Run straight from the package so every app gets the table on its next deploy, and
            // stay publishable (ops-notify-migrations) for apps that need to adjust them.
            ->discoversMigrations()
            ->runsMigrations()
            ->hasCommands([
                SendTestCommand::class,
                DiscoverTelegramChatsCommand::class,
            ]);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(ChannelManager::class);
        $this->app->singleton(OpsNotifier::class);
    }

    public function packageBooted(): void
    {
        // `via()` can return 'ops' in any Laravel notification.
        $this->callAfterResolving(NotificationChannelManager::class, function (NotificationChannelManager $manager): void {
            $manager->extend('ops', fn ($app) => $app->make(OpsChannel::class));
        });

        Event::listen(NotificationSent::class, ForwardFilamentDatabaseNotification::class);
    }
}
