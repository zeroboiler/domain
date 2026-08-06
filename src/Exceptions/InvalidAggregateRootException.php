<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Exceptions;

use function sprintf;

/**
 * Thrown when an object is not a valid aggregate root.
 *
 * Use this when validation expects an AggregateRoot but receives
 * a different object type.
 *
 * @see \ZeroBoiler\Domain\Contracts\AggregateRoot
 *
 * @example
 * ```php
 * if (! $aggregate instanceof AggregateRoot) {
 *     throw InvalidAggregateRootException::notAnAggregate($aggregate);
 * }
 *
 * // With error code:
 * $e->errorCode(); // 'INVALID_AGGREGATE_ROOT'
 * ```
 */
final class InvalidAggregateRootException extends DomainException
{
    protected function defaultErrorCode(): string
    {
        return 'INVALID_AGGREGATE_ROOT';
    }

    /**
     * Create an exception for an invalid aggregate root.
     *
     * @param  object  $object  The object that is not an aggregate root.
     * @param  string  $code  Optional machine-readable error code.
     */
    public static function notAnAggregate(object $object, string $code = ''): self
    {
        return new self(
            sprintf('Object must be an instance of AggregateRoot, got: %s', $object::class),
            0,
            null,
            $code,
        );
    }
}
