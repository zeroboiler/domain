<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain;

use Closure;
use Illuminate\Contracts\Events\Dispatcher as LaravelDispatcher;
use Illuminate\Support\Collection;
use Throwable;
use ZeroBoiler\Domain\Exceptions\ListenerException;

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

    /**
     * Dispatch an event to all subscribed listeners.
     *
     * Iterates every listener in a try/catch so one failure does not
     * silently skip the rest. After all listeners run, any collected
     * failures are aggregated into a single ListenerException.
     *
     * @throws ListenerException when one or more listeners fail
     */
    public function dispatch(DomainEvent $event): void
    {
        $eventType = $event->eventType;

        /** @var array<int, array{listener: callable, throwable: Throwable}> $failures */
        $failures = [];

        foreach ($this->listeners[$eventType] ?? [] as $listener) {
            try {
                $listener($event);
            } catch (Throwable $e) {
                $failures[] = ['listener' => $listener, 'throwable' => $e];
            }
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

        if ($failures !== []) {
            throw ListenerException::withFailures($failures);
        }
    }

    /**
     * Dispatch an event without throwing on listener failures.
     *
     * Behaves exactly like {@see dispatch()} but swallows any
     * ListenerException, allowing the caller to continue even when
     * listeners are unreliable.
     */
    public function dispatchQuietly(DomainEvent $event): void
    {
        try {
            $this->dispatch($event);
        } catch (ListenerException) {
            // Intentionally silenced — callers opt in to this behaviour.
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
}
