<?php

declare(strict_types=1);

namespace ZeroBoiler\Domain\Identifiers;

use JsonSerializable;
use Ramsey\Uuid\Uuid as RamseyUuid;
use Ramsey\Uuid\UuidInterface;
use ZeroBoiler\Domain\Contracts\Identifier;
use ZeroBoiler\Domain\Contracts\Identifier as IdentifierContract;

abstract readonly class UuidIdentifier implements IdentifierContract, JsonSerializable
{
    public function __construct(
        public string $value,
    ) {
        RamseyUuid::fromString($value);
    }

    public static function generate(): static
    {
        return new static(RamseyUuid::uuid4()->toString());
    }

    public static function fromString(string $value): static
    {
        return new static($value);
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function equals(Identifier $other): bool
    {
        return $other instanceof UuidIdentifier && $this->value === $other->value;
    }

    public function toUuid(): UuidInterface
    {
        return RamseyUuid::fromString($this->value);
    }

    public function jsonSerialize(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->toString();
    }
}
