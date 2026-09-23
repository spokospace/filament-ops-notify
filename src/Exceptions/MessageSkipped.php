<?php

namespace Spokospace\OpsNotify\Exceptions;

use RuntimeException;

/** Thrown by sendNow() when notifications are disabled globally or for the message's event. */
class MessageSkipped extends RuntimeException
{
    public static function for(string $event): self
    {
        return new self("Notifications are disabled globally or for the [{$event}] event.");
    }
}
