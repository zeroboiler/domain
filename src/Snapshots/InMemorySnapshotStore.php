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
 * Not suitable for production — use a persistent store (database, Redis)
 * in production environments.
 *
 * @implements SnapshotStore
 *
 * @see Snapshot
 * @see SnapshottingRepository
 *
 * @since 1.0.0
 *
 * @example
 * ```php
 * $store = new InMemorySnapshotStore();
 * $store->save($snapshot);
 * $loaded = $store->load(Order::class, $orderId);
 * $store->count();           // 1
 * $store->stats();           // ['total' => 1, 'by_type' => ['Order' => 1]]
 * ```
 *
 * @immutable All methods that modify state return void — the store itself
 *             is intentionally mutable as an in-memory cache. Production
 *             implementations (Redis, database) follow the same interface.
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
     *
     * Alias of purge() for backward compatibility.
     *
     * @deprecated Use {@see purge()} instead. Removed in v3.0.
     * @return void
     */
    #[\Deprecated(message: 'Use purge() instead.', since: '1.5.0')]
    public function clear(): void
    {
        $this->snapshots = [];
    }

    #[\Override]
    public function count(?string $aggregateType = null): int
    {
        if ($aggregateType === null) {
            return count($this->snapshots);
        }

        $prefix = $aggregateType . ':';
        $count = 0;

        foreach (array_keys($this->snapshots) as $key) {
            if (str_starts_with($key, $prefix)) {
                $count++;
            }
        }

        return $count;
    }

    #[\Override]
    public function stats(): array
    {
        $byType = [];

        foreach ($this->snapshots as $snapshot) {
            $type = $snapshot->aggregateType;
            $byType[$type] = ($byType[$type] ?? 0) + 1;
        }

        return [
            'total' => count($this->snapshots),
            'by_type' => $byType,
        ];
    }

    #[\Override]
    public function purge(?string $aggregateType = null): int
    {
        if ($aggregateType === null) {
            $removed = count($this->snapshots);
            $this->snapshots = [];

            return $removed;
        }

        $prefix = $aggregateType . ':';
        $removed = 0;

        foreach (array_keys($this->snapshots) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset($this->snapshots[$key]);
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * Build a composite storage key from aggregate type and ID.
     *
     * @param  string  $aggregateType  The FQCN of the aggregate class.
     * @param  string  $aggregateId  The aggregate's unique identifier.
     * @return string The composite key in "{type}:{id}" format.
     *
     * @internal Internal storage key format — not part of the public API.
     */
    private function key(string $aggregateType, string $aggregateId): string
    {
        return sprintf('%s:%s', $aggregateType, $aggregateId);
    }
}
