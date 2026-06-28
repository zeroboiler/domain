<?php

declare(strict_types=1);

namespace ZeroBoiler\Domain\Exceptions;

final class InvalidArgumentDomainException extends DomainException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
