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
 * ```
 */
final class InvalidAggregateRootException extends DomainException
{
    public static function notAnAggregate(object $object): self
    {
        return new self(sprintf(
            'Object must be an instance of AggregateRoot, got: %s',
            $object::class
        ));
    }
}
