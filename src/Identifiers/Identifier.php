<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Identifiers;

use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use ZeroBoiler\Domain\Contracts\Identifier as IdentifierContract;

abstract class Identifier implements IdentifierContract
{
    public readonly UuidInterface $value;

    public function __construct(?UuidInterface $value = null)
    {
        $this->value = $value ?? Uuid::uuid4();
    }

    public static function fromString(string $value): static
    {
        return new static(Uuid::fromString($value));
    }

    public function toString(): string
    {
        return $this->value->toString();
    }

    public function equals(IdentifierContract $other): bool
    {
        return $other instanceof Identifier && $this->value->equals($other->value);
    }

    public function __toString(): string
    {
        return $this->toString();
    }
}
