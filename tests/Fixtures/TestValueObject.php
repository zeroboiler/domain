<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Tests\Fixtures;

use ZeroBoiler\Domain\ValueObject;

/**
 * Concrete value object fixture for testing the abstract ValueObject base class.
 *
 * @internal Test-only class.
 */
final class TestValueObject extends ValueObject implements \Stringable
{
    public function __construct(
        public string $value,
    ) {}

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
