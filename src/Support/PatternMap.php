<?php

namespace Spokospace\OpsNotify\Support;

use Illuminate\Support\Str;

/** Config maps keyed by Str::is() patterns, where the first matching key wins. */
final class PatternMap
{
    /** @param  array<string, mixed>  $map */
    public static function firstKey(array $map, string $subject): ?string
    {
        foreach (array_keys($map) as $pattern) {
            if (Str::is((string) $pattern, $subject)) {
                return (string) $pattern;
            }
        }

        return null;
    }
}
