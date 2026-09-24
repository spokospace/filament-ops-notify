## Filament Ops Notify (spokospace/filament-ops-notify)

- Sends operational notifications (inquiries, errors, builds) from this app to Telegram forum topics. Built on Laravel notifications: every Filament `sendToDatabase()` notification is already forwarded, so bell notifications need no extra code.
- Settings (bot token, chat id, topics, routing rules) live in the panel on the **Ops Notify** page (`/{panel}/ops-notify` → Settings), stored encrypted in `ops_notify_settings`. Docs: https://github.com/spokospace/filament-ops-notify/tree/main/docs

### Sending your own events

Use `OpsMessage` for events that are not bell notifications. Name events `domain.action` (`inquiry.created`, `build.failed`) so routing rules like `inquiry.*` can send them to a topic.

@verbatim
<code-snippet name="Send an ops message" lang="php">
use Spokospace\OpsNotify\OpsMessage;

OpsMessage::make('build.failed')
    ->error()
    ->title('Frontend build failed')
    ->line('Exit code 1 in the asset step.')
    ->field('Branch', 'main')
    ->button('Open log', route('builds.show', $build))
    ->send();
</code-snippet>
@endverbatim

- Use `send()` in application code: it queues the message and never throws, so a broken notification setup cannot break the request. Use `sendNow()` only in commands or tests that must see Telegram's error; it throws.
- In a Laravel notification, return `'ops'` from `via()` and build the message in `toOps($notifiable)`. Without a user: `Notification::route('ops', ['topic' => 12])->notify(new BuildFailed)`.
- Levels: `info()`, `success()`, `warning()`, `error()`, `critical()`. Do not put secrets, tokens or personal data into titles, fields or buttons.

### Rules for agents

- Never put the Telegram bot token in `.env.example`, committed files, commits, PRs or chat. By default the human pastes it into the panel's Settings, where it is stored encrypted. The server's `.env` (`OPS_NOTIFY_TELEGRAM_BOT_TOKEN`) also works, but it locks the Settings field, so use it, like any `OPS_NOTIFY_*` key, only when the human asks.
- Never guess a chat id or topic id. Ask the human, or read them from `php artisan ops-notify:telegram-chats` after they sent `/ping@<bot>` in each topic.
- Delivery goes through the app's queue (`OPS_NOTIFY_QUEUE_CONNECTION` / `OPS_NOTIFY_QUEUE`). A custom queue name must be added to a Horizon supervisor or a `queue:work --queue=` list, or messages never leave it. Do not switch production to the `sync` queue without asking.
- Nothing is sent while the app's test suite runs (`ops-notify.disable_in_tests`), so tests need no fakes for it.
- Check a setup with `php artisan ops-notify:test` (sends right away and prints Telegram's error), then `php artisan ops-notify:test --queue` and confirm the new row turns *Sent* on the Ops Notify page.
