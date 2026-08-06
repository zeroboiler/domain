<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use JsonSerializable;
use Traversable;
use ZeroBoiler\Events\Domain\DomainEvent;

/**
 * Type-safe collection for domain events.
 *
 * Provides iteration, counting, array access, and JSON serialization
 * for DomainEvent objects.
 *
 * @implements IteratorAggregate<int, DomainEvent>
 * @implements JsonSerializable
 *
 * @example
 * ```php
 * $collection = new DomainEventCollection([$event1, $event2]);
 * $collection->count();      // 2
 * $collection->isEmpty();     // false
 * $collection->all();         // [DomainEvent, DomainEvent]
 * json_encode($collection);  // [[...], [...]]
 * foreach ($collection as $event) { ... }
 * ```
 */
final readonly class DomainEventCollection implements Countable, IteratorAggregate, JsonSerializable
{
    /**
     * @param  list<DomainEvent>  $events
     */
    public function __construct(
        private readonly array $events = [],
    ) {
        assert(
            array_is_list($events),
            'DomainEventCollection expects a list (sequential array) of DomainEvent objects.',
        );
    }

    /**
     * @return ArrayIterator<int, DomainEvent>
     */
    #[\Override]
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->events);
    }

    #[\Override]
    public function count(): int
    {
        return count($this->events);
    }

    /**
     * Get all events as a plain array.
     *
     * @return array<int, DomainEvent>
     */
    public function all(): array
    {
        return $this->events;
    }

    /**
     * Check if the collection is empty.
     */
    public function isEmpty(): bool
    {
        return $this->events === [];
    }

    /**
     * Serialize the event collection to JSON.
     *
     * Converts each event to an array. Uses toArray() if available,
     * otherwise falls back to casting to array for maximum compatibility.
     *
     * @return list<array<string, mixed>>
     */
    public function jsonSerialize(): array
    {
        return array_map(
            static function (DomainEvent $event): array {
                if (method_exists($event, 'toArray')) {
                    return $event->toArray();
                }

                if (method_exists($event, 'jsonSerialize')) {
                    return $event->jsonSerialize();
                }

                return (array) $event;
            },
            $this->events,
        );
    }
}
