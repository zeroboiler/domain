<?php

declare(strict_types=1);

namespace ZeroBoiler\Domain\Contracts;

/**
 * Unit of Work contract for transactional boundaries.
 *
 * Tracks changes to aggregates and commits them atomically.
 */
interface UnitOfWork
{
    /**
     * Register a new aggregate for insertion.
     */
    public function registerNew(object $aggregate): void;

    /**
     * Register a modified aggregate for update.
     */
    public function registerDirty(object $aggregate): void;

    /**
     * Register an aggregate for deletion.
     */
    public function registerDeleted(object $aggregate): void;

    /**
     * Commit all changes atomically.
     */
    public function commit(): void;

    /**
     * Rollback all changes.
     */
    public function rollback(): void;

    /**
     * Clear all tracked aggregates.
     */
    public function clear(): void;

    /**
     * Check if there are any pending changes.
     */
    public function hasChanges(): bool;
}