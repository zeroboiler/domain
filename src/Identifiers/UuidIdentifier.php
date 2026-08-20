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
 * @see IdentifierContract Cross-package identifier contract.
 * @see \JsonSerializable Provides `json_encode()` serialization.
 *
 * @since 1.0.0
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
    #[\Override]
    public static function fromString(string $value): static
    {
        return new static($value);
    }

    /**
     * Get the string representation of the identifier.
     *
     * @return string The UUID as a canonical string.
     */
    #[\Override]
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
    #[\Override]
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
    #[\Override]
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
    #[\Override]
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
     * Create an identifier from a JSON string.
     *
     * Parses the JSON and delegates to {@see fromArray()} for hydration.
     * Enables round-trip serialization via `json_encode()` → `fromJson()`.
     *
     * @param  string  $json  A valid JSON object string.
     * @return static A new identifier instance.
     *
     * @throws \JsonException If the JSON string is invalid.
     * @throws \InvalidArgumentException If the JSON does not decode to an array.
     *
     * @example
     * ```php
     * $id = OrderId::generate();
     * $json = json_encode($id->toArray());
     * $restored = OrderId::fromJson($json);
     * $id->equals($restored); // true
     * ```
     */
    #[\Override]
    public static function fromJson(string $json): static
    {
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($data)) {
            throw new \InvalidArgumentException('JSON must decode to an array/object.');
        }

        return static::fromArray($data);
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

    /**
     * Convert the identifier to a JSON string.
     *
     * @param  int  $options  JSON encoding options bitmask (default: JSON_UNESCAPED_UNICODE).
     * @return string The JSON-encoded identifier representation.
     *
     * @since 1.64.0
     */
    public function toJson(int $options = JSON_UNESCAPED_UNICODE): string
    {
        return json_encode($this->toArray(), $options | JSON_THROW_ON_ERROR);
    }

    /**
     * Serialize for PHP's native serialize().
     *
     * @return array{uuid: string}
     *
     * @since 2.12.0
     */
    public function __serialize(): array
    {
        return $this->toArray();
    }

    /**
     * Reconstruct from PHP's native unserialize().
     *
     * Uses reflection to set the readonly property after construction.
     * Unsets the property first if already initialized (PHP 8.5 pattern)
     * to handle edge cases during re-serialization.
     *
     * @param  array{uuid?: string, id?: string}  $data
     * @return void
     *
     * @since 2.12.0
     */
    public function __unserialize(array $data): void
    {
        $uuid = $data['uuid'] ?? $data['id'] ?? null;

        if (! is_string($uuid) || ! self::isValid($uuid)) {
            throw new \InvalidArgumentException(
                sprintf('Cannot unserialize %s: invalid or missing UUID, got %s.', static::class, get_debug_type($uuid)),
            );
        }

        $property = (new \ReflectionClass(static::class))->getProperty('value');

        if ($property->isInitialized($this)) {
            unset($this->value);
        }

        $property->setValue($this, $uuid);
    }
}
