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
    require_once __DIR__ . '/../../stubs/Trace.php';
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
     */
    public function snapshotStore(): SnapshotStore
    {
        return $this->snapshotStore;
    }

    /**
     * Load an aggregate using snapshot optimization.
     *
     * If a snapshot exists, restores it and replays only post-snapshot events.
     * Otherwise, falls back to full event replay via the inner repository.
     *
     * @param  string  $id  The aggregate ID.
     * @param  callable|null  $replayCallback  Receives (snapshotVersion) and returns post-snapshot events.
     *                                         If null, delegates to inner repository.
     */
    public function findWithSnapshot(
        string $id,
        ?callable $replayCallback = null,
    ): ?AggregateRoot {
        $snapshot = $this->snapshotStore->load($this->aggregateType, $id);

        if ($snapshot instanceof Snapshot && $replayCallback !== null) {
            // Create a new instance and restore from snapshot
            $aggregate = $this->instantiateFromSnapshot($snapshot);

            if ($aggregate instanceof AggregateRoot) {
                // Get events after the snapshot version
                $postSnapshotEvents = $replayCallback($snapshot->version);

                // Replay remaining events
                foreach ($postSnapshotEvents as $event) {
                    if (method_exists($aggregate, 'applyEvent')) {
                        $reflection = new \ReflectionMethod($aggregate, 'applyEvent');
                        $reflection->invoke($aggregate, $event, true);
                    }
                }

                $aggregate->clearEvents();

                return $aggregate;
            }
        }

        return $this->inner->find($id);
    }

    /**
     * Check if the aggregate uses the HasSnapshots trait.
     */
    private function usesSnapshots(AggregateRoot $aggregate): bool
    {
        return in_array(HasSnapshots::class, class_uses_recursive($aggregate), true);
    }

    /**
     * Instantiate an aggregate from a snapshot.
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
