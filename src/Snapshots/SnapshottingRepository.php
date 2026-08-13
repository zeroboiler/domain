<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Snapshots;

use ZeroBoiler\Domain\AggregateRoot;
use ZeroBoiler\Domain\Concerns\HasSnapshots;
use ZeroBoiler\Domain\Contracts\Repository;

// When zeroboiler/observability is not installed, the #[Trace] attribute
// must still be available since SnapshottingRepository uses it on methods.
// This stub file provides a no-op fallback.
if (! class_exists(\ZeroBoiler\Observability\Trace::class)) {
    require_once __DIR__ . '/../stubs/Trace.php';
}

use ZeroBoiler\Observability\Trace;

/**
 * Repository decorator that adds snapshot support to event-sourced aggregates.
 *
 * Wraps a base event-sourced repository. On find():
 * 1. Checks snapshot store for the latest snapshot
 * 2. If found, restores aggregate from snapshot + replays post-snapshot events
 * 3. If not found, delegates to the base repository (full replay)
 *
 * On save():
 * 1. Delegates to the base repository
 * 2. Checks if snapshot is due (#[SnapshotPolicy]) and creates one
 *
 * BUG-2-R39 FIX: When the inner repository doesn't support snapshot-aware
 * loading (findAfterVersion), the fallback now correctly prefers the snapshot
 * over a potentially incomplete full replay (e.g., pruned event store).
 *
 * The aggregate root must use the {@see HasSnapshots} trait for snapshot
 * operations (shouldSnapshot, createSnapshot, restoreFromSnapshot).
 *
 * @see Snapshot
 * @see SnapshotStore
 * @see SnapshotPolicy
 * @see HasSnapshots
 *
 * @example
 * ```php
 * use ZeroBoiler\Domain\Contracts\Repository;
 * use ZeroBoiler\Domain\Snapshots\SnapshottingRepository;
 * use ZeroBoiler\Domain\Snapshots\InMemorySnapshotStore;
 *
 * $innerRepo = new EventSourcedOrderRepository($eventStore);
 * $snapshotStore = new InMemorySnapshotStore();
 *
 * $repo = new SnapshottingRepository(
 *     inner: $innerRepo,
 *     snapshotStore: $snapshotStore,
 *     aggregateType: Order::class,
 * );
 *
 * $order = $repo->find($orderId);
 * // Loads from snapshot (if available) + replays only post-snapshot events
 *
 * $repo->save($order);
 * // Saves via inner repo + auto-snapshots if #[SnapshotPolicy] triggers
 * ```
 *
 * @since 1.0.0
 */
