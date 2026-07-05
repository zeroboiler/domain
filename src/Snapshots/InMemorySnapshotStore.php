<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Snapshots;

/**
 * In-memory snapshot store for testing and development.
 *
 * Stores snapshots keyed by aggregate type + ID, keeping only the latest.
 */
final class InMemorySnapshotStore implements SnapshotStore
{
    /** @var array<string, Snapshot> Key: "{type}:{id}" */
    private array $snapshots = [];

    #[\Override]
    public function load(string $aggregateType, string $aggregateId): ?Snapshot
    {
        return $this->snapshots[$this->key($aggregateType, $aggregateId)] ?? null;
    }

    #[\Override]
    public function save(Snapshot $snapshot): void
    {
        $this->snapshots[$this->key($snapshot->aggregateType, $snapshot->aggregateId)] = $snapshot;
    }

    #[\Override]
    public function has(string $aggregateType, string $aggregateId): bool
    {
        return isset($this->snapshots[$this->key($aggregateType, $aggregateId)]);
    }

    #[\Override]
    public function delete(string $aggregateType, string $aggregateId): void
    {
        unset($this->snapshots[$this->key($aggregateType, $aggregateId)]);
    }

    #[\Override]
    public function deleteOlderThan(string $aggregateType, string $aggregateId, int $version): void
    {
        $key = $this->key($aggregateType, $aggregateId);

        if (isset($this->snapshots[$key]) && $this->snapshots[$key]->version < $version) {
            unset($this->snapshots[$key]);
        }
    }

    /**
     * Clear all snapshots.
     */
    public function clear(): void
    {
        $this->snapshots = [];
    }

    /**
     * Count all stored snapshots.
     */
    public function count(): int
    {
        return count($this->snapshots);
    }

    private function key(string $aggregateType, string $aggregateId): string
    {
        return "{$aggregateType}:{$aggregateId}";
    }
}
