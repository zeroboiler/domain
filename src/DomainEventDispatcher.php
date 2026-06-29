<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain;

use Closure;
use Illuminate\Contracts\Events\Dispatcher as LaravelDispatcher;
use Illuminate\Support\Collection;

final class DomainEventDispatcher
{
    /** @var array<string, array<Closure>> */
    private array $listeners = [];

    /** @var Collection<int, DomainEvent> */
    private Collection $deferredEvents;

    /**
     * Optional forwarder callback for external event systems.
     *
     * When set, every dispatched domain event is forwarded to this
     * callback. This enables cross-package integration with the
     * Events package's EventManager without a hard dependency.
     *
     * @var (?Closure(string, array<string, mixed>): void)
     */
    private ?Closure $eventForwarder = null;

    public function __construct(
        private readonly ?LaravelDispatcher $laravelDispatcher = null,
    ) {
        $this->deferredEvents = new Collection;
    }

    /**
     * Set an external event forwarder.
     *
     * Called once by the service provider when the Events package
     * is available. The callback receives the event type and payload.
     *
     * @param  ?Closure(string, array<string, mixed>): void  $forwarder
     */
    public function setEventForwarder(?Closure $forwarder): void
    {
        $this->eventForwarder = $forwarder;
    }

    public function dispatch(DomainEvent $event): void
    {
        $eventType = $event->eventType;

        foreach ($this->listeners[$eventType] ?? [] as $listener) {
            $listener($event);
        }

        // Also dispatch through Laravel's event system so that
        // any framework-level listeners or observers are notified.
        $this->laravelDispatcher?->dispatch($event, $event->payload);

        // Forward to external event systems (e.g. Events package EventManager)
        // for DB-driven triggers, without a hard coupling.
        if ($this->eventForwarder instanceof \Closure) {
            ($this->eventForwarder)($eventType, $event->payload);
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
