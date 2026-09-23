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
Identical messages within `dedupe_seconds` are sent once.

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

## Delivery

- `SendOpsMessage` runs on the configured queue with 5 attempts and a backoff of 10 s, 30 s, 2 min
  and 5 min. When Telegram asks to slow down (`retry_after`), the job waits that long.
- Permanent errors (a bad token, an unknown chat, a malformed message) fail at once. They are not
  retried.
- A message is capped at Telegram's 4096 characters. The body is shortened first, then extra
  fields are summarised as "…and N more fields".
- Every message is logged in `ops_notify_logs` with its status (`queued`, `sent`, `failed`,
  `resent`), the number of attempts and the Telegram error.

## In your app's tests

Nothing is sent while the app's test suite runs (`runningUnitTests()`), even if the token is in
`.env`. To test delivery, set `ops-notify.disable_in_tests` to `false` and fake HTTP:

```php
config(['ops-notify.disable_in_tests' => false]);
Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
```
