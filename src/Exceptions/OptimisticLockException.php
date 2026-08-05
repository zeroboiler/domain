<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Exceptions;

/**
 * Thrown when optimistic locking detects a stale aggregate version.
 *
 * This happens when two concurrent processes load the same aggregate,
 * and one saves before the other. The second save will detect that
 * the version has changed and throw this exception.
 *
 * @example
 * ```php
 * if ($persistedVersion !== $aggregate->version()) {
 *     throw OptimisticLockException::for(
 *         $aggregate->id(),
 *         expectedVersion: $aggregate->version(),
 *         actualVersion: $persistedVersion,
 *     );
 * }
 * ```
 */
final class OptimisticLockException extends DomainException
{
    public static function for(
        string $aggregateId,
        int $expectedVersion,
        int $actualVersion,
    ): self {
        return new self(
            sprintf(
                'Optimistic lock failed for aggregate "%s": expected version %d, but current version %d. '
                . 'The aggregate may have been modified by another process.',
                $aggregateId,
                $expectedVersion,
                $actualVersion,
            ),
        );
    }
}
