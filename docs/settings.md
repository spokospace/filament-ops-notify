# Settings

Open **Ops Notify → Settings** in the panel. The page is at `{panel path}/ops-notify`, for
example `/admin/ops-notify`. The values are stored in `ops_notify_settings` and overlaid on
`config('ops-notify.*')`.

Nothing is kept until you press **Save**. That includes rows added by **Import from Telegram** and
**Create topic**: they only add rows to the form. Both actions use the **saved** token and chat id,
not the values typed into the form, so save those first.

| Field | Config key | `.env` |
|---|---|---|
| Service name (message prefix, defaults to the app name) | `service` | `OPS_NOTIFY_SERVICE` |
| Message language (defaults to the app locale) | `locale` | `OPS_NOTIFY_LOCALE` |
| Bot token (encrypted) | `channels.telegram.bot_token` | `OPS_NOTIFY_TELEGRAM_BOT_TOKEN` |
| Chat id | `channels.telegram.chat_id` | `OPS_NOTIFY_TELEGRAM_CHAT_ID` |
| Default topic | `channels.telegram.topic` | `OPS_NOTIFY_TELEGRAM_TOPIC` |
| Topics | `channels.telegram.topics` | |
| Notifications enabled | `enabled` | `OPS_NOTIFY_ENABLED` |
| Event routing | `events` | |
| Forward bell notifications | `forward_database_notifications.enabled` | `OPS_NOTIFY_FORWARD_DATABASE` |
| Filament forwarding rules | `forward_database_notifications.map` | |

New to the package? Start with the [setup checklist](setup-checklist.md).

Once **Topics**, **Event routing** and **Filament notifications** have saved items, they start
collapsed and their header lists what is inside (`Inquiries #3 · Errors #2`). You can check the
setup without expanding them.

## Panel or `.env`?

- **`.env` / config wins.** A value set there locks its field in the panel, and the field shows the
  value that is really in effect.
- **The token is encrypted** with `APP_KEY`, both in the database and in the cache, and is never
  shown back in the form. Leave the field empty to keep the saved token. After an `APP_KEY`
  rotation the saved token cannot be decrypted, and the form asks for it again.
- **Settings are cached forever** and the cache is cleared on save. Alerts therefore keep working
  while the database is down, and long-running queue workers (Horizon) pick up changes without a
  restart.

## Other config keys

These are not in the panel. Set them in `.env` where there is a variable, or in the published
`config/ops-notify.php`:

| Key | `.env` | Default | Meaning |
|---|---|---|---|
| `default_channel` | `OPS_NOTIFY_CHANNEL` | `telegram` | Channel used when an event has no rule |
| `channels.telegram.api_url` | `OPS_NOTIFY_TELEGRAM_API_URL` | `https://api.telegram.org` | For proxies or a local Bot API server |
| `channels.telegram.timeout` | | 10 s | HTTP timeout per Telegram call |
| `dedupe_seconds` | | 60 | Identical messages within this window are sent once. `0` turns it off |
| `burst.max_per_event` / `burst.window_minutes` | | 10 / 5 | Past this many messages of one event in the window, the rest are held back and summed up in one digest. `0` turns it off. See [Delivery](sending.md#delivery) |
| `rate_limit.per_second` / `rate_limit.per_minute` | | 1 / 20 | Send rate per channel; the rest wait in the queue. `0` turns a limit off |
| `rate_limit.give_up_after_minutes` | | 60 | A message still undelivered after this long is marked failed |
| `forward_database_notifications.default_event` | | `filament.notification` | Event name for titles that no rule matches. `null` forwards only matched titles |
| `queue.connection` | `OPS_NOTIFY_QUEUE_CONNECTION` | app default | Queue connection for `SendOpsMessage` jobs ([Delivery](sending.md#delivery)) |
| `queue.name` | `OPS_NOTIFY_QUEUE` | the connection's default queue | Queue name for `SendOpsMessage` jobs |
| `run_migrations` | `OPS_NOTIFY_RUN_MIGRATIONS` | `true` | Run the package's migrations. Turn off after publishing them ([Installation](installation.md#install-the-package)) |
| `log.enabled` | `OPS_NOTIFY_LOG_ENABLED` | `true` | Store every message in `ops_notify_logs` |
| `log.prune_after_days` / `log.prune_at` | | 30 / `02:45` | Daily pruning, scheduled by the package as `ops-notify:prune-log`. `prune_at: null` lets you schedule it yourself |
| `disable_in_tests` | | `true` | Send nothing while the app's test suite runs |

## A second Telegram channel

The panel edits only the channel named `telegram`. A second Telegram channel in the same app (for
example another group for errors) is config-only:

```php
// config/ops-notify.php
'channels' => [
    'telegram' => [/* … */],
    'telegram_errors' => [
        'driver' => 'telegram',
        'bot_token' => env('OPS_NOTIFY_ERRORS_BOT_TOKEN'),
        'chat_id' => env('OPS_NOTIFY_ERRORS_CHAT_ID'),
    ],
],
'events' => [
    'error.*' => ['channel' => 'telegram_errors'],
],
```

Route events to it with `events.*.channel` or `->channel('telegram_errors')`, and find its chat id
with `php artisan ops-notify:telegram-chats --channel=telegram_errors`.

## Languages

The panel follows the viewer's locale. The package ships 25 translations:

`bg` `cs` `da` `de` `el` `en` `es` `et` `fi` `fr` `hr` `hu` `it` `lt` `lv` `nb` `nl` `pl`
`pt_BR` `ro` `sk` `sl` `sv` `tr` `uk`

Any other locale falls back to English.

**Message language** is a separate setting. It sets the language of the text the package adds to
Telegram messages (for example *and 3 more fields*), so every message in a chat is in the same
language, whoever triggered it. Titles and bodies you write are sent unchanged.

To change a string or add a language, publish the translation files:

```bash
php artisan vendor:publish --tag=ops-notify-translations
```

## The page

- **Status:** service name, enabled flag, channel, connection and delivery.
  - **Connection** calls `getMe` and is cached for 10 minutes; errors are cached for 1 minute.
    *Connected as @your_bot* only proves the token. **Send test** proves the chat id and rights.
  - **Delivery** shows `connection · queue`, or *Immediately (sync queue)*, plus Horizon's state
    when the queue runs on Horizon. A warning appears below when Horizon is paused or not running,
    when no Horizon supervisor works the queue, or when messages have waited over 5 minutes
    ([Delivery](sending.md#delivery)).
- **Bot profile:** avatar, display name and descriptions ([details](telegram-setup.md#5-bot-profile)).
  The button needs both a saved token and a saved chat id.
- **Send test:** goes through the queue by default, so it also tests the worker; the row turns
  *Sent* in the history. Turn off **Send through the queue** to send right away, which only checks
  the token and chat, and shows the result or the Telegram error at once. The toggle is hidden on
  the `sync` connection.
- **History:** every message with its event, level, status, attempts, channel and topic. Filter by
  status or level. It refreshes every 5 seconds while a row is *Queued*. A failed row has
  **Resend**, which goes through the queue like a new message (or right away on `sync`); after a
  resend the row is marked *Resent* with a link to the new row.
