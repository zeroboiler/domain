<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Snapshots;

/**
 * Attribute to configure automatic snapshotting on aggregate roots.
 *
 * When applied to an aggregate root class, the {@see SnapshottingRepository}
 * will automatically create a snapshot after every N events (configured
 * via the `every` parameter). Set to 0 to disable automatic snapshots.
 *
 * @see HasSnapshots
 * @see SnapshottingRepository
 * @see InMemorySnapshotStore
 *
 * @example
 * ```php
 * use ZeroBoiler\Domain\AggregateRoot;
 * use ZeroBoiler\Domain\Concerns\EventSourced;
 * use ZeroBoiler\Domain\Concerns\HasSnapshots;
 * use ZeroBoiler\Domain\Snapshots\SnapshotPolicy;
 *
 * // Snapshot every 50 events (default)
 * #[SnapshotPolicy(every: 50)]
 * class Order extends AggregateRoot
 * {
 *     use EventSourced;
 *     use HasSnapshots;
 * }
 *
 * // Snapshot every 100 events for large aggregates
 * #[SnapshotPolicy(every: 100)]
 * class AuditLog extends AggregateRoot
 * {
 *     use EventSourced;
 *     use HasSnapshots;
 * }
 *
 * // Disable automatic snapshots (manual only)
 * #[SnapshotPolicy(every: 0)]
 * class SmallAggregate extends AggregateRoot
 * {
 *     use EventSourced;
 *     use HasSnapshots;
 * }
 * ```
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class SnapshotPolicy
{
    /**
     * @param  int  $every  Take a snapshot after every N events.
     *                      Default: 50. Set to 0 to disable automatic snapshots.
     */
    public function __construct(
        public int $every = 50,
    ) {}
}
