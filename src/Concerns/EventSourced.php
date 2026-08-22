<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Concerns;

use RuntimeException;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Events\Domain\DomainEvent;

/**
 * Provides event sourcing capabilities to aggregate roots.
 *
 * When used on an AggregateRoot, enables:
 * - `fromHistory(DomainEvent ...$events)` — reconstitute from event stream
 * - `applyEvent(DomainEvent $event, bool $isReplay)` — apply/replay single event
 *
 * The trait resolves handler methods using dot/hyphen/underscore convention:
 *   'aggregate.created' → applyAggregateCreated()
 *   'order-shipped'     → applyOrderShipped()
 *
 * @since 1.0.0
 */
trait EventSourced
{
    /**
     * Reconstitute an aggregate from its event history.
     *
     * The first event must contain an 'id' or 'aggregate_id' key in its
     * payload. All subsequent events are replayed in order, incrementing
     * the aggregate version for each handled event.
     *
     * @param  DomainEvent  ...$events  The event history to replay (oldest first).
     * @return static The reconstituted aggregate.
     *
     * @throws RuntimeException If events are empty or first event lacks an ID.
     *
     * @example
     * ```php
     * $order = Order::fromHistory($eventStream->getEventsFor($orderId));
     * echo $order->version(); // 42 (events applied)
     * ```
     */
    public static function fromHistory(DomainEvent ...$events): static
    {
        if ($events === []) {
            throw new RuntimeException('Cannot reconstitute from empty event history');
        }

        $firstEvent = $events[0];
        $payload = $firstEvent->payload;
        $idString = $payload['id'] ?? $payload['aggregate_id'] ?? null;

        if ($idString === null) {
            throw new RuntimeException(
                'Cannot reconstitute: first event must contain an "id" or "aggregate_id" key in its payload'
            );
        }

        $aggregateId = AggregateRootId::fromString((string) $idString);

        $aggregate = new \ReflectionClass(static::class)->newInstanceWithoutConstructor();

        self::setInheritedProperty($aggregate, 'aggregateId', $aggregateId);
        self::setInheritedProperty($aggregate, 'id', $aggregateId);

        // Replay all events in lenient mode
        foreach ($events as $event) {
            $aggregate->applyEvent($event, true);
        }

        $aggregate->clearDomainEvents();

        return $aggregate;
    }

    /**
     * Apply a domain event to this aggregate.
     *
     * In replay mode ($isReplay = true), only apply* handlers are invoked
     * and version increments for every event (matching the original event count).
     * In normal mode ($isReplay = false), the event is recorded and version
     * is incremented (delegates to AggregateRoot::apply()).
     *
     * Handler method resolution uses dot/hyphen/underscore convention:
     *   'aggregate.created' → applyAggregateCreated()
     *   'order-shipped'     → applyOrderShipped()
     *   'order_item.added'  → applyOrderItemAdded()
     *
     * @param  DomainEvent  $event  The event to apply.
     * @param  bool  $isReplay  When true, event is replayed without recording.
     *
     * @see AggregateRoot::apply() For normal (non-replay) event application.
     * @return void
     */
    public function applyEvent(DomainEvent $event, bool $isReplay = false): void
    {
        if ($isReplay) {
            // Resolve handler method name from event type.
            // preg_split may return false on error, but the regex is a
            // constant valid pattern — defensively cast to array.
            $parts = preg_split('/[._-]/', $event->eventType) ?: [];
            $method = 'apply' . implode('', array_map(ucfirst(...), $parts));

            // Replay mode: invoke handler only (if exists).
            // Version increments only for handled events — events without
            // apply* handlers are informational and do not affect version.
            if (method_exists($this, $method)) {
                $this->$method($event);

                if (method_exists($this, 'incrementVersion')) {
                    $this->incrementVersion();
                }
            }

            return;
        }

        // Normal mode: delegate to AggregateRoot::apply()
        if (method_exists($this, 'apply')) {
            // @phpstan-ignore-next-line
            $this->apply($event);
        }
    }

    /**
     * Set a property that may be declared on a parent class (e.g. readonly private).
     *
     * Walks the class hierarchy from the instance's class upward to find
     * the property declaration, then sets its value via reflection.
     * Handles initialized readonly properties by unsetting them first.
     *
     * @param  object  $instance  The object whose property to set.
     * @param  string  $name  The property name.
     * @param  mixed  $value  The value to assign.
     *
     * @internal Used only by {@see fromHistory()}.
     * @return void
     */
    private static function setInheritedProperty(object $instance, string $name, mixed $value): void
    {
        $target = new \ReflectionClass($instance);

        while ($target !== false && $target instanceof \ReflectionClass) {
            if ($target->hasProperty($name)) {
                $property = $target->getProperty($name);

                // Unset readonly property first if it's initialized
                // (PHP 8.5 allows re-initialization after unset via Reflection)
                if ($property->isReadOnly() && $property->isInitialized($instance)) {
                    unset($instance->{$name});
                }

                $property->setValue($instance, $value);

                return;
            }

            $target = $target->getParentClass();
        }
    }
}
