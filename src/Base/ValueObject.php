<?php

declare(strict_types=1);

namespace ZeroBoiler\Domain\Base;

use JsonSerializable;
use ZeroBoiler\Domain\Contracts\ValueObject as ValueObjectContract;

abstract class ValueObject implements JsonSerializable, ValueObjectContract
{
    public function equals(ValueObjectContract $other): bool
    {
        if ($other::class !== static::class) {
            return false;
        }

        return $this->toArray() === $other->toArray();
    }

    public function __toString(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR);
    }

    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }

    abstract protected function toArray(): array;
}
