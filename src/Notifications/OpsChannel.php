<?php

namespace Spokospace\OpsNotify\Notifications;

use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Notification;
use LogicException;
use Spokospace\OpsNotify\OpsMessage;
use Spokospace\OpsNotify\OpsNotifier;
use Spokospace\OpsNotify\Support\Dedupe;
use Spokospace\OpsNotify\Support\FilamentNotificationConverter;

/**
 * Laravel notification channel, registered as "ops". A notification opts in with:
 *
 *     public function via($notifiable): array { return ['database', 'ops']; }
 *
 *     public function toOps($notifiable): OpsMessage|FilamentNotification
 *     {
 *         return OpsMessage::make('build.failed')->error()->title('Build failed');
 *     }
 *
 * Without a user to notify: Notification::route('ops', ['topic' => 12])->notify(new BuildFailed);
 * The route may be a topic id or ['channel' => ..., 'topic' => ...]; the message's own values win.
 */
class OpsChannel
{
    public function __construct(
        private readonly OpsNotifier $notifier,
        private readonly FilamentNotificationConverter $converter,
    ) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toOps')) {
            throw new LogicException($notification::class.' must define toOps($notifiable) to use the "ops" channel.');
        }

        $message = $notification->toOps($notifiable);

        if ($message instanceof FilamentNotification) {
            $message = $this->converter->fromNotification($message, $this->eventName($notification));
        }

        if (! $message instanceof OpsMessage) {
            return;
        }

        $route = method_exists($notifiable, 'routeNotificationFor') ? $notifiable->routeNotificationFor('ops', $notification) : null;
        $route = is_array($route) ? $route : ['topic' => $route];

        $message->channel ??= $route['channel'] ?? null;
        $message->topic ??= isset($route['topic']) ? (string) $route['topic'] : null;

        // Notification::send($admins, ...) calls this once per admin; send one message.
        if (Dedupe::isFirst('channel', $message->toArray())) {
            $this->notifier->send($message);
        }
    }

    /** App\Notifications\BuildFailed → "build_failed". */
    private function eventName(Notification $notification): string
    {
        return method_exists($notification, 'opsEvent')
            ? (string) $notification->opsEvent()
            : str(class_basename($notification))->snake()->toString();
    }
}
