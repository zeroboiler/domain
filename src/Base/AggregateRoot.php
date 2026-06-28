<?php

declare(strict_types=1);

namespace ZeroBoiler\Domain\Base;

use ZeroBoiler\Domain\Collections\DomainEventCollection;
use ZeroBoiler\Domain\Contracts\AggregateRoot as AggregateRootContract;
use ZeroBoiler\Domain\Contracts\DomainEvent;
use ZeroBoiler\Domain\Contracts\ValueObject;

abstract class AggregateRoot implements AggregateRootContract
{
    private int $version = 0;

    private DomainEventCollection $domainEvents;

    public function __construct()
    {
        $this->domainEvents = new DomainEventCollection;
    }

    public function version(): int
    {
        return $this->version;
    }

    public function incrementVersion(): void
    {
        $this->version++;
    }

    public function pullDomainEvents(): DomainEventCollection
    {
        $events = $this->domainEvents;

        $this->domainEvents = new DomainEventCollection;

        return $events;
    }

    public function clearDomainEvents(): void
    {
        $this->domainEvents = new DomainEventCollection;
    }

    protected function raise(DomainEvent $event): void
    {
        $this->domainEvents->add($event);
    }

    public function equals(ValueObject $other): bool
    {
        return $other::class === static::class
            && $other->id() === $this->id();
    }

    abstract public function id(): string|int;
}
