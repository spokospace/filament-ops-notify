<?php

namespace Spokospace\OpsNotify\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;
use Spokospace\OpsNotify\Support\Trans;

enum DeliveryStatus: string implements HasColor, HasLabel
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Failed = 'failed';
    /** A failed message that was sent again; the new attempt has its own row. */
    case Resent = 'resent';

    public function getColor(): string
    {
        return match ($this) {
            self::Queued => 'gray',
            self::Sent => 'success',
            self::Failed => 'danger',
            self::Resent => 'warning',
        };
    }

    public function getLabel(): string
    {
        return Trans::get("status.{$this->value}");
    }
}
