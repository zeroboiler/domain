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
 *
 * // With error code:
 * $e->errorCode(); // 'INVALID_ARGUMENT'
 *
 * // In API response:
 * Response::error(422, 'Validation Error', $e->getMessage())
 *     ->withMeta(['code' => $e->errorCode()])
 *     ->send();
 * ```
 *
 * @since 1.0.0
 */
final class InvalidArgumentDomainException extends DomainException
{
    protected function defaultErrorCode(): string
    {
        return 'INVALID_ARGUMENT';
    }

    /**
     * Create an exception with a human-readable reason.
     *
     * @param  string  $reason  Description of the validation failure.
     * @param  string  $code  Optional machine-readable error code.
     * @return self
     */
    public static function because(string $reason, string $code = ''): self
    {
        return new self($reason, 0, null, $code);
    }
}
