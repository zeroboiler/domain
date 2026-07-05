<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Snapshots;

/**
 * Contract for snapshot storage backends.
 *
 * Implementations: database, Redis, file-based, in-memory.
 *
 * @see Snapshot
 */
interface SnapshotStore
{
    /**
     * Load the latest snapshot for an aggregate.
     *
     * @param  string  $aggregateType  The FQCN of the aggregate class.
     * @param  string  $aggregateId  The aggregate's unique identifier.
     * @return Snapshot|null  The latest snapshot, or null if none exists.
     */
    public function load(string $aggregateType, string $aggregateId): ?Snapshot;

    /**
     * Save a snapshot to the store.
     */
    public function save(Snapshot $snapshot): void;

    /**
     * Check if a snapshot exists for the given aggregate.
     */
    public function has(string $aggregateType, string $aggregateId): bool;

    /**
     * Delete all snapshots for a given aggregate.
     */
    public function delete(string $aggregateType, string $aggregateId): void;

    /**
     * Delete snapshots older than the given version.
     *
     * Useful for cleanup — keep only the latest snapshot per aggregate.
     */
    public function deleteOlderThan(string $aggregateType, string $aggregateId, int $version): void;
}
