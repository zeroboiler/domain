<?php

declare(strict_types=1);

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

namespace ZeroBoiler\Domain;

use ZeroBoiler\Domain\Support\HasDomainEvents;

abstract class Entity
{
    use HasDomainEvents;

    public function equals(Entity $other): bool
    {
        return static::class === $other::class && $this->id === $other->id;
    }
}