<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain;

use ZeroBoiler\ValueObjects\Contracts\ValueObject as ValueObjectContract;
use ZeroBoiler\ValueObjects\ValueObject as BaseValueObject;

/**
 * Domain value object base class.
 *
 * Extends the zeroboiler/value-objects package to provide
 * immutability, equality, validation, and Eloquent auto-casting
 * for domain-level value objects.
 *
 * Domain VOs (Address, Money, Email, etc.) should extend this class
 * to get full integration with the value-objects ecosystem, including
 * `fromArray()`/`toArray()` round-trip serialization and domain-level
 * equality comparison based on `toArray()` output.
 *
 * @since 1.0.0
 *
 * @implements ValueObjectContract
 *
 * @see BaseValueObject For the parent class with Eloquent casting support.
 * @see \ZeroBoiler\Domain\Identifiers\UuidIdentifier For identifier value objects.
 *
 * @example
 * ```php
 * use ZeroBoiler\Domain\ValueObject;
 *
 * class Address extends ValueObject
 * {
 *     public function __construct(
 *         public readonly string $street,
 *         public readonly string $city,
 *         public readonly string $country,
 *     ) {}
 *
 *     public static function fromArray(array $data): static
 *     {
 *         return new static(
 *             street: $data['street'],
 *             city: $data['city'],
 *             country: $data['country'],
 *         );
 *     }
 *
 *     public function toArray(): array
 *     {
 *         return [
 *             'street' => $this->street,
 *             'city' => $this->city,
 *             'country' => $this->country,
 *         ];
 *     }
 * }
 *
 * $address = Address::fromArray(['street' => '123 Main', 'city' => 'NYC', 'country' => 'US']);
 * $address->equals($otherAddress);  // true if all fields match
 * $address->toArray();               // ['street' => '123 Main', 'city' => 'NYC', 'country' => 'US']
 * echo json_encode($address);        // JSON serialization via toArray()
 * ```
 */
abstract class ValueObject extends BaseValueObject
{
    /**
     * Check equality with another value object.
     *
     * Two value objects are equal if and only if they are of the exact same
     * concrete class and their `toArray()` output is identical.
     * This provides deep structural equality rather than reference equality.
     *
     * @param  ValueObjectContract|null  $other  The value object to compare against.
     * @return bool True if both are the same type with identical array representation.
     */
    #[\Override]
    public function equals(?ValueObjectContract $other): bool
    {
        if (! $other instanceof ValueObjectContract || $other::class !== static::class) {
            return false;
        }

        return $this->toArray() === $other->toArray();
    }

    /**
     * Convert the value object to a JSON string.
     *
     * Convenience method for explicit JSON serialization without passing
     * to `json_encode()`. Uses `JSON_THROW_ON_ERROR` for safety.
     *
     * @param  int  $options  JSON encoding options bitmask (default: JSON_UNESCAPED_UNICODE).
     * @return string The JSON-encoded value object representation.
     *
     * @since 2.14.0
     *
     * @example
     * ```php
     * $address = Address::fromArray(['street' => '123 Main', 'city' => 'NYC', 'country' => 'US']);
     * $json = $address->toJson();
     * echo $json; // {"street":"123 Main","city":"NYC","country":"US"}
     * ```
     */
    public function toJson(mixed $options = JSON_UNESCAPED_UNICODE): string
    {
        return json_encode($this->toArray(), (int) $options | JSON_THROW_ON_ERROR);
    }

    /**
     * Create a value object from a JSON string.
     *
     * Parses the JSON and delegates to {@see fromArray()} for hydration.
     * Enables round-trip serialization via `json_encode()` → `fromJson()`.
     *
     * @param  string  $json  A valid JSON object string.
     * @return static A new value object instance hydrated from the JSON data.
     *
     * @throws \JsonException If the JSON string is invalid.
     * @throws \InvalidArgumentException If the JSON does not decode to an array.
     *
     * @since 2.14.0
     *
     * @example
     * ```php
     * $json = json_encode($address->toArray());
     * $restored = Address::fromJson($json);
     * $address->equals($restored); // true
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
