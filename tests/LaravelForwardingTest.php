<?php

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Spokospace\OpsNotify\Enums\Level;
use Spokospace\OpsNotify\Jobs\SendOpsMessage;
use Spokospace\OpsNotify\OpsMessage;
use Spokospace\OpsNotify\Tests\Fixtures\OrderPlaced;
use Spokospace\OpsNotify\Tests\Fixtures\User;

beforeEach(function () {
    Queue::fake();
    config(['ops-notify.forward_notifications.enabled' => true, 'ops-notify.forward_notifications.channels' => ['mail']]);
});

function sent(Notification $notification, string $channel = 'mail', ?object $to = null): void
{
    $notification->id ??= (string) Str::uuid();
    event(new NotificationSent($to ?? new User(['email' => 'anna@shop.example']), $notification, $channel));
}

it('is off unless the app turns it on', function () {
    config(['ops-notify.forward_notifications.enabled' => null]);

    sent(new OrderPlaced);

    Queue::assertNothingPushed();
});

it('forwards a mail notification: subject, lines, button and event name', function () {
    sent(new OrderPlaced);

    Queue::assertPushed(SendOpsMessage::class, fn (SendOpsMessage $job): bool => $job->message->event === 'order_placed'
        && $job->message->title === 'New order #1042'
        && $job->message->lines === ['Anna ordered a front lamp.', 'Thank you for using our application!']
        && $job->message->buttons[0]->url === 'https://shop.example/orders/1042'
        && $job->message->level === Level::Info);
});

it('sends one message however many recipients and channels', function () {
    $notification = new OrderPlaced(['mail', 'database']);
    config(['ops-notify.forward_notifications.channels' => ['mail', 'database']]);

    sent($notification, 'mail');
    sent($notification, 'database');
    sent($notification, 'mail', new User(['email' => 'piotr@shop.example']));

    Queue::assertPushed(SendOpsMessage::class, 1);
});

it('only forwards the channels picked in settings', function () {
    sent(new OrderPlaced(['vonage']), 'vonage');

    Queue::assertNothingPushed();
});

it('builds the message from toArray for other channels', function () {
    config(['ops-notify.forward_notifications.channels' => ['database']]);

    sent(new OrderPlaced(['database']), 'database');

    Queue::assertPushed(SendOpsMessage::class, fn (SendOpsMessage $job): bool => $job->message->title === 'New order #1042'
        && $job->message->lines === ['Anna ordered a front lamp.']);
});

it('never forwards secrets such as password reset links', function () {
    sent(new ResetPassword('secret-token'));

    Queue::assertNothingPushed();
});

it('prefers toOps and the notification own event name', function () {
    $notification = new class extends OrderPlaced
    {
        public function opsEvent(): string
        {
            return 'order.placed';
        }

        public function toOps(object $notifiable): OpsMessage
        {
            return OpsMessage::make('order.placed')->success()->title('Order #1042 paid');
        }
    };

    sent($notification);

    Queue::assertPushed(SendOpsMessage::class, fn (SendOpsMessage $job): bool => $job->message->title === 'Order #1042 paid'
        && $job->message->event === 'order.placed');
});

it('leaves notifications that already use the ops channel alone', function () {
    sent(new OrderPlaced(['mail', 'ops']));

    Queue::assertNothingPushed();
});
