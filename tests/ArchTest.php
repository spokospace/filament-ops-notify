<?php

use Illuminate\Contracts\Queue\ShouldQueue;

arch('no debugging leftovers')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r', 'die', 'exit'])
    ->not->toBeUsed();

arch('plain PHP hygiene')->preset()->php();

arch('no insecure functions')->preset()->security();

arch('contracts are interfaces')
    ->expect('Spokospace\OpsNotify\Contracts')
    ->toBeInterfaces();

arch('enums are enums')
    ->expect('Spokospace\OpsNotify\Enums')
    ->toBeEnums();

arch('exceptions are exceptions')
    ->expect('Spokospace\OpsNotify\Exceptions')
    ->toExtend(RuntimeException::class);

arch('jobs are queued')
    ->expect('Spokospace\OpsNotify\Jobs')
    ->toImplement(ShouldQueue::class);

arch('the core does not depend on Filament pages')
    ->expect(['Spokospace\OpsNotify\OpsNotifier', 'Spokospace\OpsNotify\OpsMessage', 'Spokospace\OpsNotify\Channels', 'Spokospace\OpsNotify\Jobs'])
    ->not->toUse('Spokospace\OpsNotify\Filament');
