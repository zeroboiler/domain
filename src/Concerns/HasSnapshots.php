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
 * When used with {@see EventSourced}, enables efficient aggregate reconstitution
 * by periodically persisting aggregate state as a snapshot. On reload, only
 * events after the snapshot version need to be replayed.
 *
 * Requires the aggregate to use the {@see EventSourced} trait and be decorated
 * with a {@see SnapshotPolicy} attribute for automatic snapshot management.
 *
 * The trait provides:
 * - `toSnapshotState()` — Serialize aggregate state (override for custom logic)
 * - `restoreFromSnapshotState()` — Restore state from array (override for custom logic)
 * - `restoreFromSnapshot()` — Restore from a Snapshot object + set version
 * - `shouldSnapshot()` — Checks if snapshot is due (based on #[SnapshotPolicy])
 * - `createSnapshot()` — Creates and stores a snapshot via SnapshotStore
 * - `getSnapshotPolicy()` — Reads the #[SnapshotPolicy] attribute from the class
 *
 * @see EventSourced
 * @see SnapshotPolicy
 * @see Snapshot
 * @see SnapshottingRepository
 * @see InMemorySnapshotStore
 *
 * @since 1.0.0
 *
 * @example
 * ```php
 * use ZeroBoiler\Domain\AggregateRoot;
 * use ZeroBoiler\Domain\Concerns\EventSourced;
 * use ZeroBoiler\Domain\Concerns\HasSnapshots;
 * use ZeroBoiler\Domain\Snapshots\SnapshotPolicy;
 *
 * #[SnapshotPolicy(every: 50)]
 * class Order extends AggregateRoot
 * {
 *     use EventSourced;
 *     use HasSnapshots;
 *
 *     public string $status = 'pending';
 *     public float $total = 0.0;
 *
 *     // Snapshots are automatically created every 50 events
 *     // by SnapshottingRepository::save()
 *
 *     // Override toSnapshotState() for custom serialization:
 *     // public function toSnapshotState(): array
 *     // {
 *     //     return [
 *     //         'status' => $this->status,
 *     //         'total' => $this->total,
 *     //     ];
 *     // }
 *
 *     // Override restoreFromSnapshotState() for custom restoration:
 *     // public function restoreFromSnapshotState(array $state): void
 *     // {
 *     //     $this->status = $state['status'];
 *     //     $this->total = $state['total'];
 *     // }
 * }
 * ```
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

            // Skip properties that aren't initialized (e.g., readonly unset)
            if (! $property->isInitialized($this)) {
                continue;
            }

            // Skip the domainEvents collection (handled separately)
            if ($property->getName() === 'domainEvents') {
                continue;
            }

            // Skip version — restored separately via setVersion()
            // in restoreFromSnapshot(). Including it in state would cause
            // a double-restore and potential inconsistency.
            if ($property->getName() === 'version') {
                continue;
            }

            $value = $property->getValue($this);

            // Skip closures and resources
            if ($value instanceof \Closure) {
                continue;
            }

            if (is_resource($value)) {
                continue;
            }

            // Skip objects that are not safely serializable
            if (is_object($value)) {
                // Allow DateTimeInterface, stdClass, BackedEnum, UnitEnum
                if ($value instanceof \DateTimeInterface) {
                    // Convert to ISO string for safe serialization
                    $state[$property->getName()] = $value->format(\DateTimeInterface::ATOM);
                    continue;
                }

                if ($value instanceof \stdClass) {
                    $state[$property->getName()] = $value;
                    continue;
                }

                if ($value instanceof \BackedEnum) {
                    $state[$property->getName()] = $value->value;
                    continue;
                }

                if ($value instanceof \UnitEnum) {
                    $state[$property->getName()] = $value->name;
                    continue;
                }

                // Skip objects that don't have __serialize and aren't serializable
                // This prevents PDO connections, service references, etc. from corrupting snapshots
                if (! method_exists($value, '__serialize')) {
                    continue;
                }
            }

            // Skip arrays containing non-serializable objects
            if (is_array($value) && json_encode($value) === false) {
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
     * sets properties directly via reflection, handling readonly properties
     * by unsetting them first when necessary (PHP 8.5+ allows re-initialization
     * of readonly properties after unset via Reflection).
     *
     * @param  array<string, mixed>  $state
     */
    public function restoreFromSnapshotState(array $state): void
    {
        $reflection = new \ReflectionClass($this);

        foreach ($state as $name => $value) {
            if (! $reflection->hasProperty($name)) {
                continue;
            }

            $property = $reflection->getProperty($name);

            if ($property->isStatic()) {
                continue;
            }

            // Handle readonly properties: if the property is readonly and
            // already initialized, we need to unset it first to make it
            // uninitialized, then set the new value via reflection.
            // This avoids "Cannot modify readonly property" errors when
            // restoring from snapshots.
            if ($property->isReadOnly() && $property->isInitialized($this)) {
                // Unset first — makes the readonly property uninitialized
                unset($this->{$name});
            }

            $property->setValue($this, $value);
        }
    }

    /**
     * Check if a snapshot should be taken at the current version.
     *
     * Reads the {@see SnapshotPolicy} attribute from the class. If the
     * current version is a positive multiple of the configured interval,
     * returns true. Returns false when version is 0 or policy is disabled.
     *
     * @return bool True if a snapshot is due at the current version.
     *
     * @see SnapshottingRepository::save() Automatically checks this after persisting.
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
     *
     * Serializes the aggregate via {@see toSnapshotState()}, creates a
     * {@see Snapshot} record, and persists it to the given store.
     *
     * @param  SnapshotStore  $store  The snapshot store to persist into.
     * @return Snapshot The newly created and persisted snapshot.
     *
     * @see SnapshottingRepository::save() Automatically calls this when policy triggers.
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
     * Restore aggregate state from a snapshot.
     *
     * Restores all properties from the snapshot's state array and sets
     * the aggregate version to match. After this call, only post-snapshot
     * events need to be replayed.
     *
     * @param  Snapshot  $snapshot  The snapshot to restore from.
     *
     * @see SnapshottingRepository::find() Calls this during aggregate reconstitution.
     */
    public function restoreFromSnapshot(Snapshot $snapshot): void
    {
        $this->restoreFromSnapshotState($snapshot->state);
        $this->setVersion($snapshot->version);
    }

    /**
     * Get the snapshot policy attribute for this aggregate class.
     *
     * Reads the {@see SnapshotPolicy} PHP attribute from the aggregate's
     * reflection class. Returns null if no attribute is configured.
     *
     * @return SnapshotPolicy|null The configured policy, or null if not set.
     *
     * @see SnapshottingRepository::save() Uses this to determine if snapshot is due.
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
