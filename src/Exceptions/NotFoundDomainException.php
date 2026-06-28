<?php

declare(strict_types=1);

namespace ZeroBoiler\Domain\Exceptions;

final class NotFoundDomainException extends DomainException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
