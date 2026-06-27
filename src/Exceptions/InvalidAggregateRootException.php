<?php

declare(strict_types=1);

namespace ZeroBoiler\Domain\Exceptions;

class InvalidAggregateRootException extends DomainException
{
    public static function notAnAggregate(object $object): self
    {
        return new self(sprintf(
            'Object must be an instance of AggregateRoot, got: %s',
            $object::class
        ));
    }
}