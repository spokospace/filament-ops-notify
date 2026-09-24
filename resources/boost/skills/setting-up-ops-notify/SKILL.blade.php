---
name: setting-up-ops-notify
description: "Use this skill when asked to install, configure, verify or troubleshoot spokospace/filament-ops-notify (Telegram notifications from a Filament panel): registering the plugin, the Telegram bot, group, topics, chat id, routing rules, queue delivery, or errors such as 'chat not found', 'Unauthorized', 'Conflict', or messages stuck as queued. Do not use for sending messages from application code; the core guidelines cover OpsMessage."
license: MIT
metadata:
  author: spokospace
---
@php
/** @var \Laravel\Boost\Install\GuidelineAssist $assist */
@endphp
# Setting up Filament Ops Notify

Part of the setup happens in Telegram and needs a human. Do the app side, then stop and hand over with exact instructions. Full docs: https://github.com/spokospace/filament-ops-notify/blob/main/docs/setup-checklist.md

## 1. App side (the agent does this)

Register the plugin in the existing panel provider (`app/Providers/Filament/*PanelProvider.php`). Base `authorize()` on the app's own admin check: read the User model first and never call a method it does not define, because `canAccess()` runs on every navigation render and a missing method breaks the whole panel.

@verbatim
<code-snippet name="Register the plugin" lang="php">
use Spokospace\OpsNotify\Filament\OpsNotifyPlugin;

->plugin(
    OpsNotifyPlugin::make()
        ->navigationGroup('System')
        // Define the gate (Gate::define('viewOpsNotify', ...)) or use the app's existing admin check.
        ->authorize(fn (): bool => auth()->user()?->can('viewOpsNotify') ?? false),
)
</code-snippet>
@endverbatim

Then run the migrations:

```bash
{{ $assist->artisanCommand('migrate') }}
```

Confirm delivery can work: a queue worker must run for the connection in `config/queue.php` (Horizon, or `queue:work`), and the scheduler must run for log pruning (`{{ $assist->artisanCommand('schedule:list') }}` lists `ops-notify:prune-log`).

## 2. Human side (stop and ask)

Tell the human to:

1. Create a bot with @BotFather (`/newbot`; the username must end in `bot`) and paste the token into **Ops Notify → Settings** in the panel. Never ask them to paste it into the chat, `.env` or a file.
2. Create a Telegram **group** (not a channel), turn on **Topics** (only the owner can), add the bot and promote it to admin with **Manage topics**.
3. Send `/ping@<bot_username>` in every topic they want to use.

Wait until they confirm.

## 3. Find the ids and verify

```bash
{{ $assist->artisanCommand('ops-notify:telegram-chats') }}
```

Give the human the supergroup id that starts with `-100` to enter as **Chat id** in Settings. If the command prints `Chat X became supergroup Y`, use Y. Topics can be imported in Settings with **Import from Telegram**, then **Save**.

First send right away, which shows Telegram's error if the token or chat is wrong:

```bash
{{ $assist->artisanCommand('ops-notify:test') }}
```

Then send through the queue, which also tests the worker. It prints `Queued, ...`; the new row on the Ops Notify page must turn *Sent* within seconds:

```bash
{{ $assist->artisanCommand('ops-notify:test --queue') }}
```

| Output | Meaning |
|---|---|
| `Sent.` | Works |
| `Nothing sent: ...` | Notifications disabled, or an event rule turns this event off |
| `Telegram API 401` | Wrong or revoked token |
| `400: Bad Request: chat not found` | Wrong chat id, or the bot is not in the group |
| `group chat was upgraded to a supergroup chat` | The old group id was entered; use the `-100` id |
| `409: Conflict` | A webhook is set on the bot; `getUpdates` cannot run |
| `No chats yet` | No `/ping@<bot>` reached the bot yet |
| Rows stay *Queued* | No worker for the queue; see **Delivery** on the Ops Notify page |

## Never

- Write the bot token to `.env`, `.env.example`, config, commits, PRs or chat.
- Set `OPS_NOTIFY_*` env keys unless asked: they lock the matching panel field.
- Invent a chat id or topic id.
- Switch production to the `sync` queue, or publish the package migrations, without asking.
