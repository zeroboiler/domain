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
 */
final readonly class Snapshot
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
    ): self {
        return new self(
            aggregateType: $aggregateType,
            aggregateId: $aggregateId,
            version: $version,
            state: $state,
            createdAt: new \DateTimeImmutable,
        );
    }

    /**
     * Serialize to array for persistence.
     *
     * @return array<string, mixed>
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
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            aggregateType: $data['aggregate_type'],
            aggregateId: $data['aggregate_id'],
            version: (int) $data['version'],
            state: $data['state'],
            createdAt: new \DateTimeImmutable($data['created_at']),
        );
    }
}
