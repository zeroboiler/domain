<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Identifiers;

use JsonSerializable;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use ZeroBoiler\Domain\Contracts\Identifier as IdentifierContract;

/**
 * Base UUID identifier for domain entities.
 *
 * Provides a concrete base class for UUID-based identifiers.
 * Subclasses must be declared `readonly` and provide a string-backed
 * constructor to maintain immutability.
 *
 * For new code, prefer the dedicated identifier types:
 * - {@see UuidIdentifier} — abstract readonly, UUID v4
 * - {@see UlidIdentifier} — abstract readonly, ULID
 * - {@see StringIdentifier} — readonly, non-empty string
 * - {@see IntegerIdentifier} — final readonly, integer
 *
 * @see UuidIdentifier
 * @see IdentifierContract
 *
 * @since 1.0.0
 *
 * @deprecated Use {@see UuidIdentifier} for new code. This class will be removed in v3.0.
 */
#[Deprecated(message: 'Use UuidIdentifier for new code. This class will be removed in v3.0.', since: '2.5.0')]
abstract class Identifier implements IdentifierContract, JsonSerializable
{
    /**
     * The underlying Ramsey UUID value.
     *
     * Note: This property is public for backward compatibility with the
     * deprecated Identifier base class. New code should use {@see UuidIdentifier}
     * instead, which keeps the value truly readonly.
     *
     * @deprecated Use {@see UuidIdentifier::value} instead.
     */
    #[Deprecated(message: 'Use UuidIdentifier::value instead.', since: '2.5.0')]
    public UuidInterface $value;

    /**
     * Create a new identifier, generating a random UUID v4 if none provided.
     *
     * @param  UuidInterface|null  $value  The UUID value, or null to generate.
     */
    public function __construct(?UuidInterface $value = null)
    {
        $this->value = $value ?? Uuid::uuid4();
    }

    /**
     * Validate whether a string is a valid UUID without throwing.
     *
     * Useful for pre-validation before calling fromString().
     *
     * @param  string  $value  The string to validate.
     * @return bool True if the string is a valid UUID.
     */
    public static function isValid(string $value): bool
    {
        return Uuid::isValid($value);
    }

    /**
     * Create an identifier from an existing UUID string.
     *
     * @param  string  $value  A valid UUID string.
     * @return static
     *
     * @throws \InvalidArgumentException If the string is not a valid UUID.
     */
    public static function fromString(string $value): static
    {
        return new static(Uuid::fromString($value));
    }

    /**
     * Get the string representation of the identifier.
     *
     * @return string The UUID as a canonical string.
     */
    public function toString(): string
    {
        return $this->value->toString();
    }

    /**
     * Check equality with another identifier.
     *
     * Two identifiers are equal if and only if they are of the same
     * concrete class and their UUID values are identical. Subclass
     * instances (e.g., OrderId vs ProductId) are never equal, even with
     * the same UUID value. This matches the semantics of UuidIdentifier,
     * UlidIdentifier, StringIdentifier, and IntegerIdentifier.
     *
     * @param  IdentifierContract  $other  The identifier to compare against.
     * @return bool True if both are the same concrete class with identical UUID values.
     */
    public function equals(IdentifierContract $other): bool
    {
        return $other instanceof Identifier
            && $other::class === static::class
            && $this->value->equals($other->value);
    }

    /**
     * Get the underlying Ramsey UUID object.
     *
     * Use this when you need access to the UUID methods
     * (e.g., getVariant(), getVersion(), toDateTime()).
     *
     * @return UuidInterface The underlying Ramsey UUID object.
     */
    public function toUuid(): UuidInterface
    {
        return $this->value;
    }

    /**
     * @return string The UUID as a canonical string.
     */
    public function __toString(): string
    {
        return $this->toString();
    }

    /**
     * Serialize the identifier to JSON.
     *
     * @return string The UUID as a canonical string.
     */
    public function jsonSerialize(): string
    {
        return $this->toString();
    }

    /**
     * Convert the identifier to an array for serialization.
     *
     * @return array{value: string}
     */
    public function toArray(): array
    {
        return ['value' => $this->toString()];
    }

    /**
     * Restore an identifier from an array (round-trip with toArray()).
     *
     * @param  array{value: string}  $data  The serialized identifier data.
     * @return static
     *
     * @throws \InvalidArgumentException If the data is missing the 'value' key or contains an invalid UUID.
     */
    public static function fromArray(array $data): static
    {
        if (! isset($data['value']) || ! is_string($data['value'])) {
            throw new \InvalidArgumentException(
                sprintf('Invalid identifier data: expected "value" string key, got %s.', json_encode(array_keys($data))),
            );
        }

        return self::fromString($data['value']);
    }

    /**
     * Convert the identifier to a JSON string.
     *
     * Convenience method for explicit JSON serialization without passing
     * to `json_encode()`. Uses `JSON_THROW_ON_ERROR` for safety.
     *
     * @param  int  $options  JSON encoding options bitmask (default: JSON_UNESCAPED_UNICODE).
     * @return string The JSON-encoded identifier representation.
     *
     * @since 1.66.0
     */
    public function toJson($options = JSON_UNESCAPED_UNICODE): string
    {
        return json_encode($this->toArray(), $options | JSON_THROW_ON_ERROR);
    }

    /**
     * Create an Identifier from a JSON string.
     *
     * Parses the JSON and delegates to {@see fromArray()} for hydration.
     * Enables round-trip serialization via `json_encode()` → `fromJson()`.
     *
     * @param  string  $json  A valid JSON object string.
     * @return static A new Identifier instance.
     *
     * @throws \JsonException If the JSON string is invalid.
     * @throws \InvalidArgumentException If the JSON does not decode to an array.
     *
     * @example
     * ```php
     * // Identifier is abstract — use a concrete subclass:
     * class OrderId extends \ZeroBoiler\Domain\Identifiers\Identifier {}
     * $id = new OrderId(); // generates random UUID v4
     * $json = json_encode($id->toArray());
     * $restored = OrderId::fromJson($json);
     * $id->equals($restored); // true
     * ```
     */
    public static function fromJson(string $json): static
    {
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($data)) {
            throw new \InvalidArgumentException('JSON must decode to an array/object.');
        }

        return static::fromArray($data);
    }
}
