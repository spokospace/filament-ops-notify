<?php

namespace Spokospace\OpsNotify\Support;

use Spokospace\OpsNotify\Models\OpsNotifyLog;

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
     * An event the app named itself ($explicit, from viewData['ops_event']) wins over a rule that
     * renames, so rewording the title cannot reroute it; a `false` rule still drops it, so Settings
     * stays the place to silence a notification.
     *
     * @param  array<string, mixed>  $config  config('ops-notify.forward_database_notifications')
     */
    public static function eventFor(string $title, array $config, ?string $explicit = null): ?string
    {
        $map = (array) ($config['map'] ?? []);
        $key = self::ruleFor($title, $map);

        if ($key !== null && ! is_string($map[$key])) {
            return null;
        }

        if ($explicit !== null) {
            return $explicit;
        }

        if ($key !== null) {
            return $map[$key];
        }

        return self::defaultEvent($config);
    }

    /**
     * An event name an app passed in viewData, or null when it is not usable as one: not a
     * string, blank, containing whitespace or `*` (it would act as a routing pattern), or longer
     * than the log keeps.
     */
    public static function explicitEvent(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' || preg_match('/[\s*]/', $value) || mb_strlen($value) > OpsNotifyLog::EVENT_LENGTH
            ? null
            : $value;
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
