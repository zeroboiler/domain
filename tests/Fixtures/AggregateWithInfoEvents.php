<?php

declare(strict_types=1);

namespace ZeroBoiler\Domain\Tests\Fixtures;

use ZeroBoiler\Domain\AggregateRoot;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\Concerns\EventSourced;
use ZeroBoiler\Domain\DomainEvent;

final class AggregateWithInfoEvents extends AggregateRoot
{
    use EventSourced;

    public string $name = '';

    public static function create(AggregateRootId $id, string $name): self
    {
        $aggregate = new self($id);

        $aggregate->apply(DomainEvent::occur('aggregate.created', [
            'id' => $id->toString(),
            'name' => $name,
        ]));

        return $aggregate;
    }

    protected function applyAggregateCreated(DomainEvent $event): void
    {
        $this->name = $event->payload['name'];
    }

    // Note: no applyAggregateNoted() handler — simulating a purely informational event
    // that doesn't change aggregate state.
}
