<?php

declare(strict_types=1);

namespace ZeroBoiler\Domain\Tests\Fixtures;

use ZeroBoiler\Domain\AggregateRoot;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\DomainEvent;

final class TestAggregate extends AggregateRoot
{
    public static function create(AggregateRootId $id): self
    {
        $aggregate = new self($id);

        $aggregate->apply(DomainEvent::occur('TestAggregateCreated', [
            'id' => $id->toString(),
        ]));

        return $aggregate;
    }
}
