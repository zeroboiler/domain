<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain;

use ZeroBoiler\Domain\Contracts\AggregateRoot as AggregateRootContract;
use ZeroBoiler\Domain\Contracts\Entity as EntityContract;
use ZeroBoiler\Events\Domain\Collections\DomainEventCollection;
use ZeroBoiler\Events\Domain\DomainEvent;

/**
 * Base class for aggregate roots in a DDD architecture.
 *
 * Extends Entity to ensure consistent initialization and inheritance.
 * The AggregateRootId is stored as a typed internal property while
 * also being passed to the parent Entity constructor for the generic $id.
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
     * Used by repositories for optimistic locking.
     */
    #[\Override]
    public function version(): int
    {
        return $this->version;
    }

    /**
     * Alias for version() — backward compatibility.
     */
    public function getVersion(): int
    {
        return $this->version;
    }

    /**
     * Set the version (used by repositories when loading from storage).
     */
    public function setVersion(int $version): void
    {
        $this->version = $version;
    }

    /**
     * Increment the version (called after a successful save).
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

        return new DomainEventCollection($events);
    }

    #[\Override]
    public function clearDomainEvents(): void
    {
        $this->domainEvents = [];
    }

    /**
     * Return the aggregate's domain identity as a string.
     *
     * Overrides Entity::id() which returns mixed. AggregateRoot narrows
     * the return type to string for consistent identity representation.
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
            && $this->aggregateId->toString() === (string) $other->id();
    }
}
