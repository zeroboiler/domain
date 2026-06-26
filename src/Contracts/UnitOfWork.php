<?php

declare(strict_types=1);

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

namespace ZeroBoiler\Domain\Contracts;

use ZeroBoiler\Domain\AggregateRoot;

interface UnitOfWork
{
    public function begin(): void;

    public function commit(): void;

    public function rollback(): void;

    /**
     * @template T of AggregateRoot
     *
     * @param  T  $aggregate
     */
    public function persist(AggregateRoot $aggregate): void;

    /**
     * @template T of AggregateRoot
     *
     * @param  T  $aggregate
     */
    public function remove(AggregateRoot $aggregate): void;

    public function hasChanges(): bool;
}