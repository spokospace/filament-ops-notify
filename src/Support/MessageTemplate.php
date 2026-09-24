<?php

namespace Spokospace\OpsNotify\Support;

use Illuminate\Support\Str;
use Spokospace\OpsNotify\OpsMessage;

/**
 * How one event's messages are laid out (`ops-notify.templates`). A channel asks for() the
 * template of a message's event and renders its parts from here, so every driver lays an event
 * out the same way. Everything returned is plain text: the channel escapes it for its format.
 *
 * Placeholders: :title (the message title, or the event name), :body, :event, :service, :level
 * and :field.Label, or :field.{Label with spaces}. A missing field is an empty string.
 */
final class MessageTemplate
{
    /** The placeholders, as Settings lists them. */
    public const PLACEHOLDERS = [':title', ':body', ':event', ':service', ':level', ':field.Label', ':field.{Label with spaces}'];

    /**
     * One pass over the template, so a value that contains a placeholder is not filled in again.
     * A name must end there: ":titles" is text, not ":title" followed by "s".
     */
    private const PLACEHOLDER = '/:(?:field\.(?:\{([^}]*)\}|([\pL\pN_-]+))|(title|body|event|service|level)(?![\pL\pN_]))/u';

    /**
     * @param  string|null  $title  Null = the message title, or the event name.
     * @param  string|false|null  $body  Null = the message body; false = no body.
     * @param  list<string>|null  $fields  Labels to show, in this order; null = all, [] = none.
     * @param  bool  $hashtag  The #event line at the end.
     * @param  bool  $service  The [service] prefix of the title.
     */
    public function __construct(
        public readonly ?string $title = null,
        public readonly string|false|null $body = null,
        public readonly ?array $fields = null,
        public readonly bool $hashtag = true,
        public readonly bool $service = true,
    ) {}

    /**
     * The template of the first pattern that matches the event, or null when none does (the
     * default layout). A message sent withoutTemplate() never gets one.
     *
     * @param  array<string, mixed>  $templates  Pattern => template (config('ops-notify.templates')).
     */
    public static function for(array $templates, OpsMessage $message): ?self
    {
        if (! $message->templated) {
            return null;
        }

        $key = PatternMap::firstKey($templates, $message->event);

        return $key === null ? null : self::fromArray($templates[$key]);
    }

    /**
     * Reads a template from config or settings. Anything of the wrong type (a hand-edited
     * value) counts as not set, so a bad template shows the default part, not an error.
     */
    public static function fromArray(mixed $data): self
    {
        $data = is_array($data) ? $data : [];
        $body = $data['body'] ?? null;
        $fields = $data['fields'] ?? null;

        return new self(
            title: is_string($data['title'] ?? null) && trim($data['title']) !== '' ? $data['title'] : null,
            body: $body === false ? false : (is_string($body) && trim($body) !== '' ? $body : null),
            fields: is_array($fields) ? array_values(array_filter(
                array_map(fn (mixed $label): string => trim((string) $label), array_filter($fields, 'is_scalar')),
                fn (string $label): bool => $label !== '',
            )) : null,
            hashtag: ($data['hashtag'] ?? true) !== false,
            service: ($data['service'] ?? true) !== false,
        );
    }

    /**
     * In config shape, with only the parts that differ from the default layout:
     * ['body' => false, 'fields' => []] rather than every part.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'title' => $this->title,
            'body' => $this->body,
            'fields' => $this->fields,
            'hashtag' => $this->hashtag ? null : false,
            'service' => $this->service ? null : false,
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * The title, at most $limit characters. One that comes out empty (only missing fields)
     * falls back to the message title.
     */
    public function title(OpsMessage $message, ?string $service, int $limit): string
    {
        $default = $message->title ?? $message->event;

        if ($this->title === null) {
            return Str::limit($default, $limit);
        }

        $title = trim($this->fill($this->title, $message, $service, $limit));

        return $title !== '' ? $title : Str::limit($default, $limit);
    }

    /** The body, at most $limit characters; '' when there is none. */
    public function body(OpsMessage $message, ?string $service, int $limit): string
    {
        return match (true) {
            $limit <= 0, $this->body === false => '',
            $this->body === null => Str::limit($message->body(), $limit),
            default => trim($this->fill($this->body, $message, $service, $limit), "\n"),
        };
    }

    /**
     * The fields to show: all, or the listed ones in the template's order. A label matches
     * exactly, or else ignoring case.
     *
     * @param  array<string, string>  $fields
     * @return array<string, string>
     */
    public function fields(array $fields): array
    {
        if ($this->fields === null) {
            return $fields;
        }

        $picked = [];

        foreach ($this->fields as $label) {
            if (($key = self::fieldKey($fields, $label)) !== null) {
                $picked[$key] = $fields[$key];
            }
        }

        return $picked;
    }

    /**
     * Fills in the placeholders, keeping the result within $limit characters. The fixed text is
     * kept whole where it fits: :title and :body, the long values, share what it leaves.
     */
    private function fill(string $template, OpsMessage $message, ?string $service, int $limit): string
    {
        $long = ['title' => $message->title ?? $message->event, 'body' => $message->body()];
        $count = 0;
        $fixed = $this->replace($template, $message, $service, ['title' => '', 'body' => ''], $count);

        if ($count > 0) {
            $share = intdiv(max(0, $limit - mb_strwidth($fixed)), $count);
            $long = array_map(fn (string $value): string => self::cut($value, $share), $long);
        }

        return self::cut($this->replace($template, $message, $service, $long), $limit);
    }

    /** At most $limit characters, the "..." of a cut included (Str::limit adds it past the limit). */
    private static function cut(string $text, int $limit): string
    {
        return match (true) {
            mb_strwidth($text) <= $limit => $text,
            $limit <= 3 => '',
            default => Str::limit($text, $limit - 3),
        };
    }

    /**
     * @param  array{title: string, body: string}  $long
     * @param  int  $longCount  Incremented for every :title and :body filled in.
     */
    private function replace(string $template, OpsMessage $message, ?string $service, array $long, int &$longCount = 0): string
    {
        return (string) preg_replace_callback(self::PLACEHOLDER, function (array $match) use ($message, $service, $long, &$longCount): string {
            if (($match[3] ?? '') === '') {
                $label = ($match[1] ?? '') !== '' ? $match[1] : ($match[2] ?? '');
                $key = self::fieldKey($message->fields, $label);

                return $key === null ? '' : $message->fields[$key];
            }

            if ($match[3] === 'title' || $match[3] === 'body') {
                $longCount++;

                return $long[$match[3]];
            }

            return match ($match[3]) {
                'event' => $message->event,
                'service' => (string) $service,
                'level' => $message->level->value,
            };
        }, $template);
    }

    /** @param  array<string, string>  $fields */
    private static function fieldKey(array $fields, string $label): ?string
    {
        if (array_key_exists($label, $fields)) {
            return $label;
        }

        $label = mb_strtolower($label);

        foreach (array_keys($fields) as $key) {
            if (mb_strtolower((string) $key) === $label) {
                return (string) $key;
            }
        }

        return null;
    }
}
