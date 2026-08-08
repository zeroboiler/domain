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
 * @deprecated Use {@see UuidIdentifier} for new code. This class will be removed in v3.0.
 */
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
    #[\Override]
    public function jsonSerialize(): string
    {
        return $this->toString();
    }
}
