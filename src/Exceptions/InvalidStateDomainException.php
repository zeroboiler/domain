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
    /** @return string Machine-readable error code for invalid state violations. */
    protected function defaultErrorCode(): string
    {
        return 'INVALID_STATE';
    }

    /** @return int HTTP status code for invalid state violations (422 Unprocessable Entity). */
    protected function defaultHttpStatus(): int
    {
        return 422;
    }

    /**
     * Create an exception with a human-readable reason.
     *
     * @param  string  $reason  Description of the invalid state.
     * @param  string  $code  Optional machine-readable error code.
     * @return self
     */
    public static function because(string $reason, string $code = ''): self
    {
        return new self($reason, 0, null, $code);
    }
}
