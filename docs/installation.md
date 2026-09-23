# Installation

## Requirements

- PHP 8.3, 8.4 or 8.5
- Laravel 12 or 13
- [Filament](https://filamentphp.com) 5
- A queue worker for delivery (any driver; `sync` works but blocks the request)
- The Laravel scheduler, for pruning the message log

## Install the package

The package is not on Packagist yet, so add its repository to the app's `composer.json`:

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/spokospace/filament-ops-notify" }
]
```

```bash
composer require spokospace/filament-ops-notify
php artisan migrate
```

The migrations create two tables, `ops_notify_logs` and `ops_notify_settings`. Both are guarded
with `Schema::hasTable` and work on MySQL and MariaDB. They run straight from the package, so there
is nothing to publish. To change them, publish them with
`php artisan vendor:publish --tag=ops-notify-migrations`.

The config file is optional. Most settings can be edited in the panel. See [Settings](settings.md).

```bash
php artisan vendor:publish --tag=ops-notify-config
```

## Register the Filament plugin

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
                ->authorize(fn (): bool => (bool) auth()->user()?->isAdmin()),
        );
}
```

The plugin adds one page, **Ops notifications**, with the connection status, a *Send test* button,
the message history and the *Settings* slide-over. `authorize()` decides who can open it. Without
it, every user of the panel can, and the page holds the bot token, so restrict it.

| Method | Default |
|---|---|
| `navigationGroup(string\|UnitEnum\|null)` | none |
| `navigationSort(?int)` | none |
| `navigationIcon(string\|BackedEnum\|null)` | `Heroicon::OutlinedBellAlert` |
| `authorize(?Closure)` | every panel user |

## Next

[Set up Telegram](telegram-setup.md), then enter the token and chat id in the panel.
