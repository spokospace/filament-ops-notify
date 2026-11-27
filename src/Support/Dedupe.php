<?php

namespace Spokospace\OpsNotify\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Laravel delivers a notification once per recipient, so one notification sent to N admins
 * would become N identical Telegram messages. The first identical payload within the window
 * wins; Cache::add is atomic, so this also holds across queue workers.
 */
final class Dedupe
{
    /** With the cache down, true: a duplicate is better than a lost message. */
    public static function isFirst(string $scope, mixed $payload): bool
    {
        $seconds = (int) config('ops-notify.dedupe_seconds', 60);

        try {
            return $seconds <= 0 || Cache::add("ops-notify:dedupe:{$scope}:".hash('xxh128', serialize($payload)), true, $seconds);
        } catch (Throwable $e) {
            Log::warning('[ops-notify] Dedupe check failed, sending anyway: '.$e->getMessage());

            return true;
        }
    }
}
