<?php

namespace Spokospace\OpsNotify;

use Illuminate\Cache\RateLimiting\Unlimited;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Notifications\ChannelManager as NotificationChannelManager;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Spokospace\OpsNotify\Commands\DiscoverTelegramChatsCommand;
use Spokospace\OpsNotify\Commands\SendTestCommand;
use Spokospace\OpsNotify\Http\AvatarThumbnailController;
use Spokospace\OpsNotify\Jobs\SendOpsMessage;
use Spokospace\OpsNotify\Listeners\ForwardFilamentDatabaseNotification;
use Spokospace\OpsNotify\Listeners\ForwardLaravelNotification;
use Spokospace\OpsNotify\Models\OpsNotifyLog;
use Spokospace\OpsNotify\Notifications\OpsChannel;
use Spokospace\OpsNotify\Settings\SettingsStore;
use Spokospace\OpsNotify\Support\QueueStatus;

class OpsNotifyServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('ops-notify')
            ->hasConfigFile()
            ->hasTranslations()
            ->hasViews()
            // Run straight from the package so every app gets the tables on its next deploy.
            // Apps that publish and edit them turn this off with ops-notify.run_migrations
            // (see packageRegistered), or the package copy would run first and win.
            ->discoversMigrations()
            ->hasCommands([
                SendTestCommand::class,
                DiscoverTelegramChatsCommand::class,
            ]);
    }

    public function packageRegistered(): void
    {
        // Here, not in configurePackage(): only now is the package config merged, so the .env
        // value applies even when the app has not published the config file.
        $this->package->runsMigrations((bool) config('ops-notify.run_migrations', true));

        $this->app->singleton(SettingsStore::class);
        $this->app->singleton(ChannelManager::class);
        $this->app->singleton(OpsNotifier::class);
        // Scoped, not singleton: one instance per request so its memoised Horizon status is shared
        // by summary() and warning() in a render, but never carried across requests.
        $this->app->scoped(QueueStatus::class);
    }

    public function packageBooted(): void
    {
        // `via()` can return 'ops' in any Laravel notification.
        $this->callAfterResolving(NotificationChannelManager::class, function (NotificationChannelManager $manager): void {
            $manager->extend('ops', fn ($app) => $app->make(OpsChannel::class));
        });

        Event::listen(NotificationSent::class, ForwardFilamentDatabaseNotification::class);
        Event::listen(NotificationSent::class, ForwardLaravelNotification::class);

        // Per-destination send rate for SendOpsMessage (its RateLimited middleware uses this name).
        RateLimiter::for(SendOpsMessage::RATE_LIMITER, fn (SendOpsMessage $job): array|Unlimited => SendOpsMessage::limits($job));

        // Thumbnails for the avatar picker. A controller, not a closure, so route:cache works.
        Route::get('ops-notify/avatars/{key}.jpg', AvatarThumbnailController::class)
            ->where('key', '[a-z0-9-]+')
            ->name('ops-notify.avatar');

        // The package owns the log table and its retention, so it schedules the pruning too.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            if (filled($at = config('ops-notify.log.prune_at'))) {
                $schedule->command('model:prune', ['--model' => [OpsNotifyLog::class]])
                    ->dailyAt($at)
                    ->name('ops-notify:prune-log')
                    ->withoutOverlapping();
            }
        });
    }
}
