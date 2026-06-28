<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Facades;

use Illuminate\Support\Facades\Facade;
use ZeroBoiler\Domain\DomainEventDispatcher;

class EventDispatcher extends Facade
{
    #[\Override]
    protected static function getFacadeAccessor(): string
    {
        return DomainEventDispatcher::class;
    }
}
