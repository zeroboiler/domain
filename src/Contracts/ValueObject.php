<?php

declare(strict_types=1);

namespace ZeroBoiler\Domain\Contracts;

interface ValueObject
{
    public function equals(ValueObject $other): bool;

    public function __toString(): string;
}
