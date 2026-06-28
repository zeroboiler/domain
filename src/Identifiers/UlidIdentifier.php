<?php

declare(strict_types=1);

namespace ZeroBoiler\Domain\Identifiers;

use JsonSerializable;
use Symfony\Component\Uid\Ulid as SymfonyUlid;
use Symfony\Component\Uid\Ulid as UlidInterface;
use ZeroBoiler\Domain\Contracts\Identifier;
use ZeroBoiler\Domain\Contracts\Identifier as IdentifierContract;

abstract readonly class UlidIdentifier implements IdentifierContract, JsonSerializable
{
    public function __construct(
        public string $value,
    ) {
        SymfonyUlid::fromString($value);
    }

    public static function generate(): static
    {
        return new static((new SymfonyUlid)->toBase32());
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
        return $other instanceof UlidIdentifier && $this->value === $other->value;
    }

    public function toUlid(): UlidInterface
    {
        return SymfonyUlid::fromString($this->value);
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
