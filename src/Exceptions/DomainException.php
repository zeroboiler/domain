<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Exceptions;

use Exception;

/**
 * Base exception for all domain-level errors.
 *
 * Domain exceptions represent business rule violations and should
 * never be caught for technical retry — they indicate invalid state,
 * invalid arguments, or conflicting operations that must be resolved
 * at the business level.
 *
 * Provides a machine-readable `errorCode()` for programmatic error
 * handling in API responses, middleware, and client-side logic.
 *
 * @see InvalidStateDomainException
 * @see InvalidArgumentDomainException
 * @see NotFoundDomainException
 * @see ConflictDomainException
 * @see AggregateNotFoundException
 * @see OptimisticLockException
 *
 * @example
 * ```php
 * // Extend for custom domain exceptions:
 * final class OrderAlreadyShippedException extends DomainException
 * {
 *     protected function defaultErrorCode(): string
 *     {
 *         return 'ORDER_ALREADY_SHIPPED';
 *     }
 *
 *     public static function forOrder(string $orderId): self
 *     {
 *         return new self("Order {$orderId} has already been shipped.");
 *     }
 * }
 *
 * // Usage with machine-readable codes in API responses:
 * try {
 *     $order->ship();
 * } catch (DomainException $e) {
 *     // $e->errorCode() → 'ORDER_ALREADY_SHIPPED'
 *     Response::error(409, 'Conflict', $e->getMessage())
 *         ->withMeta(['code' => $e->errorCode()])
 *         ->send();
 * }
 * ```
 */
abstract class DomainException extends Exception
{
    /**
     * Get a machine-readable error code for this exception.
     *
     * Subclasses override `defaultErrorCode()` to provide a stable
     * string identifier. When an explicit code is provided via the
     * third constructor argument, it takes precedence.
     *
     * @return string A machine-readable error code (e.g., 'INVALID_STATE', 'NOT_FOUND').
     */
    public function errorCode(): string
    {
        $code = $this->getCode();

        if (is_string($code) && $code !== '') {
            return $code;
        }

        return $this->defaultErrorCode();
    }

    /**
     * Get the default machine-readable error code for this exception type.
     *
     * Override in subclasses to provide a domain-specific code.
     *
     * @return string The default error code.
     */
    protected function defaultErrorCode(): string
    {
        return 'DOMAIN_ERROR';
    }
}
