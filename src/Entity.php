<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain;

use ZeroBoiler\Domain\Contracts\Entity as EntityContract;

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
 * @implements \JsonSerializable
 *
 * @since 1.0.0
 */
abstract class Entity implements EntityContract, \JsonSerializable
{
    use Concerns\HasDomainEvents;

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
     *
     * @return string The entity's identity as a string.
     *
     * @throws \LogicException When the entity's ID is of an unsupported type.
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

    /**
     * Check identity equality with another entity.
     *
     * Two entities are equal if and only if they are of the same concrete
     * class and have the same string identity. Uninitialized IDs result
     * in `false` to prevent `LogicException` crashes.
     *
     * @param  EntityContract  $other  The entity to compare against.
     * @return bool True if both are the same concrete class with identical identity.
     */
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
     * @return array{id: string, type: string, ...}
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
    #[\Override]
    public function toArray(): array
    {
        return [
            'id' => $this->id(),
            'type' => class_basename(static::class),
        ];
    }

    /**
     * Reconstruct an entity from an array representation.
     *
     * Uses reflection to hydrate constructor parameters from the array.
     * Only keys matching constructor parameter names are passed; extra
     * keys are silently ignored. Missing required parameters throw
     * an ArgumentCountError (from PHP itself).
     *
     * For aggregate roots, use {@see AggregateRoot::reconstituteFromSnapshot()}
     * instead — it handles readonly property restoration via reflection.
     *
     * @param  array<string, mixed>  $data  The array from `toArray()`.
     * @return static A new entity instance hydrated from the array.
     *
     * @throws \ArgumentCountError If a required constructor parameter is missing.
     * @throws \ReflectionException If the class cannot be reflected.
     *
     * @since 2.9.0
     *
     * @example
     * ```php
     * class OrderItem extends Entity
     * {
     *     public function __construct(
     *         int|string|\Stringable $id,
     *         public readonly string $productId,
     *         public readonly int $quantity,
     *     ) {
     *         parent::__construct($id);
     *     }
     * }
     *
     * $item = OrderItem::fromArray([
     *     'id' => '42',
     *     'productId' => 'prod-123',
     *     'quantity' => 3,
     * ]);
     * $item->toArray();
     * // ['id' => '42', 'type' => 'OrderItem', 'product_id' => 'prod-123', 'quantity' => 3]
     * ```
     */
    #[\Override]
    public static function fromArray(array $data): static
    {
        $reflection = new \ReflectionClass(static::class);
        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return new static;
        }

        $args = [];
        foreach ($constructor->getParameters() as $param) {
            $name = $param->getName();

            if (array_key_exists($name, $data)) {
                $args[$name] = $data[$name];
            } elseif ($param->isDefaultValueAvailable()) {
                // Skip — PHP will use the default
            } elseif (! $param->allowsNull()) {
                throw new \ArgumentCountError(
                    sprintf('Missing required parameter "%s" for %s.', $name, static::class)
                );
            }
        }

        return new static(...$args);
    }

    /**
     * Serialize the entity to JSON for `json_encode()` support.
     *
     * Delegates to {@see toArray()} so the entity's array representation
     * (identity, type, and domain-specific fields) is used when encoding.
     *
     * @return array<string, mixed>
     *
     * @since 2.9.0
     *
     * @example
     * ```php
     * echo json_encode($entity);
     * // {"id":"550e8400-...","type":"Order","status":"pending","total":1999}
     * ```
     */
    #[\Override]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Create an entity from a JSON string.
     *
     * Parses the JSON and delegates to {@see fromArray()} for hydration.
     * Enables round-trip serialization via `json_encode()` → `fromJson()`.
     *
     * @param  string  $json  A valid JSON object string.
     * @return static A new entity instance hydrated from the JSON data.
     *
     * @throws \JsonException If the JSON string is invalid.
     * @throws \InvalidArgumentException If the JSON does not decode to an array.
     *
     * @since 2.10.0
     *
     * @example
     * ```php
     * $json = json_encode($entity);
     * $restored = OrderItem::fromJson($json);
     * $restored->toArray(); // Same as original
     * ```
     */
    #[\Override]
    public static function fromJson(string $json): static
    {
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($data)) {
            throw new \InvalidArgumentException('JSON must decode to an array/object.');
        }

        return static::fromArray($data);
    }

    /**
     * Convert the entity to a JSON string.
     *
     * Convenience method for explicit JSON serialization without passing
     * to `json_encode()`. Uses `JSON_THROW_ON_ERROR` for safety.
     *
     * @param  int  $options  JSON encoding options bitmask (default: JSON_UNESCAPED_UNICODE).
     * @return string The JSON-encoded entity representation.
     *
     * @since 1.64.0
     *
     * @example
     * ```php
     * $json = $entity->toJson();
     * echo $json; // {"id":"42","type":"OrderItem","product_id":"prod-1","quantity":3}
     * ```
     */
    #[\Override]
    public function toJson(int $options = JSON_UNESCAPED_UNICODE): string
    {
        return json_encode($this->toArray(), $options | JSON_THROW_ON_ERROR);
    }
}
