<?php

declare(strict_types=1);

namespace ZeroBoiler\Domain\Exceptions;

use RuntimeException;
use Throwable;

abstract class DomainException extends RuntimeException
{
    public function __construct(string $message = '', ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}