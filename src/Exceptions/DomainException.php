<?php

declare(strict_types=1);

namespace ZeroBoiler\Domain\Exceptions;

use Exception;

abstract class DomainException extends Exception
{
    public static function invalidArgument(string $message): self
    {
        return new static($message, 0);
    }

    public static function invalidState(string $message): self
    {
        return new static($message, 0);
    }

    public static function notFound(string $message): self
    {
        return new static($message, 0);
    }

    public static function conflict(string $message): self
    {
        return new static($message, 0);
    }
}
