<?php

declare(strict_types=1);

namespace ZeroBoiler\Domain\Contracts;

use ZeroBoiler\Domain\DomainEventCollection;

interface AggregateRoot extends Entity
{
    public function version(): int;

    public function incrementVersion(): void;

    public function pullDomainEvents(): DomainEventCollection;

    public function clearDomainEvents(): void;
}
