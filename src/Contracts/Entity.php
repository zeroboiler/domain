<?php

declare(strict_types=1);

namespace ZeroBoiler\Domain\Contracts;

interface Entity
{
    /**
     * Return the entity's domain identity.
     *
     * Returns a string representation of the entity's identifier,
     * ensuring consistent identity handling across the hierarchy.
     */
    public function id(): string;

    public function equals(Entity $other): bool;
}
