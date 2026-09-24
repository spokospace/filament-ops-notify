# Telegram setup

## 1. Create a bot

In Telegram, open [@BotFather](https://t.me/BotFather) and send `/newbot`. BotFather asks two
questions:

1. **Display name**, which the chat list and messages show. **DashBot** is a good default. Use
   **DashBot · Shop** when several services post to the same group. You can change it later from
   the panel ([Bot profile](#5-bot-profile)).
2. **Username**, which must be unique across Telegram and end in `bot`, for example
   `shop_panel_bot`. BotFather refuses anything else. It cannot be changed from the panel.

BotFather replies with the **token**, which looks like `123456789:AA...`.

Later, `/mybots` in BotFather lists your bots, and `/revoke` issues a new token. You do not need
`/setprivacy`: the bot will be an admin, and privacy mode does not apply to admins.

Keep the token out of git, `.env.example`, chats and PR descriptions. Paste it only into
**Ops Notify → Settings**. If it leaks, send `/revoke` to BotFather and enter the new token
in the panel.

**One bot per service** (for example `shop_panel_bot`, `warehouse_panel_bot`). Messages are then
signed by the service that sent them, and each service keeps its own token. Add and promote each
bot in the shared group.

## 2. Create a group with topics

It must be a **group**. Channels have no topics.

1. Create a group. Then open it, choose **Add members**, search for `@your_bot` and add it.
2. Turn on **Topics** (as the group's owner, or an admin allowed to change the group's info):
   - Telegram Desktop: open the group → **⋮** → **Manage group** → **Topics**.
   - iOS and Android: open the group info → **Edit** → **Topics**.

   Telegram upgrades the group to a supergroup, and its id changes to one starting with `-100`.
3. Promote the bot to admin: group settings → **Administrators** → **Add admin** → pick the bot.
   The only right the package uses is **Manage topics**, for *Create topic* in Settings. You can
   switch the other rights off. Being an admin also lets the bot post in closed topics.

A common layout is one group for all services, with one topic per kind of event: *Inquiries*,
*Errors*, *Builds*, *Comments*.

### What the bot sees

As an admin, the bot receives **every** message in the group; Telegram's privacy mode does not
apply to admins. The package reads those messages only during chat discovery
(`ops-notify:telegram-chats` and **Import from Telegram**). From them it keeps chat ids, types and
titles, and topic ids and names, in the cache. It stores no message text.

## 3. Find the chat id

Save the token in **Ops Notify → Settings** first. Then send `/ping@your_bot` in the group,
and in every topic whose id you want. Within 24 hours, run:

```bash
php artisan ops-notify:telegram-chats
```

```
 INFO  Bot: @shop_panel_bot.

+----------------+------------+------+-----------+
| Chat id        | Type       | Name | Topics on |
+----------------+------------+------+-----------+
| -1001234567890 | supergroup | Ops  | yes       |
+----------------+------------+------+-----------+
+----------------+----------+-----------+
| Chat id        | Topic id | Topic     |
+----------------+----------+-----------+
| -1001234567890 | 3        | Inquiries |
+----------------+----------+-----------+
```

Why `/ping@your_bot` and not any message: before the bot is an admin (or in a group where it is
not), it only sees commands addressed to it. The `@your_bot` suffix works in both cases.

Why within 24 hours: Telegram keeps pending updates for 24 hours and returns 100 per call. The
command pages through up to 10 calls, so a `/ping` behind a busy chat is still found. What it finds
is remembered for 30 days, so later runs and **Import from Telegram** still list it.

**Two chat ids.** If the bot was added before Topics were turned on, the list may show the old
group too, followed by a warning:

```
 WARN  Chat -4012345678 ("Ops") became supergroup -1001234567890 when Topics were turned on. Use -1001234567890.
```

Always use the `-100…` supergroup id.

To read the ids of another configured Telegram channel, add `--channel=name`.

You can also read the ids from a Telegram Web link: in `web.telegram.org/a/#-1001234567890_3`, the
chat id is `-1001234567890` and the topic id is `3`.

The *General* topic has no id. To send to *General*, leave **Default topic** empty.

## 4. Enter the chat id and send a test

In **Settings**, enter the chat id and save. The status on the page should now say
**Connected as @your_bot**. That only proves the token works (`getMe`). **Send test** proves the
rest: the chat id, that the bot is in the group, and its rights. Press it, or run:

```bash
php artisan ops-notify:test "Hello from the panel"
```

## 5. Bot profile

**Ops Notify → Bot profile** edits what Telegram shows for the bot, so you don't need to go
back to BotFather:

| Field | Limit | Where Telegram shows it |
|---|---|---|
| Avatar | A preset, or your own JPEG, PNG or WebP, cropped to 640×640 | Chat list, messages, profile |
| Display name | 64 characters | Chat list and above messages |
| Short description | 120 characters | The bot's profile page |
| Description | 512 characters | An empty chat with the bot |

**Keep current** leaves the avatar as it is, and **Remove avatar** deletes it. Only the fields you
change are sent to Telegram. The button appears once both a bot token and a chat id are saved.

Next: [manage topics and route events](routing-and-topics.md).