final readonly class SnapshottingRepository implements Repository
{
    /**
     * @param  string  $aggregateType  The FQCN of the aggregate root class.
     *                                 Required to avoid snapshot collisions between different aggregate types.
     */
    public function __construct(
        private Repository $inner,
        private SnapshotStore $snapshotStore,
        private string $aggregateType,
    ) {}

    #[\Override]
    #[Trace(operation: 'domain.aggregate.find')]
    public function find(string|int $id): ?AggregateRoot
    {
        // Try loading from snapshot
        $snapshot = $this->snapshotStore->load($this->aggregateType, (string) $id);

        if ($snapshot instanceof Snapshot) {
            $aggregate = $this->instantiateFromSnapshot($snapshot);

            if ($aggregate instanceof AggregateRoot) {
                // Snapshot restored successfully — replay only post-snapshot events
                // by delegating to inner repository which does full event replay.
                //
                // If the inner repository supports snapshot-aware loading
                // (e.g., via a method like findAfterVersion), we use it.
                if (method_exists($this->inner, 'findAfterVersion')) {
                    $aggregateAfter = $this->inner->findAfterVersion($id, $snapshot->version, $aggregate);

                    return $aggregateAfter instanceof AggregateRoot ? $aggregateAfter : $aggregate;
                }

                // BUG-2-R39 FIX: No snapshot-aware inner repository.
                // Use full replay via inner->find(), but prefer the snapshot
                // if the replay returned less data than the snapshot has
                // (e.g., event store was pruned and no longer has all events).
                $replayed = $this->inner->find($id);

                if ($replayed instanceof AggregateRoot && $replayed->version() >= $snapshot->version) {
                    // Full replay is at least as current as the snapshot — use it
                    return $replayed;
                }

                // Inner has less data than snapshot (pruned event store)
                // or inner returned null — use the snapshot-restored aggregate
                return $aggregate;
            }
        }

        // No snapshot — fall back to normal loading
        return $this->inner->find($id);
    }

    #[\Override]
    #[Trace(operation: 'domain.aggregate.save')]
    public function save(AggregateRoot $aggregate): void
    {
        // Save via inner repository
        $this->inner->save($aggregate);

        // Check if snapshot should be created
        if ($this->usesSnapshots($aggregate)) {
            if ($aggregate->shouldSnapshot()) {
                $aggregate->createSnapshot($this->snapshotStore);
            }
        }
    }

    #[\Override]
    #[Trace(operation: 'domain.aggregate.delete')]
    public function delete(string|int $id): void
    {
        $this->inner->delete($id);

        $this->snapshotStore->delete($this->aggregateType, (string) $id);
    }

    /**
     * Get the underlying snapshot store.
     *
     * @return SnapshotStore The snapshot store used by this repository.
     */
    public function snapshotStore(): SnapshotStore
    {
        return $this->snapshotStore;
    }

    /**
     * Load an aggregate using snapshot optimization.
     *
     * If a snapshot exists, delegates to the aggregate's public
     * {@see AggregateRoot::reconstituteFromSnapshot()} method for snapshot
     * restoration and post-snapshot event replay.
     * Otherwise, falls back to full event replay via the inner repository.
     *
     * @param  string  $id  The aggregate ID.
     * @param  callable|null  $replayCallback  Receives (snapshotVersion) and returns post-snapshot events.
     *                                         If null, delegates to inner repository.
     * @return AggregateRoot|null The aggregate root, or null if not found.
     *
     * @example
     * ```php
     * $order = $repo->findWithSnapshot(
     *     $orderId,
     *     fn (int $version) => $eventStore->getEventsAfter($orderId, $version),
     * );
     * ```
     */
    public function findWithSnapshot(
        string $id,
        ?callable $replayCallback = null,
    ): ?AggregateRoot
    {
        $snapshot = $this->snapshotStore->load($this->aggregateType, $id);

        if ($snapshot instanceof Snapshot && $replayCallback !== null) {
            // Delegate to the aggregate's public reconstitution API
            // instead of duplicating reflection-based event replay.
            $postSnapshotEvents = $replayCallback($snapshot->version);

            if (is_subclass_of($this->aggregateType, AggregateRoot::class)) {
                return $this->aggregateType::reconstituteFromSnapshot(
                    $snapshot,
                    $postSnapshotEvents,
                );
            }
        }

        return $this->inner->find($id);
    }

    /**
     * Check if the aggregate uses the HasSnapshots trait.
     *
     * @param  AggregateRoot  $aggregate  The aggregate root to check.
     * @return bool True if the aggregate uses the HasSnapshots trait.
     */
    private function usesSnapshots(AggregateRoot $aggregate): bool
    {
        return in_array(HasSnapshots::class, class_uses_recursive($aggregate), true);
    }

    /**
     * Instantiate an aggregate from a snapshot.
     *
     * @param  Snapshot  $snapshot  The snapshot to reconstitute from.
     * @return AggregateRoot|null The reconstituted aggregate, or null if instantiation fails.
     */
    private function instantiateFromSnapshot(Snapshot $snapshot): ?AggregateRoot
    {
        $class = $snapshot->aggregateType;

        if (! class_exists($class) || ! is_subclass_of($class, AggregateRoot::class)) {
            return null;
        }

        // Check if the class uses HasSnapshots
        if (! in_array(HasSnapshots::class, class_uses_recursive($class), true)) {
            return null;
        }

        // Use reflection to create instance without constructor
        $reflection = new \ReflectionClass($class);

        $instance = $reflection->newInstanceWithoutConstructor();

        // Restore from snapshot state
        $instance->restoreFromSnapshot($snapshot);

        return $instance;
    }
}
