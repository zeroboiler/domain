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
 *
 * // With error code:
 * $e->errorCode(); // 'INVALID_STATE'
 *
 * // In API response:
 * Response::error(409, 'Invalid State', $e->getMessage())
 *     ->withMeta(['code' => $e->errorCode()])
 *     ->send();
 * ```
 *
 * @since 1.0.0
 */
final class InvalidStateDomainException extends DomainException
{
    protected function defaultErrorCode(): string
    {
        return 'INVALID_STATE';
    }

    /**
     * Create an exception with a human-readable reason.
     *
     * @param  string  $reason  Description of the invalid state.
     * @param  string  $code  Optional machine-readable error code.
     */
    public static function because(string $reason, string $code = ''): self
    {
        return new self($reason, 0, null, $code);
    }
}
