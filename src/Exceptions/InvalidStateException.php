<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Exceptions;

use Exception;

final class InvalidStateException extends Exception
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
