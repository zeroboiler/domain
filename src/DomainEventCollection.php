<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;
use ZeroBoiler\Events\Domain\DomainEvent;

/**
 * Type-safe collection for domain events.
 *
 * Provides iteration, counting, and array access for DomainEvent objects.
 *
 * @implements IteratorAggregate<int, DomainEvent>
 */
final readonly class DomainEventCollection implements Countable, IteratorAggregate
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
}
