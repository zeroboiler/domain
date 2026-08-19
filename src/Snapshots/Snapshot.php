<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Snapshots;

/**
 * Immutable snapshot of an aggregate root's state at a specific version.
 *
 * @see https://martinfowler.com/eaaDev/EventSourcing.html
 *
 * @see \JsonSerializable Provides `json_encode()` serialization.
 *
 * @since 1.0.0
 *
 * @example
 * ```php
 * $snapshot = Snapshot::create(Order::class, $id, 50, $state);
 * $snapshot->toArray();          // Serialize for storage
 * $snapshot = Snapshot::fromArray($data); // Restore from storage
 * json_encode($snapshot);       // JSON serialization
 * ```
 */
final readonly class Snapshot implements \JsonSerializable
{
    /**
     * @param  string  $aggregateType  The FQCN of the aggregate root class.
     * @param  string  $aggregateId  The aggregate's unique identifier.
     * @param  int  $version  The aggregate version at snapshot time.
     * @param  array<string, mixed>  $state  The serialized aggregate state.
     * @param  \DateTimeImmutable  $createdAt  When the snapshot was taken.
     */
    public function __construct(
        public string $aggregateType,
        public string $aggregateId,
        public int $version,
        public array $state,
        public \DateTimeImmutable $createdAt,
    ) {}

    /**
     * Create a new snapshot from an aggregate root's current state.
     *
     * @param  string  $aggregateType  The FQCN of the aggregate.
     * @param  string  $aggregateId  The aggregate's ID.
     * @param  int  $version  The current version.
     * @param  array<string, mixed>  $state  The state to snapshot.
     */
    public static function create(
        string $aggregateType,
        string $aggregateId,
        int $version,
        array $state,
    ): self
    {
        return new self(
            aggregateType: $aggregateType,
            aggregateId: $aggregateId,
            version: $version,
            state: $state,
            createdAt: new \DateTimeImmutable,
        );
    }

    /**
     * Serialize the snapshot to an array for persistence.
     *
     * @return array{aggregate_type: string, aggregate_id: string, version: int, state: array<string, mixed>, created_at: string}
     *
     * @example
     * ```php
     * $snapshot = Snapshot::create(Order::class, $id, 50, ['status' => 'paid']);
     * $snapshot->toArray();
     * // ['aggregate_type' => 'Order', 'aggregate_id' => '...'
     * //  'version' => 50, 'state' => ['status' => 'paid'], 'created_at' => '...']
     * ```
     */
    public function toArray(): array
    {
        return [
            'aggregate_type' => $this->aggregateType,
            'aggregate_id' => $this->aggregateId,
            'version' => $this->version,
            'state' => $this->state,
            'created_at' => $this->createdAt->format(\DateTimeImmutable::ATOM),
        ];
    }

    /**
     * Deserialize from persisted array.
     *
     * @param  array<string, mixed>  $data  The serialized snapshot data from {@see toArray()}.
     * @return self
     *
     * @throws \InvalidArgumentException If the data contains invalid types.
     *
     * @example
     * ```php
     * $restored = Snapshot::fromArray($snapshot->toArray());
     * $snapshot->equals($restored); // true
     * ```
     */
    public static function fromArray(array $data): self
    {
        $aggregateType = $data['aggregate_type'] ?? null;
        $aggregateId = $data['aggregate_id'] ?? null;
        $version = $data['version'] ?? null;
        $state = $data['state'] ?? null;
        $createdAt = $data['created_at'] ?? null;

        if (! is_string($aggregateType) || $aggregateType === ''
            || ! is_string($aggregateId) || $aggregateId === ''
            || ! is_int($version) || $version < 0
            || ! is_array($state)
            || ! is_string($createdAt)
        ) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid snapshot data: expected non-empty string (aggregate_type), non-empty string (aggregate_id), '
                .'non-negative int (version), array (state), ATOM datetime string (created_at); '
                .'got %s, %s, %s, %s, %s.',
                get_debug_type($aggregateType),
                get_debug_type($aggregateId),
                get_debug_type($version),
                get_debug_type($state),
                get_debug_type($createdAt),
            ));
        }

        /** @var array<string, mixed> $state */
        $state = $state;

        return new self(
            aggregateType: $aggregateType,
            aggregateId: $aggregateId,
            version: $version,
            state: $state,
            createdAt: new \DateTimeImmutable($createdAt),
        );
    }

    /**
     * Serialize the snapshot to JSON.
     *
     * Delegates to toArray() for consistent JSON representation.
     *
     * @return array<string, mixed>
     */
    #[\Override]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Convert the snapshot to a JSON string.
     *
     * Convenience method for explicit JSON serialization without passing
     * to `json_encode()`. Uses `JSON_THROW_ON_ERROR` for safety.
     *
     * @param  int  $options  JSON encoding options bitmask (default: JSON_UNESCAPED_UNICODE).
     * @return string The JSON-encoded snapshot representation.
     *
     * @since 1.66.0
     *
     * @example
     * ```php
     * $snapshot = Snapshot::create(Order::class, $id, 50, $state);
     * $json = $snapshot->toJson();
     * $restored = Snapshot::fromJson($json);
     * $snapshot->equals($restored); // true
     * ```
     */
    public function toJson(int $options = JSON_UNESCAPED_UNICODE): string
    {
        return json_encode($this->toArray(), $options | JSON_THROW_ON_ERROR);
    }

    /**
     * Create a Snapshot from a JSON string.
     *
     * Parses the JSON and delegates to {@see fromArray()} for hydration.
     * Enables round-trip serialization for caching and queue jobs.
     *
     * @param  string  $json  A valid JSON object string.
     * @return self A new Snapshot instance.
     *
     * @throws \JsonException If the JSON string is invalid.
     * @throws \InvalidArgumentException If the JSON does not decode to an array.
     *
     * @example
     * ```php
     * $json = json_encode($snapshot->toArray());
     * $restored = Snapshot::fromJson($json);
     * $snapshot->equals($restored); // true
     * ```
     */
    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($data)) {
            throw new \InvalidArgumentException('JSON must decode to an array/object.');
        }

        return self::fromArray($data);
    }

    /**
     * Check equality with another snapshot.
     *
     * Two snapshots are equal if they represent the same aggregate type,
     * aggregate ID, version, and state.
     *
     * @param  self  $other  The snapshot to compare against.
     * @return bool True if both snapshots have identical content.
     */
    public function equals(self $other): bool
    {
        return $this->aggregateType === $other->aggregateType
            && $this->aggregateId === $other->aggregateId
            && $this->version === $other->version
            && $this->state === $other->state;
    }

    /**
     * Serialize for PHP's native serialize().
     *
     * @return array{aggregate_type: string, aggregate_id: string, version: int, state: array<string, mixed>, created_at: string}
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
     * Uses reflection to set readonly properties after construction.
     *
     * @param  array<string, mixed>  $data
     * @return void
     *
     * @since 2.12.0
     */
    public function __unserialize(array $data): void
    {
        $reflection = new \ReflectionClass(self::class);
        $map = [
            'aggregateType' => 'aggregate_type',
            'aggregateId' => 'aggregate_id',
            'version' => 'version',
            'state' => 'state',
            'createdAt' => 'created_at',
        ];

        foreach ($map as $prop => $key) {
            if (! isset($data[$key])) {
                continue;
            }

            $property = $reflection->getProperty($prop);

            if ($prop === 'createdAt') {
                $property->setValue($this, new \DateTimeImmutable($data[$key]));
            } else {
                $property->setValue($this, $data[$key]);
            }
        }
    }
}
