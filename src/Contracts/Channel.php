<?php

namespace Spokospace\OpsNotify\Contracts;

use Spokospace\OpsNotify\Exceptions\ChannelException;
use Spokospace\OpsNotify\OpsMessage;
use Spokospace\OpsNotify\Support\Destination;

interface Channel
{
    /**
     * Deliver the message and return the provider's message id.
     *
     * @throws ChannelException
     */
    public function send(OpsMessage $message, Destination $destination): string;

    /** Whether the channel has the credentials it needs to send anything. */
    public function isConfigured(): bool;
}
