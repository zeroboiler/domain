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
 * for DomainEvent objects. Supports full round-trip serialization
 * via toArray()/fromArray() for caching, queue jobs, and event replay.
 *
 * @see \IteratorAggregate<int, DomainEvent> Provides `foreach` iteration over events.
 * @see \JsonSerializable Provides `json_encode()` serialization.
 *
 * @since 1.0.0
 *
 * @example
 * ```php
 * $collection = new DomainEventCollection([$event1, $event2]);
 * $collection->count();      // 2
 * $collection->isEmpty();     // false
 * $collection->all();         // [DomainEvent, DomainEvent]
 * json_encode($collection);  // [[...], [...]]
 * foreach ($collection as $event) { ... }
 *
 * // Round-trip serialization
 * $serialized = $collection->toArray();
 * $restored = DomainEventCollection::fromArray($serialized);
 * $restored->count(); // 2
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
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->events);
    }

    /**
     * Get the number of events in the collection.
     *
     * @return int The event count.
     */
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
     * Iterate over each event with a callback.
     *
     * Unlike {@see map()}, this method does not return a new collection
     * or collect results — it's used for side effects (logging, dispatching).
     *
     * @param  callable(DomainEvent, int): void  $callback  Called for each event with ($event, $index).
     * @return self Returns the same collection for fluent chaining.
     *
     * @since 1.58.0
     *
     * @example
     * ```php
     * $collection->each(function (DomainEvent $event, int $index): void {
     *     logger()->debug("Event {$index}: {$event->eventType}");
     * });
     * ```
     */
    public function each(callable $callback): self
    {
        $index = 0;
        foreach ($this->events as $event) {
            $callback($event, $index++);
        }

        return $this;
    }

    /**
     * Reduce the collection to a single value using a callback.
     *
     * Iteratively applies the callback to carry the accumulated result
     * across all events, returning the final value.
     *
     * @template TCarry
     *
     * @param  callable(TCarry, DomainEvent, int): TCarry  $callback  Reduction function ($carry, $event, $index).
     * @param  TCarry  $initial  Initial carry value.
     * @return TCarry The final accumulated value.
     *
     * @since 1.58.0
     *
     * @example
     * ```php
     * // Sum event payload amounts
     * $total = $collection->reduce(
     *     fn (float $sum, DomainEvent $event): float => $sum + ($event->payload['amount'] ?? 0),
     *     0.0,
     * );
     * // Group events by type
     * $grouped = $collection->reduce(
     *     fn (array $groups, DomainEvent $event): array => [
     *         ...$groups,
     *         $event->eventType => [...($groups[$event->eventType] ?? []), $event],
     *     ],
     *     [],
     * );
     * ```
     */
    public function reduce(callable $callback, mixed $initial = null): mixed
    {
        $carry = $initial;
        $index = 0;

        foreach ($this->events as $event) {
            $carry = $callback($carry, $event, $index++);
        }

        return $carry;
    }

    /**
     * Check if any event satisfies the given predicate.
     *
     * Returns `true` on the first matching event (short-circuits).
     * Equivalent to "exists" in other collection libraries.
     *
     * @param  callable(DomainEvent): bool  $predicate
     * @return bool True if at least one event matches.
     *
     * @since 1.58.0
     *
     * @example
     * ```php
     * $hasPayment = $collection->some(fn (DomainEvent $e): bool => $e->eventType === 'order.paid');
     * // true if any payment event exists
     * ```
     */
    public function some(callable $predicate): bool
    {
        foreach ($this->events as $event) {
            if ($predicate($event)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if no events satisfy the given predicate.
     *
     * Returns `true` only if all events fail the predicate (inverse of {@see some()}).
     *
     * @param  callable(DomainEvent): bool  $predicate
     * @return bool True if no events match the predicate.
     *
     * @since 1.58.0
     *
     * @example
     * ```php
     * $noCancellations = $collection->none(fn (DomainEvent $e): bool => $e->eventType === 'order.cancelled');
     * // true if there are no cancellation events
     * ```
     */
    public function none(callable $predicate): bool
    {
        return ! $this->some($predicate);
    }

    /**
     * Get the first event matching a predicate, or null if none found.
     *
     * Unlike {@see first()}, this always requires a predicate and is named
     * for clarity in functional-style pipelines.
     *
     * @param  callable(DomainEvent): bool  $predicate
     * @return DomainEvent|null The first matching event, or null.
     *
     * @since 1.58.0
     *
     * @example
     * ```php
     * $paymentEvent = $collection->find(fn (DomainEvent $e): bool => $e->eventType === 'order.paid');
     * ```
     */
    public function find(callable $predicate): ?DomainEvent
    {
        foreach ($this->events as $event) {
            if ($predicate($event)) {
                return $event;
            }
        }

        return null;
    }

    /**
     * Check if the collection contains a specific event type.
     *
     * Shorthand for `some(fn ($e) => $e->eventType === $type)`.
     *
     * @param  string  $eventType  The event type string to search for.
     * @return bool True if any event matches the type.
     *
     * @since 1.58.0
     *
     * @example
     * ```php
     * $collection->hasType('order.paid'); // true if any 'order.paid' event exists
     * ```
     */
    public function hasType(string $eventType): bool
    {
        return $this->some(fn (DomainEvent $event): bool => $event->eventType === $eventType);
    }

    /**
     * Get the number of events matching a predicate.
     *
     * @param  callable(DomainEvent): bool  $predicate
     * @return int Count of matching events.
     *
     * @since 1.58.0
     *
     * @example
     * ```php
     * $paymentCount = $collection->countBy(fn (DomainEvent $e): bool => $e->eventType === 'order.paid');
     * ```
     */
    public function countBy(callable $predicate): int
    {
        $count = 0;
        foreach ($this->events as $event) {
            if ($predicate($event)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Get event types present in the collection.
     *
     * Returns a unique list of event type strings in the order they first appear.
     *
     * @return list<string> Unique event types.
     *
     * @since 1.58.0
     *
     * @example
     * ```php
     * $types = $collection->types();
     * // ['order.placed', 'order.item_added', 'order.paid']
     * ```
     */
    public function types(): array
    {
        $seen = [];
        $result = [];

        foreach ($this->events as $event) {
            if (! in_array($event->eventType, $seen, true)) {
                $seen[] = $event->eventType;
                $result[] = $event->eventType;
            }
        }

        return $result;
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

    /**
     * Reconstruct a DomainEventCollection from an array of serialized events.
     *
     * Accepts the output of {@see toArray()} or any list of arrays that
     * {@see DomainEvent::fromArray()} can consume. Enables round-trip
     * serialization for caching, queue jobs, and event replay from storage.
     *
     * @param  list<array<string, mixed>>  $arrays  Array of serialized event data.
     * @return self A new DomainEventCollection with reconstructed events.
     *
     * @throws \InvalidArgumentException If any element is not an array.
     *
     * @example
     * ```php
     * $collection = new DomainEventCollection([$event1, $event2]);
     * $serialized = $collection->toArray();
     * // Cache or persist $serialized...
     * $restored = DomainEventCollection::fromArray($serialized);
     * $restored->count();  // 2
     * ```
     */
    public static function fromArray(array $arrays): self
    {
        if (! array_is_list($arrays)) {
            throw new \InvalidArgumentException(
                'DomainEventCollection::fromArray() expects a sequential list of event arrays.',
            );
        }

        $events = [];

        foreach ($arrays as $i => $item) {
            if (! is_array($item)) {
                throw new \InvalidArgumentException(sprintf(
                    'DomainEventCollection::fromArray() item at index %d must be an array, got %s.',
                    $i,
                    get_debug_type($item),
                ));
            }

            $events[] = DomainEvent::fromArray($item);
        }

        return new self($events);
    }

    /**
     * Reconstruct a DomainEventCollection from a JSON string.
     *
     * Parses the JSON array of serialized events and delegates to
     * {@see fromArray()} for hydration. Enables round-trip serialization
     * for caching, queue jobs, and event replay from storage.
     *
     * @param  string  $json  A valid JSON array string of event objects.
     * @return self A new DomainEventCollection with reconstructed events.
     *
     * @throws \JsonException If the JSON string is invalid.
     * @throws \InvalidArgumentException If the JSON does not decode to an array.
     *
     * @example
     * ```php
     * $collection = new DomainEventCollection([$event1, $event2]);
     * $json = json_encode($collection->toArray());
     * $restored = DomainEventCollection::fromJson($json);
     * $restored->count(); // 2
     * ```
     */
    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($data)) {
            throw new \InvalidArgumentException('JSON must decode to an array/object.');
        }

        return self::fromArray($data);
    }

    /**
     * Convert the collection to a JSON string.
     *
     * Convenience method for explicit JSON serialization. Uses
     * `JSON_THROW_ON_ERROR` for safety.
     *
     * @param  int  $options  JSON encoding options bitmask (default: JSON_UNESCAPED_UNICODE).
     * @return string The JSON-encoded event collection.
     *
     * @since 1.64.0
     *
     * @example
     * ```php
     * $collection = new DomainEventCollection([$event1, $event2]);
     * $json = $collection->toJson();
     * $restored = DomainEventCollection::fromJson($json);
     * $restored->count(); // 2
     * ```
     */
    public function toJson(int $options = JSON_UNESCAPED_UNICODE): string
    {
        return json_encode($this->toArray(), $options | JSON_THROW_ON_ERROR);
    }
}
