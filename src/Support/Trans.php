<?php

namespace Spokospace\OpsNotify\Support;

/**
 * Shortcut for the package's translations (resources/lang/{locale}/ops-notify.php), so call
 * sites read Trans::get('page.status') instead of __('ops-notify::ops-notify.page.status').
 * Apps override strings by publishing them: vendor:publish --tag=ops-notify-translations.
 */
final class Trans
{
    /** @param  array<string, mixed>  $replace */
    public static function get(string $key, array $replace = []): string
    {
        return (string) __("ops-notify::ops-notify.{$key}", $replace);
    }

    /** @param  array<string, mixed>  $replace */
    public static function choice(string $key, int $count, array $replace = []): string
    {
        // Laravel fills :count from $count itself.
        return trans_choice("ops-notify::ops-notify.{$key}", $count, $replace);
    }
}
