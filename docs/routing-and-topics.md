# Routing and topics

Every message has an **event** name, such as `inquiry.created`, `build.failed` or
`filament.notification`. Rules decide which channel and topic each event goes to. The event also
becomes a hashtag at the end of the message (`#inquiry_created`), so each event is searchable in
the chat.

## Topics

**Settings → Topics** holds the forum topics of the chat. The Bot API cannot list topics, so the
list is kept in the panel:

- **Create topic** creates the topic in Telegram (`createForumTopic`, with one of Telegram's six
  icon colours) and adds it with its id. The bot needs the *Manage topics* admin right, and the
  token and chat id must be saved first.
- **Import from Telegram** adds the topics the bot has seen recently. Send `/ping@your_bot` in a
  topic first.
- **Add existing topic** takes a name and an id. The id is the number after `_` in a Telegram Web
  link such as `…/#-1001234567890_3`.

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
can override its rule with `->topic()` or `->channel()`.

The same rules in `config/ops-notify.php`:

```php
'events' => [
    'inquiry.*' => ['topic' => '3'],
    'debug.*' => ['enabled' => false],
],
```

## Forwarding Filament notifications

Every Filament database notification (`Notification::make()->...->sendToDatabase($users)`) is
forwarded **once**, however many users receive it. **Settings → Filament notifications** turns
titles into events, and the first matching rule wins:

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
