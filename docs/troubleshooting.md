# Troubleshooting

The message history on the **Spoko DashBot** page shows each failed message with the Telegram
error. Hover the status to see it. Delivery problems also show as a warning under
**Status → Delivery**.

## Telegram errors

| Symptom | Cause | Fix |
|---|---|---|
| `Telegram API 401: Unauthorized` | Wrong token, or one revoked in BotFather | Enter the current token in Settings |
| `Telegram API 400: Bad Request: chat not found` | Wrong chat id, or the bot is not in the group | Add the bot, then run `ops-notify:telegram-chats` and use the id it prints |
| `Bad Request: group chat was upgraded to a supergroup chat` | You entered the old group id. Turning on Topics gave the group a new id | Run `ops-notify:telegram-chats` and use the `-100…` supergroup id |
| `Bad Request: message thread not found` | The topic was deleted or the id is wrong | Import or re-create the topic in Settings |
| `Bad Request: not enough rights to create a topic` | The bot is not an admin, or lacks *Manage topics* | Group → **Administrators** → the bot → turn on *Manage topics* |
| `Telegram API 409: Conflict` from `telegram-chats` / *Import* | A webhook is set on the bot | `getUpdates` does not work while a webhook is set. Remove the webhook (`deleteWebhook`) |
| `telegram-chats` says *No chats yet* or *No topics yet* | The bot has not seen a `/ping`, it was a plain `/ping`, or it was sent over 24 hours ago | Send `/ping@your_bot` in each topic, then run it again within 24 hours |
| `telegram-chats` lists two chats with the same name | The group was upgraded to a supergroup; the command prints a warning | Use the `-100…` id the warning names |
| *Import from Telegram* finds nothing, or *Create topic* fails with *Save the bot token and chat id first* | Both use the saved token and chat id, not unsaved form values | Save the token and chat id, then try again |
| Imported or created topics are gone after closing Settings | They were only added to the form | Press **Save** |
| Status says `Not configured` | Token or chat id missing | Save both in Settings |
| Status says *Connected as @your_bot*, but nothing arrives | That only checks the token | Press **Send test** and read the error |
| The token field says it cannot be decrypted | `APP_KEY` changed | Enter the token again |
| A field in Settings is greyed out | It is set in `.env` or the config file | Change it there, or remove it to manage it in the panel |
| A button link was sent as text | Telegram rejected the URL (for example `localhost`, `.test`) | Expected fallback; set `APP_URL` to a public URL |

## Queue and delivery

| Symptom | Cause | Fix |
|---|---|---|
| Warning: *Horizon is paused* | Someone ran `horizon:pause` | `php artisan horizon:continue` |
| Warning: *Horizon is not running* | Horizon is stopped or crashed | Start it with `php artisan horizon` (or restart its Supervisor program) |
| Warning: *No Horizon supervisor works the "…" queue* | `OPS_NOTIFY_QUEUE` names a queue no supervisor lists for this environment | Add the queue to a supervisor's `queue` list in `config/horizon.php` ([example](sending.md#a-custom-queue-name)) |
| Warning: *N messages have been queued for over 5 minutes* | No worker runs, or none works this queue | Start Horizon or `php artisan queue:work --queue=<queue>`. On a host without workers, set `OPS_NOTIFY_QUEUE_CONNECTION=sync` |
| Messages stay `queued` | Same as above | Same as above |
| Nothing is sent from tests | Intended (`disable_in_tests`) | See [Sending → tests](sending.md#in-your-apps-tests) |
| Everything goes to *General* | No routing rule matches | Add title rules and event rules, see [Routing and topics](routing-and-topics.md) |
| The same notification arrives several times | More than `dedupe_seconds` passed between sends, the content differs, `dedupe_seconds` is `0`, or the cache is per-process (`array`) | Use a shared cache store. Identical payloads within 60 s are merged by default |
| `ops-notify:prune-log` is not in `php artisan schedule:list` | `log.prune_at` is `null` | Set it, or schedule `model:prune` for `OpsNotifyLog` yourself |
