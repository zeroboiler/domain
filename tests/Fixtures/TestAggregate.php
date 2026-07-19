<?php

declare(strict_types=1);

namespace ZeroBoiler\Domain\Tests\Fixtures;

use ZeroBoiler\Domain\AggregateRoot;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Events\Domain\DomainEvent;

final class TestAggregate extends AggregateRoot
{
    public bool $nameChanged = false;

    public ?string $name = null;

    public static function create(AggregateRootId $id): self
    {
        $aggregate = new self($id);

        $aggregate->apply(DomainEvent::occur('TestAggregateCreated', [
            'id' => $id->toString(),
        ]));

        return $aggregate;
    }

    public function rename(string $name): self
    {
        $this->apply(DomainEvent::occur('TestAggregateRenamed', [
            'id' => $this->aggregateId()->toString(),
            'name' => $name,
        ]));

        return $this;
    }

    protected function applyTestAggregateRenamed(DomainEvent $event): void
    {
        $this->name = $event->payload['name'] ?? null;
        $this->nameChanged = true;
    }
}
