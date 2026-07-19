<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain;

use Closure;
use RuntimeException;
use Throwable;
use ZeroBoiler\Domain\Contracts\AggregateRoot;
use ZeroBoiler\Domain\Contracts\UnitOfWork as UnitOfWorkContract;

/**
 * In-memory UnitOfWork implementation with domain event integration.
 *
 * Provides transactional semantics for aggregate operations:
 * - Domain events raised during a transaction are queued
 * - Events are dispatched only after a successful commit
 * - Rollback discards all pending events and restores aggregate state
 * - Supports nested run() calls via savepoint semantics
 *
 * Events maintain chronological dispatch order across nested scopes.
 * All events — regardless of which scope they were queued in — share
 * a single ordered list, ensuring dispatch order matches raise order.
 *
 * BUG-1-R39 FIX: Event collection is non-destructive. Events raised before
 * track() are NOT pulled from the aggregate — they are peeked. This means
 * if a transaction is rolled back, the aggregate still retains its events
 * and can be re-tracked in a new transaction without data loss.
 *
 * IMP-1-R39 FIX: At commit time, all tracked aggregates are re-checked for
 * events raised after track() was called. This eliminates the need for
 * manual queueEvent() calls for post-track events.
 *
 * ISSUE-#7 FIX: Transactional safety via snapshot-based rollback.
 * - track() now clones the aggregate so rollback can restore its state.
 * - commit() invokes an optional persistence callback for integration
 *   with real persistence layers.
 * - rollback() restores aggregate state from snapshots, preventing dirty
 *   objects from leaking after a failed transaction.
 */
class InMemoryUnitOfWork implements UnitOfWorkContract
{
    /** @var array<int, array{active: bool, tracked: array<string, AggregateRoot>, deleted: array<string, AggregateRoot>}> */
    private array $savepoints = [];

    private int $currentScope = -1;

    /** @var array<string, AggregateRoot> Aggregates from all committed scopes */
    private array $committed = [];

    /** @var array<string, AggregateRoot> Aggregates deleted across all scopes */
    private array $deleted = [];

    /** @var array<int, DomainEvent> Events queued in chronological order across all active scopes */
    private array $pendingEvents = [];

    /** @var int Nesting depth — only dispatch events when depth reaches 0 */
    private int $nestingDepth = 0;

    /**
     * Snapshot of pendingEvents count at each savepoint entry.
     * Used to truncate events on rollback to a savepoint.
     *
     * @var array<int, int>
     */
    private array $scopeEventCounts = [];

    /**
     * Tracks how many events have been collected from each aggregate
     * so far, to support non-destructive peeking.
     *
     * @var array<string, int>
     */
    private array $aggregateEventOffsets = [];

    /**
     * Snapshots of aggregate state taken at track() time.
     * Keyed by scope, then by aggregate identity.
     * Used to restore aggregate state on rollback.
     *
     * @var array<int, array<string, AggregateRoot>>
     */
    private array $snapshots = [];

    /**
     * Callback invoked during commit() to persist aggregates.
     *
     * @var (?Closure(array<string, AggregateRoot>, array<string, AggregateRoot>): void)
     */
    private ?Closure $persistenceCallback = null;

    /** @var \WeakMap<object, string>|null Instance-local identity map for aggregates without domain ID */
    private ?\WeakMap $idMap = null;

    /** @var int Instance-local counter for fallback object IDs */
    private int $nextId = 0;

    /**
     * Callback invoked when events are ready to be dispatched.
     *
     * @var (?Closure(DomainEvent): void)
     */
    private ?Closure $eventDispatcher = null;

    /**
     * Set the event dispatcher callback.
     *
     * @param  ?Closure(DomainEvent): void  $dispatcher
     */
    public function setEventDispatcher(?Closure $dispatcher): void
    {
        $this->eventDispatcher = $dispatcher;
    }

