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
}
