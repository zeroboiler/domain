<?php

declare(strict_types=1);

namespace ZeroBoiler\Domain\Tests\Fixtures;

use ZeroBoiler\Domain\ValueObject;

final readonly class TestValueObject extends ValueObject
{
    public function __construct(
        public string $value,
    ) {
    }

    public static function from(string $value): self
    {
        return new self($value);
    }

    public function toArray(): array
    {
        return [
            'value' => $this->value,
        ];
    }

    public function __toString(): string
    {
        return $this->value;
    }
}