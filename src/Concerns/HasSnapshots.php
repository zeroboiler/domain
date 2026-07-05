<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Concerns;

use ZeroBoiler\Domain\Snapshots\Snapshot;
use ZeroBoiler\Domain\Snapshots\SnapshotPolicy;
use ZeroBoiler\Domain\Snapshots\SnapshotStore;

/**
 * Provides snapshot serialization for event-sourced aggregate roots.
 *
 * Usage:
 *   ```php
 *   #[SnapshotPolicy(every: 50)]
 *   class Order extends AggregateRoot
 *   {
 *       use HasSnapshots;
 *   }
 *   ```
 *
 * The trait provides:
 * - `toSnapshotState()` — Override to define what state to persist
 * - `restoreFromSnapshot()` — Override to restore state from snapshot
 * - `shouldSnapshot()` — Checks if snapshot is due (based on #[SnapshotPolicy])
 * - `createSnapshot()` — Creates and stores a snapshot
 */
trait HasSnapshots
{
    /**
     * Serialize the aggregate's state for snapshot storage.
     *
     * Override this method to define which properties to persist.
     * Default implementation serializes all public and protected properties.
     *
     * @return array<string, mixed>
     */
    public function toSnapshotState(): array
    {
        $reflection = new \ReflectionClass($this);
        $state = [];

        foreach ($reflection->getProperties() as $property) {
            if ($property->isStatic()) {
                continue;
            }

            // Skip the domainEvents collection (handled separately)
            if ($property->getName() === 'domainEvents') {
                continue;
            }

            $property->setAccessible(true);
            $value = $property->getValue($this);

            // Skip closures and resources
            if ($value instanceof \Closure || is_resource($value)) {
                continue;
            }

            $state[$property->getName()] = $value;
        }

        return $state;
    }

    /**
     * Restore aggregate state from a snapshot.
     *
     * Override this for custom restoration logic. Default implementation
     * sets properties directly via reflection.
     *
     * @param  array<string, mixed>  $state
     */
    public function restoreFromSnapshotState(array $state): void
    {
        $reflection = new \ReflectionClass($this);

        foreach ($state as $name => $value) {
            if ($reflection->hasProperty($name)) {
                $property = $reflection->getProperty($name);
                $property->setAccessible(true);
                $property->setValue($this, $value);
            }
        }
    }

    /**
     * Check if a snapshot should be taken at the current version.
     *
     * Reads the #[SnapshotPolicy] attribute from the class. If the
     * current version is a multiple of the configured interval,
     * returns true.
     */
    public function shouldSnapshot(): bool
    {
        $policy = $this->getSnapshotPolicy();

        if ($policy === null || $policy->every <= 0) {
            return false;
        }

        return $this->version() > 0 && $this->version() % $policy->every === 0;
    }

    /**
     * Create and store a snapshot of the current state.
     */
    public function createSnapshot(SnapshotStore $store): Snapshot
    {
        $snapshot = Snapshot::create(
            aggregateType: static::class,
            aggregateId: $this->id(),
            version: $this->version(),
            state: $this->toSnapshotState(),
        );

        $store->save($snapshot);

        return $snapshot;
    }

    /**
     * Restore from a snapshot and return the version to resume from.
     *
     * After calling this, replay events starting from version + 1.
     */
    public function restoreFromSnapshot(Snapshot $snapshot): void
    {
        $this->restoreFromSnapshotState($snapshot->state);
        $this->setVersion($snapshot->version);
    }

    /**
     * Get the snapshot policy for this aggregate class, if configured.
     */
    public function getSnapshotPolicy(): ?SnapshotPolicy
    {
        $reflection = new \ReflectionClass($this);
        $attributes = $reflection->getAttributes(SnapshotPolicy::class);

        if ($attributes === []) {
            return null;
        }

        return $attributes[0]->newInstance();
    }
}
