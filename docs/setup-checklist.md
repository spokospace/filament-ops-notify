# Setup checklist

This is everything a service needs before alerts arrive, in order. The first table is required and
the rest is optional. Each step links to the details.

## Required

| # | Where | What |
|---|---|---|
| 1 | Server | Install the package and register the plugin in the panel ([Installation](installation.md)). Make sure a queue worker and the scheduler run |
| 2 | Telegram, [@BotFather](https://t.me/BotFather) | Create a bot with `/newbot` and copy the token ([Create a bot](telegram-setup.md#1-create-a-bot)) |
| 3 | Telegram | Create a group, turn on **Topics**, add the bot and **promote it to admin** ([Group](telegram-setup.md#2-create-a-group-with-topics)) |
| 4 | Panel, **Ops Notify → Settings** | Paste the **bot token** and save |
| 5 | Telegram and server | Send `/ping@your_bot` in the group and in each topic, then within 24 hours run `php artisan ops-notify:telegram-chats` to read the **chat id** ([Chat id](telegram-setup.md#3-find-the-chat-id)) |
| 6 | Panel, **Settings** | Enter the chat id (the `-100…` one) and save |
| 7 | Panel | Check that the status says **Connected as @your_bot**, then press **Send test** |

Once step 7 passes, every Filament bell notification (`sendToDatabase()`) reaches the group's
*General* topic.

### Verify

- Press **Send test** with **Send through the queue** on. The new row in the history turns from
  *Queued* to *Sent* within a few seconds. If it stays *Queued*, read the warning under
  **Status → Delivery** ([Troubleshooting](troubleshooting.md)).
- Run `php artisan schedule:list` and check that `ops-notify:prune-log` is listed.

## Recommended

| Where | What | Why |
|---|---|---|
| Settings → **Service name** | e.g. `shop.example.com` | It prefixes every message, so you know which service sent it. Defaults to `APP_NAME` |
| Settings → **Message language** | e.g. Polish | The language of the text the package adds to messages. Defaults to the app locale |
| Settings → **Topics** | **Create topic** or **Import from Telegram**, then **Save** | One topic per kind of event, such as *Inquiries*, *Errors* and *Builds* ([Topics](routing-and-topics.md)) |
| Settings → **Event routing** | `inquiry.*` → *Inquiries* | Sends your own `OpsMessage` events to a topic |
| Settings → **Filament notifications** | `New inquiry*` → `inquiry.created` | Gives bell notifications an event name, so routing can send them to a topic |
| **Bot profile** | Avatar, display name, descriptions | Sets how the bot looks in the chat list ([Bot profile](telegram-setup.md#5-bot-profile)) |

## Bot permissions

Promote the bot to admin: group → **Administrators** → **Add admin** → pick the bot.

| Admin right | Needed for |
|---|---|
| *Manage topics* | **Create topic** in Settings |

That is the only right the package uses; the others can be switched off. Group admin rights have
no "send messages" switch: every member can post, and being an admin also lets the bot post in
closed topics.

As an admin the bot receives every message in the group. The package reads them only during chat
discovery (`ops-notify:telegram-chats` and **Import from Telegram**). It keeps only chat ids, types
and titles, and topic ids and names, in the cache, and stores no message text. See
[What the bot sees](telegram-setup.md#what-the-bot-sees).

## Several services

Each service has its own bot and token. Add each bot to the shared group and promote it. Each
service's panel then shows its own history.

## Installing with an AI agent

Give this section to a coding agent (Claude Code, Cursor, Copilot, …) that installs the package.
Part of the setup happens in Telegram and needs a human.

### The agent may

1. Run `composer require spokospace/filament-ops-notify`.
2. Run `php artisan migrate`.
3. Register the plugin in the app's existing `app/Providers/Filament/*PanelProvider.php`. Adapt the
   `authorize()` closure to the app's own admin check (an existing gate, role, `is_admin` column
   or policy). Never call `isAdmin()` or any other method the `User` model does not define.
4. Check that a queue worker (Horizon or `queue:work`) and the scheduler run on the server.
5. After the human has finished the Telegram steps below, run:

   ```bash
   php artisan ops-notify:telegram-chats
   php artisan ops-notify:test
   php artisan ops-notify:test --queue
   ```

   Then check that the queued row turns *Sent* in the history on the **Ops Notify** page.
6. Run `php artisan schedule:list` and check that `ops-notify:prune-log` is listed.

### Stop and ask the human to

- Create the bot in [@BotFather](https://t.me/BotFather) ([steps](telegram-setup.md#1-create-a-bot)).
- Create the group, turn on **Topics**, add the bot and promote it to admin
  ([steps](telegram-setup.md#2-create-a-group-with-topics)).
- Send `/ping@<bot username>` in the group and in each topic.
- Paste the bot token into the panel: **Settings** on `/{panel path}/ops-notify`. Not into the chat
  with the agent.

### Never

- Write the token to `.env`, `.env.example`, any committed file, a PR, a commit message or the chat.
- Set `OPS_NOTIFY_*` env keys unless the human asks. They lock the matching fields in the panel.
- Guess or invent a chat id. Use the one `ops-notify:telegram-chats` prints (the `-100…` one).
- Switch the queue connection to `sync` in production without asking.
- Publish the migrations unless the human asks.

### Expected output

| Output | Meaning |
|---|---|
| `Sent.` | Delivered. The setup works |
| `Queued, unless the event is disabled; check the log on the Filament page.` | `--queue` dispatched the job. Check the history for *Sent* |
| `Nothing sent: Notifications are disabled globally or for the [ops.test] event.` | Notifications are off in Settings, or an event rule disables `ops.test` |
| `Telegram API 401: Unauthorized` | Wrong or revoked token |
| `Telegram API 400: Bad Request: chat not found` | Wrong chat id, or the bot is not in the group |
| `Telegram API 400: Bad Request: group chat was upgraded to a supergroup chat` | The old group id was entered. Use the `-100…` id from `telegram-chats` |
| `Telegram API 409: Conflict: …` | A webhook is set on the bot, so `getUpdates` is unavailable |
| `No chats yet. Add the bot to your group, send /ping@… in each topic …` | The bot has not seen a `/ping@bot` yet, or it was sent more than 24 hours ago |
| `No topics yet. Send /ping@… in each topic you want to use …` | The chat was found, but no message in a topic |
| `Chat … became supergroup … Use ….` | Use the supergroup id it names |
