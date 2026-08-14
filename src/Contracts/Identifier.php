<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Contracts;

use Stringable;

/**
 * Shared identifier contract for cross-package interoperability.
 *
 * Implemented by all identifier types in the ZeroBoiler ecosystem.
 * This interface allows persistence, tenancy, and state-machine
 * packages to work with any identifier implementation uniformly.
 *
 * @see \ZeroBoiler\Domain\Identifiers\UuidIdentifier    Abstract readonly UUID v4 identifier
 * @see \ZeroBoiler\Domain\Identifiers\UlidIdentifier    Abstract readonly ULID identifier
 * @see \ZeroBoiler\Domain\Identifiers\StringIdentifier  Readonly non-empty string identifier
 * @see \ZeroBoiler\Domain\Identifiers\IntegerIdentifier Final readonly integer identifier
 * @see \ZeroBoiler\Domain\Identifiers\Identifier        Legacy base (deprecated)
 * @see \ZeroBoiler\Domain\AggregateRootId              Final readonly UUID v4 for aggregate roots
 *
 * @since 1.0.0
 */
interface Identifier extends Stringable, \JsonSerializable
{
    /**
     * Create an instance from a string representation.
     *
     * @param  string  $value  The string representation of the identifier.
     * @return static A new identifier instance.
     */
    public static function fromString(string $value): static;

    /**
     * Get the string representation of the identifier.
     *
     * @return string The identifier as a string.
     */
    public function toString(): string;

    /**
     * Check equality with another identifier of the same type.
     *
     * @param  self  $other  The identifier to compare against.
     * @return bool True if both are the same concrete class with identical values.
     */
    public function equals(self $other): bool;

    /**
     * Convert the identifier to an array for serialization.
     *
     * All implementations provide toArray()/fromArray() for round-trip
     * serialization in caching, queue jobs, and API responses.
     *
     * @return array<string, mixed> The identifier as an associative array.
     */
    public function toArray(): array;

    /**
     * Reconstruct an identifier from an array representation.
     *
     * Supports round-trip serialization with toArray().
     *
     * @param  array<string, mixed>  $array  The array from toArray().
     * @return static A new identifier instance.
     */
    public static function fromArray(array $array): static;

    /**
     * Reconstruct an identifier from a JSON string.
     *
     * Parses the JSON and delegates to fromArray() for hydration.
     *
     * @param  string  $json  A valid JSON object string.
     * @return static A new identifier instance.
     *
     * @throws \JsonException If the JSON string is invalid.
     * @throws \InvalidArgumentException If the JSON does not decode to an array.
     */
    public static function fromJson(string $json): static;
}
