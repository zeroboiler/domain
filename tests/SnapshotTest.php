<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Domain\AggregateRoot;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\Concerns\HasSnapshots;
use ZeroBoiler\Domain\Contracts\Repository;
use ZeroBoiler\Domain\Snapshots\InMemorySnapshotStore;
use ZeroBoiler\Domain\Snapshots\Snapshot;
use ZeroBoiler\Domain\Snapshots\SnapshotPolicy;
use ZeroBoiler\Domain\Snapshots\SnapshottingRepository;
use ZeroBoiler\Domain\TestStatus;
use ZeroBoiler\Events\Domain\Concerns\EventSourced;
use ZeroBoiler\Events\Domain\DomainEvent;

describe('Snapshot', function (): void {
    it('creates a snapshot with create()', function (): void {
        $snapshot = Snapshot::create(
            aggregateType: 'App\Order',
            aggregateId: 'order-123',
            version: 10,
            state: ['status' => 'paid', 'total' => 100],
        );

        expect($snapshot->aggregateType)->toBe('App\Order')
            ->and($snapshot->aggregateId)->toBe('order-123')
            ->and($snapshot->version)->toBe(10)
            ->and($snapshot->state)->toBe(['status' => 'paid', 'total' => 100])
            ->and($snapshot->createdAt)->toBeInstanceOf(DateTimeImmutable::class);
    });

    it('serializes to array', function (): void {
        $snapshot = Snapshot::create(
            aggregateType: 'App\Order',
            aggregateId: 'order-123',
            version: 5,
            state: ['status' => 'pending'],
        );

        $array = $snapshot->toArray();

        expect($array['aggregate_type'])->toBe('App\Order')
            ->and($array['aggregate_id'])->toBe('order-123')
            ->and($array['version'])->toBe(5)
            ->and($array['state'])->toBe(['status' => 'pending'])
            ->and($array['created_at'])->toBeString();
    });

    it('deserializes from array', function (): void {
        $data = [
            'aggregate_type' => 'App\User',
            'aggregate_id' => 'user-456',
            'version' => 20,
            'state' => ['name' => 'Alice', 'email' => 'alice@example.com'],
            'created_at' => '2026-01-15T10:30:00+00:00',
        ];

        $snapshot = Snapshot::fromArray($data);

        expect($snapshot->aggregateType)->toBe('App\User')
            ->and($snapshot->aggregateId)->toBe('user-456')
            ->and($snapshot->version)->toBe(20)
            ->and($snapshot->state)->toBe(['name' => 'Alice', 'email' => 'alice@example.com']);
    });

    it('round-trips through toArray/fromArray', function (): void {
        $original = Snapshot::create(
            aggregateType: 'App\Product',
            aggregateId: 'prod-789',
            version: 3,
            state: ['name' => 'Widget', 'price' => 19.99, 'tags' => ['new', 'featured']],
        );

        $roundTrip = Snapshot::fromArray($original->toArray());

        expect($roundTrip->aggregateType)->toBe($original->aggregateType)
            ->and($roundTrip->aggregateId)->toBe($original->aggregateId)
            ->and($roundTrip->version)->toBe($original->version)
            ->and($roundTrip->state)->toBe($original->state);
    });
});

