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
 * @see IdentifierContract Cross-package identifier contract.
 * @see \JsonSerializable Provides `json_encode()` serialization.
 *
 * @since 1.0.0
 *
 * @example
 * ```php
 * $slug = StringIdentifier::from('my-blog-post');
 * $slug->toString();   // 'my-blog-post'
 * $slug->isValid('');  // false
 * $slug->toArray();     // ['string' => 'my-blog-post']
 * ```
 */
final readonly class StringIdentifier implements IdentifierContract, JsonSerializable
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
     * @return self
     *
     * @throws ValueError If the value is an empty string.
     */
    public static function from(string $value): self
    {
        return new self($value);
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
     * @return self
     *
     * @throws ValueError If the value is an empty string.
     */
    #[\Override]
    public static function fromString(string $value): static
    {
        return self::from($value);
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
    #[\Override]
    public function equals(IdentifierContract $other): bool
    {
        return $other instanceof StringIdentifier && $other::class === static::class && $this->value === $other->value;
    }

    /**
     * Get the string representation of the identifier.
     *
     * @return string The identifier string.
     */
    #[\Override]
    public function toString(): string
    {
        return $this->value;
    }

    /**
     * Serialize the identifier to JSON.
     *
     * @return string The identifier string.
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
     * and cross-package data exchange. The string is stored under the
     * `string` key for clarity and to avoid ambiguity with generic `id`.
     *
     * @return array{string: string}
     *
     * @example
     * ```php
     * $slug = StringIdentifier::from('my-blog-post');
     * $slug->toArray();       // ['string' => 'my-blog-post']
     * ```
     */
    #[\Override]
    public function toArray(): array
    {
        return ['string' => $this->value];
    }

    /**
     * Reconstruct an identifier from an array representation.
     *
     * Accepts the output of {@see toArray()} for full round-trip support.
     * Also accepts a plain string under the `string` or `id` key
     * for maximum deserialization flexibility.
     *
     * @param  array{string?: string, id?: string}  $array  The array from `toArray()`.
     * @return self A new StringIdentifier instance.
     *
     * @throws \InvalidArgumentException If neither `string` nor `id` key is present.
     * @throws ValueError If the value is an empty string.
     *
     * @example
     * ```php
     * $slug = StringIdentifier::fromArray(['string' => 'my-blog-post']);
     * $restored = StringIdentifier::fromArray($slug->toArray());
     * $slug->equals($restored); // true
     * ```
     */
    #[\Override]
    public static function fromArray(array $array): static
    {
        $value = $array['string'] ?? $array['id'] ?? null;

        if (! is_string($value)) {
            throw new \InvalidArgumentException(
                sprintf('StringIdentifier::fromArray() expects a "string" or "id" string key, got %s.', get_debug_type($value)),
            );
        }

        return self::from($value);
    }

    /**
     * Create a StringIdentifier from a JSON string.
     *
     * Parses the JSON and delegates to {@see fromArray()} for hydration.
     * Enables round-trip serialization via `json_encode()` → `fromJson()`.
     *
     * @param  string  $json  A valid JSON object string.
     * @return self A new StringIdentifier instance.
     *
     * @throws \JsonException If the JSON string is invalid.
     * @throws \InvalidArgumentException If the JSON does not decode to an array.
     *
     * @example
     * ```php
     * $slug = StringIdentifier::from('my-blog-post');
     * $json = json_encode($slug->toArray());
     * $restored = StringIdentifier::fromJson($json);
     * $slug->equals($restored); // true
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
     * @return string The identifier string.
     */
    public function __toString(): string
    {
        return $this->value;
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
     * @return array{string: string}
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
     * @param  array{string?: string, id?: string}  $data
     * @return void
     *
     * @since 2.12.0
     */
    public function __unserialize(array $data): void
    {
        $value = $data['string'] ?? $data['id'] ?? null;

        if (! is_string($value) || $value === '') {
            throw new \InvalidArgumentException(
                sprintf('Cannot unserialize StringIdentifier: missing or empty value, got %s.', get_debug_type($value)),
            );
        }

        (new \ReflectionClass(self::class))->getProperty('value')
            ->setValue($this, $value);
    }
}
