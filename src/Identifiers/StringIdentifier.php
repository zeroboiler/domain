<?php

declare(strict_types=1);

namespace ZeroBoiler\Domain\Identifiers;

use JsonSerializable;
use ValueError;
use ZeroBoiler\Domain\Contracts\Identifier;
use ZeroBoiler\Domain\Contracts\Identifier as IdentifierContract;

readonly class StringIdentifier implements IdentifierContract, JsonSerializable
{
    public function __construct(
        public string $value,
    ) {
        if ($value === '') {
            throw new ValueError('String identifier cannot be empty');
        }
    }

    public static function from(string $value): static
    {
        return new static($value);
    }

    public static function fromString(string $value): static
    {
        return static::from($value);
    }

    public function equals(Identifier $other): bool
    {
        return $other instanceof StringIdentifier && $this->value === $other->value;
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function jsonSerialize(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
