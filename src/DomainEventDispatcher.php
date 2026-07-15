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

    /**
     * Track whether listeners have been registered.
     * Used for test assertions and Octane request isolation checks.
     */
    private int $totalListenerCount = 0;

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
        // Note: Laravel's dispatcher ignores the payload argument when the
        // first argument is an event object (not a string), so we pass only
        // the event object to avoid confusion and ensure correct behavior.
        $this->laravelDispatcher?->dispatch($event);

        // Forward to external event systems (e.g. Events package EventManager)
        // for DB-driven triggers, without a hard coupling.
        if ($this->eventForwarder instanceof Closure) {
            ($this->eventForwarder)($eventType, $event->payload);
        }
    }

    public function subscribe(string $eventType, Closure $listener): void
    {
        $this->listeners[$eventType][] = $listener;
        $this->totalListenerCount++;
    }

    public function defer(DomainEvent $event): void
    {
        $this->deferredEvents->push($event);
    }

    public function releaseDeferred(): void
    {
        try {
            foreach ($this->deferredEvents as $event) {
                $this->dispatch($event);
            }
        } finally {
            // Always clear the deferred collection, even if a listener threw.
            // This prevents memory leaks and re-dispatching stale events.
            $this->deferredEvents = new Collection;
        }
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

    /**
     * Remove all registered listeners.
     *
     * Essential for long-running processes (Octane, Swoole) where
     * listeners would otherwise accumulate across requests.
     *
     * @param  ?string  $eventType  Only clear listeners for this event type; null clears all.
     */
    public function clearListeners(?string $eventType = null): void
    {
        if ($eventType === null) {
            $this->totalListenerCount = 0;
            $this->listeners = [];

            return;
        }

        $this->totalListenerCount -= count($this->listeners[$eventType] ?? []);
        unset($this->listeners[$eventType]);
    }

    /**
     * Full reset: clears listeners, deferred events, and forwarder.
     *
     * Intended for test teardown to ensure complete isolation
     * between test cases.
     */
    public function flush(): void
    {
        $this->clearListeners();
        $this->deferredEvents = new Collection;
        $this->eventForwarder = null;
    }

    /**
     * Check if any listeners are registered for an event type.
     */
    public function hasListeners(string $eventType): bool
    {
        return isset($this->listeners[$eventType]) && $this->listeners[$eventType] !== [];
    }

    /**
     * Get the total number of registered listeners across all event types.
     */
    public function getListenerCount(): int
    {
        return $this->totalListenerCount;
    }
}
