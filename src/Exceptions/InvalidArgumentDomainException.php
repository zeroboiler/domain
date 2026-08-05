<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Exceptions;

/**
 * Thrown when a method receives an argument that violates domain rules.
 *
 * Use this for input validation failures at the domain boundary
 * (e.g., negative quantities, empty strings where non-empty is required).
 *
 * @example
 * ```php
 * throw InvalidArgumentDomainException::because('Quantity must be positive.');
 * ```
 */
final class InvalidArgumentDomainException extends DomainException
{
    /**
     * Create an exception with a human-readable reason.
     */
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
