<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain;

use ZeroBoiler\Domain\Collections\DomainEventCollection;
use ZeroBoiler\Domain\Contracts\AggregateRoot as AggregateRootContract;
use ZeroBoiler\Domain\Support\HasDomainEvents;

abstract class AggregateRoot implements AggregateRootContract
{
    use HasDomainEvents;

    protected int $version = 0;

    protected function __construct(public AggregateRootId $id) {}

    protected function apply(DomainEvent $event): void
    {
        $this->recordThat($event);
        $this->version++;
    }

    /**
     * Get the current version of this aggregate.
     *
     * Used by repositories for optimistic locking.
     */
    #[\Override]
    public function version(): int
    {
        return $this->version;
    }

    /**
     * Alias for version() — backward compatibility.
     */
    public function getVersion(): int
    {
        return $this->version;
    }

    /**
     * Set the version (used by repositories when loading from storage).
     */
    public function setVersion(int $version): void
    {
        $this->version = $version;
    }

    /**
     * Increment the version (called after a successful save).
     */
    #[\Override]
    public function incrementVersion(): void
    {
        $this->version++;
    }

    #[\Override]
    public function pullDomainEvents(): DomainEventCollection
    {
        $events = $this->domainEvents;
        $this->domainEvents = [];

        return new DomainEventCollection($events);
    }

    #[\Override]
    public function clearDomainEvents(): void
    {
        $this->domainEvents = [];
    }

    /**
     * @return string|int
     */
    public function id(): string|int
    {
        return $this->id->toString();
    }

    /**
     * Check identity equality with another entity.
     */
    #[\Override]
    public function equals(\ZeroBoiler\Domain\Contracts\Entity $other): bool
    {
        if ($other::class !== static::class) {
            return false;
        }

        return $this->id->toString() === (string) $other->id();
    }
}
