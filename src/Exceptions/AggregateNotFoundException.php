<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Exceptions;

use Exception;

use function sprintf;

/**
 * Thrown when an aggregate root cannot be found in the repository.
 *
 * Typically raised by {@see \ZeroBoiler\Domain\Contracts\Repository::findById()}
 * when no aggregate exists for the given identifier.
 */
final class AggregateNotFoundException extends Exception
{
    /**
     * @param  string  $aggregateType  The FQCN of the aggregate that was not found.
     * @param  string  $aggregateId    The identity of the aggregate that was not found.
     */
    public function __construct(string $aggregateType, string $aggregateId)
    {
        parent::__construct(
            sprintf('Aggregate %s with ID %s not found.', $aggregateType, $aggregateId)
        );
    }
}
