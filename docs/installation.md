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
                ->navigationSort(90),
        );
}
```

The plugin adds one page, **Ops Notify**, at `{panel path}/ops-notify` (for example
`/admin/ops-notify`). It has the connection status, a *Send test* button, the message history and
the *Settings* slide-over.

## Who may use it

Two permissions, the way Horizon and Telescope do it:

| Ability | Allows | Default |
|---|---|---|
| `viewOpsNotify` | Opening the page: status and message history | Nobody, except in the `local` environment |
| `manageOpsNotify` | *Settings*, *Bot profile*, *Send test*, *Resend* | Whoever has `viewOpsNotify` |

Define them in a service provider's `boot()`, with your app's own admin check:

```php
use Illuminate\Support\Facades\Gate;

Gate::define('viewOpsNotify', fn (User $user): bool => $user->is_admin);

// Optional: let more people see the history than change the settings.
Gate::define('manageOpsNotify', fn (User $user): bool => $user->is_owner);
```

With [Filament Shield](https://github.com/bezhanSalleh/filament-shield) or spatie/laravel-permission,
point the gates at permissions: `fn (User $user): bool => $user->can('view_ops_notify')`.

Or decide in the panel provider; a closure there wins over the gate:

```php
OpsNotifyPlugin::make()
    ->authorize(fn (): bool => auth()->user()?->is_admin ?? false)
    ->authorizeManagement(fn (): bool => auth()->user()?->is_owner ?? false)
```

**Use checks your `User` model really has.** The page's access check runs on every navigation
render, so calling a method the model does not define (for example `isAdmin()`) breaks the whole
panel, not just this page. Hidden actions are also blocked: Filament refuses to run them.

| Method | Default |
|---|---|
| `navigationGroup(string\|UnitEnum\|null)` | none |
| `navigationLabel(?string)` | `Ops Notify` (not translated; pass your own, e.g. a translated string) |
| `navigationSort(?int)` | none |
| `navigationIcon(string\|BackedEnum\|null)` | `Heroicon::OutlinedBellAlert` |
| `authorize(?Closure)` | the `viewOpsNotify` gate, else only `local` |
| `authorizeManagement(?Closure)` | the `manageOpsNotify` gate, else the same as `authorize` |

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
