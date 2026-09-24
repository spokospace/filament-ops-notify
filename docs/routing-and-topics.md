# Routing and topics

Every message has an **event** name, such as `inquiry.created`, `build.failed` or
`filament.notification`. Rules decide which channel and topic each event goes to. The event also
becomes a hashtag at the end of the message (`#inquiry_created`), so each event is searchable in
the chat.

## Topics

**Settings → Topics** holds the forum topics of the chat. The Bot API cannot list topics, so the
list is kept in the panel:

- **Create topic** creates the topic in Telegram (`createForumTopic`, with one of Telegram's six
  icon colours) and adds it with its id. The bot needs the *Manage topics* admin right.
- **Import from Telegram** adds the topics of the saved chat that the bot has seen. Send
  `/ping@your_bot` in each topic first, and import within 24 hours; after that, found topics are
  remembered for 30 days ([why](telegram-setup.md#3-find-the-chat-id)).
- **Add existing topic** takes a name and an id. The id is the number after `_` in a Telegram Web
  link such as `…/#-1001234567890_3`.

**Create topic** and **Import from Telegram** only add rows to the form. Press **Save** to keep
them. Both use the **saved** token and chat id, not unsaved values in the form.

The *General* topic has no id. To send to *General*, leave **Default topic** (or a rule's topic)
empty.

Routing rules pick topics by name ("Inquiries #3"), and the history shows the name as well.
Removing a topic from the list does not delete it in Telegram.

## Where event names come from

- `OpsMessage::make('inquiry.created')`: the name you pass.
- A notification sent through the `ops` channel, or a forwarded Laravel notification:
  `opsEvent()` if the notification defines it, else the class name in snake case
  (`App\Notifications\OrderPlaced` → `order_placed`).
- A Filament bell notification: the event its title rule gives it
  ([Forwarding Filament notifications](#forwarding-filament-notifications)), else
  `default_event` (`filament.notification` unless you change it; with `null` it is not forwarded).

The history on the plugin's page shows each message's event. Settings also suggests them: the
**Pattern** field offers event names sent in the last 30 days (or fewer, if `log.prune_after_days` is lower; most frequent first), together
with the wildcard form of each prefix (`inquiry.created` also offers `inquiry.*`). A line under
the list counts these events, e.g. `inquiry.created (12) · order_placed (3, no topic in rules)`.
*no topic in rules* marks events that no rule matches, or whose rule has no topic: they go to the
default topic, unless the message sets its own with `->topic()`. *Disabled* marks
events a disabled rule drops. The suggestions are refreshed every five minutes. Typing a name that
was never sent is fine.

## Event routing

**Settings → Event routing** is a list of rules. Each rule has a `Str::is()` pattern, a topic and an
on/off switch, and the first matching rule wins:

| Pattern | Topic | Enabled |
|---|---|---|
| `inquiry.*` | Inquiries #3 | ✅ |
| `build.*` | Builds #4 | ✅ |
| `error.*` | Errors #2 | ✅ |
| `debug.*` | – | ❌ (not sent) |

Events that no rule matches go to the default topic, or to *General* when there is none. A message
can override its rule with `->topic()` or `->channel()`. A rule can also send events to another
channel with `channel` (config only, see [A second Telegram channel](settings.md#a-second-telegram-channel)).

The same rules in `config/ops-notify.php`:

```php
'events' => [
    'inquiry.*' => ['topic' => '3'],
    'debug.*' => ['enabled' => false],
],
```

## Forwarding Filament notifications

Every Filament database notification (`Notification::make()->...->sendToDatabase($users)`) is
forwarded **once**, however many users receive it.

That relies on two things. Filament stores one copy per user, and the package lets only the first
identical copy through, using `Cache::add`. So:

- `dedupe_seconds` must be above `0` (default `60`).
- The cache store must be shared by web and queue worker processes (redis, database, file on one
  server). With `dedupe_seconds` set to `0`, or a per-process store such as `array`, a notification
  sent to N users becomes N Telegram messages.

**Settings → Filament notifications** turns titles into events, and the first matching rule wins:

| Title | Event | Forward |
|---|---|---|
| `New inquiry*` | `inquiry.created` | ✅ |
| `* build completed` | `build.completed` | ✅ |
| `* build failed` | `build.failed` | ✅ |
| `Job failed:*` | `error.job` | ✅ |
| `* translations completed` | | ❌ (dropped) |

Titles that no rule matches use `default_event` (`filament.notification`). Set it to `null` to
forward only the titles you listed.

Title patterns work like event patterns: `*` matches anything, so `New inquiry*` also matches
"New inquiry from Anna". The **Title** field suggests the titles of recent bell notifications that
no rule renamed yet, i.e. those sent as `default_event`. With `default_event` set to `null` there
are none to suggest, because unmatched titles are not forwarded.

The title rules pair with event routing. `New inquiry*` becomes `inquiry.created`, and
`inquiry.*` sends it to the Inquiries topic.

What a forwarded notification keeps:

- the title and body (HTML is stripped to plain text),
- the status, which becomes the level emoji (success ✅, warning ⚠️, danger ❌, anything else ℹ️),
- actions with a URL, which become inline buttons. A relative URL is made absolute with
  `APP_URL`. If Telegram rejects a button URL (for example `localhost`), the message is sent again
  with the links as text.

## Forwarding other Laravel notifications

Not every app uses Filament's bell. **Settings → Other Laravel notifications** forwards any Laravel
notification sent through the channels you pick, such as `mail`, `vonage` (SMS), `broadcast` (push)
or `database`, with no code changes. It is off by default.

- **One message per notification.** Laravel gives each send one id, shared by all its recipients
  and channels, so an e-mail to five admins arrives once.
- **What the message is built from**, in this order:
  1. `toOps($notifiable)`, if the notification defines it;
  2. for `mail`, the mail message: subject as the title, its lines, and its button;
  3. otherwise `toArray()` / `toDatabase()`: `title` (or `subject`), `body` (or `message`) and a
     `url`, if present.
- **Event name:** `opsEvent()` if the notification defines it, else the class name in snake case
  (`App\Notifications\OrderPlaced` → `order_placed`). Route it like any other event, for example
  `order_placed` → *Orders*.
- **Never forwarded:** password resets, e-mail verification, one-time codes and magic links
  (`forward_notifications.except` in the config, by class pattern), because their content is
  secret. Keep these patterns when you extend the list.
- Notifications that already use the `ops` channel, and Filament bell notifications, have their
  own path and are not forwarded twice.

`forward_notifications.only` (class patterns) limits forwarding to chosen notifications, for
example `['App\Notifications\Order*']`.
