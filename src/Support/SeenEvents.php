<?php

namespace Spokospace\OpsNotify\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Spokospace\OpsNotify\Models\OpsNotifyLog;
use Throwable;

/**
 * Event names and bell notification titles from the message history, so Settings can suggest
 * what routing and title rules can match instead of making people look them up in the code.
 */
final class SeenEvents
{
    private const LIMIT = 30;

    /** The form is rebuilt on every Livewire request while Settings is open. */
    private const CACHE_SECONDS = 300;

    /** Days of history to read: 30, or fewer when the log is pruned sooner. */
    public static function days(): int
    {
        return max(1, min(30, (int) config('ops-notify.log.prune_after_days', 30)));
    }

    /** @return array<string, int> Event name => messages in the last days(), most frequent first. */
    public static function counts(): array
    {
        return self::frequent('event');
    }

    /**
     * Titles of bell notifications no title rule renamed, most frequent first. Unmatched titles
     * are sent as `default_event` (see ForwardFilamentDatabaseNotification::eventFor()).
     *
     * @return list<string>
     */
    public static function titles(): array
    {
        $default = config('ops-notify.forward_database_notifications.default_event');

        return is_string($default) && $default !== ''
            ? array_keys(self::frequent('title', $default))
            : [];
    }

    /**
     * Each event, followed by the wildcard form of each of its prefixes:
     * inquiry.created → inquiry.created, inquiry.*.
     *
     * @param  array<array-key, int>  $counts
     * @return list<string>
     */
    public static function patterns(array $counts): array
    {
        $patterns = [];

        foreach (array_keys($counts) as $event) {
            $event = (string) $event;
            $patterns[] = $event;

            for ($prefix = $event; str_contains($prefix, '.');) {
                $prefix = Str::beforeLast($prefix, '.');
                $patterns[] = $prefix.'.*';
            }
        }

        return array_values(array_unique($patterns));
    }

    /**
     * Counts of one column's values, optionally only for one event. Settings must open even when
     * the history table is missing or unreadable, so a failed query suggests nothing.
     *
     * @return array<string, int>
     */
    private static function frequent(string $column, ?string $event = null): array
    {
        $days = self::days();

        try {
            return Cache::remember(
                "ops-notify:seen:{$column}:{$event}:{$days}",
                self::CACHE_SECONDS,
                fn (): array => OpsNotifyLog::query()
                    ->where('created_at', '>=', now()->subDays($days))
                    ->whereNotNull($column)
                    ->when($event !== null, fn ($query) => $query->where('event', $event))
                    ->select("{$column} as value")
                    ->selectRaw('count(*) as aggregate')
                    ->groupBy($column)
                    ->orderByDesc('aggregate')
                    ->orderBy($column)
                    ->limit(self::LIMIT)
                    ->get()
                    ->mapWithKeys(fn (OpsNotifyLog $row): array => [(string) $row->getAttribute('value') => (int) $row->getAttribute('aggregate')])
                    ->all(),
            );
        } catch (Throwable) {
            return [];
        }
    }
}
