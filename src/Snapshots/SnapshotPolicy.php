<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Snapshots;

/**
 * Attribute to configure automatic snapshotting on aggregate roots.
 *
 * When applied, the EventSourcedRepository will automatically create
 * a snapshot after every N events.
 *
 * @see SnapshotStore
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class SnapshotPolicy
{
    /**
     * @param  int  $every  Take a snapshot after every N events.
     *                       Default: 50. Set to 0 to disable automatic snapshots.
     */
    public function __construct(
        public int $every = 50,
    ) {}
}
