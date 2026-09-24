<?php

namespace Spokospace\OpsNotify\Support;

use Illuminate\Support\Str;

/** Config maps keyed by Str::is() patterns, where the first matching key wins. */
final class PatternMap
{
    /**
     * @param  array<string, mixed>  $map
     * @param  string  ...$subjects  Forms of one value; a pattern matching any of them wins.
     */
    public static function firstKey(array $map, string ...$subjects): ?string
    {
        foreach (array_keys($map) as $pattern) {
            foreach ($subjects as $subject) {
                if (Str::is((string) $pattern, $subject)) {
                    return (string) $pattern;
                }
            }
        }

        return null;
    }

    /**
     * firstKey() for a value known only by its start: a pattern that starts with it may match
     * the whole value too, and counts as matching.
     *
     * @param  array<array-key, mixed>  $map
     */
    public static function firstKeyForStart(array $map, string $start): ?string
    {
        foreach (array_keys($map) as $pattern) {
            if (Str::is((string) $pattern, $start) || str_starts_with((string) $pattern, $start)) {
                return (string) $pattern;
            }
        }

        return null;
    }
}
