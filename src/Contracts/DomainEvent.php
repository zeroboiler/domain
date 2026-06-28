<?php

declare(strict_types=1);

namespace ZeroBoiler\Domain\Contracts;

use DateTimeImmutable;

abstract readonly class DomainEvent
{
    public function __construct(
        public DateTimeImmutable $occurredAt = new DateTimeImmutable,
    ) {}

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
