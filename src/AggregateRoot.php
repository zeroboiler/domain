<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain;

use ZeroBoiler\Domain\Contracts\AggregateRoot as AggregateRootContract;
use ZeroBoiler\Domain\Contracts\Entity as EntityContract;
use ZeroBoiler\Domain\Snapshots\Snapshot;
use ZeroBoiler\Domain\Snapshots\SnapshotStore;
use ZeroBoiler\Events\Domain\DomainEvent;

/**
 * Base class for aggregate roots in a DDD architecture.
 *
 * Extends Entity to ensure consistent initialization and inheritance.
 * The AggregateRootId is stored as a typed internal property while
 * also being passed to the parent Entity constructor for the generic $id.
 *
 * @method bool shouldSnapshot() Check if a snapshot should be taken (from HasSnapshots trait)
 * @method void createSnapshot(SnapshotStore $store) Create and store a snapshot (from HasSnapshots trait)
 * @method void restoreFromSnapshot(Snapshot $snapshot) Restore from a snapshot (from HasSnapshots trait)
 *
 * @extends Entity<AggregateRootId>
 *
 * @since 1.0.0
 */
abstract class AggregateRoot extends Entity implements AggregateRootContract
{
    protected int $version = 0;

    protected function __construct(
        private readonly AggregateRootId $aggregateId,
    ) {
        // Pass the AggregateRootId to parent Entity constructor.
        // Entity stores it as mixed $id; we keep a typed alias for internal use.
        parent::__construct($aggregateId);
    }

    /**
     * Record and apply a new domain event to this aggregate.
     *
     * In normal (non-replay) mode, this method:
     * 1. Records the event via `recordThat()` for later dispatch
     * 2. Dispatches to the specific `apply*` handler if present
     * 3. Increments the aggregate version
     *
     * Handler method resolution supports dot-separated event types:
     *   'order.item_added' → applyOrderItemAdded()
     *   'order-item-added' → applyOrderItemAdded()
     *
     * For replay mode (event sourcing), use `EventSourced::applyEvent()` instead,
     * which skips recording and only invokes handlers.
     *
     * @param  DomainEvent  $event  The domain event to apply.
     * @return void
     *
     * @see EventSourced::applyEvent() For replay-mode event application.
     */
    protected function apply(DomainEvent $event): void
    {
        $this->recordThat($event);

        // Dispatch to the specific apply* handler if present.
        // This ensures state mutation handlers (e.g., applyOrderPlaced) are invoked
        // when applying new events, not just when replaying from history (#664).
        $parts = explode('.', $event->eventType);
        $method = 'apply' . implode('', array_map(ucfirst(...), $parts));

        if (method_exists($this, $method)) {
            $this->$method($event);
        }

        $this->version++;
    }

    /**
     * Get the current version of this aggregate.
     *
     * Used by repositories for optimistic locking and for tracking
     * the number of events applied to this aggregate.
     *
     * @return int The current aggregate version (0 = newly created).
     */
    #[\Override]
    public function version(): int
    {
        return $this->version;
    }

    /**
     * Alias for version() — backward compatibility.
     *
     * @deprecated Use {@see version()} instead. This alias will be removed in v3.0.
     */
    #[\Deprecated(message: 'Use version() instead.', since: '1.5.0')]
    public function getVersion(): int
    {
        return $this->version;
    }

    /**
     * Set the version (used by repositories when loading from storage).
     *
     * Not part of the AggregateRoot contract — intended for infrastructure
     * use only (repository hydration, event replay, snapshot restoration).
     *
     * @return void
     *
     * @internal Infrastructure use only — not part of the AggregateRoot contract.
     */
    public function setVersion(int $version): void
    {
        $this->version = $version;
    }

    /**
     * Increment the version (called after a successful save).
     * @return void
     */
    #[\Override]
    public function incrementVersion(): void
    {
        $this->version++;
    }

    #[\Override]
    public function pullDomainEvents(): DomainEventCollection
    {
        $events = $this->domainEvents;
        $this->domainEvents = [];

        return new DomainEventCollection(array_values($events));
    }

    #[\Override]
    public function clearDomainEvents(): void
    {
        $this->domainEvents = [];
    }

    /**
     * Peek at recorded domain events without removing them.
     *
     * Returns a typed collection for inspection, logging, or debugging
     * without affecting the event state. The events remain available
     * for subsequent `pullDomainEvents()` calls.
     *
     * @return DomainEventCollection A copy of all recorded events.
     *
     * @example
     * ```php
     * // Inspect events without consuming them
     * $peeked = $order->peekDomainEvents();
     * foreach ($peeked as $event) {
     *     logger()->debug('Pending event', ['type' => $event->eventType]);
     * }
     *
     * // Events are still available for pulling
     * $pulled = $order->pullDomainEvents(); // Same events, now consumed
     * ```
     */
    public function peekDomainEvents(): DomainEventCollection
    {
        return new DomainEventCollection(array_values($this->domainEvents));
    }

    /**
     * Return the aggregate's domain identity as a string.
     *
     * Overrides Entity::id() which returns mixed. AggregateRoot narrows
     * the return type to string for consistent identity representation.
     *
     * @return string The aggregate's UUID identity as a canonical string.
     */
    #[\Override]
    public function id(): string
    {
        return $this->aggregateId->toString();
    }

