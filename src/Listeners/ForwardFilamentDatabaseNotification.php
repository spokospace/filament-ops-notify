<?php

namespace Spokospace\OpsNotify\Listeners;

use Filament\Notifications\DatabaseNotification;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Log;
use Spokospace\OpsNotify\OpsNotifier;
use Spokospace\OpsNotify\Settings\SettingsStore;
use Spokospace\OpsNotify\Support\Dedupe;
use Spokospace\OpsNotify\Support\FilamentNotificationConverter;
use Spokospace\OpsNotify\Support\TitleRules;
use Throwable;

/**
 * Mirrors Filament bell notifications (Notification::make()->sendToDatabase($users)) to the
 * ops channel, so apps need no code changes.
 *
 * Filament calls $user->notify() once per recipient with an identical payload and no shared id;
 * Dedupe lets only the first copy through.
 */
class ForwardFilamentDatabaseNotification
{
    public function __construct(
        private readonly OpsNotifier $notifier,
        private readonly FilamentNotificationConverter $converter,
        private readonly SettingsStore $settings,
    ) {}

    public function handle(NotificationSent $event): void
    {
        if ($event->channel !== 'database' || ! $event->notification instanceof DatabaseNotification) {
            return;
        }

        $data = $event->notification->data;

        if (($data['format'] ?? null) !== 'filament') {
            return;
        }

        $this->settings->apply();
        $config = (array) config('ops-notify.forward_database_notifications', []);

        if (! ($config['enabled'] ?? false)) {
            return;
        }

        $opsEvent = TitleRules::eventFor((string) ($data['title'] ?? ''), $config);

        if ($opsEvent === null) {
            return;
        }

        // A forwarding problem (cache down, bad payload) must not fail the bell notification itself.
        try {
            if (Dedupe::isFirst('filament', $data)) {
                $this->notifier->send($this->converter->fromArray($data, $opsEvent));
            }
        } catch (Throwable $e) {
            Log::warning('[ops-notify] Could not forward Filament notification: '.$e->getMessage());
        }
    }
}
