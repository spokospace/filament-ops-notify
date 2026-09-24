<?php

namespace Spokospace\OpsNotify;

use Spokospace\OpsNotify\Enums\Level;
use Spokospace\OpsNotify\Models\OpsNotifyLog;
use Spokospace\OpsNotify\Support\Button;
use Stringable;

/**
 * A channel-agnostic notification. Build it fluently and call send():
 *
 *     OpsMessage::make('inquiry.created')
 *         ->title('New inquiry #42')
 *         ->line($inquiry->message)
 *         ->field('Email', $inquiry->email)
 *         ->button('Open in panel', $url)
 *         ->send();
 */
final class OpsMessage
{
    public Level $level = Level::Info;

    public ?string $title = null;

    /** @var list<string> */
    public array $lines = [];

    /** @var array<string, string> */
    public array $fields = [];

    /** @var list<Button> */
    public array $buttons = [];

    /** Overrides the channel resolved from config('ops-notify.events'). */
    public ?string $channel = null;

    /** Overrides the topic resolved from config('ops-notify.events'). */
    public ?string $topic = null;

    /** False: laid out the default way, whatever `ops-notify.templates` says for the event. */
    public bool $templated = true;

    public function __construct(public readonly string $event) {}

    public static function make(string $event): self
    {
        return new self($event);
    }

    public function level(Level|string $level): self
    {
        $this->level = is_string($level) ? Level::from($level) : $level;

        return $this;
    }

    public function info(): self
    {
        return $this->level(Level::Info);
    }

    public function success(): self
    {
        return $this->level(Level::Success);
    }

    public function warning(): self
    {
        return $this->level(Level::Warning);
    }

    public function error(): self
    {
        return $this->level(Level::Error);
    }

    public function critical(): self
    {
        return $this->level(Level::Critical);
    }

    public function title(string $title): self
    {
        $this->title = $title;

        return $this;
    }

    public function line(string|Stringable $line): self
    {
        $this->lines[] = (string) $line;

        return $this;
    }

    /** @param  iterable<string|Stringable>  $lines */
    public function lines(iterable $lines): self
    {
        foreach ($lines as $line) {
            $this->line($line);
        }

        return $this;
    }

    /** Adds a "Label: value" row. Null and empty values are skipped so callers can pass optional data. */
    public function field(string $label, mixed $value): self
    {
        if ($value === null || $value === '') {
            return $this;
        }

        $this->fields[$label] = match (true) {
            is_bool($value) => $value ? 'yes' : 'no',
            is_scalar($value), $value instanceof Stringable => (string) $value,
            default => (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        };

        return $this;
    }

    /** @param  iterable<string, mixed>  $fields */
    public function fields(iterable $fields): self
    {
        foreach ($fields as $label => $value) {
            $this->field($label, $value);
        }

        return $this;
    }

    public function button(string $label, string $url): self
    {
        $this->buttons[] = new Button($label, $url);

        return $this;
    }

    public function channel(?string $channel): self
    {
        $this->channel = $channel;

        return $this;
    }

    public function topic(int|string|null $topic): self
    {
        $this->topic = $topic === null ? null : (string) $topic;

        return $this;
    }

    /** Ignore the event's message template, e.g. for a message the package writes itself. */
    public function withoutTemplate(): self
    {
        $this->templated = false;

        return $this;
    }

    public function body(): string
    {
        return implode("\n", $this->lines);
    }

    /** Queue the message. Returns the log row, or null when notifications or this event are disabled. */
    /**
     * Queues the message; never throws. An identical message sent again within
     * ops-notify.dedupe_seconds is dropped (returns null), so code that runs once per user or
     * per retry does not repeat itself in the chat.
     */
    public function send(): ?OpsNotifyLog
    {
        if (! Support\Dedupe::isFirst('message', $this->toArray())) {
            return null;
        }

        return app(OpsNotifier::class)->send($this);
    }

    /** Deliver synchronously; throws when the channel rejects the message. */
    public function sendNow(): ?OpsNotifyLog
    {
        return app(OpsNotifier::class)->sendNow($this);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'event' => $this->event,
            'level' => $this->level->value,
            'title' => $this->title,
            'lines' => $this->lines,
            'fields' => $this->fields,
            'buttons' => array_map(fn (Button $button): array => ['label' => $button->label, 'url' => $button->url], $this->buttons),
            'channel' => $this->channel,
            'topic' => $this->topic,
        ] + ($this->templated ? [] : ['templated' => false]);
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        $message = self::make($data['event'])
            ->level($data['level'] ?? Level::Info)
            ->lines($data['lines'] ?? [])
            ->fields($data['fields'] ?? [])
            ->channel($data['channel'] ?? null)
            ->topic($data['topic'] ?? null);

        if (filled($data['title'] ?? null)) {
            $message->title($data['title']);
        }

        if (($data['templated'] ?? true) === false) {
            $message->withoutTemplate();
        }

        foreach ($data['buttons'] ?? [] as $button) {
            $message->button($button['label'], $button['url']);
        }

        return $message;
    }
}
