<?php

namespace Spokospace\OpsNotify\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spokospace\OpsNotify\Enums\DeliveryStatus;
use Spokospace\OpsNotify\Models\OpsNotifyLog;
use Throwable;

/**
 * Event names and bell notification titles from the message history, so Settings can suggest
 * what routing and title rules can match instead of making people look them up in the code.
 */
final class SeenEvents
{
    /** Suggestions offered in one field. */
    public const SUGGESTED = 30;

    /** Event names read. The seen line has to reach rare events too, not just the top ones. */
    public const EVENT_LIMIT = 200;

    /** Titles read: room for the ones a rule already matches, which are not suggested. */
    private const TITLE_LIMIT = self::SUGGESTED * 2;

    /** The form is rebuilt on every Livewire request while Settings is open. */
    private const CACHE_SECONDS = 300;

    /** A failed read is retried sooner, but not on every request. */
    private const FAILED_CACHE_SECONDS = 60;

    /** Days of history to read: 30, or fewer when the log is pruned sooner. */
    public static function days(): int
    {
        return max(1, min(30, (int) config('ops-notify.log.prune_after_days', 30)));
    }

    /** Start of the history that is read. */
    public static function since(): Carbon
    {
        return now()->subDays(self::days());
    }

    /**
     * Messages per event in the last days(), most frequent first. Messages held back during a
     * burst are not counted: one digest stands for them.
     *
     * @return array<string, int> Event name => messages.
     */
    public static function counts(): array
    {
        return self::frequent('event', self::EVENT_LIMIT);
    }

    /**
     * The most frequent events of counts(), the ones offered as suggestions.
     *
     * @param  array<string, int>  $counts
     * @return array<string, int>
     */
    public static function suggested(array $counts): array
    {
        return array_slice($counts, 0, self::SUGGESTED, true);
    }

    /** The log keeps the first 120 characters of an event name: this one may be longer. */
    public static function isTruncated(string $event): bool
    {
        return mb_strwidth($event) >= OpsNotifyLog::EVENT_LENGTH;
    }

    /**
     * Titles of bell notifications that no current title rule matches, most frequent first.
     * Unmatched titles are sent as `default_event` (see TitleRules). A title the log cut short
     * is offered as a pattern: "Its first 250 characters*".
     *
     * @return list<string>
     */
    public static function titles(): array
    {
        $config = (array) config('ops-notify.forward_database_notifications', []);

        if (($default = TitleRules::defaultEvent($config)) === null) {
            return [];
        }

        $map = (array) ($config['map'] ?? []);

        return array_values(collect(self::frequent('title', self::TITLE_LIMIT, $default))
            ->keys()
            ->map(function (string|int $title) use ($map): ?string {
                $title = (string) $title;
                $truncated = mb_strwidth($title) > OpsNotifyLog::TITLE_LENGTH && str_ends_with($title, OpsNotifyLog::TITLE_END);
                $text = $truncated ? Str::beforeLast($title, OpsNotifyLog::TITLE_END) : $title;

                // History from before a rule was added still carries the default event. The log
                // already holds the plain title, the form TitleRules::ruleFor() also tries.
                if (PatternMap::firstKey($map, $text) !== null) {
                    return null;
                }

                return $truncated ? $text.'*' : $text;
            })
            ->filter()
            ->unique()
            ->take(self::SUGGESTED)
            ->all());
    }

    /**
     * Each event, followed by the wildcard form of each of its prefixes:
     * inquiry.created → inquiry.created, inquiry.*. An event the log cut short is offered only
     * as "its first 120 characters*", since the full name is not known.
     *
     * @param  array<array-key, int>  $counts
     * @return list<string>
     */
    public static function patterns(array $counts): array
    {
        $patterns = [];

        foreach (array_keys($counts) as $event) {
            $event = (string) $event;
            $patterns[] = self::isTruncated($event) ? $event.'*' : $event;

            for ($prefix = $event; str_contains($prefix, '.');) {
                $prefix = Str::beforeLast($prefix, '.');
                $patterns[] = $prefix.'.*';
            }
        }

        return array_values(array_unique($patterns));
    }

    /**
     * Counts of one column's values without held-back burst messages; for one event, also
     * without burst digests, whose titles are not notification titles. Settings must open even when the history table is missing or
     * unreadable, or the cache is down, so a failed read suggests nothing.
     *
     * @return array<string, int>
     */
    private static function frequent(string $column, int $limit, ?string $event = null): array
    {
        $days = self::days();
        $key = "ops-notify:seen:{$column}:{$event}:{$days}";

        try {
            if (is_array($cached = Cache::get($key))) {
                return $cached;
            }
        } catch (Throwable) {
            // No cache: read the log.
        }

        $ttl = self::CACHE_SECONDS;

        try {
            $values = OpsNotifyLog::query()
                ->where('created_at', '>=', self::since())
                ->where('status', '!=', DeliveryStatus::Suppressed)
                ->whereNotNull($column)
                // Only for one event's titles: reading the payload of every row in the window
                // would give up the index that covers the event counts.
                ->when($event !== null, fn ($query) => $query->where('event', $event)->whereNull('payload->'.OpsNotifyLog::DIGEST))
                ->select("{$column} as value")
                ->selectRaw('count(*) as aggregate')
                ->groupBy($column)
                ->orderByDesc('aggregate')
                ->orderBy($column)
                ->limit($limit)
                ->get()
                ->mapWithKeys(fn (OpsNotifyLog $row): array => [(string) $row->getAttribute('value') => (int) $row->getAttribute('aggregate')])
                ->all();
        } catch (Throwable $e) {
            Log::warning('[ops-notify] Could not read recent events for Settings: '.$e->getMessage());
            [$values, $ttl] = [[], self::FAILED_CACHE_SECONDS];
        }

        try {
            Cache::put($key, $values, $ttl);
        } catch (Throwable) {
            // Uncached: read again next time.
        }

        return $values;
    }
}
