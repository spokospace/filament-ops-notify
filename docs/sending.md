# Sending messages

There are three ways in. All of them end up as an `OpsMessage` sent through the configured
channel.

## 1. Filament bell notifications (no code)

Anything the app already sends with `Notification::make()->...->sendToDatabase($users)` is
forwarded automatically. See [Routing and topics](routing-and-topics.md#forwarding-filament-notifications).

## 2. Laravel notifications: the `ops` channel

```php
use Illuminate\Notifications\Notification;
use Spokospace\OpsNotify\OpsMessage;

class BuildFailed extends Notification
{
    public function via(object $notifiable): array
    {
        return ['database', 'ops'];
    }

    public function toOps(object $notifiable): OpsMessage
    {
        return OpsMessage::make('build.failed')
            ->error()
            ->title('Catalog build failed')
            ->field('Exit code', 1);
    }
}
```

`toOps()` may also return a Filament `Notification`, which is converted like a forwarded one. Its
event name is `opsEvent()` if the notification defines it, otherwise the snake-cased class name
(`build_failed`).

Sending without a user:

```php
Notification::route('ops', ['topic' => 4])->notify(new BuildFailed);   // or just the topic id
```

`Notification::send($admins, new BuildFailed)` sends **one** Telegram message, not one per admin.
Identical messages within `dedupe_seconds` are sent once, whether they come through the `ops`
channel, Filament forwarding or `OpsMessage::send()` (which then returns `null`). *Send test* and
*Resend* on the page are never deduped. This needs `dedupe_seconds` above `0` and
a cache store shared by web and worker processes (see
[Forwarding](routing-and-topics.md#forwarding-filament-notifications)).

## 3. Directly

```php
use Spokospace\OpsNotify\OpsMessage;

OpsMessage::make('build.completed')
    ->success()
    ->title('Frontend build completed')
    ->line('Deployed release 2026.09.23')
    ->field('Duration', '4m 12s')
    ->button('Open site', 'https://shop.example')
    ->send();
```

| Method | |
|---|---|
| `make(string $event)` | The event name drives routing and the hashtag |
| `info()` `success()` `warning()` `error()` `critical()` / `level(Level\|string)` | Level: ℹ️ ✅ ⚠️ ❌ 🚨 |
| `title(string)` | Bold first line, after the `[service]` prefix |
| `line(string)` / `lines(iterable)` | Body lines |
| `field(string $label, mixed $value)` / `fields(iterable)` | `Label: value` rows. `null` and `''` are skipped, and arrays are JSON-encoded |
| `button(string $label, string $url)` | Inline URL button |
| `topic(int\|string\|null)` / `channel(?string)` | Override routing |
| `send(): ?OpsNotifyLog` | Queues the message. **Never throws**; problems are logged as warnings |
| `sendNow(): ?OpsNotifyLog` | Delivers synchronously. Throws `MessageSkipped` (disabled) or `ChannelException` (rejected) |

`send()` is safe in request code, such as saving an inquiry. A broken notification setup never
breaks the request that triggered it.

## What a message looks like

The example above arrives as:

```
✅ [shop.example.com] Frontend build completed

Deployed release 2026.09.23

Duration: 4m 12s

#build_completed
```

- The first line is the level emoji, the `[service]` prefix and the title, in bold. Without a title
  the event name is used.
- Then the body, then the fields, one per line, with bold labels.
- The last line is the event as a hashtag, so each event is searchable in the chat.
- Buttons are inline buttons below the message.

## Message templates

A template changes that layout for one kind of event. You can set it in **Settings → Message
templates** or in `config('ops-notify.templates')`. Keys are event patterns, and `*` matches
anything, as in [event routing](routing-and-topics.md). Templates are checked from the top and the
first match wins. An event that matches no template keeps the default layout.

```php
'templates' => [
    'inquiry.*' => [
        'title' => 'New inquiry from :field.Name',
        'body' => ":body\n\nCall back within 1h",
        'fields' => ['Name', 'Email'], // only these, in this order
    ],
    'debug.*' => ['body' => false, 'fields' => [], 'hashtag' => false],
],
```

| Part | Leave it out | Change it |
|---|---|---|
| `title` | the message title, or the event name | text with placeholders |
| `body` | the message body | text with placeholders, or `false` for no body |
| `fields` | all fields | a list of labels in the order to show them, or `[]` for none |
| `hashtag` | the `#event` line | `false` drops it |
| `service` | the `[service]` prefix | `false` drops it |

The level emoji and the buttons always stay.

- **Placeholders:** `:title`, `:body`, `:event`, `:service`, `:level` and `:field.Label`. For a label
  with spaces, use `:field.{Order number}`. Labels match ignoring case, and a missing field is left
  empty. If the title comes out empty, the message title is used instead.
- **Plain text.** Template text is escaped like everything else, so `<b>` is shown as typed. A
  template can't produce HTML that Telegram rejects.
- **Same limits.** A template is cut to the same length budgets as the default layout. Your fixed text
  is kept, and `:title` and `:body` are shortened to fit.
- **Preview.** In Settings, **Preview** on a template shows it rendered with the latest logged message
  of a matching event. The form doesn't have to be saved first. It also warns when a template higher
  up matches that event first.
- **Resend** uses the current template, because the log keeps the message and not the rendered text.
- Burst digests and the package's own test messages (**Send test**, `ops-notify:test`) don't use
  templates, so a catch-all template can't hide a connectivity test. Your own message can skip
  them with `->withoutTemplate()`.

## The `OpsNotify` facade

The facade is aliased automatically and proxies `OpsNotifier`:

| Method | |
|---|---|
| `OpsNotify::send(OpsMessage)` | Same as `$message->send()`: queues, never throws |
| `OpsNotify::sendNow(OpsMessage)` | Same as `$message->sendNow()` |
| `OpsNotify::destinationFor(OpsMessage)` | The channel and topic the message would go to, or `null` when it would not be sent |
| `OpsNotify::channels()` | The `ChannelManager`, for example to register a [driver](drivers.md) |

## Delivery

Every message becomes a queued `SendOpsMessage` job.

- **Where:** `OPS_NOTIFY_QUEUE_CONNECTION` and `OPS_NOTIFY_QUEUE`. By default, the app's queue
  connection and that connection's default queue.
- **Any Laravel driver works:** Horizon (redis), `queue:work` (database, redis, sqs, beanstalkd),
  or `sync`, which needs no worker and delivers inside the request.
- **Rate limit:** at most 1 message a second and 20 a minute per channel, the pace Telegram
  accepts in one group (`rate_limit.per_second` / `rate_limit.per_minute`, `0` turns one off).
  In a burst the rest wait in the queue instead of being rejected. The limiter uses the cache, so
  it needs a store shared by all workers (redis, database). It is skipped on the `sync` queue.
- **Bursts:** when one event is sent more than 10 times within 5 minutes (an error loop, for
  example, whose messages differ only in an id), the rest are not sent. They appear in the
  history as *Suppressed*, and when the window ends one summary arrives in the same topic:
  *"37 more "error.thrown" messages were held back in 5 minutes"*, followed by the most frequent
  titles. Tune it with `burst.max_per_event` and `burst.window_minutes` (`0` turns it off). It
  is counted per event name (bell notifications without a title rule share the default event, so
  they are counted per title), needs a cache shared by the workers, and does not apply to the
  `sync` queue or to `sendNow()`.
- **Retries:** up to 5 real failures (timeouts, 5xx), with a backoff of 10 s, 30 s, 2 min and
  5 min. Waiting for the rate limit, or for Telegram's `retry_after` after a 429, does not count.
  A message still undelivered after `rate_limit.give_up_after_minutes` (60) is marked failed.
- **Permanent errors** (a bad token, an unknown chat, a malformed message) fail at once. They are
  not retried.
- **After commit:** the job is dispatched after the surrounding database transaction commits, so a
  message never arrives before the data it talks about is saved.
- **Filament bell notifications take two queue hops.** Filament queues the database notification
  itself. Once that job has stored it, the package forwards it as a second job.
- **Length:** a message is capped at 3,900 visible characters, below Telegram's 4,096. The body is
  shortened first, then extra fields are summarised as "…and N more fields".
- **Log:** every message is stored in `ops_notify_logs` with its status (`queued`, `sent`,
  `failed`, `resent`, `suppressed`), the number of attempts and the Telegram error.

**Status → Delivery** on the page shows `connection · queue` (or *Immediately (sync queue)*) and,
with Horizon, its state. A warning appears when Horizon is paused or not running, when no Horizon
supervisor works the queue, or when messages have been queued for over 5 minutes.

### A queue of its own

Recommended for busy apps. During a burst, messages wait for the rate limit; on a queue of their
own they don't hold up the app's other jobs, and the app's jobs don't delay alerts.

With Horizon, give the queue a small supervisor of its own (one process is plenty, since the rate
limit allows one message a second), or add it to an existing supervisor's `queue` list. Otherwise
the messages never leave the queue:

```php
// .env: OPS_NOTIFY_QUEUE=ops
'environments' => [
    'production' => [
        'supervisor-1' => [/* the app's queues */],
        'supervisor-ops' => [
            'connection' => 'redis',
            'queue' => ['ops'],
            'minProcesses' => 1,
            'maxProcesses' => 1,
        ],
    ],
],
```

With `queue:work`, name the queue when you start the worker:

```bash
php artisan queue:work --queue=ops,default
```

## Testing the setup

**Send test** on the page goes through the queue by default (**Send through the queue** on), so it
also tests the worker. The row turns from *Queued* to *Sent* in the history. Turned off, the
message is sent right away, which only checks the token and chat. On the `sync` connection the
toggle is hidden.

**Resend** on a failed row follows the same rule: through the queue, or right away on `sync`.

### From the command line

```bash
php artisan ops-notify:test                         # default text, sent right away
php artisan ops-notify:test "Hello from the server"  # your own text
php artisan ops-notify:test --queue                 # through the queue, like a real message
php artisan ops-notify:test --event=build.failed    # test a routing rule
```

| Argument / option | Default | |
|---|---|---|
| `text` | *If you can read this, notifications work.* | Message body |
| `--event=` | `ops.test` | Event name, used for routing and the hashtag |
| `--queue` | off | Dispatch through the queue instead of sending right away |

The test message has the title *Test notification* and the fields *Environment* and *Host*.

`php artisan ops-notify:telegram-chats [--channel=name]` lists the chats and topics the bot has
seen ([Telegram setup](telegram-setup.md#3-find-the-chat-id)).

## In your app's tests

Nothing is sent while the app's test suite runs (`runningUnitTests()`), even if the token is in
`.env`. To test delivery, set `ops-notify.disable_in_tests` to `false` and fake HTTP:

```php
config(['ops-notify.disable_in_tests' => false]);
Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
```
