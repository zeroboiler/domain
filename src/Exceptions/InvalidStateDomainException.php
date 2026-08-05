<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Exceptions;

/**
 * Thrown when an entity or aggregate is in an invalid state for the requested operation.
 *
 * Use this for business rule violations where the object's current state
 * prevents the action (e.g., paying an already-paid order, publishing a draft).
 *
 * @example
 * ```php
 * throw InvalidStateDomainException::because('Order must be pending to pay.');
 * ```
 */
final class InvalidStateDomainException extends DomainException
{
    /**
     * Create an exception with a human-readable reason.
     */
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
