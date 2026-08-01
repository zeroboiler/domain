<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain;

use ZeroBoiler\Domain\Concerns\HasDomainEvents;
use ZeroBoiler\Domain\Contracts\Entity as EntityContract;

abstract class Entity implements EntityContract
{
    use HasDomainEvents;

    public function __construct(
        public readonly mixed $id,
    ) {}

    /**
     * Return the entity's domain identity as a string.
     *
     * Always returns a string representation for consistent identity
     * handling across the entity hierarchy.
     */
    public function id(): string
    {
        return match (true) {
            is_string($this->id) => $this->id,
            $this->id instanceof \Stringable => (string) $this->id,
            is_int($this->id) => (string) $this->id,
            default => throw new \LogicException(sprintf(
                'Entity id must be string, int, or Stringable; got %s',
                get_debug_type($this->id),
            )),
        };
    }

    public function equals(EntityContract $other): bool
    {
        return static::class === $other::class
            && $this->id() === $other->id();
    }
}
