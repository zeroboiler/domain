<?php

declare(strict_types=1);

namespace ZeroBoiler\Domain\Collections;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use ZeroBoiler\Domain\Contracts\DomainEvent;

class DomainEventCollection implements Countable, IteratorAggregate
{
    public function __construct(
        private array $events = [],
    ) {}

    public static function fromArray(array $events): self
    {
        return new self($events);
    }

    public function add(DomainEvent $event): void
    {
        $this->events[] = $event;
    }

    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->events);
    }

    public function count(): int
    {
        return count($this->events);
    }

    public function toArray(): array
    {
        return $this->events;
    }

    public function isEmpty(): bool
    {
        return $this->count() === 0;
    }

    public function first(): ?DomainEvent
    {
        return $this->events[0] ?? null;
    }

    public function last(): ?DomainEvent
    {
        return $this->events[count($this->events) - 1] ?? null;
    }

    public function filter(callable $callback): self
    {
        return new self(array_filter($this->events, $callback));
    }

    public function map(callable $callback): array
    {
        return array_map($callback, $this->events);
    }

    public function merge(self $other): self
    {
        return new self([...$this->events, ...$other->events]);
    }
}
