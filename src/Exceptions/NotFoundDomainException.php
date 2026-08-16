<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Exceptions;

use function sprintf;

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
 *
 * // With error code:
 * $e->errorCode(); // 'NOT_FOUND'
 * ```
 *
 * @since 1.0.0
 */
final class NotFoundDomainException extends DomainException
{
    /** @return string Machine-readable error code for not-found violations. */
    protected function defaultErrorCode(): string
    {
        return 'NOT_FOUND';
    }

    /** @return int HTTP status code for not-found violations (404 Not Found). */
    protected function defaultHttpStatus(): int
    {
        return 404;
    }

    /**
     * Create an exception with a human-readable reason.
     *
     * @param  string  $reason  Description of what was not found and why.
     * @param  string  $code  Optional machine-readable error code.
     * @return self
     */
    public static function because(string $reason, string $code = ''): self
    {
        return new self($reason, 0, null, $code);
    }

    /**
     * Create an exception for an aggregate that was not found.
     *
     * Produces a standardized error message including the aggregate type
     * and identifier for easier debugging and logging.
     *
     * @param  string  $aggregateType  The FQCN or human-readable name of the aggregate.
     * @param  string  $aggregateId    The identifier that was searched for.
     * @param  string  $code  Optional machine-readable error code.
     * @return self
     */
    public static function forAggregate(string $aggregateType, string $aggregateId, string $code = ''): self
    {
        return new self(
            sprintf('Aggregate "%s" with ID "%s" was not found.', $aggregateType, $aggregateId),
            0,
            null,
            $code,
        );
    }

    /**
     * Create an exception for a missing aggregate or entity by its ID.
     *
     * Convenience shortcut when you don't have the aggregate type at hand
     * but know the ID that was searched for.
     *
     * @param  string  $id  The identifier that was searched for.
     * @param  string  $code  Optional machine-readable error code.
     * @return self
     *
     * @example
     * ```php
     * throw NotFoundDomainException::forId('order-123');
     * ```
     */
    public static function forId(string $id, string $code = ''): self
    {
        return new self(
            sprintf('Aggregate or entity with ID "%s" was not found.', $id),
            0,
            null,
            $code,
        );
    }
}
