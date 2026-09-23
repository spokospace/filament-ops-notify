<?php

namespace Spokospace\OpsNotify\Support;

final readonly class Button
{
    public function __construct(
        public string $label,
        public string $url,
    ) {}
}
