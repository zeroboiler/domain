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
use ZeroBoiler\Events\Domain\DomainEvent;

/**
 * In-memory Unit of Work implementation with domain event integration.
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
 * Snapshot-based rollback (ISSUE-#7): When tracking an aggregate, a clone
 * is captured. On rollback, non-readonly properties are restored from the
 * clone, preventing dirty objects from leaking after a failed transaction.
 *
 * Persistence callback (ISSUE-#7): An optional persistence callback can
 * be set via `setPersistenceCallback()`. It is invoked at commit time
 * (outermost scope) BEFORE event dispatch so aggregates are durably
 * stored before consumers react to events.
 *
 * @implements UnitOfWorkContract
 *
 * @since 1.0.0
 *
 * @example
 * ```php
 * $uow = app(UnitOfWork::class);
 *
 * // Transactional with auto-commit/rollback
 * $result = $uow->run(function () use ($repository, $id) {
 *     $order = $repository->find($id);
 *     $order->pay(100.00);
 *     $repository->save($order);
 *     return $order;
 * });
 *
 * // Manual transaction control
 * $uow->begin();
 * $uow->track($aggregate);
 * $uow->commit();
 *
 * // With persistence callback
 * $uow->setPersistenceCallback(function (array $committed, array $deleted): void {
 *     foreach ($committed as $aggregate) {
 *         DB::table('aggregates')->upsert([...]);
 *     }
 * });
 * ```
 */
final class InMemoryUnitOfWork implements UnitOfWorkContract
{
    /** @var array<int, array{active?: bool, tracked: array<string, AggregateRoot>, deleted: array<string, AggregateRoot>}> */
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
     * @return void
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
     * @return void
     */
    public function setPersistenceCallback(?Closure $callback): void
    {
        $this->persistenceCallback = $callback;
    }

