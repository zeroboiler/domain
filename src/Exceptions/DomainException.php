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

 * @implements \JsonSerializable<array{title: string, detail: string, code: string}>
 *
 * @since 1.0.0
 */
abstract class DomainException extends Exception implements \JsonSerializable
{
    /**
     * Custom machine-readable error code, separate from PHP's int $code.
     *
     * @var string Custom domain error code (e.g., 'ORDER_NOT_PENDING').
     */
    private string $domainCode = '';

    /**
     * @param  string  $message  Human-readable exception message.
     * @param  int  $code  PHP exception code (default: 0).
     * @param  \Throwable|null  $previous  Previous exception for chaining.
     * @param  string  $domainCode  Machine-readable error code for API consumers.
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        string $domainCode = '',
    ) {
        parent::__construct($message, $code, $previous);
        $this->domainCode = $domainCode;
    }

    /**
     * Get a machine-readable error code for this exception.
     *
     * Subclasses override `defaultErrorCode()` to provide a stable
     * string identifier. When an explicit code is provided via the
     * `$domainCode` constructor parameter, it takes precedence.
     *
     * @return string A machine-readable error code (e.g., 'INVALID_STATE', 'NOT_FOUND').
     */
    public function errorCode(): string
    {
        if ($this->domainCode !== '') {
            return $this->domainCode;
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

    /**
     * Convert the exception to a structured array for API responses.
     *
     * Returns an RFC 9457-compatible error object with title, detail,
     * and a machine-readable code. Useful for mapping domain exceptions
     * to response DTOs via DomainResponseFactory::error().
     *
     * @return array{title: string, detail: string, code: string}
     *
     * @example
     * ```php
     * try {
     *     $order->pay($amount);
     * } catch (DomainException $e) {
     *     $error = $e->toErrorArray();
     *     // ['title' => '...', 'detail' => '...', 'code' => 'INVALID_STATE']
     *     return DomainResponseFactory::error($error)->send();
     * }
     * ```
     */
    public function toErrorArray(): array
    {
        return [
            'title' => class_basename(static::class),
            'detail' => $this->getMessage(),
            'code' => $this->errorCode(),
        ];
    }

    /**
     * Convert the exception to an array representation.
     *
     * Includes the error code, message, and file/line information
     * for debugging and logging purposes.
     *
     * @return array{error_code: string, message: string, file: string, line: int}
     */
    public function toArray(): array
    {
        return [
            'error_code' => $this->errorCode(),
            'message' => $this->getMessage(),
            'file' => $this->getFile(),
            'line' => $this->getLine(),
        ];
    }

    /**
     * Serialize the exception for `json_encode()` support.
     *
     * Uses the RFC 9457-compatible error array format, suitable for
     * direct JSON serialization in API error responses.
     *
     * @return array{title: string, detail: string, code: string}
     */
    #[\Override]
    public function jsonSerialize(): array
    {
        return $this->toErrorArray();
    }
}
