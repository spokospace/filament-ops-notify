<?php

namespace Spokospace\OpsNotify\Exceptions;

use RuntimeException;

class ChannelException extends RuntimeException
{
    /**
     * @param  int|null  $retryAfter  Seconds the provider asked us to wait (rate limit).
     * @param  bool  $permanent  Retrying cannot help (bad token, unknown chat, malformed payload).
     */
    public function __construct(
        string $message,
        public readonly ?int $retryAfter = null,
        public readonly bool $permanent = false,
    ) {
        parent::__construct($message);
    }
}
