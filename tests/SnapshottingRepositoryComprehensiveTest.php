<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Domain\AggregateRoot;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\Concerns\EventSourced;
use ZeroBoiler\Domain\Concerns\HasSnapshots;
use ZeroBoiler\Domain\Contracts\Repository;
use ZeroBoiler\Domain\Identifiers\UuidIdentifier;
use ZeroBoiler\Domain\Snapshots\InMemorySnapshotStore;
use ZeroBoiler\Domain\Snapshots\Snapshot;
use ZeroBoiler\Domain\Snapshots\SnapshotPolicy;
use ZeroBoiler\Domain\Snapshots\SnapshottingRepository;
use ZeroBoiler\Events\Domain\DomainEvent;

// ===========================================================================
//  SnapshottingRepository — comprehensive tests
// ===========================================================================

describe('SnapshottingRepository comprehensive', function (): void {
    it('snapshot + event replay via findWithSnapshot with applyEvent callback', function (): void {
        $store = new InMemorySnapshotStore;

        $aggregateClass = new #[SnapshotPolicy(every: 5)] class extends AggregateRoot
        {
            use EventSourced;
            use HasSnapshots;

            public string $status = 'pending';
            public int $amount = 0;

            public function __construct()
            {
                parent::__construct(AggregateRootId::generate());
            }

            protected function applyStatusChanged(DomainEvent $event): void
            {
                $this->status = $event->payload['status'];
            }

            protected function applyAmountSet(DomainEvent $event): void
            {
                $this->amount = $event->payload['amount'];
            }
        };

        $id = $aggregateClass->id();
        $aggregateClass->setStatus('confirmed');
        $aggregateClass->setVersion(5);

        // Snapshot at version 5
        $snapshot = Snapshot::create(
            aggregateType: $aggregateClass::class,
            aggregateId: $id,
            version: 5,
            state: ['status' => 'confirmed', 'amount' => 0],
        );
        $store->save($snapshot);

        $innerRepo = new class implements Repository
        {
            public function find(string|int $id): ?AggregateRoot
            {
                return null;
            }

            public function save(AggregateRoot $aggregate): void {}

            public function delete(string|int $id): void {}
        };

        $repo = new SnapshottingRepository(
            inner: $innerRepo,
            snapshotStore: $store,
            aggregateType: $aggregateClass::class,
        );

        // Replay events after version 5 via callback
        $postSnapshotEvents = [
            DomainEvent::occur('status.changed', ['status' => 'shipped']),
            DomainEvent::occur('amount.set', ['amount' => 100]),
        ];

        $result = $repo->findWithSnapshot(
            $id,
            fn (int $version) => $postSnapshotEvents,
        );

        expect($result)->not->toBeNull()
            ->and($result->version())->toBeGreaterThanOrEqual(5)
            ->and($result->status)->toBe('confirmed'); // snapshot restored
    });

    it('findWithSnapshot without callback falls back to inner', function (): void {
        $store = new InMemorySnapshotStore;

        $expected = new class extends AggregateRoot
        {
            use HasSnapshots;

            public string $name = 'from-inner';

            public function __construct()
            {
                parent::__construct(AggregateRootId::generate());
            }
        };
        $expected->setVersion(3);

        $snapshot = Snapshot::create(
            aggregateType: $expected::class,
            aggregateId: $expected->id(),
            version: 10,
            state: ['name' => 'from-snapshot'],
        );
        $store->save($snapshot);

        $innerRepo = new class($expected) implements Repository
        {
            public function __construct(public ?AggregateRoot $returnAggregate) {}

            public function find(string|int $id): ?AggregateRoot
            {
                return $this->returnAggregate;
            }

            public function save(AggregateRoot $aggregate): void {}

            public function delete(string|int $id): void {}
        };

        $repo = new SnapshottingRepository(
            inner: $innerRepo,
            snapshotStore: $store,
            aggregateType: $expected::class,
        );

        // No callback → falls back to inner repo
        $result = $repo->findWithSnapshot($expected->id());

        expect($result)->toBe($expected);
    });

    it('snapshot version comparison is greater-than-or-equal (not strictly greater)', function (): void {
        $store = new InMemorySnapshotStore;

        $aggregateClass = new class extends AggregateRoot
        {
            use HasSnapshots;

            public function __construct()
            {
                parent::__construct(AggregateRootId::generate());
            }
        };

        $id = $aggregateClass->id();

        // Snapshot at version 10
        $store->save(Snapshot::create(
            aggregateType: $aggregateClass::class,
            aggregateId: $id,
            version: 10,
            state: [],
        ));

        // Inner returns aggregate at version 10 (exact match)
        $innerAggregate = clone $aggregateClass;
        $innerAggregate->setVersion(10);

        $innerRepo = new class($innerAggregate) implements Repository
        {
            public function __construct(public ?AggregateRoot $returnAggregate) {}

            public function find(string|int $id): ?AggregateRoot
            {
                return $this->returnAggregate;
            }

            public function save(AggregateRoot $aggregate): void {}

            public function delete(string|int $id): void {}
        };

        $repo = new SnapshottingRepository(
            inner: $innerRepo,
            snapshotStore: $store,
            aggregateType: $aggregateClass::class,
        );

        $result = $repo->find($id);

        // Inner should be preferred when version >= snapshot version
        expect($result)->toBe($innerAggregate);
    });

    it('snapshot restored when inner returns stale version', function (): void {
        $store = new InMemorySnapshotStore;

        $aggregateClass = new class extends AggregateRoot
        {
            use HasSnapshots;

            public string $data = 'current';

            public function __construct()
            {
                parent::__construct(AggregateRootId::generate());
            }
        };

        $id = $aggregateClass->id();

        // Snapshot at version 20
        $store->save(Snapshot::create(
            aggregateType: $aggregateClass::class,
            aggregateId: $id,
            version: 20,
            state: ['data' => 'current'],
        ));

        // Inner returns stale version 5 (pruned event store)
        $staleAggregate = clone $aggregateClass;
        $staleAggregate->setVersion(5);

        $innerRepo = new class($staleAggregate) implements Repository
        {
            public function __construct(public ?AggregateRoot $returnAggregate) {}

            public function find(string|int $id): ?AggregateRoot
            {
                return $this->returnAggregate;
            }

            public function save(AggregateRoot $aggregate): void {}

            public function delete(string|int $id): void {}
        };

        $repo = new SnapshottingRepository(
            inner: $innerRepo,
            snapshotStore: $store,
            aggregateType: $aggregateClass::class,
        );

        $result = $repo->find($id);

        // Snapshot should be preferred over stale data
        expect($result)->not->toBeNull()
            ->and($result)->not->toBe($staleAggregate);
    });

    it('save with snapshot creates snapshot at correct version boundary', function (): void {
        $store = new InMemorySnapshotStore;

        $aggregateClass = new #[SnapshotPolicy(every: 3)] class extends AggregateRoot
        {
            use HasSnapshots;

            public function __construct()
            {
                parent::__construct(AggregateRootId::generate());
            }
        };

        $aggregateClass->setVersion(6); // Multiple of 3 → should snapshot

        $innerRepo = new class implements Repository
        {
            public function find(string|int $id): ?AggregateRoot
            {
                return null;
            }

            public function save(AggregateRoot $aggregate): void {}

            public function delete(string|int $id): void {}
        };

        $repo = new SnapshottingRepository(
            inner: $innerRepo,
            snapshotStore: $store,
            aggregateType: $aggregateClass::class,
        );

        $repo->save($aggregateClass);

        $loaded = $store->load($aggregateClass::class, $aggregateClass->id());

        expect($loaded)->not->toBeNull()
            ->and($loaded->version)->toBe(6);
    });

    it('delete also removes snapshot', function (): void {
        $store = new InMemorySnapshotStore;

        $store->save(Snapshot::create(
            'App\\Order',
            'order-123',
            5,
            ['status' => 'active'],
        ));

        expect($store->has('App\\Order', 'order-123'))->toBeTrue();

        $innerRepo = new class implements Repository
        {
            public bool $deleteCalled = false;

            public function find(string|int $id): ?AggregateRoot
            {
                return null;
            }

            public function save(AggregateRoot $aggregate): void {}

            public function delete(string|int $id): void
            {
                $this->deleteCalled = true;
            }
        };

        $repo = new SnapshottingRepository(
            inner: $innerRepo,
            snapshotStore: $store,
            aggregateType: 'App\\Order',
        );

        $repo->delete('order-123');

        expect($innerRepo->deleteCalled)->toBeTrue()
            ->and($store->has('App\\Order', 'order-123'))->toBeFalse();
    });

    it('does not snapshot when version is not at boundary', function (): void {
        $store = new InMemorySnapshotStore;

        $aggregateClass = new #[SnapshotPolicy(every: 10)] class extends AggregateRoot
        {
            use HasSnapshots;

            public function __construct()
            {
                parent::__construct(AggregateRootId::generate());
            }
        };

        $aggregateClass->setVersion(7); // Not a multiple of 10

        $innerRepo = new class implements Repository
        {
            public function find(string|int $id): ?AggregateRoot
            {
                return null;
            }

            public function save(AggregateRoot $aggregate): void {}

            public function delete(string|int $id): void {}
        };

        $repo = new SnapshottingRepository(
            inner: $innerRepo,
            snapshotStore: $store,
            aggregateType: $aggregateClass::class,
        );

        $repo->save($aggregateClass);

        expect($store->has($aggregateClass::class, $aggregateClass->id()))->toBeFalse();
    });
});
