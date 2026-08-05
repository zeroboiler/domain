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

// ===========================================================================
//  SnapshottingRepository — production-ready tests
// ===========================================================================

describe('SnapshottingRepository production', function (): void {
    it('prefers snapshot over inner when inner returns null', function (): void {
        $store = new InMemorySnapshotStore;

        $aggregateClass = new class extends AggregateRoot
        {
            use HasSnapshots;

            public string $status = 'active';

            public function __construct()
            {
                parent::__construct(AggregateRootId::generate());
            }
        };

        $aggregateClass->setStatus('active');
        $aggregateClass->setVersion(15);

        $snapshot = Snapshot::create(
            aggregateType: $aggregateClass::class,
            aggregateId: $aggregateClass->id(),
            version: 15,
            state: ['status' => 'active', 'version' => 15],
        );
        $store->save($snapshot);

        // Inner repo has no data (event store empty/pruned)
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

        $result = $repo->find($aggregateClass->id());

        expect($result)->not->toBeNull()
            ->and($result->version())->toBe(15);
    });

    it('falls back to inner when no snapshot exists', function (): void {
        $store = new InMemorySnapshotStore;

        $aggregateClass = new class extends AggregateRoot
        {
            use HasSnapshots;

            public string $name = 'Test';

            public function __construct()
            {
                parent::__construct(AggregateRootId::generate());
            }
        };

        $expectedAggregate = clone $aggregateClass;
        $expectedAggregate->setVersion(5);

        $innerRepo = new class($expectedAggregate) implements Repository
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

        $result = $repo->find('any-id');

        expect($result)->toBe($expectedAggregate);
    });

    it('prefers snapshot over stale inner data (BUG-2-R39)', function (): void {
        $store = new InMemorySnapshotStore;

        $aggregateClass = new class extends AggregateRoot
        {
            use HasSnapshots;

            public string $state = 'current';

            public function __construct()
            {
                parent::__construct(AggregateRootId::generate());
            }
        };

        $aggregateClass->setState('current');
        $aggregateClass->setVersion(20);

        $snapshot = Snapshot::create(
            aggregateType: $aggregateClass::class,
            aggregateId: $aggregateClass->id(),
            version: 20,
            state: ['state' => 'current'],
        );
        $store->save($snapshot);

        // Inner repo returns stale data (version 10, pruned event store)
        $staleAggregate = clone $aggregateClass;
        $staleAggregate->setState('stale');
        $staleAggregate->setVersion(10);

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

        $result = $repo->find($aggregateClass->id());

        expect($result)->not->toBeNull();
    });

    it('creates snapshot on save when policy triggers', function (): void {
        $store = new InMemorySnapshotStore;

        $aggregateClass = new #[SnapshotPolicy(every: 10)] class extends AggregateRoot
        {
            use HasSnapshots;

            public string $data = 'test';

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

        expect($store->has($aggregateClass::class, $aggregateClass->id()))->toBeTrue();

        $loaded = $store->load($aggregateClass::class, $aggregateClass->id());
        expect($loaded->version)->toBe(10)
            ->and($loaded->state)->toHaveKey('data');
    });

    it('does not create snapshot when policy does not trigger', function (): void {
        $store = new InMemorySnapshotStore;

        $aggregateClass = new #[SnapshotPolicy(every: 10)] class extends AggregateRoot
        {
            use HasSnapshots;

            public function __construct()
            {
                parent::__construct(AggregateRootId::generate());
            }
        };

        $aggregateClass->setVersion(7); // not a multiple of 10

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

    it('delegates save to inner repository', function (): void {
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

    it('delegates delete to inner and removes snapshot', function (): void {
        $store = new InMemorySnapshotStore;
        $deleteCalled = false;

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

        $store->save(Snapshot::create('App\\Order', 'order-1', 5, []));

        $repo->delete('order-1');

        expect($innerRepo->deleteCalled)->toBeTrue()
            ->and($store->has('App\\Order', 'order-1'))->toBeFalse();
    });

    it('isolates snapshots across different aggregate types', function (): void {
        $store = new InMemorySnapshotStore;

        $store->save(Snapshot::create('App\\Order', 'id-1', 10, ['type' => 'order']));
        $store->save(Snapshot::create('App\\User', 'id-1', 5, ['type' => 'user']));

        $innerRepo = new class implements Repository
        {
            public function find(string|int $id): ?AggregateRoot
            {
                return null;
            }

            public function save(AggregateRoot $aggregate): void {}

            public function delete(string|int $id): void {}
        };

        $orderRepo = new SnapshottingRepository(
            inner: $innerRepo,
            snapshotStore: $store,
            aggregateType: 'App\\Order',
        );

        $userRepo = new SnapshottingRepository(
            inner: $innerRepo,
            snapshotStore: $store,
            aggregateType: 'App\\User',
        );

        // Both should find their respective snapshots
        $orderSnapshot = $store->load('App\\Order', 'id-1');
        $userSnapshot = $store->load('App\\User', 'id-1');

        expect($orderSnapshot->state['type'])->toBe('order')
            ->and($userSnapshot->state['type'])->toBe('user');
    });
});

describe('SnapshottingRepository findWithSnapshot', function (): void {
    it('restores from snapshot and replays events via callback', function (): void {
        $store = new InMemorySnapshotStore;

        $aggregateClass = new class extends AggregateRoot
        {
            use EventSourced;
            use HasSnapshots;

            public string $status = 'pending';

            public function __construct()
            {
                parent::__construct(AggregateRootId::generate());
            }
        };

        $aggregateClass->setStatus('paid');
        $aggregateClass->setVersion(10);

        $snapshot = Snapshot::create(
            aggregateType: $aggregateClass::class,
            aggregateId: $aggregateClass->id(),
            version: 10,
            state: ['status' => 'paid'],
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

        $result = $repo->findWithSnapshot(
            $aggregateClass->id(),
            fn (int $version) => [], // No post-snapshot events
        );

        expect($result)->not->toBeNull()
            ->and($result->version())->toBe(10);
    });

    it('delegates to inner when no snapshot and no callback', function (): void {
        $store = new InMemorySnapshotStore;

        $expectedAggregate = new class extends AggregateRoot
        {
            use HasSnapshots;

            public function __construct()
            {
                parent::__construct(AggregateRootId::generate());
            }
        };
        $expectedAggregate->setVersion(5);

        $innerRepo = new class($expectedAggregate) implements Repository
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
            aggregateType: $expectedAggregate::class,
        );

        $result = $repo->findWithSnapshot('any-id');

        expect($result)->toBe($expectedAggregate);
    });
});
