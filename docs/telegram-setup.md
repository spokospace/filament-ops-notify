# Telegram setup

## 1. Create a bot

In Telegram, open [@BotFather](https://t.me/BotFather), send `/newbot`, and choose:

- **Display name**, which the chat list and messages show. **DashBot** is a good default. Use
  **DashBot · Shop** when several services post to the same group. You can change it later from
  the panel ([Bot profile](#5-bot-profile)).
- **Username**, which must be unique across Telegram and end in `bot`, for example
  `shop_panel_bot`. It cannot be changed from the panel.

BotFather replies with the **token**.

Keep the token out of git, chats and PR descriptions. If it leaks, send `/revoke` to BotFather and
enter the new one in the panel.

**One bot per service** (for example `shop_panel_bot`, `warehouse_panel_bot`). Messages are then
signed by the service that sent them, and each service keeps its own token.

## 2. Create a group with topics

1. Create a group and add the bot.
2. In the group settings, turn on **Topics**. Telegram converts the group into a supergroup, and its
   id changes to one starting with `-100`.
3. Make the bot an **admin** with at least *Send messages*. Add *Manage topics* if you want to create
   topics from the panel.

A common layout is one group for all services, with one topic per kind of event: *Inquiries*,
*Errors*, *Builds*, *Comments*.

## 3. Find the chat id

Save the token in **Ops notifications → Settings** first. Then send `/ping@your_bot` in the group
(in every topic, if you want their ids), and run:

```bash
php artisan ops-notify:telegram-chats
```

```
Bot: @shop_panel_bot
+----------------+------------+----------+
| Chat id        | Type       | Name     |
| -1001234567890 | supergroup | Ops      |
+----------------+------------+----------+
| Chat id        | Topic id   | Topic    |
| -1001234567890 | 3          | Inquiries|
+----------------+------------+----------+
```

In a group the bot only sees commands addressed to it, so use `/ping@your_bot`, not `/ping`.

You can also read the ids from a Telegram Web link: in `web.telegram.org/a/#-1001234567890_3`, the
chat id is `-1001234567890` and the topic id is `3`.

## 4. Enter the chat id and send a test

In **Settings**, enter the chat id and save. The status on the page should now say
**Connected as @your_bot**. Press **Send test**, or run:

```bash
php artisan ops-notify:test "Hello from the panel"
```

## 5. Bot profile

**Ops notifications → Bot profile** edits what Telegram shows for the bot, so you don't need to go
back to BotFather:

| Field | Limit | Where Telegram shows it |
|---|---|---|
| Avatar | A preset, or your own JPEG, PNG or WebP, cropped to 640×640 | Chat list, messages, profile |
| Display name | 64 characters | Chat list and above messages |
| Short description | 120 characters | The bot's profile page |
| Description | 512 characters | An empty chat with the bot |

**Keep current** leaves the avatar as it is, and **Remove avatar** deletes it. Only the fields you
change are sent to Telegram. The button appears once a bot token is saved.

Next: [manage topics and route events](routing-and-topics.md).
