<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Exceptions;

/**
 * Thrown when a requested aggregate or entity cannot be found.
 *
 * Use this when a repository lookup returns null but the caller
 * expected a result (e.g., editing a non-existent order).
 *
 * @example
 * ```php
 * throw NotFoundDomainException::because('User not found with ID: ' . $id);
 * throw NotFoundDomainException::forAggregate('Order', $orderId);
 * ```
 */
final class NotFoundDomainException extends DomainException
{
    /**
     * Create an exception with a human-readable reason.
     *
     * @param  string  $reason  Description of what was not found and why.
     */
    public static function because(string $reason): self
    {
        return new self($reason);
    }

    /**
     * Create an exception for an aggregate that was not found.
     *
     * Produces a standardized error message including the aggregate type
     * and identifier for easier debugging and logging.
     *
     * @param  string  $aggregateType  The FQCN or human-readable name of the aggregate.
     * @param  string  $aggregateId    The identifier that was searched for.
     */
    public static function forAggregate(string $aggregateType, string $aggregateId): self
    {
        return new self(
            sprintf('Aggregate "%s" with ID "%s" was not found.', $aggregateType, $aggregateId),
        );
    }
}
