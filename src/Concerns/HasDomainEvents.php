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
