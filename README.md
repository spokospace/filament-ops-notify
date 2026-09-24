<picture>
  <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/spokospace/filament-ops-notify/main/art/cover-dark.jpg">
  <img class="filament-hidden" alt="Filament Ops Notify: inquiries, errors and builds from your Filament panel, delivered to Telegram forum topics" src="https://raw.githubusercontent.com/spokospace/filament-ops-notify/main/art/cover-light.jpg">
</picture>

# Filament Ops Notify

[![Tests](https://github.com/spokospace/filament-ops-notify/actions/workflows/tests.yml/badge.svg)](https://github.com/spokospace/filament-ops-notify/actions/workflows/tests.yml)
[![Latest release](https://img.shields.io/github/v/release/spokospace/filament-ops-notify)](https://github.com/spokospace/filament-ops-notify/releases)
[![PHP](https://img.shields.io/badge/PHP-8.3%20%7C%208.4%20%7C%208.5-777bb4)](https://www.php.net)
[![Laravel](https://img.shields.io/badge/Laravel-12%20%7C%2013-ff2d20)](https://laravel.com)
[![Filament](https://img.shields.io/badge/Filament-5-f59e0b)](https://filamentphp.com)

Operational notifications for [Laravel](https://laravel.com) + [Filament](https://filamentphp.com)
panels: inquiries, errors and builds, delivered to [Telegram](https://core.telegram.org/bots/api)
forum topics. Built on [Laravel notifications](https://laravel.com/docs/notifications), so existing
[Filament database notifications](https://filamentphp.com/docs/5.x/notifications/database-notifications)
reach Telegram with no code changes.

## Features

- **Bell notifications → Telegram, no code.** Every `sendToDatabase()` notification is forwarded
  once, however many users receive it ([with a shared cache](docs/routing-and-topics.md#forwarding-filament-notifications)).
- **Topics and routing.** Create forum topics from the panel and route events to them by pattern
  (`inquiry.*` → *Inquiries*). Filament notifications are routed by title.
- **Settings in the panel.** Token (encrypted), chat id, service name, topics and rules, all without
  touching `.env`.
- **Reliable delivery.** Queued, retried, rate-limit aware, and never breaks the request that sent it.
- **Bot profile.** Pick an avatar (a preset or your own) and set the display name and
  descriptions from the panel.
- **Translated.** The panel ships in 25 languages, and a separate setting controls the
  language of the messages.
- **History.** Every message is logged with its status and Telegram error, and failed ones can be
  resent.
- **`ops` notification channel and a fluent `OpsMessage`** for events that are not bell notifications.

## Screenshots

**The Ops Notify page:** the Telegram connection, where messages are delivered (queue and
Horizon state), and the history of every message sent, with failed ones ready to resend.

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/spokospace/filament-ops-notify/main/art/screenshots/page-dark.png">
  <img alt="Ops Notify page with the connection status and the message history" src="https://raw.githubusercontent.com/spokospace/filament-ops-notify/main/art/screenshots/page-light.png">
</picture>

**Settings:** bot token, chat id and message language, plus forum topics that can be created in
Telegram or imported from it. Filled lists collapse to a one-line summary.

<img alt="Settings slide-over with the Telegram connection and the topics list" src="https://raw.githubusercontent.com/spokospace/filament-ops-notify/main/art/screenshots/settings-light.png" width="600">

**Bot profile:** pick a preset avatar or upload your own, and set the name and descriptions
Telegram shows.

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/spokospace/filament-ops-notify/main/art/screenshots/bot-profile-dark.png">
  <img alt="Bot profile slide-over with preset avatars, display name and descriptions" src="https://raw.githubusercontent.com/spokospace/filament-ops-notify/main/art/screenshots/bot-profile-light.png" width="600">
</picture>

## Requirements

- PHP 8.3 / 8.4 / 8.5
- Laravel 12 / 13
- Filament 5

## Installation

```bash
composer require spokospace/filament-ops-notify
php artisan migrate
```

```php
use Spokospace\OpsNotify\Filament\OpsNotifyPlugin;

$panel->plugin(OpsNotifyPlugin::make()->navigationGroup('System'));
```

Then say who may use it. Until you do, the page opens only in the `local` environment:

```php
// In a service provider's boot(), with your app's own admin check:
Gate::define('viewOpsNotify', fn (User $user): bool => $user->is_admin);
```

`manageOpsNotify` can limit *Settings*, *Bot profile*, *Send test* and *Resend* further. See
[Who may use it](docs/installation.md#who-may-use-it).

Delivery runs on the queue, so the app needs a worker (Horizon or `queue:work`) and the scheduler.

## Quick start

1. Create a bot with [@BotFather](https://t.me/BotFather) and a Telegram group with **Topics**
   turned on. Add the bot and promote it to admin.
2. In the panel, open **Ops Notify → Settings** and enter the bot token and chat id.
3. Press **Send test**.

The [setup checklist](docs/setup-checklist.md) has every step in order. Installing with an AI
coding agent? Give it [Installing with an AI agent](docs/setup-checklist.md#installing-with-an-ai-agent).

Your existing Filament notifications now reach Telegram. To send something yourself:

```php
use Spokospace\OpsNotify\OpsMessage;

OpsMessage::make('build.completed')
    ->success()
    ->title('Frontend build completed')
    ->field('Duration', '4m 12s')
    ->button('Open site', 'https://shop.example')
    ->send();
```

In Telegram it reads:

```
✅ [shop.example.com] Frontend build completed

Duration: 4m 12s

#build_completed
```

with an **Open site** button below. The title and field labels are bold.

## Documentation

- [Setup checklist](docs/setup-checklist.md): what to set up, in order, the rights the bot needs, and
  instructions for AI agents
- [Installation](docs/installation.md): requirements, migrations, plugin options
- [Telegram setup](docs/telegram-setup.md): bot, group, topics, finding the chat id, bot profile
- [Settings](docs/settings.md): panel vs `.env`, every option, languages, the Ops Notify page
- [Routing and topics](docs/routing-and-topics.md): topics, event rules, forwarding Filament notifications
- [Sending messages](docs/sending.md): the `ops` channel, the `OpsMessage` API, delivery, tests
- [Drivers](docs/drivers.md): writing your own channel driver
- [Troubleshooting](docs/troubleshooting.md): common Telegram and queue errors and fixes

## Testing

```bash
composer test
```

## Credits

Built and maintained by [spoko.space](https://spoko.space), where it runs in production on our
own Filament panels.

## License

MIT, see [LICENSE.md](LICENSE.md).
