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
 * @implements JsonSerializable
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
}
