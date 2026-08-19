<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Concerns;

use ZeroBoiler\Domain\Exceptions\InvalidArgumentDomainException;
use ZeroBoiler\Domain\Exceptions\InvalidStateDomainException;
use ZeroBoiler\Domain\Exceptions\NotFoundDomainException;

/**
 * Provides common domain assertion guard clauses for entities and value objects.
 *
 * Encapsulates frequently repeated validation patterns into reusable methods
 * that throw the appropriate domain exception when the assertion fails.
 * This keeps entity/VO constructors and business methods clean and focused
 * on domain logic rather than boilerplate validation.
 *
 * Each guard method throws a specific domain exception type:
 * - `assertNotEmptyString()` → InvalidArgumentDomainException
 * - `assertPositiveInteger()` → InvalidArgumentDomainException
 * - `assertNotNull()` → InvalidArgumentDomainException
 * - `assertStateIs()` → InvalidStateDomainException
 * - `assertFound()` → NotFoundDomainException
 *
 * @see InvalidArgumentDomainException For input validation failures.
 * @see InvalidStateDomainException For state-based business rule violations.
 * @see NotFoundDomainException For missing aggregate/entity lookups.
 *
 * @since 1.76.0
 *
 * @example
 * ```php
 * use ZeroBoiler\Domain\Concerns\Guards;
 *
 * class Order extends AggregateRoot
 * {
 *     use Guards;
 *
 *     public function __construct(
 *         public readonly string $status,
 *         public readonly int $total,
 *     ) {
 *         parent::__construct(AggregateRootId::generate());
 *         $this->assertNotEmptyString($status, 'status');
 *         $this->assertPositiveInteger($total, 'total');
 *     }
 *
 *     public function pay(): void
 *     {
 *         $this->assertStateIs('pending', $this->status, 'pay');
 *         // ... payment logic
 *     }
 * }
 * ```
 */
trait Guards
{
    /**
     * Assert that a string value is not empty.
     *
     * @param  string  $value  The value to check.
     * @param  string  $name  The parameter/field name (for error message).
     * @return void
     *
     * @throws InvalidArgumentDomainException When the string is empty.
     *
     * @example
     * ```php
     * $this->assertNotEmptyString($email, 'email');
     * ```
     */
    protected function assertNotEmptyString(string $value, string $name): void
    {
        if ($value === '') {
            throw InvalidArgumentDomainException::because(
                sprintf('"%s" must not be empty.', $name),
                code: 'EMPTY_STRING',
            );
        }
    }

    /**
     * Assert that a string matches a maximum length constraint.
     *
     * @param  string  $value  The value to check.
     * @param  int  $maxLength  The maximum allowed length.
     * @param  string  $name  The parameter/field name (for error message).
     * @return void
     *
     * @throws InvalidArgumentDomainException When the string exceeds maxLength.
     *
     * @example
     * ```php
     * $this->assertMaxLength($name, 255, 'name');
     * ```
     */
    protected function assertMaxLength(string $value, int $maxLength, string $name): void
    {
        if (mb_strlen($value) > $maxLength) {
            throw InvalidArgumentDomainException::because(
                sprintf('"%s" must not exceed %d characters, got %d.', $name, $maxLength, mb_strlen($value)),
                code: 'STRING_TOO_LONG',
            );
        }
    }

    /**
     * Assert that an integer value is positive (greater than zero).
     *
     * @param  int  $value  The value to check.
     * @param  string  $name  The parameter/field name (for error message).
     * @return void
     *
     * @throws InvalidArgumentDomainException When the integer is not positive.
     *
     * @example
     * ```php
     * $this->assertPositiveInteger($quantity, 'quantity');
     * ```
     */
    protected function assertPositiveInteger(int $value, string $name): void
    {
        if ($value <= 0) {
            throw InvalidArgumentDomainException::because(
                sprintf('"%s" must be a positive integer, got %d.', $name, $value),
                code: 'NON_POSITIVE_INTEGER',
            );
        }
    }

    /**
     * Assert that an integer value is non-negative (zero or positive).
     *
     * @param  int  $value  The value to check.
     * @param  string  $name  The parameter/field name (for error message).
     * @return void
     *
     * @throws InvalidArgumentDomainException When the integer is negative.
     *
     * @example
     * ```php
     * $this->assertNonNegativeInteger($balance, 'balance');
     * ```
     */
    protected function assertNonNegativeInteger(int $value, string $name): void
    {
        if ($value < 0) {
            throw InvalidArgumentDomainException::because(
                sprintf('"%s" must be zero or positive, got %d.', $name, $value),
                code: 'NEGATIVE_INTEGER',
            );
        }
    }

    /**
     * Assert that a value is not null.
     *
     * @param  mixed  $value  The value to check.
     * @param  string  $name  The parameter/field name (for error message).
     * @return void
     *
     * @throws InvalidArgumentDomainException When the value is null.
     *
     * @example
     * ```php
     * $this->assertNotNull($user, 'user');
     * ```
     */
    protected function assertNotNull(mixed $value, string $name): void
    {
        if ($value === null) {
            throw InvalidArgumentDomainException::because(
                sprintf('"%s" must not be null.', $name),
                code: 'NULL_VALUE',
            );
        }
    }

