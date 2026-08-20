<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain;

use JsonSerializable;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

/**
 * Immutable UUID v4 identity for aggregate roots.
 *
 * Each aggregate root has exactly one AggregateRootId, generated
 * at creation time. The ID is stored as a Ramsey UuidInterface for
 * interoperability with the broader UUID ecosystem.
 *
 * @see \Stringable Used for automatic string coercion (`echo $id`).
 * @see \JsonSerializable Used for `json_encode()` serialization.
 *
 * @since 1.0.0
 *
 * @example
 * ```php
 * $id = AggregateRootId::generate();          // Random UUID v4
 * $id = AggregateRootId::fromString('...');   // Parse existing UUID
 * $id->isValid('not-a-uuid');                 // false
 * $id->toString();                             // '550e8400-e29b-41d4-...'
 * $id->equals($otherId);                      // Type-safe equality
 * echo json_encode(['order_id' => $id]);
 * // → {"order_id": "550e8400-e29b-41d4-a716-446655440000"}
 * $id->toArray();                              // ['uuid' => '550e8400-...']
 * AggregateRootId::fromArray($id->toArray()); // Round-trip
 * ```
 */
final readonly class AggregateRootId implements \Stringable, JsonSerializable
{
    public function __construct(public UuidInterface $value) {}

    /**
     * Generate a new random UUID v4 aggregate root ID.
     *
     * @return self A new instance with a random UUID v4 value.
     */
    public static function generate(): self
    {
        return new self(Uuid::uuid4());
    }

    /**
     * Validate whether a string is a valid UUID without throwing.
     *
     * @param  string  $value  The string to validate.
     * @return bool True if the string is a valid UUID.
     */
    public static function isValid(string $value): bool
    {
        return Uuid::isValid($value);
    }

    /**
     * Create an aggregate root ID from an existing UUID string.
     *
     * @param  string  $id  A valid UUID string.
     * @return self
     *
     * @throws \Ramsey\Uuid\Exception\InvalidUuidStringException If the string is not a valid UUID.
     */
    public static function fromString(string $id): self
    {
        return new self(Uuid::fromString($id));
    }

    /**
     * Get the string representation of the aggregate root ID.
     *
     * @return string The UUID as a canonical string.
     */
    public function toString(): string
    {
        return $this->value->toString();
    }

    /**
     * Check equality with another aggregate root ID.
     *
     * @param  AggregateRootId  $other  The ID to compare against.
     * @return bool True if both IDs have the same UUID value.
     */
    public function equals(AggregateRootId $other): bool
    {
        return $this->value->equals($other->value);
    }

    /**
     * Serialize the identifier to JSON.
     *
     * @return string The UUID as a canonical string.
     */
    #[\Override]
    public function jsonSerialize(): string
    {
        return $this->toString();
    }

    /**
     * Convert the aggregate root ID to a JSON string.
     *
     * Convenience method for explicit JSON serialization without passing
     * to `json_encode()`. Uses `JSON_THROW_ON_ERROR` for safety.
     * Encodes the full `toArray()` representation (not just the UUID string)
     * for consistent round-trip with `fromJson()`.
     *
     * @param  int  $options  JSON encoding options bitmask (default: JSON_UNESCAPED_UNICODE).
     * @return string The JSON-encoded aggregate root ID representation.
     *
     * @since 1.66.0
     *
     * @example
     * ```php
     * $id = AggregateRootId::generate();
     * $json = $id->toJson();
     * $restored = AggregateRootId::fromJson($json);
     * $id->equals($restored); // true
     * ```
     */
    public function toJson(int $options = JSON_UNESCAPED_UNICODE): string
    {
        return json_encode($this->toArray(), $options | JSON_THROW_ON_ERROR);
    }

    /**
     * @return string The UUID as a canonical string.
     */
    public function __toString(): string
    {
        return $this->toString();
    }

    /**
     * Serialize for PHP's native serialize().
     *
     * @return array{uuid: string}
     *
     * @see https://www.php.net/manual/en/language.oop5.magic.php#object.serialize
     * @since 2.12.0
     */
    public function __serialize(): array
    {
        return $this->toArray();
    }

    /**
     * Reconstruct from PHP's native unserialize().
     *
     * Uses reflection to set the readonly property, as PHP requires
     * unsetting a readonly property before re-initializing it.
     *
     * @param  array{uuid?: string, id?: string}  $data
     * @return void
     *
     * @see https://www.php.net/manual/en/language.oop5.magic.php#object.unserialize
     * @since 2.12.0
     */
    public function __unserialize(array $data): void
    {
        $uuid = $data['uuid'] ?? $data['id'] ?? null;

        if (! is_string($uuid) || ! self::isValid($uuid)) {
            throw new \InvalidArgumentException(
                sprintf('Cannot unserialize AggregateRootId: invalid or missing UUID, got %s.', get_debug_type($uuid)),
            );
        }

        $property = (new \ReflectionClass(self::class))->getProperty('value');
        $property->setValue($this, Uuid::fromString($uuid));
    }

    /**
     * Convert the aggregate root ID to an array representation.
     *
     * Provides a consistent serialization format for caching, persistence,
     * and cross-package data exchange. The UUID is stored under the
     * `uuid` key for clarity and to avoid ambiguity with generic `id`.
     *
     * @return array{uuid: string}
     *
     * @example
     * ```php
     * $id = AggregateRootId::generate();
     * $id->toArray();
     * // ['uuid' => '550e8400-e29b-41d4-a716-446655440000']
     * ```
     */
    public function toArray(): array
    {
        return ['uuid' => $this->toString()];
    }

    /**
     * Reconstruct an aggregate root ID from an array representation.
     *
     * Accepts the output of {@see toArray()} for full round-trip support.
     * Also accepts a plain UUID string under the `uuid` or `id` key
     * for maximum deserialization flexibility.
     *
     * @param  array{uuid?: string, id?: string}  $array  The array from `toArray()`.
     * @return self A new AggregateRootId instance.
     *
     * @throws \InvalidArgumentException If neither `uuid` nor `id` key is present or valid.
     *
     * @example
     * ```php
     * $id = AggregateRootId::generate();
     * $restored = AggregateRootId::fromArray($id->toArray());
     * $id->equals($restored); // true
     * ```
     */
    public static function fromArray(array $array): self
    {
        $uuid = $array['uuid'] ?? $array['id'] ?? null;

        if (! is_string($uuid)) {
            throw new \InvalidArgumentException(
                sprintf('AggregateRootId::fromArray() expects a "uuid" or "id" string key, got %s.', get_debug_type($uuid)),
            );
        }

        return self::fromString($uuid);
    }

    /**
     * Create an AggregateRootId from a JSON string.
     *
     * Parses the JSON and delegates to {@see fromArray()} for hydration.
     * Enables round-trip serialization via `json_encode()` → `fromJson()`.
     *
     * @param  string  $json  A valid JSON object string.
     * @return self A new AggregateRootId instance.
     *
     * @throws \JsonException If the JSON string is invalid.
     * @throws \InvalidArgumentException If the JSON does not decode to an array.
     *
     * @example
     * ```php
     * $id = AggregateRootId::generate();
     * $json = json_encode($id->toArray());
     * $restored = AggregateRootId::fromJson($json);
     * $id->equals($restored); // true
     * ```
     */
    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($data)) {
            throw new \InvalidArgumentException('JSON must decode to an array/object.');
        }

        return self::fromArray($data);
    }
}

