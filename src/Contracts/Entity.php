<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Contracts;

/**
 * Contract for domain entities.
 *
 * Entities have identity and support equality comparisons based on
 * their identity rather than their properties.
 */
interface Entity
{
    /**
     * Return the entity's domain identity.
     *
     * Returns a string representation of the entity's identifier,
     * ensuring consistent identity handling across the hierarchy.
     */
    public function id(): string;

    /**
     * Check identity equality with another entity.
     *
     * Two entities are equal if and only if they are of the same type
     * and have the same identity.
     */
    public function equals(Entity $other): bool;
}
