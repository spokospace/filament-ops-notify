<?php

namespace Spokospace\OpsNotify\Support;

use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Support\Str;
use Spokospace\OpsNotify\Enums\Level;
use Spokospace\OpsNotify\OpsMessage;

/**
 * Turns a Filament notification (the object, or the array Filament stores in the
 * notifications table) into an OpsMessage: title, body, status → level, URL actions → buttons.
 */
class FilamentNotificationConverter
{
    public function fromNotification(FilamentNotification $notification, string $event): OpsMessage
    {
        return $this->fromArray($notification->getDatabaseMessage(), $event);
    }

    /** @param  array<string, mixed>  $data  Filament's database payload (format = filament). */
    public function fromArray(array $data, string $event): OpsMessage
    {
        $message = OpsMessage::make($event)->level($this->level($data));

        if (filled($title = $this->plain($data['title'] ?? null))) {
            $message->title($title);
        }

        if (filled($body = $this->plain($data['body'] ?? null))) {
            $message->line($body);
        }

        foreach ($data['actions'] ?? [] as $action) {
            $url = $this->absoluteUrl($action['url'] ?? null);

            if ($url !== null) {
                $message->button($this->plain($action['label'] ?? null) ?: Trans::get('message.open'), $url);
            }
        }

        return $message;
    }

    /** @param  array<string, mixed>  $data */
    private function level(array $data): Level
    {
        return match ($data['status'] ?? $data['color'] ?? null) {
            'success' => Level::Success,
            'warning' => Level::Warning,
            'danger' => Level::Error,
            default => Level::Info,
        };
    }

    /** Filament bodies may hold HTML or Markdown-rendered HTML; Telegram gets plain text. */
    private function plain(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        $text = preg_replace('/<br\s*\/?>|<\/p>/i', "\n", $value) ?? $value;

        return trim(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /** Filament actions often use panel-relative URLs; Telegram buttons need absolute http(s) ones. */
    private function absoluteUrl(mixed $url): ?string
    {
        if (! is_string($url) || $url === '') {
            return null;
        }

        $url = url($url);

        return Str::isUrl($url, ['http', 'https']) ? $url : null;
    }
}
