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
 * $id->toArray();                        // ['ulid' => '01H...']
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
     * Two ULID identifiers are equal if and only if they are of the same
     * concrete class and their ULID string values are identical. Subclass
     * instances (e.g., ProductId vs CategoryId) are never equal.
     *
     * @param  IdentifierContract  $other  The identifier to compare against.
     * @return bool True if both are the same concrete class with identical ULID values.
     */
    public function equals(IdentifierContract $other): bool
    {
        return $other instanceof UlidIdentifier && $other::class === static::class && $this->value === $other->value;
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
    #[\Override]
    public function jsonSerialize(): string
    {
        return $this->value;
    }

    /**
     * Convert the identifier to an array representation.
     *
     * Provides consistent serialization for caching, persistence,
     * and cross-package data exchange. The ULID is stored under the
     * `ulid` key for clarity and to avoid ambiguity with generic `id`.
     *
     * @return array{ulid: string}
     *
     * @example
     * ```php
     * $id = ProductId::generate();
     * $id->toArray();       // ['ulid' => '01H...']
     * ```
     */
    public function toArray(): array
    {
        return ['ulid' => $this->value];
    }

    /**
     * Reconstruct an identifier from an array representation.
     *
     * Accepts the output of {@see toArray()} for full round-trip support.
     * Also accepts a plain ULID string under the `ulid` or `id` key
     * for maximum deserialization flexibility.
     *
     * @param  array{ulid?: string, id?: string}  $array  The array from `toArray()`.
     * @return static A new identifier instance.
     *
     * @throws \InvalidArgumentException If neither `ulid` nor `id` key is present or valid.
     *
     * @example
     * ```php
     * $id = ProductId::fromArray(['ulid' => '01H...']);
     * $restored = ProductId::fromArray($id->toArray());
     * $id->equals($restored); // true
     * ```
     */
    public static function fromArray(array $array): static
    {
        $ulid = $array['ulid'] ?? $array['id'] ?? null;

        if (! is_string($ulid)) {
            throw new \InvalidArgumentException(
                sprintf('%s::fromArray() expects an "ulid" or "id" string key, got %s.', static::class, get_debug_type($ulid)),
            );
        }

        return static::fromString($ulid);
    }

    /**
     * @return string The ULID as a base32-encoded string.
     */
    public function __toString(): string
    {
        return $this->toString();
    }
}
