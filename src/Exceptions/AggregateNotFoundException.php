<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Exceptions;

use Exception;

use function sprintf;

final class AggregateNotFoundException extends Exception
{
    public function __construct(string $aggregateType, string $aggregateId)
    {
        parent::__construct(
            sprintf('Aggregate %s with ID %s not found.', $aggregateType, $aggregateId)
        );
    }
}
