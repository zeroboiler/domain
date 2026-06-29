<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Identifiers;

use ZeroBoiler\Domain\Contracts\Identifier as IdentifierContract;

final readonly class IntegerIdentifier implements IdentifierContract
{
    public function __construct(public int $value) {}

    public static function from(int $value): self
    {
        return new self($value);
    }

    public static function fromString(string $value): static
    {
        return new self((int) $value);
    }

    public function toInt(): int
    {
        return $this->value;
    }

    public function toString(): string
    {
        return (string) $this->value;
    }

    public function equals(IdentifierContract $other): bool
    {
        return $other instanceof IntegerIdentifier && $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->toString();
    }
}
