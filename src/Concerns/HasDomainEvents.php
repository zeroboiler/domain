<?php

declare(strict_types=1);

namespace ZeroBoiler\Domain\Concerns;

use ZeroBoiler\Domain\Contracts\DomainEvent;

trait HasDomainEvents
{
    private DomainEventCollection $domainEvents;

    public function __construct()
    {
        $this->domainEvents = new DomainEventCollection;
    }

    protected function raise(DomainEvent $event): void
    {
        $this->domainEvents->add($event);
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
}