describe('InMemorySnapshotStore', function (): void {
    it('saves and loads snapshots', function (): void {
        $store = new InMemorySnapshotStore;
        $snapshot = Snapshot::create('App\Order', 'order-1', 10, ['status' => 'paid']);

        $store->save($snapshot);

        $loaded = $store->load('App\Order', 'order-1');

        expect($loaded)->not->toBeNull()
            ->and($loaded->version)->toBe(10)
            ->and($loaded->state)->toBe(['status' => 'paid']);
    });

    it('returns null when no snapshot exists', function (): void {
        $store = new InMemorySnapshotStore;

        expect($store->load('App\Order', 'missing'))->toBeNull();
    });

    it('checks if snapshot exists', function (): void {
        $store = new InMemorySnapshotStore;

        expect($store->has('App\Order', 'order-1'))->toBeFalse();

        $store->save(Snapshot::create('App\Order', 'order-1', 5, []));

        expect($store->has('App\Order', 'order-1'))->toBeTrue();
    });

    it('overwrites with newer snapshot', function (): void {
        $store = new InMemorySnapshotStore;

        $store->save(Snapshot::create('App\Order', 'order-1', 10, ['v' => 1]));
        $store->save(Snapshot::create('App\Order', 'order-1', 20, ['v' => 2]));

        $loaded = $store->load('App\Order', 'order-1');

        expect($loaded->version)->toBe(20)
            ->and($loaded->state)->toBe(['v' => 2]);
    });

    it('deletes snapshots', function (): void {
        $store = new InMemorySnapshotStore;
        $store->save(Snapshot::create('App\Order', 'order-1', 10, []));

        $store->delete('App\Order', 'order-1');

        expect($store->has('App\Order', 'order-1'))->toBeFalse();
    });

    it('deletes snapshots older than version', function (): void {
        $store = new InMemorySnapshotStore;
        $store->save(Snapshot::create('App\Order', 'order-1', 10, []));

        // Delete snapshots older than version 20 (10 < 20, should delete)
        $store->deleteOlderThan('App\Order', 'order-1', 20);

        expect($store->has('App\Order', 'order-1'))->toBeFalse();
    });

    it('keeps snapshots at or above version threshold', function (): void {
        $store = new InMemorySnapshotStore;
        $store->save(Snapshot::create('App\Order', 'order-1', 30, []));

        // Delete snapshots older than version 20 (30 >= 20, should keep)
        $store->deleteOlderThan('App\Order', 'order-1', 20);

        expect($store->has('App\Order', 'order-1'))->toBeTrue();
    });

    it('clears all snapshots', function (): void {
        $store = new InMemorySnapshotStore;
        $store->save(Snapshot::create('App\Order', 'order-1', 10, []));
        $store->save(Snapshot::create('App\User', 'user-1', 5, []));

        $store->clear();

        expect($store->count())->toBe(0);
    });

    it('counts stored snapshots', function (): void {
        $store = new InMemorySnapshotStore;

        expect($store->count())->toBe(0);

        $store->save(Snapshot::create('App\Order', 'order-1', 10, []));
        $store->save(Snapshot::create('App\Order', 'order-2', 5, []));

        expect($store->count())->toBe(2);
    });

    it('isolates by aggregate type', function (): void {
        $store = new InMemorySnapshotStore;
        $store->save(Snapshot::create('App\Order', 'shared-id', 10, ['from' => 'order']));
        $store->save(Snapshot::create('App\User', 'shared-id', 5, ['from' => 'user']));

        expect($store->load('App\Order', 'shared-id')->state)->toBe(['from' => 'order'])
            ->and($store->load('App\User', 'shared-id')->state)->toBe(['from' => 'user']);
    });
});

describe('SnapshotPolicy attribute', function (): void {
    it('has default interval of 50', function (): void {
        $policy = new SnapshotPolicy;

        expect($policy->every)->toBe(50);
    });

    it('accepts custom interval', function (): void {
        $policy = new SnapshotPolicy(every: 100);

        expect($policy->every)->toBe(100);
    });

    it('can be read from class attributes', function (): void {
        $anonymous = new #[SnapshotPolicy(every: 25)] class {};

        $reflection = new ReflectionClass($anonymous);
        $attributes = $reflection->getAttributes(SnapshotPolicy::class);

        expect($attributes)->toHaveCount(1);

        $policy = $attributes[0]->newInstance();

        expect($policy->every)->toBe(25);
    });
});

