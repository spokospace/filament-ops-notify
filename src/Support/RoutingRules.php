<?php

namespace Spokospace\OpsNotify\Support;

/**
 * Reads the `events` routing rules. OpsNotifier::destinationFor() and the Settings form both
 * go through here, so the form describes an event exactly the way it will be routed.
 */
final class RoutingRules
{
    /**
     * The first rule whose pattern matches the event, or [] when none does.
     *
     * @param  array<string, mixed>  $events  Pattern => rule (config('ops-notify.events')).
     * @return array<string, mixed>
     */
    public static function ruleFor(array $events, string $event): array
    {
        $key = PatternMap::firstKey($events, $event);

        return $key === null ? [] : (array) $events[$key];
    }

    /**
     * Only an explicit `false` stops an event; a missing or other value sends it.
     *
     * @param  array<string, mixed>  $rule  From ruleFor().
     */
    public static function sends(array $rule): bool
    {
        return ($rule['enabled'] ?? true) !== false;
    }
}
