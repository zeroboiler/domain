<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Identifiers;

use JsonSerializable;
use Ramsey\Uuid\Uuid as RamseyUuid;
use Ramsey\Uuid\UuidInterface;
use ZeroBoiler\Domain\Contracts\Identifier as IdentifierContract;

/**
 * Abstract readonly UUID v4 identifier for domain entities.
 *
 * Provides immutable, validated UUID-based identity. Subclass this
 * to create domain-specific identifiers (e.g., `OrderId extends UuidIdentifier`).
 *
 * Validation is performed in the constructor — invalid UUIDs throw
 * an exception at construction time, ensuring identifiers are always valid.
 *
 * @implements IdentifierContract
 *
 * @example
 * ```php
 * class OrderId extends UuidIdentifier {}
 * $id = OrderId::generate();          // Random UUID v4
 * $id = OrderId::fromString('...');  // Parse existing UUID
 * $id->equals($otherId);             // Type-safe equality
 * $id->toUuid();                      // Ramsey UuidInterface
 * $id->isValid('not-a-uuid');         // false
 * $id->toArray();                     // ['uuid' => '550e8400-...']
 * ```
 */
abstract readonly class UuidIdentifier implements IdentifierContract, JsonSerializable
{
    /**
     * Create a validated UUID identifier.
     *
     * The constructor validates the string is a proper UUID. Invalid strings
     * will throw a Ramsey/Uuid/Exception/InvalidUuidStringException.
     *
     * @param  string  $value  A valid UUID string.
     */
    public function __construct(
        public string $value,
    ) {
        RamseyUuid::fromString($value);
    }

    /**
     * Generate a new random UUID v4 identifier.
     *
     * @return static A new instance with a random UUID v4 value.
     */
    public static function generate(): static
    {
        return new static(RamseyUuid::uuid4()->toString());
    }

    /**
     * Validate whether a string is a valid UUID without throwing.
     *
     * @param  string  $value  The string to validate.
     * @return bool True if the string is a valid UUID.
     */
    public static function isValid(string $value): bool
    {
        return RamseyUuid::isValid($value);
    }

    /**
     * Create an identifier from an existing UUID string.
     *
     * @param  string  $value  A valid UUID string.
     * @return static
     *
     * @throws \Ramsey\Uuid\Exception\InvalidUuidStringException If the string is not a valid UUID.
     */
    public static function fromString(string $value): static
    {
        return new static($value);
    }

    /**
     * Get the string representation of the identifier.
     *
     * @return string The UUID as a canonical string.
     */
    public function toString(): string
    {
        return $this->value;
    }

    /**
     * Check equality with another identifier.
     *
     * Two UUID identifiers are equal if and only if they are of the same
     * concrete class and their UUID string values are identical. Subclass
     * instances (e.g., OrderId vs ProductId) are never equal, even with
     * the same UUID value.
     *
     * @param  IdentifierContract  $other  The identifier to compare against.
     * @return bool True if both are the same concrete class with identical UUID values.
     */
    public function equals(IdentifierContract $other): bool
    {
        return $other instanceof UuidIdentifier && $other::class === static::class && $this->value === $other->value;
    }

    /**
     * Get the underlying Ramsey UUID object.
     *
     * Useful for accessing UUID metadata (version, variant, timestamp).
     *
     * @return UuidInterface The Ramsey UUID instance.
     */
    public function toUuid(): UuidInterface
    {
        return RamseyUuid::fromString($this->value);
    }

    /**
     * Serialize the identifier to JSON.
     *
     * @return string The UUID string.
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
     * and cross-package data exchange. The UUID is stored under the
     * `uuid` key for clarity and to avoid ambiguity with generic `id`.
     *
     * @return array{uuid: string}
     *
     * @example
     * ```php
     * $id = OrderId::generate();
     * $id->toArray();       // ['uuid' => '550e8400-...']
     * ```
     */
    public function toArray(): array
    {
        return ['uuid' => $this->value];
    }

    /**
     * Reconstruct an identifier from an array representation.
     *
     * Accepts the output of {@see toArray()} for full round-trip support.
     * Also accepts a plain UUID string under the `uuid` or `id` key
     * for maximum deserialization flexibility.
     *
     * @param  array{uuid?: string, id?: string}  $array  The array from `toArray()`.
     * @return static A new identifier instance.
     *
     * @throws \InvalidArgumentException If neither `uuid` nor `id` key is present or valid.
     *
     * @example
     * ```php
     * $id = OrderId::fromArray(['uuid' => '550e8400-...']);
     * $restored = OrderId::fromArray($id->toArray());
     * $id->equals($restored); // true
     * ```
     */
    public static function fromArray(array $array): static
    {
        $uuid = $array['uuid'] ?? $array['id'] ?? null;

        if (! is_string($uuid)) {
            throw new \InvalidArgumentException(
                sprintf('%s::fromArray() expects a "uuid" or "id" string key, got %s.', static::class, get_debug_type($uuid)),
            );
        }

        return static::fromString($uuid);
    }

    /**
     * Get the string representation of the identifier.
     *
     * @return string The UUID as a canonical string.
     */
    public function __toString(): string
    {
        return $this->toString();
    }
}
