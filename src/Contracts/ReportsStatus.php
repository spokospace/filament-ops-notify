<?php

namespace Spokospace\OpsNotify\Contracts;

/** Optional for channels: a one-line connection check shown on the Filament page. */
interface ReportsStatus
{
    /** E.g. "Connected as @bot" or the provider's error. Must not throw; should cache. */
    public function status(): string;
}
