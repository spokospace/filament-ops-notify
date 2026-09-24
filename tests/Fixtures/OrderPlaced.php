<?php

namespace Spokospace\OpsNotify\Tests\Fixtures;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A plain Laravel mail notification, as an app without Filament's bell would send. */
class OrderPlaced extends Notification
{
    /** @param  list<string>  $via */
    public function __construct(public array $via = ['mail']) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $this->via;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('New order #1042')
            ->greeting('Hello!')
            ->line('Anna ordered a front lamp.')
            ->action('Open order', 'https://shop.example/orders/1042')
            ->line('Thank you for using our application!');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return ['title' => 'New order #1042', 'body' => 'Anna ordered a front lamp.', 'url' => 'https://shop.example/orders/1042'];
    }
}
