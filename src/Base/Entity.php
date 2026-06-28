<?php

declare(strict_types=1);

namespace ZeroBoiler\Domain\Base;

use ZeroBoiler\Domain\Contracts\Entity as EntityContract;

abstract class Entity implements EntityContract
{
    public function equals(EntityContract $other): bool
    {
        return $other::class === static::class
            && $other->id() === $this->id();
    }

    abstract public function id(): string|int;
}
