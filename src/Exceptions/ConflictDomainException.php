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
 *
 * // With error code:
 * $e->errorCode(); // 'CONFLICT'
 * ```
 *
 * @since 1.0.0
 */
final class ConflictDomainException extends DomainException
{
    protected function defaultErrorCode(): string
    {
        return 'CONFLICT';
    }

    /**
     * Create an exception with a human-readable reason.
     *
     * @param  string  $reason  Description of the conflict.
     * @param  string  $code  Optional machine-readable error code.
     * @return self
     */
    public static function because(string $reason, string $code = ''): self
    {
        return new self($reason, 0, null, $code);
    }
}
