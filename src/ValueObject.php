<?php

declare(strict_types=1);

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

namespace ZeroBoiler\Domain;

use JsonSerializable;
use Stringable;

abstract readonly class ValueObject implements JsonSerializable, Stringable
{
    public function equals(self $other): bool
    {
        return $this->toArray() === $other->toArray();
    }

    abstract public function toArray(): array;

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR);
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    abstract public function __toString(): string;
}