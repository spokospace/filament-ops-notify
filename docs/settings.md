# Settings

Open **Ops notifications → Settings** in the panel. The values are stored in
`ops_notify_settings` and overlaid on `config('ops-notify.*')`.

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

These live only in `config/ops-notify.php`:

| Key | Default | Meaning |
|---|---|---|
| `default_channel` | `telegram` | Channel used when an event has no rule |
| `channels.telegram.api_url` / `timeout` | Telegram API, 10 s | For proxies or a local Bot API server |
| `dedupe_seconds` | 60 | Identical messages within this window are sent once. `0` turns it off |
| `forward_database_notifications.default_event` | `filament.notification` | Event name for titles that no rule matches. `null` forwards only matched titles |
| `queue.connection` / `queue.name` | app default | Where `SendOpsMessage` jobs go |
| `log.enabled` | `true` | Store every message in `ops_notify_logs` |
| `log.prune_after_days` / `log.prune_at` | 30 / `02:45` | Daily pruning scheduled by the package. `prune_at: null` lets you schedule it yourself |
| `disable_in_tests` | `true` | Send nothing while the app's test suite runs |

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

- **Status:** service name, enabled flag, channel and connection. The connection check calls
  `getMe` and is cached for 10 minutes; errors are cached for 1 minute.
- **Bot profile:** avatar, display name and descriptions ([details](telegram-setup.md#5-bot-profile)).
- **Send test:** delivers immediately and shows the result or the Telegram error.
- **History:** every message with its event, level, status, attempts, channel and topic. Filter by
  status or level. A failed row has **Resend**; after a resend it is marked *Resent* with a link to
  the new row.
