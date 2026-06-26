<?php

declare(strict_types=1);

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

namespace ZeroBoiler\Domain;

trait HasDomainEvents
{
    /** @var array<int, DomainEvent> */
    private array $domainEvents = [];

    protected function recordThat(DomainEvent $event): void
    {
        $this->domainEvents[] = $event;
    }

    /**
     * @return array<int, DomainEvent>
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