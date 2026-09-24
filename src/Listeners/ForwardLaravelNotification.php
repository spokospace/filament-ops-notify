<?php

namespace Spokospace\OpsNotify\Listeners;

use Filament\Notifications\DatabaseNotification;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spokospace\OpsNotify\OpsNotifier;
use Spokospace\OpsNotify\Settings\SettingsStore;
use Spokospace\OpsNotify\Support\Dedupe;
use Spokospace\OpsNotify\Support\LaravelNotificationConverter;
use Throwable;

/**
 * Mirrors any Laravel notification (mail, SMS, broadcast, …) to the ops channel, for apps
 * that do not use Filament's bell. Off by default: an app opts in and picks the channels.
 *
 * One message per notification: Laravel gives every send one id, shared by all its
 * recipients and channels, and Dedupe lets only the first copy through.
 */
class ForwardLaravelNotification
{
    public function __construct(
        private readonly OpsNotifier $notifier,
        private readonly LaravelNotificationConverter $converter,
        private readonly SettingsStore $settings,
    ) {}

    public function handle(NotificationSent $event): void
    {
        $notification = $event->notification;

        // Sent through ops already, or a Filament bell notification (forwarded by its own listener).
        if ($event->channel === 'ops' || $notification instanceof DatabaseNotification) {
            return;
        }

        $this->settings->apply();
        $config = (array) config('ops-notify.forward_notifications', []);

        if (! ($config['enabled'] ?? false) || ! $this->forwards($notification::class, $event->channel, $config)) {
            return;
        }

        // Forwarding must never fail the notification itself.
        try {
            if ($this->usesOpsChannel($notification, $event->notifiable)) {
                return;
            }

            // Laravel sets the id when sending; an event fired by hand may have none.
            if (Dedupe::isFirst('notification', filled($notification->id) ? $notification->id : spl_object_id($notification))) {
                $message = $this->converter->convert($notification, $event->notifiable, $event->channel);

                if ($message !== null) {
                    $this->notifier->send($message);
                }
            }
        } catch (Throwable $e) {
            Log::warning('[ops-notify] Could not forward '.$notification::class.': '.$e->getMessage());
        }
    }

    /** @param  array<string, mixed>  $config */
    private function forwards(string $class, string $channel, array $config): bool
    {
        $channels = array_map('strval', (array) ($config['channels'] ?? []));
        $only = array_map('strval', (array) ($config['only'] ?? []));
        $except = array_map('strval', (array) ($config['except'] ?? []));

        return in_array($channel, $channels, true)
            && ($only === [] || Str::is($only, $class))
            && ! Str::is($except, $class);
    }

    /** A notification that already routes to "ops" would otherwise arrive twice. */
    private function usesOpsChannel(object $notification, object $notifiable): bool
    {
        return method_exists($notification, 'via') && in_array('ops', (array) $notification->via($notifiable), true);
    }
}
