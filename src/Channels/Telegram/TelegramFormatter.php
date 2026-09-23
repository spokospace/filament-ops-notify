<?php

namespace Spokospace\OpsNotify\Channels\Telegram;

use Illuminate\Support\Str;
use Spokospace\OpsNotify\OpsMessage;
use Spokospace\OpsNotify\Support\Button;
use Spokospace\OpsNotify\Support\Trans;

/**
 * Renders an OpsMessage as Telegram HTML.
 *
 * Telegram limits a message to 4096 characters counted AFTER entity parsing, i.e. the visible
 * text. All budgets below are therefore measured on the raw text, and it is cut BEFORE escaping,
 * so a cut can never split an entity like "&amp;" (which makes Telegram reject the message).
 */
class TelegramFormatter
{
    /** Visible characters for the whole message, leaving headroom below Telegram's 4096. */
    private const TOTAL_LIMIT = 3900;

    private const TITLE_LIMIT = 200;

    private const BODY_LIMIT = 3000;

    private const FIELDS_LIMIT = 1200;

    private const FIELD_LABEL_LIMIT = 60;

    private const FIELD_VALUE_LIMIT = 300;

    private const LINK_LIMIT = 300;

    /** @param  bool  $linksAsText  Render buttons as text lines (fallback when Telegram rejects a button URL). */
    public function format(OpsMessage $message, ?string $service, bool $linksAsText = false): string
    {
        $prefix = filled($service) ? '['.$service.'] ' : '';
        $emoji = $message->level->emoji();
        $title = $prefix.Str::limit($message->title ?? $message->event, self::TITLE_LIMIT);
        $hashtag = '#'.$this->hashtag($message->event);

        $fields = $this->fieldLines($message->fields);
        $links = $linksAsText ? $this->linkLines($message->buttons) : [];

        $used = mb_strlen($emoji.' '.$title) + mb_strlen($hashtag)
            + array_sum(array_map(fn (array $line): int => mb_strlen($line[0].$line[1]) + 3, [...$fields, ...$links]))
            + 8; // blank lines between parts
        $bodyBudget = max(0, min(self::BODY_LIMIT, self::TOTAL_LIMIT - $used));

        $parts = [$emoji.' <b>'.$this->escape($title).'</b>'];

        if (filled($body = $message->body()) && $bodyBudget > 0) {
            $parts[] = $this->escape(Str::limit($body, $bodyBudget));
        }

        if ($fields !== []) {
            $parts[] = $this->renderLines($fields);
        }

        if ($links !== []) {
            $parts[] = $this->renderLines($links);
        }

        $parts[] = $hashtag;

        return implode("\n\n", $parts);
    }

    /**
     * Fields in order until FIELDS_LIMIT is used up; the rest are summarised.
     *
     * @param  array<string, string>  $fields
     * @return list<array{0: string, 1: string}> [label, value] pairs, raw text
     */
    private function fieldLines(array $fields): array
    {
        $lines = [];
        $budget = self::FIELDS_LIMIT;

        foreach ($fields as $label => $value) {
            $line = [Str::limit($label, self::FIELD_LABEL_LIMIT), Str::limit($value, self::FIELD_VALUE_LIMIT)];
            $length = mb_strlen($line[0].$line[1]) + 3;

            if ($length > $budget) {
                $rest = count($fields) - count($lines);
                $lines[] = ['…', Trans::choice('message.more_fields', $rest)];

                break;
            }

            $lines[] = $line;
            $budget -= $length;
        }

        return $lines;
    }

    /**
     * @param  list<Button>  $buttons
     * @return list<array{0: string, 1: string}>
     */
    private function linkLines(array $buttons): array
    {
        return array_map(
            fn (Button $button): array => [Str::limit($button->label, self::FIELD_LABEL_LIMIT), Str::limit($button->url, self::LINK_LIMIT)],
            $buttons,
        );
    }

    /** @param  list<array{0: string, 1: string}>  $lines */
    private function renderLines(array $lines): string
    {
        return collect($lines)
            ->map(fn (array $line): string => '<b>'.$this->escape($line[0]).':</b> '.$this->escape($line[1]))
            ->implode("\n");
    }

    /**
     * Telegram's HTML mode knows only &lt; &gt; &amp; &quot; and numeric entities, so named
     * ones like &apos; would show literally. Text content needs only < > & escaped.
     */
    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** "inquiry.created" → "inquiry_created", so every event is searchable in the chat. */
    private function hashtag(string $event): string
    {
        return trim((string) preg_replace('/[^\pL\pN_]+/u', '_', $event), '_') ?: 'ops';
    }
}