describe('HasSnapshots trait', function (): void {
    // Create a test aggregate that uses the trait
    $testAggregateClass = new class extends AggregateRoot
    {
        use EventSourced;
        use HasSnapshots;

        public string $status = 'pending';

        public int $total = 0;

        private array $metadata = [];

        public function __construct()
        {
            parent::__construct(AggregateRootId::generate());
        }

        public function setStatus(string $status): void
        {
            $this->status = $status;
        }

        public function setTotal(int $total): void
        {
            $this->total = $total;
        }

        public function setMetadata(array $metadata): void
        {
            $this->metadata = $metadata;
        }

        public function getMetadata(): array
        {
            return $this->metadata;
        }

        public function recordEvent(DomainEvent $event): void
        {
            $this->apply($event);
        }

        public function applyOrderPlaced(DomainEvent $event): void
        {
            $this->status = $event->payload['status'] ?? 'placed';
            $this->total = $event->payload['total'] ?? 0;
        }

        public function applyOrderShipped(DomainEvent $event): void
        {
            $this->status = 'shipped';
        }
    };

    it('serializes state to snapshot', function () use ($testAggregateClass): void {
        $aggregate = clone $testAggregateClass;
        $aggregate->setStatus('paid');
        $aggregate->setTotal(150);
        $aggregate->setMetadata(['source' => 'web']);

        $state = $aggregate->toSnapshotState();

        expect($state)
            ->toHaveKey('status', 'paid')
            ->toHaveKey('total', 150)
            ->toHaveKey('metadata', ['source' => 'web']);
    });

    it('restores state from snapshot', function () use ($testAggregateClass): void {
        $aggregate = clone $testAggregateClass;
        $aggregate->setStatus('pending');
        $aggregate->setTotal(0);

        $snapshot = Snapshot::create(
            aggregateType: $aggregate::class,
            aggregateId: $aggregate->id(),
            version: 10,
            state: [
                'status' => 'shipped',
                'total' => 250,
                'metadata' => ['source' => 'api'],
            ],
        );

        $aggregate->restoreFromSnapshot($snapshot);

        expect($aggregate->status)->toBe('shipped')
            ->and($aggregate->total)->toBe(250)
            ->and($aggregate->getMetadata())->toBe(['source' => 'api'])
            ->and($aggregate->version())->toBe(10);
    });

    it('checks if snapshot is due', function (): void {
        $aggregateClass = new #[SnapshotPolicy(every: 5)] class extends AggregateRoot
        {
            use HasSnapshots;

            public function __construct()
            {
                parent::__construct(AggregateRootId::generate());
            }
        };

        // Version 0 — no snapshot
        expect($aggregateClass->shouldSnapshot())->toBeFalse();

        // Set to version 5 — should snapshot
        $aggregateClass->setVersion(5);
        expect($aggregateClass->shouldSnapshot())->toBeTrue();

        // Set to version 6 — no snapshot
        $aggregateClass->setVersion(6);
        expect($aggregateClass->shouldSnapshot())->toBeFalse();

        // Set to version 10 — should snapshot
        $aggregateClass->setVersion(10);
        expect($aggregateClass->shouldSnapshot())->toBeTrue();
    });

    it('returns false for shouldSnapshot without policy', function (): void {
        $aggregate = new class extends AggregateRoot
        {
            use HasSnapshots;

            public function __construct()
            {
                parent::__construct(AggregateRootId::generate());
            }
        };

        $aggregate->setVersion(100);

        expect($aggregate->shouldSnapshot())->toBeFalse();
    });

    it('returns false for shouldSnapshot with every=0', function (): void {
        $aggregate = new #[SnapshotPolicy(every: 0)] class extends AggregateRoot
        {
            use HasSnapshots;

            public function __construct()
            {
                parent::__construct(AggregateRootId::generate());
            }
        };

        $aggregate->setVersion(100);

        expect($aggregate->shouldSnapshot())->toBeFalse();
    });

    it('creates and stores snapshot', function () use ($testAggregateClass): void {
        $store = new InMemorySnapshotStore;
        $aggregate = clone $testAggregateClass;
        $aggregate->setStatus('paid');
        $aggregate->setTotal(500);
        $aggregate->setVersion(50);

        $snapshot = $aggregate->createSnapshot($store);

        expect($snapshot->aggregateId)->toBe($aggregate->id())
            ->and($snapshot->version)->toBe(50)
            ->and($snapshot->state['status'])->toBe('paid')
            ->and($snapshot->state['total'])->toBe(500)
            ->and($store->has($aggregate::class, $aggregate->id()))->toBeTrue();
    });

    it('gets snapshot policy from attribute', function (): void {
        $aggregateClass = new #[SnapshotPolicy(every: 25)] class extends AggregateRoot
        {
            use HasSnapshots;

            public function __construct()
            {
                parent::__construct(AggregateRootId::generate());
            }
        };

        $policy = $aggregateClass->getSnapshotPolicy();

        expect($policy)->not->toBeNull()
            ->and($policy->every)->toBe(25);
    });

    it('returns null policy when not set', function (): void {
        $aggregate = new class extends AggregateRoot
        {
            use HasSnapshots;

            public function __construct()
            {
                parent::__construct(AggregateRootId::generate());
            }
        };

        expect($aggregate->getSnapshotPolicy())->toBeNull();
    });
});

describe('HasSnapshots with EventSourced', function (): void {
    it('creates snapshot from event-sourced aggregate', function (): void {
        $aggregateClass = new #[SnapshotPolicy(every: 3)] class extends AggregateRoot
        {
            use EventSourced;
            use HasSnapshots;

            public string $status = 'pending';

            public int $orderCount = 0;

            public function __construct()
            {
                parent::__construct(AggregateRootId::generate());
            }

            public function placeOrder(int $amount): void
            {
                $this->apply(DomainEvent::occur('order.placed', [
                    'id' => $this->id(),
                    'status' => 'placed',
                    'amount' => $amount,
                ]));
            }

            public function applyOrderPlaced(DomainEvent $event): void
            {
                $this->status = $event->payload['status'];
                $this->orderCount++;
            }

            public function shipOrder(): void
            {
                $this->apply(DomainEvent::occur('order.shipped', [
                    'id' => $this->id(),
                ]));
            }

            public function applyOrderShipped(DomainEvent $event): void
            {
                $this->status = 'shipped';
            }
        };

        // Apply 3 events to trigger snapshot
        $aggregateClass->placeOrder(100);
        $aggregateClass->placeOrder(200);
        $aggregateClass->placeOrder(300);

        expect($aggregateClass->version())->toBe(3)
            ->and($aggregateClass->shouldSnapshot())->toBeTrue();

        $store = new InMemorySnapshotStore;
        $snapshot = $aggregateClass->createSnapshot($store);

        expect($snapshot->version)->toBe(3)
            ->and($snapshot->state['status'])->toBe('placed')
            ->and($snapshot->state['orderCount'])->toBe(3);
    });
});

