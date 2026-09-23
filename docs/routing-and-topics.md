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

The title rules pair with event routing. `New inquiry*` becomes `inquiry.created`, and
`inquiry.*` sends it to the Inquiries topic.

What a forwarded notification keeps:

- the title and body (HTML is stripped to plain text),
- the status, which becomes the level emoji (success ✅, warning ⚠️, danger ❌, anything else ℹ️),
- actions with a URL, which become inline buttons. A relative URL is made absolute with
  `APP_URL`. If Telegram rejects a button URL (for example `localhost`), the message is sent again
  with the links as text.
