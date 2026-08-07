<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Identifiers;

use JsonSerializable;
use ValueError;
use ZeroBoiler\Domain\Contracts\Identifier as IdentifierContract;

/**
 * Readonly string identifier for domain entities.
 *
 * Enforces non-empty strings at construction time, ensuring identifiers
 * are always meaningful. Ideal for slugs, codes, and natural keys.
 *
 * @implements IdentifierContract
 *
 * @example
 * ```php
 * $slug = StringIdentifier::from('my-blog-post');
 * $slug->toString();   // 'my-blog-post'
 * $slug->isValid('');  // false
 * ```
 */
readonly class StringIdentifier implements IdentifierContract, JsonSerializable
{
    /**
     * Create a validated non-empty string identifier.
     *
     * @param  string  $value  A non-empty string identifier.
     *
     * @throws ValueError If the value is an empty string.
     */
    public function __construct(
        public string $value,
    ) {
        if ($value === '') {
            throw new ValueError('String identifier cannot be empty');
        }
    }

    /**
     * Create an identifier from a non-empty string.
     *
     * @param  string  $value  A non-empty string.
     * @return static
     *
     * @throws ValueError If the value is an empty string.
     */
    public static function from(string $value): static
    {
        return new static($value);
    }

    /**
     * Validate whether a string is a valid non-empty identifier without throwing.
     *
     * @param  string  $value  The string to validate.
     * @return bool True if the string is non-empty.
     */
    public static function isValid(string $value): bool
    {
        return $value !== '';
    }

    /**
     * Create an identifier from a string (IdentifierContract interface).
     *
     * @param  string  $value  A non-empty string.
     * @return static
     *
     * @throws ValueError If the value is an empty string.
     */
    public static function fromString(string $value): static
    {
        return static::from($value);
    }

    /**
     * Check equality with another identifier.
     *
     * Two string identifiers are equal if and only if they are of the same
     * concrete class and their string values are identical. Subclass instances
     * are never equal, even with the same value.
     *
     * @param  IdentifierContract  $other  The identifier to compare against.
     * @return bool True if both are the same concrete class with identical values.
     */
    public function equals(IdentifierContract $other): bool
    {
        return $other instanceof StringIdentifier && $other::class === static::class && $this->value === $other->value;
    }

    /**
     * Get the string representation of the identifier.
     *
     * @return string The identifier string.
     */
    public function toString(): string
    {
        return $this->value;
    }

    /**
     * Serialize the identifier to JSON.
     *
     * @return string The identifier string.
     */
    public function jsonSerialize(): string
    {
        return $this->value;
    }

    /**
     * @return string The identifier string.
     */
    public function __toString(): string
    {
        return $this->value;
    }
}
