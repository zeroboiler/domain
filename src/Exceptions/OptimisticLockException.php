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
 *
 * // With error code:
 * $e->errorCode(); // 'OPTIMISTIC_LOCK'
 * ```
 *
 * @since 1.0.0
 */
final class OptimisticLockException extends DomainException
{
    protected function defaultErrorCode(): string
    {
        return 'OPTIMISTIC_LOCK';
    }

    /**
     * Create an optimistic lock exception with typed parameters.
     *
     * @param  string  $aggregateId  The identity of the conflicting aggregate.
     * @param  int  $expectedVersion  The version the caller expected.
     * @param  int  $actualVersion  The version found in storage.
     * @param  string  $code  Optional machine-readable error code.
     * @return self
     */
    public static function for(
        string $aggregateId,
        int $expectedVersion,
        int $actualVersion,
        string $code = '',
    ): self {
        return new self(
            sprintf(
                'Optimistic lock failed for aggregate "%s": expected version %d, but current version %d. '
                . 'The aggregate may have been modified by another process.',
                $aggregateId,
                $expectedVersion,
                $actualVersion,
            ),
            0,
            null,
            $code,
        );
    }
}
