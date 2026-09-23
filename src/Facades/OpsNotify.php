<?php

namespace Spokospace\OpsNotify\Facades;

use Illuminate\Support\Facades\Facade;
use Spokospace\OpsNotify\ChannelManager;
use Spokospace\OpsNotify\Models\OpsNotifyLog;
use Spokospace\OpsNotify\OpsMessage;
use Spokospace\OpsNotify\OpsNotifier;
use Spokospace\OpsNotify\Support\Destination;

/**
 * @method static OpsNotifyLog|null send(OpsMessage $message)
 * @method static OpsNotifyLog|null sendNow(OpsMessage $message)
 * @method static Destination|null destinationFor(OpsMessage $message)
 * @method static ChannelManager channels()
 *
 * @see OpsNotifier
 */
class OpsNotify extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return OpsNotifier::class;
    }
}
