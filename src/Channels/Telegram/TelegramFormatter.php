<?php

namespace Spokospace\OpsNotify\Channels\Telegram;

use Illuminate\Support\Str;
use Spokospace\OpsNotify\OpsMessage;

/**
 * Renders an OpsMessage as Telegram HTML. Every piece of user data is escaped, and it is
 * truncated BEFORE escaping so a cut can never split an entity like "&amp;" (which makes
 * Telegram reject the whole message). Telegram counts the 4096-char limit after parsing,
 * so the budgets below leave room for markup.
 */
class TelegramFormatter
{
    private const TITLE_LIMIT = 200;

    private const BODY_LIMIT = 3000;

    private const FIELD_LIMIT = 300;

    public function format(OpsMessage $message, ?string $service): string
    {
        $prefix = filled($service) ? '['.$service.'] ' : '';
        $title = $message->title ?? $message->event;

        $parts = [$message->level->emoji().' <b>'.$this->escape($prefix.Str::limit($title, self::TITLE_LIMIT)).'</b>'];

        if (filled($body = $message->body())) {
            $parts[] = $this->escape(Str::limit($body, self::BODY_LIMIT));
        }

        if ($message->fields !== []) {
            $parts[] = collect($message->fields)
                ->map(fn (string $value, string $label): string => '<b>'.$this->escape($label).':</b> '.$this->escape(Str::limit($value, self::FIELD_LIMIT)))
                ->implode("\n");
        }

        $parts[] = '#'.$this->hashtag($message->event);

        return implode("\n\n", $parts);
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8', false);
    }

    /** "inquiry.created" → "inquiry_created", so every event is searchable in the chat. */
    private function hashtag(string $event): string
    {
        return trim((string) preg_replace('/[^\pL\pN_]+/u', '_', $event), '_') ?: 'ops';
    }
}