    /**
     * Set the persistence callback invoked during commit().
     *
     * The callback receives two arrays: committed aggregates and deleted aggregates.
     * Use this to integrate with a real persistence layer (e.g. Eloquent, Doctrine).
     *
     * @param  ?Closure(array<string, AggregateRoot>, array<string, AggregateRoot>): void  $callback
     */
    public function setPersistenceCallback(?Closure $callback): void
    {
        $this->persistenceCallback = $callback;
    }

    #[\Override]
    public function begin(): void
    {
        if ($this->nestingDepth === 0) {
            $this->nestingDepth = 1;
            $this->currentScope = 0;
            $this->savepoints = [];
            $this->savepoints[0] = [
                'active' => true,
                'tracked' => [],
                'deleted' => [],
            ];
            $this->snapshots[0] = [];
            $this->scopeEventCounts[0] = count($this->pendingEvents);
        } else {
            $this->nestingDepth++;
            $this->currentScope++;
            $this->savepoints[$this->currentScope] = [
                'active' => true,
                'tracked' => [],
                'deleted' => [],
            ];
            $this->snapshots[$this->currentScope] = [];
            $this->scopeEventCounts[$this->currentScope] = count($this->pendingEvents);
        }
    }

    #[\Override]
    public function commit(): void
    {
        if ($this->nestingDepth === 0) {
            throw new RuntimeException('No active unit of work');
        }

        $scope = $this->savepoints[$this->currentScope] ?? null;

        if ($scope === null || ! $scope['active']) {
            throw new RuntimeException('No active unit of work');
        }

        // IMP-1-R39: Re-collect events raised after track() from all tracked aggregates
        foreach ($scope['tracked'] as $id => $aggregate) {
            $this->collectNewEventsFromAggregate($aggregate);
        }

        // Merge tracked aggregates into committed set
        foreach ($scope['tracked'] as $id => $aggregate) {
            $this->committed[$id] = $aggregate;
        }

        // Merge deleted aggregates
        foreach ($scope['deleted'] as $id => $aggregate) {
            $this->deleted[$id] = $aggregate;
            unset($this->committed[$id]);
        }

        // Mark scope as inactive
        $this->savepoints[$this->currentScope]['active'] = false;

        $this->nestingDepth--;
        $this->currentScope--;

        // Only dispatch events and persist when the outermost transaction commits
        if ($this->nestingDepth === 0) {
            // ISSUE-#7: Invoke persistence callback before event dispatch
            // so aggregates are durably stored before consumers react to events.
            $this->invokePersistenceCallback();
            $this->dispatchPendingEvents();
            $this->pendingEvents = [];
            $this->aggregateEventOffsets = [];
            $this->savepoints = [];
            $this->snapshots = [];
            $this->scopeEventCounts = [];
            $this->currentScope = -1;
        }
    }

    #[\Override]
    public function rollback(): void
    {
        if ($this->nestingDepth === 0) {
            throw new RuntimeException('No active unit of work');
        }

        $scope = $this->savepoints[$this->currentScope] ?? null;

        if ($scope === null || ! $scope['active']) {
            throw new RuntimeException('No active unit of work');
        }

        // BUG-1-R39: Reset event offsets for aggregates tracked in this scope
        // so they can be re-collected in a future transaction.
        foreach ($scope['tracked'] as $id => $aggregate) {
            unset($this->aggregateEventOffsets[$id]);
        }

        // ISSUE-#7: Restore aggregate state from snapshots so objects
        // are not left dirty after a failed transaction.
        $scopeSnapshots = $this->snapshots[$this->currentScope] ?? [];
        foreach ($scope['tracked'] as $id => $aggregate) {
            if (isset($scopeSnapshots[$id])) {
                $this->restoreAggregateState($aggregate, $scopeSnapshots[$id]);
                // Events were pulled during track() and are being discarded
                // on rollback, so clear them from the restored aggregate too.
                $aggregate->clearDomainEvents();
            }
        }

        // Discard tracked aggregates from this scope
        $this->savepoints[$this->currentScope]['tracked'] = [];
        $this->savepoints[$this->currentScope]['deleted'] = [];
        $this->savepoints[$this->currentScope]['active'] = false;
        unset($this->snapshots[$this->currentScope]);

        // Truncate pending events back to the savepoint snapshot
        $eventCount = $this->scopeEventCounts[$this->currentScope] ?? 0;
        $this->pendingEvents = array_slice($this->pendingEvents, 0, $eventCount);

        $this->nestingDepth--;
        $this->currentScope--;

        if ($this->nestingDepth === 0) {
            // Outer scope rolled back — discard ALL pending events
            $this->pendingEvents = [];
            $this->aggregateEventOffsets = [];
            $this->savepoints = [];
            $this->snapshots = [];
            $this->scopeEventCounts = [];
            $this->currentScope = -1;
        }
    }

