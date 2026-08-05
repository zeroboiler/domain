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
 *     public static function forOrder(string $orderId): self
 *     {
 *         return new self("Order {$orderId} has already been shipped.");
 *     }
 * }
 * ```
 */
abstract class DomainException extends Exception {}
