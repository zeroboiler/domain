<?php

declare(strict_types=1);

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

namespace ZeroBoiler\Domain\Exceptions;

use Exception;

use function sprintf;

final readonly class AggregateNotFoundException extends Exception
{
    public function __construct(string $aggregateType, string $aggregateId)
    {
        parent::__construct(
            sprintf('Aggregate %s with ID %s not found.', $aggregateType, $aggregateId)
        );
    }
}