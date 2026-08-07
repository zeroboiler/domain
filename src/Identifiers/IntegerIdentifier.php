<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Identifiers;

use JsonSerializable;
use ZeroBoiler\Domain\Contracts\Identifier as IdentifierContract;

/**
 * Final readonly integer identifier for domain entities.
 *
 * Ideal for auto-increment database IDs, sequence numbers, and
 * numeric natural keys.
 *
 * @implements IdentifierContract
 *
 * @example
 * ```php
 * $id = IntegerIdentifier::from(42);
 * $id->toString();     // '42'
 * $id->toInt();        // 42
 * $id->isValid('abc'); // false
 * echo json_encode(['order_id' => $id]);
 * // → {"order_id": 42}
 * ```
 */
final readonly class IntegerIdentifier implements IdentifierContract, JsonSerializable
{
    /**
     * Create an integer identifier.
     *
     * @param  int  $value  The integer value.
     */
    public function __construct(public int $value) {}

    /**
     * Create an identifier from an integer.
     *
     * @param  int  $value  The integer value.
     * @return self
     */
    public static function from(int $value): self
    {
        return new self($value);
    }

    /**
     * Validate whether a string represents a valid integer without throwing.
     *
     * Accepts positive integers, negative integers, and zero.
     *
     * @param  string  $value  The string to validate.
     * @return bool True if the string represents a valid integer.
     */
    public static function isValid(string $value): bool
    {
        return ctype_digit($value) || (str_starts_with($value, '-') && ctype_digit(substr($value, 1)));
    }

    /**
     * Create an identifier from a string representation (IdentifierContract interface).
     *
     * @param  string  $value  A string that represents an integer.
     * @return static
     */
    public static function fromString(string $value): static
    {
        return new self((int) $value);
    }

    /**
     * Get the integer value.
     *
     * @return int The integer value.
     */
    public function toInt(): int
    {
        return $this->value;
    }

    /**
     * Get the string representation of the identifier.
     *
     * @return string The integer as a string.
     */
    public function toString(): string
    {
        return (string) $this->value;
    }

    /**
     * Check equality with another identifier.
     *
     * Two integer identifiers are equal if and only if they are of the same
     * concrete class and their integer values are identical.
     *
     * @param  IdentifierContract  $other  The identifier to compare against.
     * @return bool True if both are the same concrete class with identical values.
     */
    public function equals(IdentifierContract $other): bool
    {
        return $other instanceof IntegerIdentifier && $other::class === static::class && $this->value === $other->value;
    }

    /**
     * Serialize the identifier to JSON.
     *
     * Returns the integer value directly for clean API serialization.
     *
     * @return int The integer value.
     */
    public function jsonSerialize(): int
    {
        return $this->value;
    }

    /**
     * @return string The integer as a string.
     */
    public function __toString(): string
    {
        return $this->toString();
    }
}
