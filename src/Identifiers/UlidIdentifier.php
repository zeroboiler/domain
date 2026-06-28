<?php

declare(strict_types=1);

namespace ZeroBoiler\Domain\Identifiers;

use JsonSerializable;
use Symfony\Component\Uid\Ulid as SymfonyUlid;
use Symfony\Component\Uid\Ulid as UlidInterface;

abstract readonly class UlidIdentifier implements \Stringable, JsonSerializable
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

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
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
        return $this->value;
    }
}
