<?php

declare(strict_types=1);

namespace ZeroBoiler\Domain\Contracts;

interface UnitOfWork
{
    public function begin(): void;

    public function commit(): void;

    public function rollback(): void;

    public function track(AggregateRoot $aggregate): void;

    public function isTracking(AggregateRoot $aggregate): bool;
}
