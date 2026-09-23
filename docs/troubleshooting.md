# Troubleshooting

The message history on the **Ops notifications** page shows each failed message with the Telegram
error. Hover the status to see it.

| Symptom | Cause | Fix |
|---|---|---|
| `Telegram API 401: Unauthorized` | Wrong token, or one revoked in BotFather | Enter the current token in Settings |
| `Telegram API 400: Bad Request: chat not found` | Wrong chat id, or the bot is not in the group | Add the bot, then run `ops-notify:telegram-chats`. After enabling Topics the id changes to `-100…` |
| `Bad Request: message thread not found` | The topic was deleted or the id is wrong | Import or re-create the topic in Settings |
| `Bad Request: not enough rights to create a topic` | The bot lacks *Manage topics* | Group settings → Administrators → the bot → Manage topics |
| `Telegram API 409: Conflict` from `telegram-chats` / *Import* | A webhook is set on the bot | `getUpdates` does not work while a webhook is set |
| `telegram-chats` shows no chats or topics | The bot only sees commands addressed to it | Send `/ping@your_bot`, not `/ping`, in each topic |
| Status says `Not configured` | Token or chat id missing | Save both in Settings |
| The token field says it cannot be decrypted | `APP_KEY` changed | Enter the token again |
| A field in Settings is greyed out | It is set in `.env` or the config file | Change it there, or remove it to manage it in the panel |
| Messages stay `queued` | No queue worker is running | Start the worker (Horizon / `queue:work`), or set `queue.connection` to `sync` |
| Nothing is sent from tests | Intended (`disable_in_tests`) | See [Sending → tests](sending.md#in-your-apps-tests) |
| Everything goes to *General* | No routing rule matches | Add title rules and event rules, see [Routing and topics](routing-and-topics.md) |
| The same notification arrives several times | More than `dedupe_seconds` passed between sends, or the content differs | Identical payloads within 60 s are merged by default |
| A button link was sent as text | Telegram rejected the URL (for example `localhost`, `.test`) | Expected fallback; set `APP_URL` to a public URL |
