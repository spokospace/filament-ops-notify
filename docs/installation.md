# Installation

## Requirements

- PHP 8.3, 8.4 or 8.5
- Laravel 12 or 13
- [Filament](https://filamentphp.com) 5
- A queue worker for delivery: Horizon or `queue:work`, any driver. The `sync` connection works
  without a worker but sends inside the request. See [Sending → Delivery](sending.md#delivery).
- The Laravel scheduler, for pruning the message log

## Install the package

```bash
composer require spokospace/filament-ops-notify
php artisan migrate
```

The service provider and the `OpsNotify` facade are registered automatically.

The migrations create two tables, `ops_notify_logs` and `ops_notify_settings`. They use a plain
schema, so they work on MySQL, MariaDB, PostgreSQL and SQLite, and both are guarded with
`Schema::hasTable`. They run straight from the package, so there is nothing to publish.

To change them, publish them and stop the package copies from running:

```bash
php artisan vendor:publish --tag=ops-notify-migrations
```

```env
OPS_NOTIFY_RUN_MIGRATIONS=false
```

Without the second step the package copies still run, and your edits are ignored.

The config file is optional. Most settings can be edited in the panel. See [Settings](settings.md).

```bash
php artisan vendor:publish --tag=ops-notify-config
```

## Register the Filament plugin

Add the plugin to the app's existing panel provider (`app/Providers/Filament/*PanelProvider.php`):

```php
use Spokospace\OpsNotify\Filament\OpsNotifyPlugin;

public function panel(Panel $panel): Panel
{
    return $panel
        // ...
        ->plugin(
            OpsNotifyPlugin::make()
                ->navigationGroup('System')
                ->navigationSort(90)
                ->authorize(fn (): bool => auth()->user()?->can('viewOpsNotify') ?? false),
        );
}
```

The plugin adds one page, **Spoko DashBot**, at `{panel path}/ops-notify` (for example
`/admin/ops-notify`). It has the connection status, a *Send test* button, the message history and
the *Settings* slide-over.

`authorize()` decides who can open the page. Without it, every user of the panel can, and the page
holds the bot token, so restrict it.

**Use your app's own admin check.** Do not copy `isAdmin()` unless your `User` model defines it.
The closure runs on every navigation render, so a call to a missing method breaks the whole panel,
not just this page. Two options that work everywhere:

```php
// A gate or policy ability, defined in a service provider:
// Gate::define('viewOpsNotify', fn (User $user): bool => $user->is_admin);
->authorize(fn (): bool => auth()->user()?->can('viewOpsNotify') ?? false)

// An email allowlist:
->authorize(fn (): bool => in_array(auth()->user()?->email, ['ops@shop.example'], true))
```

| Method | Default |
|---|---|
| `navigationGroup(string\|UnitEnum\|null)` | none |
| `navigationLabel(?string)` | `Spoko DashBot` (not translated; pass your own, e.g. a translated string) |
| `navigationSort(?int)` | none |
| `navigationIcon(string\|BackedEnum\|null)` | `Heroicon::OutlinedBellAlert` |
| `authorize(?Closure)` | every panel user |

## What else the package registers

| What | Details |
|---|---|
| Commands | `ops-notify:test` and `ops-notify:telegram-chats` ([Sending → CLI](sending.md#from-the-command-line)) |
| Scheduled task | `ops-notify:prune-log`, daily at `02:45`. Check it with `php artisan schedule:list` |
| Public route | `GET ops-notify/avatars/{key}.jpg` (name `ops-notify.avatar`). Serves the preset avatar thumbnails for the bot profile picker |
| Notification channel | `ops`, for `via()` in any Laravel notification |
| Facade | `OpsNotify`, aliased automatically ([Sending → facade](sending.md#the-opsnotify-facade)) |
| Event listener | Forwards Filament database notifications |

## Next

[Set up Telegram](telegram-setup.md), then enter the token and chat id in the panel.
