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
 * @implements \JsonSerializable
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
     *
     * @throws \InvalidArgumentException If $events is not a sequential list or contains non-DomainEvent items.
     */
    public function __construct(
        private readonly array $events = [],
    ) {
        if (! array_is_list($events)) {
            throw new \InvalidArgumentException(
                'DomainEventCollection expects a sequential list (array_is_list) of DomainEvent objects.',
            );
        }

        foreach ($events as $i => $event) {
            if (! $event instanceof DomainEvent) {
                throw new \InvalidArgumentException(sprintf(
                    'DomainEventCollection item at index %d must be a DomainEvent, got %s.',
                    $i,
                    get_debug_type($event),
                ));
            }
        }
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
     *
     * @return bool True when the collection contains zero events.
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
    #[\Override]
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

    /**
     * Apply a callback to each event and return a new collection with the results.
     *
     * @template T
     *
     * @param  callable(DomainEvent, int): T  $callback
     * @return list<T>
     */
    public function map(callable $callback): array
    {
        $result = [];
        $index = 0;

        foreach ($this->events as $event) {
            $result[] = $callback($event, $index++);
        }

        return $result;
    }

    /**
     * Filter events by a predicate, returning a new collection.
     *
     * @param  callable(DomainEvent, int): bool  $predicate
     */
    public function filter(callable $predicate): self
    {
        $filtered = [];
        $index = 0;

        foreach ($this->events as $event) {
            if ($predicate($event, $index++)) {
                $filtered[] = $event;
            }
        }

        return new self($filtered);
    }

    /**
     * Get the first event that matches a predicate, or null.
     *
     * @param  (callable(DomainEvent): bool)|null  $predicate  If null, returns the first event.
     * @return DomainEvent|null The first matching event, or null.
     */
    public function first(?callable $predicate = null): ?DomainEvent
    {
        foreach ($this->events as $event) {
            if ($predicate === null || $predicate($event)) {
                return $event;
            }
        }

        return null;
    }

    /**
     * Get the last event in the collection.
     *
     * @return DomainEvent|null The last event, or null if empty.
     */
    public function last(): ?DomainEvent
    {
        return $this->events !== [] ? $this->events[array_key_last($this->events)] : null;
    }

    /**
     * Merge another event collection into this one, returning a new collection.
     *
     * @param  self|list<DomainEvent>  $other  Events to append.
     * @return self New collection with all events from both sources.
     */
    public function merge(self|array $other): self
    {
        $events = $other instanceof self ? $other->all() : $other;

        return new self([...$this->events, ...$events]);
    }

    /**
     * Get an event at a specific index, or null if out of bounds.
     *
     * @param  int  $index  Zero-based index.
     * @return DomainEvent|null The event at the index, or null.
     */
    public function get(int $index): ?DomainEvent
    {
        return $this->events[$index] ?? null;
    }

    /**
     * Convert the collection to a plain array representation.
     *
     * Alias for {@see jsonSerialize()} — provides explicit `toArray()` method
     * for API consistency across the ZeroBoiler ecosystem.
     *
     * @return list<array<string, mixed>> Each event serialized to array.
     *
     * @example
     * ```php
     * $collection = new DomainEventCollection([$event1, $event2]);
     * $collection->toArray();  // [[...], [...]]
     * $collection->jsonSerialize(); // Same result
     * ```
     */
    public function toArray(): array
    {
        return $this->jsonSerialize();
    }
}
