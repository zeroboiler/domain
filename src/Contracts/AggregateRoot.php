<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Contracts;

use ZeroBoiler\Domain\DomainEventCollection;

/**
 * Contract for aggregate roots in the domain model.
 *
 * Aggregate roots are the top-level entities that serve as consistency
 * boundaries. They own domain events and expose versioning for
 * optimistic locking.
 *
 * @see \ZeroBoiler\Domain\AggregateRoot Base implementation
 * @see \ZeroBoiler\Domain\Contracts\Entity Parent entity contract
 *
 * @since 1.0.0
 */
interface AggregateRoot extends Entity
{
    /**
     * Get the current version number of this aggregate.
     *
     * Used by repositories for optimistic locking checks.
     */
    public function version(): int;

    /**
     * Increment the aggregate version (typically after a successful save).
     */
    public function incrementVersion(): void;

    /**
     * Pull (destructive) all recorded domain events.
     *
     * Removes events from the aggregate and returns them for dispatch.
     *
     * @return DomainEventCollection
     */
    public function pullDomainEvents(): DomainEventCollection;

    /**
     * Clear all recorded domain events without returning them.
     */
    public function clearDomainEvents(): void;
}
