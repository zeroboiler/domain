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
 * @implements \Stringable
 * @implements \JsonSerializable
 *
 * @example
 * ```php
 * $id = AggregateRootId::generate();          // Random UUID v4
 * $id = AggregateRootId::fromString('...');   // Parse existing UUID
 * $id->toString();                             // '550e8400-e29b-41d4-...'
 * $id->equals($otherId);                      // Type-safe equality
 * echo json_encode(['order_id' => $id]);
 * // → {"order_id": "550e8400-e29b-41d4-a716-446655440000"}
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
     * @return string The UUID as a canonical string.
     */
    public function __toString(): string
    {
        return $this->toString();
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
}
