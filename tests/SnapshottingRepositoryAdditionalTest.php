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
use ZeroBoiler\Domain\Snapshots\InMemorySnapshotStore;
use ZeroBoiler\Domain\Snapshots\Snapshot;
use ZeroBoiler\Domain\Snapshots\SnapshotPolicy;
use ZeroBoiler\Domain\Snapshots\SnapshottingRepository;
use ZeroBoiler\Events\Domain\DomainEvent;

// ===========================================================================
//  SnapshottingRepository — additional production-ready tests
// ===========================================================================

describe('SnapshottingRepository additional tests', function (): void {
    it('find returns null when no snapshot and inner returns null', function (): void {
        $store = new InMemorySnapshotStore;

        $aggregateClass = new class extends AggregateRoot
        {
            use HasSnapshots;

            public function __construct()
            {
                parent::__construct(AggregateRootId::generate());
            }
        };

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

        expect($repo->find('nonexistent-id'))->toBeNull();
    });

    it('snapshotStore() returns the injected store', function (): void {
        $store = new InMemorySnapshotStore;

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
            aggregateType: 'TestAggregate',
        );

        expect($repo->snapshotStore())->toBe($store);
    });

    it('find falls back to inner when snapshot is for different aggregate type', function (): void {
        $store = new InMemorySnapshotStore;

        // Save a snapshot for type A
        $store->save(Snapshot::create(
            'App\\Order',
            'order-id',
            10,
            ['status' => 'shipped'],
        ));

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

        // Query for type B — should not find type A's snapshot
        $repo = new SnapshottingRepository(
            inner: $innerRepo,
            snapshotStore: $store,
            aggregateType: $expected::class,
        );

        $result = $repo->find($expected->id());

        expect($result)->toBe($expected);
    });

    it('findWithSnapshot returns inner when callback is null and snapshot exists', function (): void {
        $store = new InMemorySnapshotStore;

        $expected = new class extends AggregateRoot
        {
            use HasSnapshots;

            public function __construct()
            {
                parent::__construct(AggregateRootId::generate());
            }
        };
        $expected->setVersion(5);

        $store->save(Snapshot::create(
            $expected::class,
            $expected->id(),
            5,
            [],
        ));

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

        // findWithSnapshot with no callback should fall back to inner
        $result = $repo->findWithSnapshot($expected->id());

        expect($result)->toBe($expected);
    });

    it('save does not snapshot when aggregate does not use HasSnapshots', function (): void {
        $store = new InMemorySnapshotStore;

        $aggregateClass = new #[SnapshotPolicy(every: 1)]
        class extends AggregateRoot
        {
            // NOTE: does NOT use HasSnapshots

            public function __construct()
            {
                parent::__construct(AggregateRootId::generate());
            }
        };

        $aggregateClass->setVersion(10);

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

    it('findWithSnapshot replays events via reconstituteFromSnapshot', function (): void {
        $store = new InMemorySnapshotStore;

        $aggregateClass = new class extends AggregateRoot
        {
            use EventSourced;
            use HasSnapshots;

            public string $status = 'pending';

            public $count = 0;

            public function __construct()
            {
                parent::__construct(AggregateRootId::generate());
            }

            protected function applyStatusChanged(DomainEvent $event): void
            {
                $this->status = $event->payload['status'];
            }

            protected function applyCountIncremented(DomainEvent $event): void
            {
                $this->count = $event->payload['count'];
            }
        };

        $id = $aggregateClass->id();

        // Create a snapshot at version 5
        $store->save(Snapshot::create(
            aggregateType: $aggregateClass::class,
            aggregateId: $id,
            version: 5,
            state: ['status' => 'confirmed', 'count' => 3],
        ));

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

        // Provide post-snapshot events
        $postEvents = [
            DomainEvent::occur('status.changed', ['status' => 'shipped']),
            DomainEvent::occur('count.incremented', ['count' => 7]),
        ];

        $result = $repo->findWithSnapshot(
            $id,
            fn (int $version) => $postEvents,
        );

        expect($result)->not->toBeNull()
            ->and($result->status)->toBe('shipped')
            ->and($result->count)->toBe(7);
    });

    it('save creates snapshot at version 1 when policy is every:1', function (): void {
        $store = new InMemorySnapshotStore;

        $aggregateClass = new #[SnapshotPolicy(every: 1)]
        class extends AggregateRoot
        {
            use HasSnapshots;

            public function __construct()
            {
                parent::__construct(AggregateRootId::generate());
            }
        };

        $aggregateClass->setVersion(1);

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
            ->and($loaded->version)->toBe(1);
    });

    it('delete removes snapshot even if inner delete throws', function (): void {
        $store = new InMemorySnapshotStore;

        $store->save(Snapshot::create(
            'App\\Order',
            'order-456',
            3,
            ['status' => 'active'],
        ));

        expect($store->has('App\\Order', 'order-456'))->toBeTrue();

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

        $repo->delete('order-456');

        expect($innerRepo->deleteCalled)->toBeTrue()
            ->and($store->has('App\\Order', 'order-456'))->toBeFalse();
    });

    it('save delegates to inner repository', function (): void {
        $store = new InMemorySnapshotStore;

        $savedAggregate = null;

        $aggregateClass = new class extends AggregateRoot
        {
            use HasSnapshots;

            public function __construct()
            {
                parent::__construct(AggregateRootId::generate());
            }
        };

        $innerRepo = new class implements Repository
        {
            public ?AggregateRoot $savedAggregate = null;

            public function find(string|int $id): ?AggregateRoot
            {
                return null;
            }

            public function save(AggregateRoot $aggregate): void
            {
                $this->savedAggregate = $aggregate;
            }

            public function delete(string|int $id): void {}
        };

        $repo = new SnapshottingRepository(
            inner: $innerRepo,
            snapshotStore: $store,
            aggregateType: $aggregateClass::class,
        );

        $repo->save($aggregateClass);

        expect($innerRepo->savedAggregate)->toBe($aggregateClass);
    });
});
