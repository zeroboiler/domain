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
     *
     * @return int The current aggregate version (0 = newly created).
     */
    public function version(): int;

    /**
     * Increment the aggregate version (typically after a successful save).
     * @return void
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
     * @return void
     */
    public function clearDomainEvents(): void;

    /**
     * Check if there are any uncommitted domain events.
     *
     * Returns true when the aggregate has recorded events that have not yet
     * been pulled via `pullDomainEvents()` or cleared via `clearDomainEvents()`.
     *
     * @return bool True if there are pending events, false otherwise.
     */
    public function hasUncommittedEvents(): bool;

    /**
     * Peek at recorded domain events without removing them.
     *
     * Returns a typed collection for inspection, logging, or debugging
     * without affecting the event state. The events remain available
     * for subsequent `pullDomainEvents()` calls.
     *
     * @return DomainEventCollection A copy of all recorded events.
     */
    public function peekDomainEvents(): DomainEventCollection;

    /**
     * Convert the aggregate root to a JSON string.
     *
     * Convenience method for explicit JSON serialization without passing
     * to `json_encode()`. Uses `JSON_THROW_ON_ERROR` for safety.
     *
     * @param  int  $options  JSON encoding options bitmask (default: JSON_UNESCAPED_UNICODE).
     * @return string The JSON-encoded aggregate root representation.
     *
     * @since 1.66.0
     */
    public function toJson(int $options = JSON_UNESCAPED_UNICODE): string;
}
