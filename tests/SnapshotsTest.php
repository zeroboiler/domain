<?php

declare(strict_types=1);

/**
 * Tests for Snapshot value object and snapshot stores.
 *
 * @covers \ZeroBoiler\Domain\Snapshots\Snapshot
 * @covers \ZeroBoiler\Domain\Snapshots\InMemorySnapshotStore
 */
describe('Snapshot', function (): void {
    it('creates with aggregate id, version, and state', function (): void {
        $snapshot = new \ZeroBoiler\Domain\Snapshots\Snapshot(
            aggregateId: 'order-123',
            version: 5,
            state: ['status' => 'paid', 'total' => 1999],
        );

        expect($snapshot->aggregateId)->toBe('order-123');
        expect($snapshot->version)->toBe(5);
        expect($snapshot->state)->toBe(['status' => 'paid', 'total' => 1999]);
        expect($snapshot->createdAt)->toBeInstanceOf(\DateTimeImmutable::class);
    });

    it('serializes to array', function (): void {
        $snapshot = new \ZeroBoiler\Domain\Snapshots\Snapshot(
            aggregateId: 'order-123',
            version: 3,
            state: ['key' => 'value'],
        );

        $array = $snapshot->toArray();
        expect($array)->toHaveKeys(['aggregate_id', 'version', 'state', 'created_at']);
        expect($array['aggregate_id'])->toBe('order-123');
        expect($array['version'])->toBe(3);
    });

    it('round-trips through fromArray/toArray', function (): void {
        $original = new \ZeroBoiler\Domain\Snapshots\Snapshot(
            aggregateId: 'order-123',
            version: 3,
            state: ['status' => 'pending'],
        );

        $restored = \ZeroBoiler\Domain\Snapshots\Snapshot::fromArray($original->toArray());
        expect($restored->aggregateId)->toBe($original->aggregateId);
        expect($restored->version)->toBe($original->version);
        expect($restored->state)->toBe($original->state);
    });

    it('implements JsonSerializable', function (): void {
        $snapshot = new \ZeroBoiler\Domain\Snapshots\Snapshot(
            aggregateId: 'order-123',
            version: 1,
            state: [],
        );

        $json = json_encode($snapshot);
        expect($json)->toBeString()->toBeJson();
    });
});

describe('InMemorySnapshotStore', function (): void {
    it('stores and retrieves snapshots', function (): void {
        $store = new \ZeroBoiler\Domain\Snapshots\InMemorySnapshotStore;

        $snapshot = new \ZeroBoiler\Domain\Snapshots\Snapshot(
            aggregateId: 'order-123',
            version: 5,
            state: ['status' => 'paid'],
        );

        $store->save(\TestConcreteAggregate::class, 'order-123', $snapshot);

        $loaded = $store->load(\TestConcreteAggregate::class, 'order-123');
        expect($loaded)->not()->toBeNull();
        expect($loaded->version)->toBe(5);
    });

    it('returns null for non-existent snapshot', function (): void {
        $store = new \ZeroBoiler\Domain\Snapshots\InMemorySnapshotStore;
        expect($store->load('NonExistent', 'missing'))->toBeNull();
    });

    it('returns count of stored snapshots', function (): void {
        $store = new \ZeroBoiler\Domain\Snapshots\InMemorySnapshotStore;
        expect($store->count())->toBe(0);

        $snapshot = new \ZeroBoiler\Domain\Snapshots\Snapshot(
            aggregateId: 'order-1',
            version: 1,
            state: [],
        );
        $store->save(\TestConcreteAggregate::class, 'order-1', $snapshot);
        expect($store->count())->toBe(1);
    });
});
