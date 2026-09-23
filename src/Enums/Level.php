<?php

namespace Spokospace\OpsNotify\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum Level: string implements HasColor, HasLabel
{
    case Info = 'info';
    case Success = 'success';
    case Warning = 'warning';
    case Error = 'error';
    case Critical = 'critical';

    public function emoji(): string
    {
        return match ($this) {
            self::Info => 'ℹ️',
            self::Success => '✅',
            self::Warning => '⚠️',
            self::Error => '❌',
            self::Critical => '🚨',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Info => 'info',
            self::Success => 'success',
            self::Warning => 'warning',
            self::Error, self::Critical => 'danger',
        };
    }

    public function getLabel(): string
    {
        return ucfirst($this->value);
    }
}