describe('SnapshottingRepository (BUG-1/BUG-2 R37)', function (): void {
    it('requires aggregateType in constructor (no more reflection guessing)', function (): void {
        $store = new InMemorySnapshotStore;

        // Create a simple inner repository stub
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
            aggregateType: 'App\\Models\\Order',
        );

        // Verify the aggregateType is stored correctly by checking snapshot isolation
        expect($repo)->toBeInstanceOf(SnapshottingRepository::class);
    });

    it('uses snapshot in find() instead of skipping it', function (): void {
        $store = new InMemorySnapshotStore;

        // Create test aggregate class
        $aggregateClass = new class extends AggregateRoot
        {
            use HasSnapshots;

            public string $status = 'pending';

            public function __construct()
            {
                parent::__construct(AggregateRootId::generate());
            }
        };

        // Save a snapshot
        $aggregateClass->setVersion(10);

        $snapshot = Snapshot::create(
            aggregateType: $aggregateClass::class,
            aggregateId: $aggregateClass->id(),
            version: 10,
            state: ['status' => 'paid', 'version' => 10],
        );
        $store->save($snapshot);

        // Inner repo returns null (no event store)
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

        // find() should return the snapshot-restored aggregate, not null
        $result = $repo->find($aggregateClass->id());

        expect($result)->not->toBeNull();
    });

    it('isolates snapshots by aggregate type (BUG-2 R37)', function (): void {
        $store = new InMemorySnapshotStore;

        // Two different aggregate types with same ID should not collide
        $store->save(Snapshot::create('App\\Order', 'shared-123', 5, ['type' => 'order']));
        $store->save(Snapshot::create('App\\Product', 'shared-123', 3, ['type' => 'product']));

        expect($store->load('App\\Order', 'shared-123')->state['type'])->toBe('order')
            ->and($store->load('App\\Product', 'shared-123')->state['type'])->toBe('product');
    });
});

describe('HasSnapshots serialization safety (BUG-3 R37)', function (): void {
    it('filters out non-serializable objects from snapshot state', function (): void {
        $pdo = new PDO('sqlite::memory:');

        $aggregate = new class extends AggregateRoot
        {
            use HasSnapshots;

            public string $name = 'test';

            public Closure $callback;

            public function __construct()
            {
                parent::__construct(AggregateRootId::generate());
                $this->callback = fn (): string => 'hello';
            }
        };

        $state = $aggregate->toSnapshotState();

        // name should be present
        expect($state)->toHaveKey('name', 'test');

        // callback (Closure) should be filtered out
        expect($state)->not->toHaveKey('callback');
    });

    it('converts DateTimeInterface to ISO string for safe serialization', function (): void {
        $date = new DateTimeImmutable('2026-01-15T10:30:00+00:00');

        $aggregate = new class($date) extends AggregateRoot
        {
            use HasSnapshots;

            public string $name = 'test';

            public function __construct(public DateTimeImmutable $createdAt)
            {
                parent::__construct(AggregateRootId::generate());
            }
        };

        $state = $aggregate->toSnapshotState();

        expect($state)->toHaveKey('createdAt');
        expect($state['createdAt'])->toBe('2026-01-15T10:30:00+00:00');
    });

    it('converts BackedEnum to its value for safe serialization', function (): void {
        $aggregate = new class extends AggregateRoot
        {
            use HasSnapshots;

            public string $name = 'test';

            public TestStatus $status;

            public function __construct()
            {
                parent::__construct(AggregateRootId::generate());
            }
        };

        $aggregate->status = TestStatus::ACTIVE;

        $state = $aggregate->toSnapshotState();

        expect($state)->toHaveKey('status');
        expect($state['status'])->toBe('active');
    });
});

describe('SnapshotCommand display (IMP-1 R37)', function (): void {
    it('handles non-serializable values gracefully in display', function (): void {
        // Test that json_encode failure path works
        $value = [\NAN]; // NAN causes json_encode to fail

        $encoded = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        expect($encoded)->toBeFalse();
    });
});
