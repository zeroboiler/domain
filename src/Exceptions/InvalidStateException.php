<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Exceptions;

use Exception;

/**
 * Thrown when the system enters an invalid state.
 *
 * Unlike {@see InvalidStateDomainException}, this does not extend
 * DomainException and is used for general invalid-state checks
 * outside the domain layer.
 *
 * @example
 * ```php
 * if (! $config->isValid()) {
 *     throw InvalidStateException::because('Application configuration is invalid.');
 * }
 * ```
 */
final class InvalidStateException extends Exception
{
    /**
     * Create an exception with the given reason.
     *
     * @param  string  $reason  Description of why the state is invalid.
     */
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
