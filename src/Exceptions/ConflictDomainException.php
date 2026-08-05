<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Exceptions;

/**
 * Thrown when a concurrent modification conflict is detected.
 *
 * Use this for write-write conflicts where two operations attempt
 * to modify the same aggregate simultaneously.
 *
 * @see OptimisticLockException
 *
 * @example
 * ```php
 * throw ConflictDomainException::because('Concurrent modification detected.');
 * ```
 */
final class ConflictDomainException extends DomainException
{
    /**
     * Create an exception with a human-readable reason.
     */
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
