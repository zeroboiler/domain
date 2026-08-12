<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Contracts;

use Closure;
use ZeroBoiler\Domain\AggregateRoot;

/**
 * Contract for the unit of work pattern.
 *
 * Manages transactional consistency boundaries. Domain events raised
 * within a unit of work are queued and dispatched atomically upon commit.
 *
 * @since 1.0.0
 */
interface UnitOfWork
{
    /**
     * Begin a transactional unit of work.
     *
     * Domain events raised inside the unit of work are queued
     * and only dispatched after a successful commit.
     *
     * @throws \RuntimeException If a unit of work is already active.
     * @return void
     */
    public function begin(): void;

    /**
     * Commit the current unit of work.
     *
     * All tracked aggregates are persisted and their domain events
     * are dispatched in the order they were raised.
     *
     * @throws \RuntimeException If no active unit of work.
     * @return void
     */
    public function commit(): void;

    /**
     * Rollback the current unit of work.
     *
     * All tracked aggregates are discarded and pending domain events
     * are cleared (never dispatched).
     *
     * @throws \RuntimeException If no active unit of work.
     * @return void
     */
    public function rollback(): void;

    /**
     * Execute a closure within a transactional unit of work.
     *
     * Automatically calls begin(), commits on success (dispatching events),
     * and rolls back on exception (discarding events).
     *
     * Supports nesting via savepoints — nested run() calls create
     * savepoints rather than nested transactions.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     *
     * @throws \Throwable Re-throws any exception from the callback.
     */
    public function run(Closure $callback): mixed;

    /**
     * Check whether a unit of work is currently active.
     */
    public function isActive(): bool;

    /**
     * Track an aggregate within the current unit of work.
     * @return void
     */
    public function track(AggregateRoot $aggregate): void;

    /**
     * Check if an aggregate is tracked in the current unit of work.
     */
    public function isTracking(AggregateRoot $aggregate): bool;

    /**
     * Mark an aggregate for deletion within the current unit of work.
     * @return void
     */
    public function markForDeletion(AggregateRoot $aggregate): void;

    /**
     * Get aggregates committed in this unit of work's lifecycle.
     *
     * @return array<string, AggregateRoot>
     */
    public function getCommitted(): array;

    /**
     * Get aggregates marked for deletion.
     *
     * @return array<string, AggregateRoot>
     */
    public function getDeleted(): array;

    /**
     * Check if there are pending domain events queued in the current
     * transaction scope.
     */
    public function hasPendingEvents(): bool;

    /**
     * Get the count of pending domain events.
     */
    public function getPendingEventCount(): int;

    /**
     * Peek at pending domain events without removing them.
     *
     * Returns a typed DomainEventCollection for inspection, logging, or debugging
     * without affecting the pending event queue. The events remain queued for
     * dispatch on commit.
     *
     * @return DomainEventCollection A copy of all pending events across all active scopes.
     */
    public function getPendingEvents(): DomainEventCollection;

    /**
     * Clear all state including committed/deleted aggregates and pending events.
     *
     * Resets the unit of work to its initial state, discarding all
     * savepoints, snapshots, pending events, and committed/deleted aggregates.
     * Intended for testing — use rollback() for transactional resets.
     * @return void
     */
    public function clear(): void;
}
