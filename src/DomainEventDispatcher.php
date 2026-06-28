<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain;

use Closure;
use Illuminate\Support\Collection;

final class DomainEventDispatcher
{
    /** @var array<string, array<Closure>> */
    private array $listeners = [];

    /** @var Collection<int, DomainEvent> */
    private Collection $deferredEvents;

    public function __construct()
    {
        $this->deferredEvents = new Collection;
    }

    public function dispatch(DomainEvent $event): void
    {
        $eventType = $event->eventType;

        foreach ($this->listeners[$eventType] ?? [] as $listener) {
            $listener($event);
        }
    }

    public function subscribe(string $eventType, Closure $listener): void
    {
        $this->listeners[$eventType][] = $listener;
    }

    public function defer(DomainEvent $event): void
    {
        $this->deferredEvents->push($event);
    }

    public function releaseDeferred(): void
    {
        foreach ($this->deferredEvents as $event) {
            $this->dispatch($event);
        }

        $this->deferredEvents = new Collection;
    }

    public function clearDeferred(): void
    {
        $this->deferredEvents = new Collection;
    }

    public function hasDeferredEvents(): bool
    {
        return $this->deferredEvents->isNotEmpty();
    }

    public function getDeferredEventsCount(): int
    {
        return $this->deferredEvents->count();
    }
}
