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
     */
    protected function recordThat(DomainEvent $event): void
    {
        $this->domainEvents[] = $event;
    }

    /**
     * Pull (destructive) all recorded domain events.
     *
     * @return array<int, DomainEvent>
     */
    public function releaseEvents(): array
    {
        $events = $this->domainEvents;
        $this->domainEvents = [];

        return $events;
    }

    /**
     * Clear all recorded domain events without dispatching.
     */
    public function clearEvents(): void
    {
        $this->domainEvents = [];
    }

    /**
     * Check if there are any uncommitted domain events.
     */
    public function hasUncommittedEvents(): bool
    {
        return $this->domainEvents !== [];
    }
}
