<?php

declare(strict_types=1);

namespace ZeroBoiler\Domain\Contracts;

interface Repository
{
    public function find(string|int $id): ?AggregateRoot;

    public function save(AggregateRoot $aggregate): void;

    public function delete(string|int $id): void;
}
