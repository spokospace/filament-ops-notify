<?php

namespace Spokospace\OpsNotify\Support;

use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;
use Spokospace\OpsNotify\Enums\Level;
use Spokospace\OpsNotify\OpsMessage;

/**
 * Turns any Laravel notification into an OpsMessage, from what it already defines:
 * toOps() if present, else the mail message (subject, lines, button), else toArray().
 */
class LaravelNotificationConverter
{
    /** Mail lines kept in the message; the rest is usually boilerplate (greeting, footer). */
    private const MAIL_LINES = 6;

    public function __construct(private readonly FilamentNotificationConverter $filament) {}

    /** The event name used for routing: opsEvent() if defined, else the class, snake-cased. */
    public static function eventName(Notification $notification): string
    {
        return method_exists($notification, 'opsEvent')
            ? (string) $notification->opsEvent()
            : Str::snake(class_basename($notification));
    }

    /** Null when the notification offers nothing to build a message from. */
    public function convert(Notification $notification, object $notifiable, string $channel): ?OpsMessage
    {
        $event = self::eventName($notification);

        if (method_exists($notification, 'toOps')) {
            $message = $notification->toOps($notifiable);

            return match (true) {
                $message instanceof OpsMessage => $message,
                $message instanceof FilamentNotification => $this->filament->fromNotification($message, $event),
                default => null,
            };
        }

        if ($channel === 'mail' && method_exists($notification, 'toMail')) {
            $mail = $notification->toMail($notifiable);

            if ($mail instanceof MailMessage) {
                return $this->fromMail($mail, $notification, $event);
            }
        }

        $data = match (true) {
            method_exists($notification, 'toArray') => $notification->toArray($notifiable),
            method_exists($notification, 'toDatabase') => $notification->toDatabase($notifiable),
            default => null,
        };

        return is_array($data) ? $this->fromArray($data, $notification, $event) : null;
    }

    private function fromMail(MailMessage $mail, Notification $notification, string $event): OpsMessage
    {
        $message = OpsMessage::make($event)
            ->level(match ($mail->level) {
                'error' => Level::Error,
                'success' => Level::Success,
                default => Level::Info,
            })
            ->title($this->plain($mail->subject) ?: Str::headline(class_basename($notification)));

        $lines = array_filter(array_map(fn (mixed $line): string => $this->plain($line), [...$mail->introLines, ...$mail->outroLines]));
        $message->lines(array_slice($lines, 0, self::MAIL_LINES));

        if (filled($mail->actionText) && filled($mail->actionUrl)) {
            $message->button($this->plain($mail->actionText), (string) $mail->actionUrl);
        }

        return $message;
    }

    /** @param  array<array-key, mixed>  $data */
    private function fromArray(array $data, Notification $notification, string $event): OpsMessage
    {
        $message = OpsMessage::make($event)
            ->title($this->plain($data['title'] ?? $data['subject'] ?? null) ?: Str::headline(class_basename($notification)));

        if (filled($body = $this->plain($data['body'] ?? $data['message'] ?? $data['line'] ?? null))) {
            $message->line($body);
        }

        if (is_string($url = $data['url'] ?? $data['action_url'] ?? null) && str_starts_with($url, 'http')) {
            $message->button(Trans::get('message.open'), $url);
        }

        return $message;
    }

    /** Mail lines can be Htmlable or Markdown; Telegram gets plain text. */
    private function plain(mixed $value): string
    {
        $text = match (true) {
            $value instanceof Htmlable => $value->toHtml(),
            is_scalar($value) => (string) $value,
            default => '',
        };

        return trim(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
