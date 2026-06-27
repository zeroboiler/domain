<?php

declare(strict_types=1);

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

namespace ZeroBoiler\Domain;

use JsonSerializable;

abstract readonly class ValueObject implements JsonSerializable
{
    public function equals(ValueObject $other): bool
    {
        return $this->toArray() === $other->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    abstract public function toArray(): array;

    public function toJson(): string
    {
        return json_encode($this, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}