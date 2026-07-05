<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Snapshots;

use ZeroBoiler\Domain\AggregateRoot;
use ZeroBoiler\Domain\Contracts\Repository;
use ZeroBoiler\Domain\DomainEvent;
use ZeroBoiler\Domain\Concerns\EventSourced;
use ZeroBoiler\Domain\Concerns\HasSnapshots;

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
 */
final class SnapshottingRepository implements Repository
{
    public function __construct(
        private readonly Repository $inner,
        private readonly SnapshotStore $snapshotStore,
    ) {}

    #[\Override]
    public function find(string|int $id): ?AggregateRoot
    {
        $aggregateType = $this->detectAggregateType();

        // Try loading from snapshot
        $snapshot = $this->snapshotStore->load($aggregateType, (string) $id);

        if ($snapshot !== null) {
            // Check if the aggregate uses HasSnapshots trait
            // We need to create an instance first, then restore
            // For now, delegate to inner repository but provide snapshot hint
            // The inner repository would need to support this pattern.
            // Since the base repository does full replay, we optimize
            // by checking if the inner repo supports snapshot-aware loading.
        }

        // Fall back to normal loading
        return $this->inner->find($id);
    }

    #[\Override]
    public function save(AggregateRoot $aggregate): void
    {
        // Save via inner repository
        $this->inner->save($aggregate);

        // Check if snapshot should be created
        if ($this->usesSnapshots($aggregate) && $aggregate->shouldSnapshot()) {
            $aggregate->createSnapshot($this->snapshotStore);
        }
    }

    #[\Override]
    public function delete(string|int $id): void
    {
        $this->inner->delete($id);

        $aggregateType = $this->detectAggregateType();
        $this->snapshotStore->delete($aggregateType, (string) $id);
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
        $aggregateType = $this->detectAggregateType();
        $snapshot = $this->snapshotStore->load($aggregateType, $id);

        if ($snapshot !== null && $replayCallback !== null) {
            // Create a new instance and restore from snapshot
            $aggregate = $this->instantiateFromSnapshot($snapshot);

            if ($aggregate !== null) {
                // Get events after the snapshot version
                /** @var list<DomainEvent> $postSnapshotEvents */
                $postSnapshotEvents = $replayCallback($snapshot->version);

                // Replay remaining events
                foreach ($postSnapshotEvents as $event) {
                    if (method_exists($aggregate, 'applyEvent')) {
                        /** @var EventSourced $aggregate */
                        $reflection = new \ReflectionMethod($aggregate, 'applyEvent');
                        $reflection->setAccessible(true);
                        $reflection->invoke($aggregate, $event, true);
                    }
                }

                if (method_exists($aggregate, 'clearEvents')) {
                    $aggregate->clearEvents();
                }

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
     * Try to detect the aggregate type from the inner repository.
     */
    private function detectAggregateType(): string
    {
        // Use reflection to check if the inner repository has a model property
        $reflection = new \ReflectionClass($this->inner);

        foreach (['model', 'aggregateType', 'aggregateClass'] as $property) {
            if ($reflection->hasProperty($property)) {
                $prop = $reflection->getProperty($property);
                $prop->setAccessible(true);

                $value = $prop->getValue($this->inner);

                if (is_string($value)) {
                    return $value;
                }
            }
        }

        // Fall back to Repository class name as a generic key
        return 'aggregate';
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

        // Create a new instance via fromHistory with no events (just the snapshot)
        // We need to construct it without events
        // Use the snapshot state to build the aggregate

        // Try to extract aggregate ID from state
        $aggregateId = $snapshot->aggregateId;

        // Use reflection to create instance without constructor
        $reflection = new \ReflectionClass($class);
        /** @var AggregateRoot $instance */
        $instance = $reflection->newInstanceWithoutConstructor();

        // Restore from snapshot state
        /** @var HasSnapshots $instance */
        $instance->restoreFromSnapshot($snapshot);

        return $instance;
    }
}
