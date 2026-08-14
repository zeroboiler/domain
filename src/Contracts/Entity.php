<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Contracts;

/**
 * Contract for domain entities.
 *
 * Entities have identity and support equality comparisons based on
 * their identity rather than their properties. Two entities with
 * the same ID but different property values are still considered equal.
 *
 * @see \ZeroBoiler\Domain\Entity Base implementation
 * @see \ZeroBoiler\Domain\Contracts\AggregateRoot Extended contract with versioning and events
 *
 * @since 1.0.0
 */
interface Entity
{
    /**
     * Return the entity's domain identity.
     *
     * Returns a string representation of the entity's identifier,
     * ensuring consistent identity handling across the hierarchy.
     *
     * @return string The entity's identity as a string.
     */
    public function id(): string;

    /**
     * Check identity equality with another entity.
     *
     * Two entities are equal if and only if they are of the same type
     * and have the same identity.
     *
     * @param  Entity  $other  The entity to compare against.
     * @return bool True if both entities are of the same type with identical identities.
     */
    public function equals(Entity $other): bool;

    /**
     * Convert the entity to an array representation.
     *
     * Returns a base array with at least `id` and `type` keys.
     * Subclasses may include additional domain-specific fields.
     * Useful for DomainTransformer integration and response serialization.
     *
     * @return array{id: string, type: string, ...}
     */
    public function toArray(): array;

    /**
     * Reconstruct an entity from an array representation.
     *
     * Uses reflection to hydrate constructor parameters from the array.
     * Supports round-trip serialization with toArray() for caching,
     * queue jobs, and API responses.
     *
     * @param  array<string, mixed>  $array  The array from toArray().
     * @return static A new entity instance hydrated from the array.
     */
    public static function fromArray(array $array): static;

    /**
     * Reconstruct an entity from a JSON string.
     *
     * Parses the JSON and delegates to fromArray() for hydration.
     * Enables round-trip serialization via json_encode() → fromJson().
     *
     * @param  string  $json  A valid JSON object string.
     * @return static A new entity instance hydrated from the JSON data.
     *
     * @throws \JsonException If the JSON string is invalid.
     * @throws \InvalidArgumentException If the JSON does not decode to an array.
     */
    public static function fromJson(string $json): static;

    /**
     * Check if there are any uncommitted domain events.
     *
     * Entities using the HasDomainEvents trait expose this method
     * for inspection of pending events before they are dispatched.
     *
     * @return bool True if there are pending events, false otherwise.
     */
    public function hasUncommittedEvents(): bool;
}
