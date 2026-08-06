<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Exceptions;

/**
 * Thrown when an aggregate root cannot be found in the repository.
 *
 * Typically raised by repository implementations when no aggregate exists
 * for the given identifier. Extends DomainException for consistency
 * with the domain exception hierarchy.
 *
 * @see NotFoundDomainException For the general-purpose alternative.
 *
 * @example
 * ```php
 * throw AggregateNotFoundException::for('App\Domain\Order', '550e8400-...');
 *
 * // With error code:
 * $e->errorCode(); // 'AGGREGATE_NOT_FOUND'
 * ```
 */
final class AggregateNotFoundException extends DomainException
{
    protected function defaultErrorCode(): string
    {
        return 'AGGREGATE_NOT_FOUND';
    }

    /**
     * Create an exception for a missing aggregate.
     *
     * @param  string  $aggregateType  The FQCN of the aggregate that was not found.
     * @param  string  $aggregateId    The identity of the aggregate that was not found.
     * @param  string  $code  Optional machine-readable error code.
     */
    public static function for(string $aggregateType, string $aggregateId, string $code = ''): self
    {
        return new self(
            sprintf('Aggregate %s with ID %s not found.', $aggregateType, $aggregateId),
            0,
            null,
            $code,
        );
    }
}
