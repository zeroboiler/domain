<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain;

use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

final readonly class AggregateRootId implements \Stringable
{
    public function __construct(public UuidInterface $value) {}

    public static function generate(): self
    {
        return new self(Uuid::uuid4());
    }

    public static function fromString(string $id): self
    {
        return new self(Uuid::fromString($id));
    }

    public function toString(): string
    {
        return $this->value->toString();
    }

    public function equals(AggregateRootId $other): bool
    {
        return $this->value->equals($other->value);
    }

    public function __toString(): string
    {
        return $this->toString();
    }
}
