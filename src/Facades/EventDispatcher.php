<?php

declare(strict_types=1);

namespace ZeroBoiler\Domain\Facades;

use Illuminate\Support\Facades\Facade;

class EventDispatcher extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \ZeroBoiler\Domain\EventDispatcher::class;
    }
}
