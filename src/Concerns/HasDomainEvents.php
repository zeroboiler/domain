<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Concerns;

use ZeroBoiler\Events\Domain\DomainEvent;

/**
 * Provides domain event recording capabilities to entities and aggregate roots.
 *
 * Events are stored in an internal array and can be pulled (destructive)
 * or peeked (non-destructive) depending on the use case.
 *
 * @see HasSnapshots For snapshot support on event-sourced aggregates.
 * @see EventSourced For reconstituting aggregates from event history.
 *
 * @since 1.0.0
 *
 * @example
 * ```php
 * class Order extends AggregateRoot
 * {
 *     use HasDomainEvents;
 *
 *     public function addItem(string $productId, int $qty): void
 *     {
 *         $this->recordThat(DomainEvent::occur('order.item_added', [
 *             'product_id' => $productId,
 *             'qty' => $qty,
 *         ]));
 *     }
 *
 *     // Check for uncommitted events
 *     if ($this->hasUncommittedEvents()) {
 *         $events = $this->releaseEvents(); // Destructive pull
 *         // Dispatch events...
 *     }
 * }
 * ```
 */
trait HasDomainEvents
{
    /** @var array<int, DomainEvent> */
    protected array $domainEvents = [];

    /**
     * Record a domain event for later dispatch.
     *
     * Appends the event to the internal buffer. Events are not dispatched
     * immediately — they are queued for later dispatch via the Unit of Work
     * or manual `releaseEvents()` call.
     *
     * @param  DomainEvent  $event  The domain event to record.
     */
    protected function recordThat(DomainEvent $event): void
    {
        $this->domainEvents[] = $event;
    }

    /**
     * Pull (destructive) all recorded domain events.
     *
     * Removes all events from the entity's internal buffer and returns them.
     * After this call, `hasUncommittedEvents()` returns false.
     *
     * Note: AggregateRoot overrides this via `pullDomainEvents()` which
     * returns a typed `DomainEventCollection` instead of a plain array.
     *
     * @return array<int, DomainEvent> All recorded events, in order of recording.
     */
    public function releaseEvents(): array
    {
        $events = $this->domainEvents;
        $this->domainEvents = [];

        return $events;
    }

    /**
     * Clear all recorded domain events without dispatching.
     *
     * Discards all uncommitted events from the entity's buffer.
     * Use this when events should be silently dropped (e.g., after
     * a clone or reconstitution).
     */
    public function clearEvents(): void
    {
        $this->domainEvents = [];
    }

    /**
     * Check if there are any uncommitted domain events.
     *
     * Returns true when the entity has recorded events that have not yet
     * been pulled via `releaseEvents()` or cleared via `clearEvents()`.
     *
     * @return bool True if there are pending events, false otherwise.
     *
     * @see Contracts\Entity::hasUncommittedEvents()
     */
    #[\Override]
    public function hasUncommittedEvents(): bool
    {
        return $this->domainEvents !== [];
    }

    /**
     * Peek at recorded domain events without removing them.
     *
     * Returns a copy of the internal event buffer for inspection,
     * logging, or debugging without affecting the event state.
     * The events remain available for subsequent `releaseEvents()` calls.
     *
     * Note: AggregateRoot overrides this via `peekDomainEvents()` which
     * returns a typed `DomainEventCollection` instead of a plain array.
     *
     * @return array<int, DomainEvent> A copy of all recorded events, in order of recording.
     *
     * @example
     * ```php
     * // Inspect events without consuming them
     * foreach ($aggregate->peekEvents() as $event) {
     *     logger()->debug('Pending event', ['type' => $event->eventType]);
     * }
     *
     * // Events are still available for pulling
     * $events = $aggregate->releaseEvents(); // Still returns all events
     * ```
     */
    public function peekEvents(): array
    {
        return $this->domainEvents;
    }
}
