<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Exceptions;

/**
 * Thrown when the system enters an invalid state.
 *
 * Unlike {@see InvalidStateDomainException}, this is intended for
 * infrastructure-level state checks outside the domain layer.
 * However, it extends DomainException for consistent error handling,
 * serialization, and RFC 9457 compatibility across the entire exception
 * hierarchy.
 *
 * All ZeroBoiler domain-level exceptions now extend DomainException,
 * ensuring uniform access to `errorCode()`, `toErrorArray()`, `toArray()`,
 * and `JsonSerializable` across the entire codebase.
 *
 * @example
 * ```php
 * if (! $config->isValid()) {
 *     throw InvalidStateException::because('Application configuration is invalid.');
 * }
 *
 * // In API response:
 * $e->errorCode();    // 'INVALID_STATE_SYSTEM'
 * $e->toErrorArray(); // ['title' => 'InvalidStateException', 'detail' => '...', 'code' => 'INVALID_STATE_SYSTEM']
 * ```
 *
 * @since 1.0.0
 */
final class InvalidStateException extends DomainException
{
    protected function defaultErrorCode(): string
    {
        return 'INVALID_STATE_SYSTEM';
    }

    /**
     * Create an exception with the given reason.
     *
     * @param  string  $reason  Description of why the state is invalid.
     * @param  string  $code  Optional machine-readable error code.
     */
    public static function because(string $reason, string $code = ''): self
    {
        return new self($reason, 0, null, $code);
    }
}
