<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain;

use ZeroBoiler\Domain\Concerns\HasDomainEvents;
use ZeroBoiler\Domain\Contracts\Entity as EntityContract;
use ZeroBoiler\Domain\Identifiers\IntegerIdentifier;
use ZeroBoiler\Domain\Identifiers\StringIdentifier;
use ZeroBoiler\Domain\Identifiers\UlidIdentifier;
use ZeroBoiler\Domain\Identifiers\UuidIdentifier;

/**
 * Base class for domain entities with flexible identity types.
 *
 * Supports multiple ID strategies via PHP's union type on the constructor:
 *
 * - `int` — auto-increment database IDs
 * - `string` — natural keys, slugs, composite string IDs
 * - `\Stringable` — typed identifiers from the ZeroBoiler ecosystem:
 *   - `AggregateRootId` (UUID v4, for aggregate roots)
 *   - `UuidIdentifier` — domain UUID identifiers
 *   - `UlidIdentifier` — monotonic ULID identifiers
 *   - `StringIdentifier` — validated string identifiers
 *   - `IntegerIdentifier` — validated integer identifiers
 *
 * For aggregate roots, extend {@see AggregateRoot} instead of Entity directly.
 *
 * @template TId of int|string|\Stringable
 *
 * @since 1.0.0
 */
abstract class Entity implements EntityContract
{
    use HasDomainEvents;

    /**
     * @param  TId  $id  The entity's identity.
     *                   Aggregate roots should extend AggregateRoot instead.
     *                   Simple entities may use int, string, or any Stringable identifier
     *                   (UUID, ULID, custom Identifier, etc.).
     */
    public function __construct(
        public readonly int|string|\Stringable $id,
    ) {}

    /**
     * Return the entity's domain identity as a string.
     *
     * Always returns a string representation for consistent identity
     * handling across the entity hierarchy.
     */
    #[\Override]
    public function id(): string
    {
        return match (true) {
            is_string($this->id) => $this->id,
            $this->id instanceof \Stringable => (string) $this->id,
            is_int($this->id) => (string) $this->id,
            default => throw new \LogicException(sprintf(
                'Entity id must be string, int, or Stringable; got %s',
                get_debug_type($this->id),
            )),
        };
    }

    #[\Override]
    public function equals(EntityContract $other): bool
    {
        if (static::class !== $other::class) {
            return false;
        }

        // Guard against uninitialized IDs to prevent LogicException crashes.
        // Entities that haven't been assigned an ID yet cannot be equal
        // to anything — even another ID-less entity of the same type.
        try {
            return $this->id() === $other->id();
        } catch (\LogicException) {
            return false;
        }
    }

    /**
     * Convert the entity to an array representation.
     *
     * Provides a base array with identity and type information.
     * Subclasses should override to add domain-specific fields.
     * Useful for DomainTransformer integration and response serialization.
     *
     * @return array{id: string, type: string}
     *
     * @example
     * ```php
     * // Base entity
     * $item->toArray();
     * // ['id' => '42', 'type' => 'OrderItem']
     *
     * // Subclass override:
     * class OrderItem extends Entity
     * {
     *     public function __construct(
     *         int|string|\Stringable $id,
     *         public readonly string $productId,
     *         public readonly int $quantity,
     *     ) {
     *         parent::__construct($id);
     *     }
     *
     *     public function toArray(): array
     *     {
     *         return [
     *             ...parent::toArray(),
     *             'product_id' => $this->productId,
     *             'quantity' => $this->quantity,
     *         ];
     *     }
     * }
     * ```
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id(),
            'type' => class_basename(static::class),
        ];
    }
}