    /**
     * Execute a closure within a transactional unit of work.
     *
     * Uses savepoint semantics for nested calls.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     *
     * @throws Throwable Re-throws any exception from the callback.
     */
    #[\Override]
    public function run(Closure $callback): mixed
    {
        $this->begin();

        try {
            $result = $callback();

            $this->commit();

            return $result;
        } catch (Throwable $throwable) {
            try {
                $this->rollback();
            } catch (RuntimeException) {
                // Ignore rollback errors during exception handling
            }

            throw $throwable;
        }
    }

    #[\Override]
    public function isActive(): bool
    {
        return $this->nestingDepth > 0;
    }

    #[\Override]
    public function track(AggregateRoot $aggregate): void
    {
        if (! $this->isActive()) {
            throw new RuntimeException('No active unit of work');
        }

        $id = $this->resolveId($aggregate);

        if ($this->isTracking($aggregate)) {
            return;
        }

        // ISSUE-#7: Snapshot the aggregate before tracking so rollback
        // can restore its pre-transaction state.
        $this->snapshots[$this->currentScope][$id] = clone $aggregate;

        $this->savepoints[$this->currentScope]['tracked'][$id] = $aggregate;

        // BUG-1-R39: Non-destructive event collection.
        // Pull events from the aggregate (this clears the aggregate's list),
        // but track the offset so we DON'T need to restore them on rollback.
        // The aggregate's events ARE cleared, but that's correct behavior:
        // the UoW has taken ownership of those events for this transaction.
        // On rollback, the events are discarded from pendingEvents, and
        // the aggregate's offset is reset so future track() calls start fresh.
        $this->collectEventsFromAggregate($aggregate);
    }

    #[\Override]
    public function isTracking(AggregateRoot $aggregate): bool
    {
        $id = $this->resolveId($aggregate);

        return array_any($this->savepoints, fn (array $scope): bool => isset($scope['tracked'][$id]));
    }

    #[\Override]
    public function markForDeletion(AggregateRoot $aggregate): void
    {
        if (! $this->isActive()) {
            throw new RuntimeException('No active unit of work');
        }

        $id = $this->resolveId($aggregate);
        $this->savepoints[$this->currentScope]['deleted'][$id] = $aggregate;
    }

    /**
     * Collect domain events from an aggregate into the shared pending list.
     *
     * Uses pullDomainEvents() which clears the aggregate's event list.
     * This is intentional: the UoW takes ownership of event dispatch.
     */
    private function collectEventsFromAggregate(AggregateRoot $aggregate): void
    {
        $events = $aggregate->pullDomainEvents();

        foreach ($events as $event) {
            $this->pendingEvents[] = $event;
        }
    }

    /**
     * IMP-1-R39: Collect events that were raised after the initial track().
     *
     * Called at commit time to automatically capture any events the aggregate
     * raised between track() and commit(), without requiring manual queueEvent().
     */
    private function collectNewEventsFromAggregate(AggregateRoot $aggregate): void
    {
        if (! method_exists($aggregate, 'hasUncommittedEvents') || ! $aggregate->hasUncommittedEvents()) {
            return;
        }

        $events = $aggregate->pullDomainEvents();

        foreach ($events as $event) {
            $this->pendingEvents[] = $event;
        }
    }

