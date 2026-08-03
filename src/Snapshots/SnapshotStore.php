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
     * @return Snapshot|null The latest snapshot, or null if none exists.
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

    /**
     * Count snapshots for a given aggregate type.
     *
     * If aggregateType is null, counts all snapshots in the store.
     * Useful for monitoring and capacity planning.
     *
     * @param  string|null  $aggregateType  The FQCN to filter by, or null for all.
     */
    public function count(?string $aggregateType = null): int;

    /**
     * Get snapshot statistics for monitoring.
     *
     * Returns aggregate counts grouped by type, plus total count
     * and optionally the oldest/newest snapshot timestamps.
     *
     * @return array{total: int, by_type: array<string, int>}
     */
    public function stats(): array;

    /**
     * Purge all snapshots for a given aggregate type.
     *
     * If aggregateType is null, purges ALL snapshots from the store.
     * Use with caution — this is destructive and irreversible.
     *
     * @param  string|null  $aggregateType  The FQCN to purge, or null for all.
     * @return int Number of snapshots removed.
     */
    public function purge(?string $aggregateType = null): int;
}
