# Setup checklist

This is everything a service needs before alerts arrive, in order. The first table is required and
the rest is optional. Each step links to the details.

## Required

| # | Where | What |
|---|---|---|
| 1 | Server | Install the package and register the plugin in the panel ([Installation](installation.md)) |
| 2 | Telegram, [@BotFather](https://t.me/BotFather) | Create a bot with `/newbot` and copy the token ([Create a bot](telegram-setup.md#1-create-a-bot)) |
| 3 | Telegram | Create a group, turn on **Topics** and make the bot an **admin** ([Group](telegram-setup.md#2-create-a-group-with-topics)) |
| 4 | Panel, **Ops notifications → Settings** | Paste the **bot token** and save |
| 5 | Telegram and server | Send `/ping@your_bot` in the group and run `ops-notify:telegram-chats` to read the **chat id** ([Chat id](telegram-setup.md#3-find-the-chat-id)) |
| 6 | Panel, **Settings** | Enter the chat id and save |
| 7 | Panel | Check that the status says **Connected as @your_bot**, then press **Send test** |

Once step 7 passes, every Filament bell notification (`sendToDatabase()`) reaches the group's
*General* topic.

## Recommended

| Where | What | Why |
|---|---|---|
| Settings → **Service name** | e.g. `shop.example.com` | It prefixes every message, so you know which service sent it. Defaults to `APP_NAME` |
| Settings → **Message language** | e.g. Polish | The language of the text the package adds to messages. Defaults to the app locale |
| Settings → **Topics** | **Create topic** or **Import from Telegram** | One topic per kind of event, such as *Inquiries*, *Errors* and *Builds* ([Topics](routing-and-topics.md)) |
| Settings → **Event routing** | `inquiry.*` → *Inquiries* | Sends your own `OpsMessage` events to a topic |
| Settings → **Filament notifications** | `New inquiry*` → `inquiry.created` | Gives bell notifications an event name, so routing can send them to a topic |
| **Bot profile** | Avatar, display name, descriptions | Sets how the bot looks in the chat list ([Bot profile](telegram-setup.md#5-bot-profile)) |

## Bot permissions

| Admin right | Needed for |
|---|---|
| *Send messages* | Every alert |
| *Manage topics* | **Create topic** in Settings |

The bot never reads the group's conversation. It only sees commands addressed to it, such as
`/ping@your_bot`, and that is all **Import from Telegram** and `ops-notify:telegram-chats` rely on.
