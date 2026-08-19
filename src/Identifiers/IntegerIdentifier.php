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
 * @see IdentifierContract Cross-package identifier contract.
 * @see \JsonSerializable Provides `json_encode()` serialization.
 *
 * @since 1.0.0
 *
 * @example
 * ```php
 * $id = IntegerIdentifier::from(42);
 * $id->toString();     // '42'
 * $id->toInt();        // 42
 * $id->isValid('abc'); // false
 * $id->toArray();       // ['integer' => 42]
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
     *
     * @throws \InvalidArgumentException If the string does not represent a valid integer.
     */
    #[\Override]
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
    #[\Override]
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
    #[\Override]
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
    #[\Override]
    public function jsonSerialize(): int
    {
        return $this->value;
    }

    /**
     * Convert the identifier to an array representation.
     *
     * Provides consistent serialization for caching, persistence,
     * and cross-package data exchange. The integer is stored under the
     * `integer` key for clarity and to avoid ambiguity with generic `id`.
     *
     * @return array{integer: int}
     *
     * @example
     * ```php
     * $id = IntegerIdentifier::from(42);
     * $id->toArray();       // ['integer' => 42]
     * ```
     */
    #[\Override]
    public function toArray(): array
    {
        return ['integer' => $this->value];
    }

    /**
     * Reconstruct an identifier from an array representation.
     *
     * Accepts the output of {@see toArray()} for full round-trip support.
     * Also accepts an integer under the `integer` or `id` key
     * for maximum deserialization flexibility.
     *
     * @param  array{integer?: int, id?: int|string}  $array  The array from `toArray()`.
     * @return self A new IntegerIdentifier instance.
     *
     * @throws \InvalidArgumentException If neither `integer` nor `id` key is present or valid.
     *
     * @example
     * ```php
     * $id = IntegerIdentifier::fromArray(['integer' => 42]);
     * $restored = IntegerIdentifier::fromArray($id->toArray());
     * $id->equals($restored); // true
     * ```
     */
    #[\Override]
    public static function fromArray(array $array): static
    {
        $value = $array['integer'] ?? $array['id'] ?? null;

        if (is_int($value)) {
            return self::from($value);
        }

        if (is_string($value) && self::isValid($value)) {
            return self::from((int) $value);
        }

        throw new \InvalidArgumentException(
            sprintf('IntegerIdentifier::fromArray() expects an "integer" or "id" int key, got %s.', get_debug_type($value)),
        );
    }

    /**
     * Create an IntegerIdentifier from a JSON string.
     *
     * Parses the JSON and delegates to {@see fromArray()} for hydration.
     * Enables round-trip serialization via `json_encode()` → `fromJson()`.
     *
     * @param  string  $json  A valid JSON object string.
     * @return self A new IntegerIdentifier instance.
     *
     * @throws \JsonException If the JSON string is invalid.
     * @throws \InvalidArgumentException If the JSON does not decode to an array.
     *
     * @example
     * ```php
     * $id = IntegerIdentifier::from(42);
     * $json = json_encode($id->toArray());
     * $restored = IntegerIdentifier::fromJson($json);
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

        return self::fromArray($data);
    }

    /**
     * @return string The integer as a string.
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
     * @return array{integer: int}
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
     *
     * @param  array{integer?: int, id?: int|string}  $data
     * @return void
     *
     * @since 2.12.0
     */
    public function __unserialize(array $data): void
    {
        $value = $data['integer'] ?? $data['id'] ?? null;

        if (is_int($value)) {
            $integer = $value;
        } elseif (is_string($value) && self::isValid($value)) {
            $integer = (int) $value;
        } else {
            throw new \InvalidArgumentException(
                sprintf('Cannot unserialize IntegerIdentifier: invalid or missing integer, got %s.', get_debug_type($value)),
            );
        }

        (new \ReflectionClass(self::class))->getProperty('value')
            ->setValue($this, $integer);
    }
}
