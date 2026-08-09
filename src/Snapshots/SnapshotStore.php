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
 * The snapshot store is used by {@see SnapshottingRepository} to persist
 * and retrieve aggregate state snapshots, reducing the number of events
 * that need to be replayed on aggregate reconstitution.
 *
 * @see Snapshot
 * @see SnapshottingRepository
 * @see InMemorySnapshotStore
 *
 * @since 1.0.0
 *
 * @example
 * ```php
 * // Custom Redis implementation
 * final class RedisSnapshotStore implements SnapshotStore
 * {
 *     public function __construct(private readonly \Redis $redis) {}
 *
 *     public function load(string $aggregateType, string $aggregateId): ?Snapshot
 *     {
 *         $data = $this->redis->get("snapshot:{$aggregateType}:{$aggregateId}");
 *         return $data !== false ? Snapshot::fromArray(json_decode($data, true)) : null;
 *     }
 *
 *     public function save(Snapshot $snapshot): void
 *     {
 *         $this->redis->set(
 *             "snapshot:{$snapshot->aggregateType}:{$snapshot->aggregateId}",
 *             json_encode($snapshot->toArray()),
 *         );
 *     }
 *
 *     // ... implement remaining methods
 * }
 * ```
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
     *
     * @param  Snapshot  $snapshot  The snapshot to persist.
     */
    public function save(Snapshot $snapshot): void;

    /**
     * Check if a snapshot exists for the given aggregate.
     *
     * @param  string  $aggregateType  The FQCN of the aggregate class.
     * @param  string  $aggregateId  The aggregate's unique identifier.
     * @return bool True if a snapshot exists.
     */
    public function has(string $aggregateType, string $aggregateId): bool;

    /**
     * Delete all snapshots for a given aggregate.
     *
     * @param  string  $aggregateType  The FQCN of the aggregate class.
     * @param  string  $aggregateId  The aggregate's unique identifier.
     */
    public function delete(string $aggregateType, string $aggregateId): void;

    /**
     * Delete snapshots older than the given version.
     *
     * Useful for cleanup — keep only the latest snapshot per aggregate.
     *
     * @param  string  $aggregateType  The FQCN of the aggregate class.
     * @param  string  $aggregateId  The aggregate's unique identifier.
     * @param  int  $version  The minimum version to keep.
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
