<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Identifiers;

use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use Stringable;

abstract class Identifier implements Stringable
{
    public readonly UuidInterface $value;

    public function __construct(?UuidInterface $value = null)
    {
        $this->value = $value ?? Uuid::uuid4();
    }

    public static function fromString(string $value): Identifier
    {
        return new class($value) extends Identifier
        {
            public function __construct(string $value)
            {
                parent::__construct(Uuid::fromString($value));
            }
        };
    }

    public function toString(): string
    {
        return $this->value->toString();
    }

    public function equals(Identifier $other): bool
    {
        return $this->value->equals($other->value);
    }

    public function __toString(): string
    {
        return $this->toString();
    }
}
