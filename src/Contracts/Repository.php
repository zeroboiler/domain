<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Contracts;

use ZeroBoiler\Domain\AggregateRoot;
use ZeroBoiler\Domain\Exceptions\OptimisticLockException;

/**
 * Contract for aggregate root repositories.
 *
 * Provides the standard CRUD operations for aggregate roots with
 * optimistic locking support. All implementations must handle
 * version checking on save to prevent concurrent modification conflicts.
 *
 * @see AggregateRoot The entity type this repository manages.
 * @see SnapshottingRepository A decorator that adds snapshot support.
 * @see OptimisticLockException Thrown on version mismatch during save.
 *
 * @since 1.0.0
 *
 * @example
 * ```php
 * use ZeroBoiler\Domain\Contracts\Repository;
 *
 * interface OrderRepository extends Repository
 * {
 *     // Optional domain-specific queries:
 *     public function findByStatus(string $status): array;
 *     public function findAfterVersion(string|int $id, int $version, ?AggregateRoot $aggregate = null): ?AggregateRoot;
 * }
 *
 * // Eloquent implementation:
 * final class EloquentOrderRepository implements OrderRepository
 * {
 *     public function find(string|int $id): ?Order
 *     {
 *         return Order::where('id', $id)->first();
 *     }
 *
 *     public function save(AggregateRoot $aggregate): void
 *     {
 *         $persisted = $this->find($aggregate->id());
 *
 *         if ($persisted !== null && $persisted->version() !== $aggregate->version()) {
 *             throw OptimisticLockException::for(
 *                 $aggregate->id(),
 *                 expectedVersion: $aggregate->version(),
 *                 actualVersion: $persisted->version(),
 *             );
 *         }
 *
 *         DB::table('orders')->upsert([
 *             'id' => $aggregate->id(),
 *             'version' => $aggregate->version() + 1,
 *             'state' => json_encode($aggregate->toArray()),
 *         ], 'id');
 *     }
 *
 *     public function delete(string|int $id): void
 *     {
 *         DB::table('orders')->delete($id);
 *     }
 * }
 * ```
 */
interface Repository
{
    /**
     * Find an aggregate by ID.
     *
     * @param  string|int  $id  The aggregate's identity (UUID string, ULID string, or int).
     * @return AggregateRoot|null The aggregate root, or null if not found.
     */
    public function find(string|int $id): ?AggregateRoot;

    /**
     * Save an aggregate with optimistic locking.
     *
     * Implementations should check the aggregate's version against
     * the persisted version. If they differ, throw OptimisticLockException.
     * After a successful save, the aggregate's version should be incremented
     * via `incrementVersion()`.
     *
     * @param  AggregateRoot  $aggregate  The aggregate to persist.
     *
     * @throws OptimisticLockException When the aggregate version is stale.
     * @return void
     */
    public function save(AggregateRoot $aggregate): void;

    /**
     * Delete an aggregate by ID.
     *
     * @param  string|int  $id  The aggregate's identity.
     * @return void
     */
    public function delete(string|int $id): void;
}
