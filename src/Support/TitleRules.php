<?php

namespace Spokospace\OpsNotify\Support;

/**
 * Reads the bell notification title rules (`forward_database_notifications`). The forwarder
 * and the Settings suggestions both go through here, so a suggested title is one a rule can match.
 */
final class TitleRules
{
    /**
     * First title pattern in `map` wins: a string renames the event (for routing to a topic),
     * false drops the notification. Unmatched titles use `default_event`; null forwards nothing
     * but the mapped ones.
     *
     * @param  array<string, mixed>  $config  config('ops-notify.forward_database_notifications')
     */
    public static function eventFor(string $title, array $config): ?string
    {
        $map = (array) ($config['map'] ?? []);

        if (($key = self::ruleFor($title, $map)) !== null) {
            return is_string($map[$key]) ? $map[$key] : null;
        }

        return self::defaultEvent($config);
    }

    /**
     * The title rule that matches, tried on the title as sent and as plain text. The log and
     * Settings show the plain one (no HTML tags or entities), so a rule written from there works.
     *
     * @param  array<string, mixed>  $map  Title pattern => event name or false.
     */
    public static function ruleFor(string $title, array $map): ?string
    {
        $plain = (string) FilamentNotificationConverter::plain($title);

        return $plain === $title
            ? PatternMap::firstKey($map, $title)
            : PatternMap::firstKey($map, $title, $plain);
    }

    /**
     * The event unmatched titles are sent as, or null when they are not forwarded.
     *
     * @param  array<string, mixed>  $config  config('ops-notify.forward_database_notifications')
     */
    public static function defaultEvent(array $config): ?string
    {
        $default = $config['default_event'] ?? null;

        return is_string($default) && $default !== '' ? $default : null;
    }
}