    /**
     * Get the typed AggregateRootId instance.
     *
     * Use this when you need the actual AggregateRootId object rather
     * than its string representation.
     *
     * @return AggregateRootId The aggregate's typed identity.
     */
    public function aggregateId(): AggregateRootId
    {
        return $this->aggregateId;
    }

    /**
     * Check identity equality with another entity.
     *
     * Uses string comparison consistently across the hierarchy, matching
     * AggregateRootId's toString() output for reliable identity checks.
     */
    #[\Override]
    public function equals(EntityContract $other): bool
    {
        return static::class === $other::class
            && $this->aggregateId->toString() === $other->id();
    }

    /**
     * Convert the aggregate root to an array representation.
     *
     * Provides a base array with identity and version information.
     * Subclasses should override to add domain-specific fields.
     * Useful for DomainTransformer integration and response serialization.
     *
     * @return array{id: string, version: int, type: string}
     *
     * @example
     * ```php
     * $order->toArray();
     * // ['id' => '550e8400-...', 'version' => 3, 'type' => 'Order']
     *
     * // Subclass override:
     * class Order extends AggregateRoot
     * {
     *     public function toArray(): array
     *     {
     *         return [
     *             ...parent::toArray(),
     *             'status' => $this->status,
     *             'total' => $this->total,
     *         ];
     *     }
     * }
     * ```
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id(),
            'version' => $this->version,
            'type' => class_basename(static::class),
        ];
    }

    /**
     * Reconstitute an aggregate root from a snapshot and optional post-snapshot events.
     *
     * This is a convenience factory that combines snapshot restoration with
     * event replay, commonly used in event-sourced repositories.
     *
     * The aggregate must use the {@see Concerns\HasSnapshots} trait for
     * `restoreFromSnapshot()` to work.
     *
     * @param  Snapshots\Snapshot  $snapshot  The snapshot to restore from.
     * @param  array<int, Events\Domain\DomainEvent>  $postSnapshotEvents  Events after the snapshot version.
     * @return static The reconstituted aggregate root.
     *
     * @throws \RuntimeException If the aggregate class does not extend AggregateRoot or uses HasSnapshots.
     *
     * @example
     * ```php
     * use ZeroBoiler\Domain\AggregateRoot;
     * use ZeroBoiler\Domain\Concerns\HasSnapshots;
     *
     * class Order extends AggregateRoot
     * {
     *     use HasSnapshots;
     *     use Concerns\EventSourced;
     *
     *     protected function applyOrderPlaced(DomainEvent $event): void { ... }
     * }
     *
     * $order = Order::reconstituteFromSnapshot($snapshot, $postSnapshotEvents);
     * ```
     */
    public static function reconstituteFromSnapshot(
        Snapshots\Snapshot $snapshot,
        array $postSnapshotEvents = [],
    ): static {
        $reflection = new \ReflectionClass(static::class);

        if (! $reflection->isSubclassOf(self::class)) {
            throw new \RuntimeException(sprintf(
                'Class %s must extend %s to use reconstituteFromSnapshot.',
                static::class,
                self::class,
            ));
        }

        /** @var static $instance */
        $instance = $reflection->newInstanceWithoutConstructor();

        // Restore the readonly AggregateRootId via reflection
        $aggregateId = AggregateRootId::fromString($snapshot->aggregateId);
        self::setReadOnlyProperty($instance, 'aggregateId', $aggregateId);
        self::setReadOnlyProperty($instance, 'id', $aggregateId);

        // Restore from snapshot state if the aggregate uses HasSnapshots
        if (method_exists($instance, 'restoreFromSnapshot')) {
            $instance->restoreFromSnapshot($snapshot);
        } else {
            throw new \RuntimeException(sprintf(
                'Aggregate %s must use the HasSnapshots trait to use reconstituteFromSnapshot.',
                static::class,
            ));
        }

        // Replay post-snapshot events
        foreach ($postSnapshotEvents as $event) {
            if (method_exists($instance, 'applyEvent')) {
                $instance->applyEvent($event, isReplay: true);
            }
        }

        // Clear any events accumulated during reconstitution
        if (method_exists($instance, 'clearDomainEvents')) {
            $instance->clearDomainEvents();
        }

        return $instance;
    }

    /**
     * Set a readonly property via reflection (used during reconstitution).
     *
     * Walks up the class hierarchy to find the property declaration,
     * handling initialized readonly properties by unsetting them first.
     *
     * @param  object  $instance  The object whose property to set.
     * @param  string  $name  The property name.
     * @param  mixed  $value  The value to assign.
     *
     * @internal Used only by {@see reconstituteFromSnapshot()}.
     * @return void
     */
    private static function setReadOnlyProperty(object $instance, string $name, mixed $value): void
    {
        $class = new \ReflectionClass($instance);

        while ($class !== false) {
            if ($class->hasProperty($name)) {
                $property = $class->getProperty($name);

                // Unset readonly property first if it's initialized
                if ($property->isReadOnly() && $property->isInitialized($instance)) {
                    unset($instance->{$name});
                }

                $property->setValue($instance, $value);

                return;
            }

            $class = $class->getParentClass();
        }
    }
}
