<?php

declare(strict_types=1);

namespace ZeroBoiler\Domain\Contracts;

use ZeroBoiler\Domain\AggregateRoot;

/**
 * Repository contract for aggregate persistence.
 */
interface Repository
{
    /**
     * Find aggregate by identifier.
     */
    public function find(string|int $id): ?AggregateRoot;

    /**
     * Get all aggregates.
     *
     * @return array<AggregateRoot>
     */
    public function all(): array;

    /**
     * Save aggregate.
     */
    public function save(AggregateRoot $aggregate): void;

    /**
     * Delete aggregate.
     */
    public function delete(AggregateRoot $aggregate): void;

    /**
     * Check if aggregate exists.
     */
    public function exists(string|int $id): bool;

    /**
     * Get next identity value.
     */
    public function nextIdentity(): string|int;
}