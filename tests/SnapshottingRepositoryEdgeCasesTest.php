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
use ZeroBoiler\Observability\Trace;

// ===========================================================================
//  SnapshottingRepository — edge cases and cross-cutting tests
// ===========================================================================

describe('SnapshottingRepository edge cases', function (): void {
    it('handles non-HasSnapshots aggregate gracefully on save', function (): void {
        $store = new InMemorySnapshotStore;

        $aggregate = new class extends AggregateRoot
        {
            public string $name = 'Test';

            public function __construct()
            {
                parent::__construct(AggregateRootId::generate());
            }
        };

        $savedAggregate = null;

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
            aggregateType: $aggregate::class,
        );

        // Should delegate to inner without error even though no HasSnapshots trait
        $repo->save($aggregate);

        expect($innerRepo->savedAggregate)->toBe($aggregate)
            ->and($store->count())->toBe(0);
    });

    it('returns inner result when both snapshot and inner have same version', function (): void {
        $store = new InMemorySnapshotStore;

        $aggregateClass = new class extends AggregateRoot
        {
            use HasSnapshots;

            public string $data = 'same-version';

            public function __construct()
            {
                parent::__construct(AggregateRootId::generate());
            }
        };

        $aggregateClass->setVersion(10);

        $snapshot = Snapshot::create(
            aggregateType: $aggregateClass::class,
            aggregateId: $aggregateClass->id(),
            version: 10,
            state: ['data' => 'snapshot'],
        );
        $store->save($snapshot);

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

        $result = $repo->find($aggregateClass->id());

        // Inner result should be preferred when version >= snapshot version
        expect($result)->not->toBeNull()
            ->and($result)->toBe($innerAggregate);
    });

    it('snapshotStore accessor returns the injected store', function (): void {
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
            aggregateType: 'App\\Test',
        );

        expect($repo->snapshotStore())->toBe($store);
    });

    it('delete on non-existent aggregate does not throw', function (): void {
        $store = new InMemorySnapshotStore;

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

        // Delete should not throw even if no snapshot exists
        $repo->delete('non-existent-id');

        expect($innerRepo->deleteCalled)->toBeTrue();
    });

    it('findWithSnapshot returns inner result when callback returns empty array', function (): void {
        $store = new InMemorySnapshotStore;

        $expectedAggregate = new class extends AggregateRoot
        {
            use EventSourced;
            use HasSnapshots;

            public string $value = 'restored';

            public function __construct()
            {
                parent::__construct(AggregateRootId::generate());
            }
        };

        $expectedAggregate->setVersion(5);

        $snapshot = Snapshot::create(
            aggregateType: $expectedAggregate::class,
            aggregateId: $expectedAggregate->id(),
            version: 5,
            state: ['value' => 'snapshot-value'],
        );
        $store->save($snapshot);

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

        // Callback returns empty — no additional events to replay
        $result = $repo->findWithSnapshot(
            $expectedAggregate->id(),
            fn (int $version) => [],
        );

        expect($result)->not->toBeNull()
            ->and($result->version())->toBe(5);
    });

    it('findWithSnapshot returns inner when snapshot class does not exist', function (): void {
        $store = new InMemorySnapshotStore;

        // Create snapshot for a non-existent class
        $store->save(Snapshot::create(
            aggregateType: 'NonExistent\\Aggregate',
            aggregateId: 'id-1',
            version: 1,
            state: [],
        ));

        $expectedAggregate = new class extends AggregateRoot
        {
            use HasSnapshots;

            public function __construct()
            {
                parent::__construct(AggregateRootId::generate());
            }
        };

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
            aggregateType: 'NonExistent\\Aggregate',
        );

        $result = $repo->findWithSnapshot('id-1');

        // Should fall back to inner since the snapshot class can't be instantiated
        expect($result)->toBe($expectedAggregate);
    });

    it('creates snapshot with multiple properties including enum', function (): void {
        $store = new InMemorySnapshotStore;

        $aggregateClass = new #[SnapshotPolicy(every: 1)] class extends AggregateRoot
        {
            use EventSourced;
            use HasSnapshots;

            public string $name = 'test';

            public int $count = 0;

            public ?string $nullable = null;

            public array $items = [];

            public bool $active = true;

            public float $rate = 0.0;

            public function __construct()
            {
                parent::__construct(AggregateRootId::generate());
            }
        };

        $aggregateClass->name = 'Order';
        $aggregateClass->count = 42;
        $aggregateClass->nullable = 'set';
        $aggregateClass->items = ['a', 'b'];
        $aggregateClass->active = false;
        $aggregateClass->rate = 3.14;
        $aggregateClass->setVersion(1);

        $snapshot = $aggregateClass->createSnapshot($store);

        $loaded = $store->load($aggregateClass::class, $aggregateClass->id());

        expect($loaded)->not->toBeNull()
            ->and($loaded->state['name'])->toBe('Order')
            ->and($loaded->state['count'])->toBe(42)
            ->and($loaded->state['nullable'])->toBe('set')
            ->and($loaded->state['items'])->toBe(['a', 'b'])
            ->and($loaded->state['active'])->toBeFalse()
            ->and($loaded->state['rate'])->toBe(3.14);
    });

    it('snapshotting repository handles version 0 aggregate', function (): void {
        $store = new InMemorySnapshotStore;

        $aggregateClass = new #[SnapshotPolicy(every: 1)] class extends AggregateRoot
        {
            use HasSnapshots;

            public string $data = 'initial';

            public function __construct()
            {
                parent::__construct(AggregateRootId::generate());
            }
        };

        // Version 0 — shouldSnapshot returns false
        expect($aggregateClass->shouldSnapshot())->toBeFalse();

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

describe('SnapshottingRepository observability stub', function (): void {
    it('Trace attribute stub is loaded when observability is not installed', function (): void {
        // Verify the stub class exists and is usable
        $stubFile = __DIR__ . '/../../src/stubs/Trace.php';

        expect(file_exists($stubFile))->toBeTrue();

        require_once $stubFile;

        expect(class_exists(Trace::class))->toBeTrue();

        $attr = new Trace(operation: 'test');
        expect($attr->operation)->toBe('test');
    });
});
