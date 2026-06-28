<?php

declare(strict_types=1);

namespace ZeroBoiler\Domain\Contracts;

interface AggregateRoot extends Entity
{
    public function version(): int;

    public function incrementVersion(): void;

    public function pullDomainEvents(): DomainEventCollection;

    public function clearDomainEvents(): void;
}