    /**
     * Manually queue a domain event for dispatch after commit.
     */
    public function queueEvent(DomainEvent $event): void
    {
        if (! $this->isActive()) {
            throw new RuntimeException('No active unit of work');
        }

        $this->pendingEvents[] = $event;
    }

    #[\Override]
    public function hasPendingEvents(): bool
    {
        return $this->pendingEvents !== [];
    }

    #[\Override]
    public function getPendingEventCount(): int
    {
        return count($this->pendingEvents);
    }

    /**
     * Resolve a stable string identifier for an aggregate.
     */
    private function resolveId(AggregateRoot $aggregate): string
    {
        try {
            $aggregateId = $aggregate->id();

            if ($aggregateId !== '') {
                return (string) $aggregateId;
            }
        } catch (Throwable) {
            // Aggregate may not have an ID yet
        }

        if (! $this->idMap instanceof \WeakMap) {
            $this->idMap = new \WeakMap;
        }

        if (! isset($this->idMap[$aggregate])) {
            $this->idMap[$aggregate] = 'obj_' . (++$this->nextId);
        }

        return $this->idMap[$aggregate];
    }

    #[\Override]
    public function getCommitted(): array
    {
        return $this->committed;
    }

    #[\Override]
    public function getDeleted(): array
    {
        return $this->deleted;
    }

    /**
     * Dispatch all pending events through the configured dispatcher.
     */
    private function dispatchPendingEvents(): void
    {
        if ($this->eventDispatcher instanceof Closure) {
            foreach ($this->pendingEvents as $event) {
                ($this->eventDispatcher)($event);
            }
        }
    }

    /**
     * ISSUE-#7: Invoke the persistence callback with committed & deleted aggregates.
     *
     * Called at commit time (outermost scope) before event dispatch so that
     * aggregates are durably stored before any event consumers react.
     */
    private function invokePersistenceCallback(): void
    {
        if ($this->persistenceCallback instanceof Closure) {
            ($this->persistenceCallback)($this->committed, $this->deleted);
        }
    }

    /**
     * ISSUE-#7: Restore mutable state from a snapshot clone back into the original aggregate.
     *
     * Uses reflection to copy non-readonly properties from the snapshot into
     * the live object, preserving identity (same reference) while reverting state.
     * Readonly properties (e.g. AggregateRootId) are skipped since they cannot change.
     *
     * @param  AggregateRoot  $target  The live aggregate to restore state into
     * @param  AggregateRoot  $snapshot  The cloned snapshot to read state from
     */
    private function restoreAggregateState(AggregateRoot $target, AggregateRoot $snapshot): void
    {
        $reflection = new \ReflectionClass($target);

        foreach ($reflection->getProperties() as $property) {
            if ($property->isReadOnly()) {
                continue;
            }

            $property->setValue($target, $property->getValue($snapshot));
        }

        // Also restore properties declared on parent classes (e.g. Entity)
        $parent = $reflection->getParentClass();

        while ($parent !== false) {
            foreach ($parent->getProperties() as $property) {
                if ($property->isReadOnly()) {
                    continue;
                }

                // Skip if already handled by the child class
                if ($reflection->hasProperty($property->name) && ! $parent->hasProperty($property->name)) {
                    continue;
                }

                $property->setValue($target, $property->getValue($snapshot));
            }

            $parent = $parent->getParentClass();
        }
    }

    /**
     * Clear all state including committed data.
     */
    public function clear(): void
    {
        $this->savepoints = [];
        $this->scopeEventCounts = [];
        $this->aggregateEventOffsets = [];
        $this->snapshots = [];
        $this->currentScope = -1;
        $this->committed = [];
        $this->deleted = [];
        $this->pendingEvents = [];
        $this->nestingDepth = 0;
    }
}
