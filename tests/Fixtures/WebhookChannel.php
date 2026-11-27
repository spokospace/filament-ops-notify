<?php

namespace Spokospace\OpsNotify\Tests\Fixtures;

use Spokospace\OpsNotify\Contracts\Channel;
use Spokospace\OpsNotify\OpsMessage;
use Spokospace\OpsNotify\Support\Destination;

/** A custom, non-Telegram driver, for apps whose default channel is not Telegram. */
class WebhookChannel implements Channel
{
    public function send(OpsMessage $message, Destination $destination): string
    {
        return 'sent';
    }

    public function isConfigured(): bool
    {
        return true;
    }
}
