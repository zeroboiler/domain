<?php

declare(strict_types=1);

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

namespace ZeroBoiler\Domain\Exceptions;

use Exception;
use Throwable;

final class DomainException extends Exception
{
    public static function because(string $reason, ?Throwable $previous = null): self
    {
        return new self($reason, 0, $previous);
    }

    public static function invalidState(string $reason): InvalidStateException
    {
        return new InvalidStateException($reason);
    }
}