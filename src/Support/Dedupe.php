<?php

namespace Spokospace\OpsNotify\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Laravel delivers a notification once per recipient, so one notification sent to N admins
 * would become N identical Telegram messages. The first identical payload within the window
 * wins; Cache::add is atomic, so this also holds across queue workers.
 */
final class Dedupe
{
    public static function isFirst(string $scope, mixed $payload): bool
    {
        $seconds = (int) config('ops-notify.dedupe_seconds', 60);

        return $seconds <= 0 || Cache::add("ops-notify:dedupe:{$scope}:".md5(serialize($payload)), true, $seconds);
    }
}
