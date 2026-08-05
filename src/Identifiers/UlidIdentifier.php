<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Identifiers;

use JsonSerializable;
use Symfony\Component\Uid\Ulid as SymfonyUlid;
use ZeroBoiler\Domain\Contracts\Identifier as IdentifierContract;

/**
 * Abstract readonly ULID identifier for domain entities.
 *
 * ULIDs are monotonic — each new ULID is lexicographically sortable and
 * can be used as both a unique identifier and a timestamp. Ideal for
 * high-throughput systems where insertion order matters.
 *
 * @implements IdentifierContract
 *
 * @example
 * ```php
 * class ProductId extends UlidIdentifier {}
 * $id = ProductId::generate();          // Monotonic ULID
 * $id = ProductId::fromString('...');  // Parse existing ULID
 * $id->toUlid();                        // Symfony Ulid object
 * $id->isValid('...');                  // Pre-validate without throwing
 * ```
 */
abstract readonly class UlidIdentifier implements IdentifierContract, JsonSerializable
{
    /**
     * Create a validated ULID identifier.
     *
     * The constructor validates the string is a proper ULID. Invalid strings
     * will throw an InvalidArgumentException.
     *
     * @param  string  $value  A valid ULID string.
     */
    public function __construct(
        public string $value,
    ) {
        SymfonyUlid::fromString($value);
    }

    /**
     * Generate a new monotonic ULID identifier.
     *
     * @return static A new instance with a monotonic ULID value.
     */
    public static function generate(): static
    {
        return new static((new SymfonyUlid)->toBase32());
    }

    /**
     * Validate whether a string is a valid ULID without throwing.
     *
     * @param  string  $value  The string to validate.
     * @return bool True if the string is a valid ULID.
     */
    public static function isValid(string $value): bool
    {
        try {
            SymfonyUlid::fromString($value);

            return true;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    /**
     * Create an identifier from an existing ULID string.
     *
     * @param  string  $value  A valid ULID string.
     * @return static
     *
     * @throws \InvalidArgumentException If the string is not a valid ULID.
     */
    public static function fromString(string $value): static
    {
        return new static($value);
    }

    /**
     * Get the string representation of the identifier.
     *
     * @return string The ULID as a base32-encoded string.
     */
    public function toString(): string
    {
        return $this->value;
    }

    /**
     * Check equality with another identifier.
     *
     * @param  IdentifierContract  $other  The identifier to compare against.
     * @return bool True if both identifiers are ULIDs with the same value.
     */
    public function equals(IdentifierContract $other): bool
    {
        return $other instanceof UlidIdentifier && $this->value === $other->value;
    }

    /**
     * Get the underlying Symfony ULID object.
     *
     * Useful for accessing ULID metadata (timestamp, isMonotonic).
     *
     * @return SymfonyUlid The Symfony ULID instance.
     */
    public function toUlid(): SymfonyUlid
    {
        return SymfonyUlid::fromString($this->value);
    }

    /**
     * Serialize the identifier to JSON.
     *
     * @return string The ULID string.
     */
    public function jsonSerialize(): string
    {
        return $this->value;
    }

    /**
     * @return string The ULID as a base32-encoded string.
     */
    public function __toString(): string
    {
        return $this->toString();
    }
}
