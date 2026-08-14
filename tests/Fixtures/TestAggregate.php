<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Tests\Fixtures;

use ZeroBoiler\Domain\AggregateRoot;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Events\Domain\DomainEvent;

/**
 * Concrete aggregate root fixture for testing the abstract AggregateRoot class.
 *
 * @internal Test-only class.
 */
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
