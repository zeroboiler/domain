<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Contracts;

use ZeroBoiler\Domain\AggregateRoot;
use ZeroBoiler\Domain\Exceptions\OptimisticLockException;

interface Repository
{
    /**
     * Find an aggregate by ID.
     */
    public function find(string|int $id): ?AggregateRoot;

    /**
     * Save an aggregate with optimistic locking.
     *
     * Implementations should check the aggregate's version against
     * the persisted version. If they differ, throw OptimisticLockException.
     *
     * @throws OptimisticLockException When the aggregate version is stale.
     */
    public function save(AggregateRoot $aggregate): void;

    /**
     * Delete an aggregate by ID.
     */
    public function delete(string|int $id): void;
}
