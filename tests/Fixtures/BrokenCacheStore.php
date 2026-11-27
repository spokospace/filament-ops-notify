<?php

namespace Spokospace\OpsNotify\Tests\Fixtures;

use Illuminate\Contracts\Cache\Store;
use RuntimeException;

/** A cache store whose server is down: every call throws, like Redis refusing connections. */
class BrokenCacheStore implements Store
{
    public const ERROR = 'Connection refused [tcp://redis:6379]';

    public function get($key): mixed
    {
        throw new RuntimeException(self::ERROR);
    }

    public function many(array $keys): array
    {
        throw new RuntimeException(self::ERROR);
    }

    public function put($key, $value, $seconds): bool
    {
        throw new RuntimeException(self::ERROR);
    }

    public function putMany(array $values, $seconds): bool
    {
        throw new RuntimeException(self::ERROR);
    }

    public function increment($key, $value = 1): int|bool
    {
        throw new RuntimeException(self::ERROR);
    }

    public function decrement($key, $value = 1): int|bool
    {
        throw new RuntimeException(self::ERROR);
    }

    public function forever($key, $value): bool
    {
        throw new RuntimeException(self::ERROR);
    }

    public function forget($key): bool
    {
        throw new RuntimeException(self::ERROR);
    }

    public function flush(): bool
    {
        throw new RuntimeException(self::ERROR);
    }

    public function getPrefix(): string
    {
        return '';
    }

    /** Repository::add() uses the store's own add() when it has one, as Redis does. */
    public function add($key, $value, $seconds): bool
    {
        throw new RuntimeException(self::ERROR);
    }
}
