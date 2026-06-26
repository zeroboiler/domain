<?php

declare(strict_types=1);

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

namespace ZeroBoiler\Domain;

use ZeroBoiler\Domain\Support\HasDomainEvents;

abstract class AggregateRoot
{
    use HasDomainEvents;

    public AggregateRootId $id;

    public int $version = 0;

    protected function __construct(AggregateRootId $id)
    {
        $this->id = $id;
    }

    protected function apply(DomainEvent $event): void
    {
        $this->recordThat($event);
        $this->version++;
    }

    public function getVersion(): int
    {
        return $this->version;
    }
}