    /**
     * Assert that an entity or value object exists (is not null).
     *
     * Alias for {@see assertNotNull()} with NotFoundDomainException semantics.
     * Use this in repository/query methods where a missing entity is a
     * domain-level error (not an argument validation error).
     *
     * @param  mixed  $value  The value to check.
     * @param  string  $name  The entity/field name (for error message).
     * @return void
     *
     * @throws NotFoundDomainException When the value is null.
     *
     * @example
     * ```php
     * $order = $this->orderRepository->find($orderId);
     * $this->assertFound($order, 'Order');
     * ```
     */
    protected function assertFound(mixed $value, string $name): void
    {
        if ($value === null) {
            throw NotFoundDomainException::because(
                sprintf('%s was not found.', $name),
            );
        }
    }

    /**
     * Assert that the current state matches an expected value.
     *
     * Use this in entity/aggregate business methods to enforce state-based
     * invariants (e.g., "can only pay if status is 'pending'").
     *
     * @param  string  $expected  The expected state value.
     * @param  string  $actual  The current state value.
     * @param  string  $action  The action being attempted (for error message).
     * @return void
     *
     * @throws InvalidStateDomainException When the actual state does not match.
     *
     * @example
     * ```php
     * $this->assertStateIs('pending', $this->status, 'pay');
     * // Throws: "Cannot pay — order must be in 'pending' state, got 'shipped'."
     * ```
     */
    protected function assertStateIs(string $expected, string $actual, string $action): void
    {
        if ($actual !== $expected) {
            throw InvalidStateDomainException::because(
                sprintf(
                    'Cannot %s — must be in "%s" state, got "%s".',
                    $action,
                    $expected,
                    $actual,
                ),
            );
        }
    }

    /**
     * Assert that the current state is one of the allowed values.
     *
     * Use this when multiple states are valid for an action.
     *
     * @param  array<int, string>  $allowed  The allowed state values.
     * @param  string  $actual  The current state value.
     * @param  string  $action  The action being attempted (for error message).
     * @return void
     *
     * @throws InvalidStateDomainException When the actual state is not in the allowed list.
     *
     * @example
     * ```php
     * $this->assertStateIn(['pending', 'confirmed'], $this->status, 'cancel');
     * ```
     */
    protected function assertStateIn(array $allowed, string $actual, string $action): void
    {
        if (! in_array($actual, $allowed, true)) {
            throw InvalidStateDomainException::because(
                sprintf(
                    'Cannot %s — must be in one of [%s] state, got "%s".',
                    $action,
                    implode(', ', array_map(static fn (string $s): string => '"' . $s . '"', $allowed)),
                    $actual,
                ),
            );
        }
    }

    /**
     * Assert that the current state is NOT a specific disallowed value.
     *
     * @param  string  $disallowed  The disallowed state value.
     * @param  string  $actual  The current state value.
     * @param  string  $action  The action being attempted (for error message).
     * @return void
     *
     * @throws InvalidStateDomainException When the actual state matches the disallowed value.
     *
     * @example
     * ```php
     * $this->assertStateIsNot('cancelled', $this->status, 'ship');
     * ```
     */
    protected function assertStateIsNot(string $disallowed, string $actual, string $action): void
    {
        if ($actual === $disallowed) {
            throw InvalidStateDomainException::because(
                sprintf(
                    'Cannot %s — must not be in "%s" state.',
                    $action,
                    $disallowed,
                ),
            );
        }
    }

    /**
     * Assert that a numeric value is within a specified range (inclusive).
     *
     * @param  int|float  $value  The value to check.
     * @param  int|float  $min  The minimum allowed value (inclusive).
     * @param  int|float  $max  The maximum allowed value (inclusive).
     * @param  string  $name  The parameter/field name (for error message).
     * @return void
     *
     * @throws InvalidArgumentDomainException When the value is outside the range.
     *
     * @example
     * ```php
     * $this->assertRange($amount, 0.01, 99999.99, 'amount');
     * ```
     */
    protected function assertRange(int|float $value, int|float $min, int|float $max, string $name): void
    {
        if ($value < $min || $value > $max) {
            throw InvalidArgumentDomainException::because(
                sprintf('"%s" must be between %s and %s, got %s.', $name, $min, $max, $value),
                code: 'OUT_OF_RANGE',
            );
        }
    }

    /**
     * Assert that a value is one of a list of allowed values.
     *
     * Useful for enum-like validation without backed enums, or for validating
     * user input against a known set of valid options.
     *
     * @param  array<int, string>  $allowed  The allowed values.
     * @param  string  $actual  The value to check.
     * @param  string  $name  The parameter/field name (for error message).
     * @return void
     *
     * @throws InvalidArgumentDomainException When the value is not in the allowed list.
     *
     * @example
     * ```php
     * $this->assertIn(['USD', 'EUR', 'GBP'], $currency, 'currency');
     * ```
     */
    protected function assertIn(array $allowed, string $actual, string $name): void
    {
        if (! in_array($actual, $allowed, true)) {
            throw InvalidArgumentDomainException::because(
                sprintf(
                    '"%s" must be one of [%s], got "%s".',
                    $name,
                    implode(', ', array_map(static fn (string $s): string => '"' . $s . '"', $allowed)),
                    $actual,
                ),
                code: 'INVALID_OPTION',
            );
        }
    }
}
