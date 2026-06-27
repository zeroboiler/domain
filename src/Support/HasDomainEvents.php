<?php

declare(strict_types=1);

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

namespace ZeroBoiler\Domain\Support;

trait HasDomainEvents
{
    /** @var array<int, \ZeroBoiler\Domain\DomainEvent> */
    private array $domainEvents = [];

    protected function recordThat(\ZeroBoiler\Domain\DomainEvent $event): void
    {
        $this->domainEvents[] = $event;
    }

    /**
     * @return array<int, \ZeroBoiler\Domain\DomainEvent>
     */
    public function releaseEvents(): array
    {
        $events = $this->domainEvents;
        $this->domainEvents = [];

        return $events;
    }

    public function hasUncommittedEvents(): bool
    {
        return $this->domainEvents !== [];
    }

    public function clearEvents(): void
    {
        $this->domainEvents = [];
    }
}