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
}
