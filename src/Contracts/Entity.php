<?php

declare(strict_types=1);

namespace ZeroBoiler\Domain\Contracts;

interface Entity
{
    public function id(): string|int;

    public function equals(Entity $other): bool;
}