    /**
     * Begin a new transactional unit of work.
     *
     * At the outermost level, clears any committed/deleted data from
     * previous transaction cycles to prevent stale data from leaking.
     * Nested calls create savepoints for scoped rollback support.
     *
     * Safe to call multiple times — nesting depth is tracked internally.
     * @return void
     */
    #[\Override]
    public function begin(): void
    {
        if ($this->nestingDepth === 0) {
            $this->savepoints = [];
            $this->currentScope = -1;
            // Clear committed/deleted from previous transaction cycle
            // to prevent stale data from leaking into new transactions.
            $this->committed = [];
            $this->deleted = [];
        }

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

    /**
     * Commit the current transactional scope.
     *
     * Collects any events raised after track(), merges tracked/deleted
     * aggregates into their respective sets, and at the outermost scope:
     * 1. Invokes the persistence callback (if set) for durable storage
     * 2. Dispatches all pending domain events in chronological order
     * 3. Resets transactional state for the next cycle
     *
     * @throws RuntimeException When no unit of work is active.
     * @return void
     */
    #[\Override]
    public function commit(): void
    {
        $scope = $this->requireActiveScope();

        // IMP-1-R39: Re-collect events raised after track() from all tracked aggregates
        foreach ($scope['tracked'] as $id => $aggregate) {
            $this->collectNewEventsFromAggregate($aggregate);
        }

        // Merge tracked + deleted aggregates into their respective sets
        $this->committed = array_merge($this->committed, $scope['tracked']);
        foreach ($scope['deleted'] as $id => $aggregate) {
            $this->deleted[$id] = $aggregate;
            unset($this->committed[$id]);
        }

        // Mark scope as inactive
        $this->savepoints[$this->currentScope]['active'] = false;

        $this->exitScope();

        // Only dispatch events and persist when the outermost transaction commits
        if ($this->nestingDepth === 0) {
            // ISSUE-#7: Invoke persistence callback before event dispatch
            // so aggregates are durably stored before consumers react to events.
            $this->invokePersistenceCallback();
            $this->dispatchPendingEvents();
            $this->resetTransactionState();
        }
    }

    /**
     * Rollback the current transactional scope.
     *
     * Restores aggregate state from snapshots taken at track() time,
     * discards pending events raised in this scope, and at the outermost
     * scope resets all transactional state.
     *
     * After rollback, tracked aggregates are restored to their pre-transaction
     * state and any events they raised are discarded.
     *
     * @throws RuntimeException When no unit of work is active.
     * @return void
     */
    #[\Override]
    public function rollback(): void
    {
        $scope = $this->requireActiveScope();

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

        $this->exitScope();

        if ($this->nestingDepth === 0) {
            // Outer scope rolled back — discard ALL pending events
            $this->resetTransactionState();
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
     * @return T The return value of the callback.
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

    /**
     * Check if any transactional scope is currently active.
     *
     * @return bool True when at least one begin() has been called without a matching commit/rollback.
     */
    #[\Override]
    public function isActive(): bool
    {
        return $this->nestingDepth > 0;
    }

    /**
     * Track an aggregate root within the current transactional scope.
     *
     * Takes a snapshot of the aggregate's state for rollback support,
     * registers it in the current savepoint, and collects any pending
     * domain events it has already raised.
     *
     * Events raised between track() and commit() are automatically
     * collected at commit time — no manual queueEvent() needed.
     *
     * @param  AggregateRoot  $aggregate  The aggregate to track.
     *
     * @throws RuntimeException When no unit of work is active.
     * @return void
     */
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

    /**
     * Check if an aggregate is currently being tracked in any active scope.
     *
     * Searches all savepoints (including nested scopes) for the aggregate.
     *
     * @param  AggregateRoot  $aggregate  The aggregate to check.
     * @return bool True if the aggregate is tracked in any scope.
     */
    #[\Override]
    public function isTracking(AggregateRoot $aggregate): bool
    {
        $id = $this->resolveId($aggregate);

        return array_any($this->savepoints, fn (array $scope): bool => isset($scope['tracked'][$id]));
    }

    /**
     * Mark an aggregate for deletion at commit time.
     *
     * The aggregate will be removed from the committed set and added to
     * the deleted set when the outermost transaction commits. This is
     * used by repositories to handle aggregate deletion within transactions.
     *
     * @param  AggregateRoot  $aggregate  The aggregate to mark for deletion.
     *
     * @throws RuntimeException When no unit of work is active.
     * @return void
     */
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
     *
     * @internal
     * @return void
     */
    private function collectEventsFromAggregate(AggregateRoot $aggregate): void
    {
        $this->appendEvents($aggregate->pullDomainEvents());
    }

    /**
     * IMP-1-R39: Collect events that were raised after the initial track().
     *
     * Called at commit time to automatically capture any events the aggregate
     * raised between track() and commit(), without requiring manual queueEvent().
     *
     * @internal
     * @return void
     */
    private function collectNewEventsFromAggregate(AggregateRoot $aggregate): void
    {
        if (! method_exists($aggregate, 'hasUncommittedEvents') || ! $aggregate->hasUncommittedEvents()) {
            return;
        }

        $this->appendEvents($aggregate->pullDomainEvents());
    }

    /**
     * Append domain events to the pending event queue.
     *
     * Accepts both DomainEventCollection (from pullDomainEvents())
     * and plain arrays (from releaseEvents()).
     *
     * @param  DomainEventCollection|list<DomainEvent>  $events
     *
     * @internal
     * @return void
     */
    private function appendEvents(DomainEventCollection|array $events): void
    {
        foreach ($events as $event) {
            $this->pendingEvents[] = $event;
        }
    }

    /**
     * Manually queue a domain event for dispatch after commit.
     *
     * Use this to inject events into the unit of work from outside
     * the aggregate (e.g., cross-aggregate side effects).
     *
     * @param  DomainEvent  $event  The event to queue for dispatch.
     *
     * @throws RuntimeException When no unit of work is active.
     * @return void
     */
    public function queueEvent(DomainEvent $event): void
    {
        if (! $this->isActive()) {
            throw new RuntimeException('No active unit of work');
        }

        $this->pendingEvents[] = $event;
    }

    /**
     * Check if there are pending events waiting for dispatch.
     *
     * @return bool True when at least one event is queued across all active scopes.
     */
    #[\Override]
    public function hasPendingEvents(): bool
    {
        return $this->pendingEvents !== [];
    }

    /**
     * Get the number of pending events across all active scopes.
     *
     * @return int The count of events queued for dispatch.
     */
    #[\Override]
    public function getPendingEventCount(): int
    {
        return count($this->pendingEvents);
    }

    /**
     * Peek at pending events without removing them.
     *
     * Returns a typed DomainEventCollection for inspection, logging, or debugging
     * without affecting the pending event queue. The events remain queued for
     * dispatch on commit.
     *
     * @return DomainEventCollection A copy of all pending events across all active scopes.
     *
     * @example
     * ```php
     * $uow->begin();
     * $uow->track($order);
     * $order->addItem('SKU-001', 2);
     *
     * // Inspect without consuming
     * $peeked = $uow->getPendingEvents();
     * foreach ($peeked as $event) {
     *     logger()->debug('Pending', ['type' => $event->eventType]);
     * }
     *
     * $uow->commit(); // Events are dispatched normally
     * ```
     */
    #[\Override]
    public function getPendingEvents(): DomainEventCollection
    {
        return new DomainEventCollection($this->pendingEvents);
    }

    /**
     * Resolve a stable string identifier for an aggregate.
     *
     * Uses the aggregate's `id()` method when available.
     * For aggregates without an ID (e.g., not yet initialized),
     * falls back to an instance-local identity map via WeakMap.
     *
     * @param  AggregateRoot  $aggregate  The aggregate to resolve an ID for.
     * @return string A stable string identifier for the aggregate.
     *
     * @internal
     */
    private function resolveId(AggregateRoot $aggregate): string
    {
        try {
            $aggregateId = $aggregate->id();

            if ($aggregateId !== '') {
                return $aggregateId;
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

    /**
     * Get all aggregates committed in the current transaction cycle.
     *
     * @return array<string, AggregateRoot> Committed aggregates keyed by identity.
     */
    #[\Override]
    public function getCommitted(): array
    {
        return $this->committed;
    }

    /**
     * Get all aggregates marked for deletion in the current transaction cycle.
     *
     * @return array<string, AggregateRoot> Deleted aggregates keyed by identity.
     */
    #[\Override]
    public function getDeleted(): array
    {
        return $this->deleted;
    }

    /**
     * Dispatch all pending events through the configured dispatcher.
     *
     * @internal
     * @return void
     */
    private function dispatchPendingEvents(): void
    {
        $dispatcher = $this->eventDispatcher;

        if ($dispatcher !== null) {
            foreach ($this->pendingEvents as $event) {
                $dispatcher($event);
            }
        }
    }

    /**
     * ISSUE-#7: Invoke the persistence callback with committed & deleted aggregates.
     *
     * Called at commit time (outermost scope) before event dispatch so that
     * aggregates are durably stored before any event consumers react.
     *
     * @internal
     * @return void
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
     *
     * @internal
     * @return void
     */
    private function restoreAggregateState(AggregateRoot $target, AggregateRoot $snapshot): void
    {
        // Walk the entire class hierarchy and copy non-readonly properties.
        // We collect all properties first to avoid duplicate work when a
        // child overrides a parent property declaration.
        $seen = [];
        $reflection = new \ReflectionClass($target);

        while ($reflection !== false) {
            foreach ($reflection->getProperties() as $property) {
                $name = $property->getName();

                if (isset($seen[$name])) {
                    continue;
                }

                if ($property->isReadOnly()) {
                    continue;
                }

                if ($property->isStatic()) {
                    continue;
                }

                // Skip uninitialized properties — getValue() throws on them
                // and there's nothing meaningful to restore anyway.
                if (! $property->isInitialized($snapshot)) {
                    $seen[$name] = true;
                    continue;
                }

                $seen[$name] = true;

                try {
                    $property->setValue($target, $property->getValue($snapshot));
                } catch (\Throwable) {
                    // Silently skip properties that cannot be restored
                    // (e.g., Closure properties, resources, or dynamically
                    // added internal state that doesn't survive clone).
                }
            }

            $reflection = $reflection->getParentClass();
        }
    }

    /**
     * Require an active transaction scope, returning its data array.
     *
     * @return array{active: bool, tracked: array<string, AggregateRoot>, deleted: array<string, AggregateRoot>}
     *
     * @throws RuntimeException When no unit of work is active.
     *
     * @internal
     */
    private function requireActiveScope(): array
    {
        if ($this->nestingDepth === 0) {
            throw new RuntimeException('No active unit of work');
        }

        $scope = $this->savepoints[$this->currentScope] ?? null;

        if ($scope === null || ! isset($scope['active']) || ! $scope['active']) {
            throw new RuntimeException('No active unit of work');
        }

        return $scope;
    }

    /**
     * Decrement nesting depth and scope counter after commit/rollback.
     *
     * @internal
     * @return void
     */
    private function exitScope(): void
    {
        $this->nestingDepth--;
        $this->currentScope--;
    }

    /**
     * Reset all transactional state after the outermost scope completes.
     *
     * Clears savepoints, snapshots, pending events, and scope counters.
     * Does NOT clear committed/deleted — those are accessible via
     * getCommitted()/getDeleted() until the next begin() or clear().
     *
     * @internal
     * @return void
     */
    private function resetTransactionState(): void
    {
        $this->pendingEvents = [];
        $this->aggregateEventOffsets = [];
        $this->savepoints = [];
        $this->snapshots = [];
        $this->scopeEventCounts = [];
        $this->currentScope = -1;
    }

    /**
     * Clear all state including committed data.
     *
     * Resets the unit of work to its initial state, discarding all
     * savepoints, snapshots, pending events, and committed/deleted aggregates.
     * Useful for testing and between test cases.
     * @return void
     */
    #[\Override]
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

    /**
     * Convert the unit of work's current state to an array.
     *
     * Provides a snapshot of the current transactional state for debugging,
     * logging, or inspection. Does not include aggregate objects themselves,
     * only their identity strings and counts.
     *
     * @return array{nesting_depth: int, pending_event_count: int, committed_count: int, deleted_count: int, is_active: bool}
     *
     * @since 1.66.0
     *
     * @example
     * ```php
     * $uow->begin();
     * $uow->track($order);
     * $state = $uow->toArray();
     * // ['nesting_depth' => 1, 'pending_event_count' => 0, 'committed_count' => 0, ...]
     * ```
     */
    public function toArray(): array
    {
        return [
            'nesting_depth' => $this->nestingDepth,
            'pending_event_count' => count($this->pendingEvents),
            'committed_count' => count($this->committed),
            'deleted_count' => count($this->deleted),
            'is_active' => $this->isActive(),
        ];
    }

    /**
     * Convert the unit of work state to a JSON string.
     *
     * @param  int  $options  JSON encoding options bitmask (default: JSON_UNESCAPED_UNICODE).
     * @return string The JSON-encoded UoW state representation.
     *
     * @since 1.66.0
     */
    public function toJson(int $options = JSON_UNESCAPED_UNICODE): string
    {
        return json_encode($this->toArray(), $options | JSON_THROW_ON_ERROR);
    }
}
