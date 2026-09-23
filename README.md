# Filament Ops Notify

Operational notifications for Laravel + Filament panels: inquiries, errors and builds, delivered to
Telegram. Built on Laravel notifications, so existing Filament bell notifications
(`Notification::make()->sendToDatabase($users)`) reach Telegram with no code changes.

- Laravel 12/13, Filament 5, PHP 8.3+
- Telegram driver (a channel-agnostic core, so other drivers such as WhatsApp can be added)
- Queued delivery with retries, rate-limit handling and a log of every message
- Filament page: connection status, "Send test", history, resend failed

## Install

The package is private, so add the repository to the app's `composer.json`:

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/spokospace/filament-ops-notify" }
]
```

```bash
composer require spokospace/filament-ops-notify
php artisan migrate          # creates ops_notify_logs
```

Register the plugin in the panel provider:

```php
use Spokospace\OpsNotify\Filament\OpsNotifyPlugin;

->plugin(OpsNotifyPlugin::make()
    ->navigationGroup('System')
    ->authorize(fn () => auth()->user()?->is_admin))
```

## Telegram setup

1. Create a bot with [@BotFather](https://t.me/BotFather) (`/newbot`) and copy the token.
2. Create a group, enable **Topics** in its settings, add the bot and make it an admin.
3. Send `/ping` in every topic you want to use, then run:

```bash
php artisan ops-notify:telegram-chats   # prints the chat id and topic ids
```

4. Put them in `.env`:

```env
OPS_NOTIFY_SERVICE=panel.polo.blue          # prefix of every message
OPS_NOTIFY_TELEGRAM_BOT_TOKEN=123456:ABC...
OPS_NOTIFY_TELEGRAM_CHAT_ID=-1001234567890
OPS_NOTIFY_TELEGRAM_TOPIC=                  # optional default topic
```

5. `php artisan ops-notify:test` sends a test message.

Use one bot per service and one topic per kind of event (inquiries, errors, builds), all in the
same group.

## Sending

### Filament bell notifications (automatic)

Every Filament database notification is forwarded once, however many users receive it. Route or
drop them by title in `config/ops-notify.php`:

```php
'forward_database_notifications' => [
    'enabled' => true,
    'default_event' => 'filament.notification',   // null = forward only mapped titles
    'map' => [
        'Nowe zapytanie*' => 'inquiry.created',
        'Export completed*' => false,              // don't forward
    ],
],

'events' => [
    'inquiry.*' => ['topic' => env('OPS_NOTIFY_TOPIC_INQUIRIES')],
],
```

### Laravel notifications

Return `'ops'` from `via()` and add `toOps()`, which returns an `OpsMessage` or a Filament `Notification`:

```php
public function via($notifiable): array
{
    return ['database', 'ops'];
}

public function toOps($notifiable): OpsMessage
{
    return OpsMessage::make('build.failed')->error()->title('Build failed');
}
```

Without a user: `Notification::route('ops', ['topic' => 12])->notify(new BuildFailed);`

### Directly

```php
use Spokospace\OpsNotify\OpsMessage;

OpsMessage::make('build.finished')
    ->success()
    ->title('Frontend build finished')
    ->line('Deployed release 2026.09.23')
    ->field('Duration', '4m 12s')
    ->button('Open site', 'https://catalog.polo.blue')
    ->send();          // queued, never throws; ->sendNow() delivers synchronously and throws
```

## Events and routing

`events` keys are `Str::is()` patterns (first match wins) with `enabled`, `channel` and `topic`.
The message's own `->topic()` / `->channel()` override them. Each message ends with a hashtag of the
event (`#inquiry_created`), so every event is searchable in the chat.

## Log

Every message is stored in `ops_notify_logs`. To remove old rows, schedule:

```php
Schedule::command('model:prune', ['--model' => [\Spokospace\OpsNotify\Models\OpsNotifyLog::class]])->daily();
```

## Adding a driver

```php
app(\Spokospace\OpsNotify\ChannelManager::class)
    ->extend('whatsapp', fn ($app, array $config) => new WhatsAppChannel($config));
```

A driver implements `Spokospace\OpsNotify\Contracts\Channel`.

## Tests

```bash
composer test
```
