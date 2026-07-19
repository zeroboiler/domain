<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain;

use ZeroBoiler\Domain\Contracts\Entity as EntityContract;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\Domain\Support\HasDomainEvents;

abstract class Entity implements EntityContract
{
    use HasDomainEvents;

    public function __construct(
        public readonly mixed $id,
    ) {}

    /**
     * Return the entity's domain identity.
     *
     * Defaults to returning the raw $id property. Subclasses (like AggregateRoot)
     * may override this to return a normalized string representation.
     */
    public function id(): string|int
    {
        return $this->id instanceof \Stringable ? (string) $this->id : $this->id;
    }

    public function equals(EntityContract $other): bool
    {
        return static::class === $other::class && $this->id() === $other->id();
    }
}
