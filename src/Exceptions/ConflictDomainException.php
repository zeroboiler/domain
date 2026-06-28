<?php

declare(strict_types=1);

namespace ZeroBoiler\Domain\Exceptions;

class ConflictDomainException extends DomainException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
