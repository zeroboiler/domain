<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Exceptions;

/**
 * Thrown when a requested aggregate or entity cannot be found.
 *
 * Use this when a repository lookup returns null but the caller
 * expected a result (e.g., editing a non-existent order).
 *
 * @example
 * ```php
 * throw NotFoundDomainException::because('User not found with ID: ' . $id);
 * ```
 */
final class NotFoundDomainException extends DomainException
{
    /**
     * Create an exception with a human-readable reason.
     */
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
