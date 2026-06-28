<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain;

use ZeroBoiler\Domain\Support\HasDomainEvents;

abstract class AggregateRoot
{
    use HasDomainEvents;

    public int $version = 0;

    protected function __construct(public AggregateRootId $id) {}

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
