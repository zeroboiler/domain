<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Domain\AggregateRoot;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\Contracts\Repository;
use ZeroBoiler\Domain\Contracts\UnitOfWork;
use ZeroBoiler\Domain\Concerns\EventSourced;
use ZeroBoiler\Domain\Concerns\HasSnapshots;
use ZeroBoiler\Domain\Identifiers\UuidIdentifier;
use ZeroBoiler\Domain\Identifiers\UlidIdentifier;
use ZeroBoiler\Domain\Identifiers\StringIdentifier;
use ZeroBoiler\Domain\Identifiers\IntegerIdentifier;
use ZeroBoiler\Domain\Snapshots\{Snapshot, SnapshotPolicy, SnapshottingRepository, InMemorySnapshotStore};
use ZeroBoiler\Domain\Exceptions\{
    DomainException,
    InvalidStateDomainException,
    InvalidArgumentDomainException,
    NotFoundDomainException,
    AggregateNotFoundException,
    ConflictDomainException,
    OptimisticLockException,
    InvalidAggregateRootException,
};
use ZeroBoiler\Events\Domain\DomainEvent;

/**
 * End-to-end integration test verifying the complete domain → response pipeline.
 *
 * This test covers:
 * 1. Aggregate root lifecycle (create, mutate, events, versioning)
 * 2. Event sourcing reconstitution from history
 * 3. Snapshot save/restore cycle
 * 4. Unit of work transactional semantics
 * 5. Repository with optimistic locking
 * 6. Domain exception hierarchy with error codes
 * 7. Identifier serialization for API responses
 * 8. Domain invariant enforcement
 * 9. Cross-type identifier inequality
 *
 * @since 1.43.0
 */

// ─── Test Fixtures ────────────────────────────────────────────────────────────

final class OrderId extends UuidIdentifier {}

final class ProductId extends UlidIdentifier {}

#[SnapshotPolicy(every: 3)]
final class TestOrder extends AggregateRoot
{
    use EventSourced;
    use HasSnapshots;

    public string $status = 'pending';
    public float $total = 0.0;
    public array $items = [];

    public function __construct(AggregateRootId $id)
    {
        parent::__construct($id);
    }

    public static function create(AggregateRootId $id, array $items = []): self
    {
        $order = new self($id);
        $order->apply(DomainEvent::occur('order.placed', [
            'id' => $id->toString(),
            'items' => $items,
        ]));

        return $order;
    }

    public function addItem(string $productId, int $quantity, float $price): void
    {
        if ($this->status !== 'pending') {
            throw InvalidStateDomainException::because(
                'Cannot add items to a non-pending order.',
                code: 'ORDER_NOT_PENDING',
            );
        }

        if ($quantity <= 0) {
            throw InvalidArgumentDomainException::because(
                'Quantity must be positive.',
                code: 'INVALID_QUANTITY',
            );
        }

        $this->apply(DomainEvent::occur('order.item_added', [
            'product_id' => $productId,
            'quantity' => $quantity,
            'price' => $price,
        ]));
    }

    public function pay(float $amount): void
    {
        if ($this->status !== 'pending') {
            throw InvalidStateDomainException::because('Order must be pending to pay.');
        }

        $this->apply(DomainEvent::occur('order.paid', [
            'amount' => $amount,
        ]));
    }

    public function ship(): void
    {
        if ($this->status !== 'paid') {
            throw InvalidStateDomainException::because('Order must be paid to ship.');
        }

        $this->apply(DomainEvent::occur('order.shipped', []));
    }

    protected function applyOrderPlaced(DomainEvent $event): void
    {
        $this->status = 'event:pending';
        $this->items = $event->payload['items'] ?? [];
    }

    protected function applyOrderItemAdded(DomainEvent $event): void
    {
        $this->items[] = [
            'product_id' => $event->payload['product_id'],
            'quantity' => $event->payload['quantity'],
            'price' => $event->payload['price'],
        ];
        $this->total += $event->payload['quantity'] * $event->payload['price'];
    }

    protected function applyOrderPaid(DomainEvent $event): void
    {
        $this->status = 'paid';
        $this->total = $event->payload['amount'];
    }

    protected function applyOrderShipped(DomainEvent $event): void
    {
        $this->status = 'shipped';
    }

    public function toSnapshotState(): array
    {
        return [
            'status' => $this->status,
            'total' => $this->total,
            'items' => $this->items,
        ];
    }

    public static function reconstituteFromSnapshot(Snapshot $snapshot, AggregateRootId $id): static
    {
        $order = new static($id);
        $order->status = $snapshot->state['status'];
        $order->total = $snapshot->state['total'];
        $order->items = $snapshot->state['items'];
        $order->setVersion($snapshot->version);

        return $order;
    }
}

// In-memory repository for testing
final class InMemoryOrderRepository implements Repository
{
    /** @var array<string, TestOrder> */
    private array $aggregates = [];

    public function find(string|int $id): ?AggregateRoot
    {
        return $this->aggregates[$id] ?? null;
    }

    public function save(AggregateRoot $aggregate): void
    {
        $this->aggregates[$aggregate->id()] = $aggregate;
    }

    public function delete(string|int $id): void
    {
        unset($this->aggregates[$id]);
    }
}

// ─── Tests ───────────────────────────────────────────────────────────────────

// 1. Aggregate Root Lifecycle
test('aggregate root create records placed event and increments version', function () {
    $id = AggregateRootId::generate();
    $order = TestOrder::create($id, ['item1']);

    expect($order->id())->toBe($id->toString());
    expect($order->version())->toBe(1);
    expect($order->status)->toBe('event:pending');
    expect($order->hasUncommittedEvents())->toBeTrue();

    $events = $order->pullDomainEvents();
    expect($events->count())->toBe(1);
    expect($events->first()->eventType)->toBe('order.placed');
    expect($order->hasUncommittedEvents())->toBeFalse();
});

test('aggregate root mutation records events and enforces invariants', function () {
    $id = AggregateRootId::generate();
    $order = TestOrder::create($id);
    $order->pullDomainEvents(); // clear

    $order->addItem('prod-1', 2, 9.99);
    expect($order->version())->toBe(2);
    expect($order->total)->toBe(19.98);
    expect($order->hasUncommittedEvents())->toBeTrue();

    $order->pay(19.98);
    expect($order->version())->toBe(3);
    expect($order->status)->toBe('paid');

    $order->ship();
    expect($order->version())->toBe(4);
    expect($order->status)->toBe('shipped');
});

test('aggregate root invariant violation throws typed domain exception', function () {
    $id = AggregateRootId::generate();
    $order = TestOrder::create($id);
    $order->pay(10.0);
    $order->pullDomainEvents();

    // Cannot add items to paid order
    expect(fn () => $order->addItem('prod-1', 1, 5.0))
        ->toThrow(InvalidStateDomainException::class, 'ORDER_NOT_PENDING');

    // Invalid quantity
    $order2 = TestOrder::create($id);
    $order2->pullDomainEvents();

    expect(fn () => $order2->addItem('prod-1', 0, 5.0))
        ->toThrow(InvalidArgumentDomainException::class, 'INVALID_QUANTITY');
});

test('aggregate root equality and identity', function () {
    $id = AggregateRootId::generate();
    $id2 = AggregateRootId::generate();
    $order1 = TestOrder::create($id);
    $order2 = TestOrder::create($id);
    $order3 = TestOrder::create($id2);

    expect($id->equals($id))->toBeTrue();
    expect($id->equals($id2))->toBeFalse();
    expect($order1->id())->toBe($order2->id());
    expect($order1->id())->not->toBe($order3->id());
});

// 2. Event Sourcing — Reconstitution from History
test('event sourcing reconstitutes aggregate from event history', function () {
    $id = AggregateRootId::generate();
    $order = TestOrder::create($id);
    $order->addItem('prod-1', 3, 10.0);
    $order->pay(30.0);

    $events = $order->pullDomainEvents();
    expect($events->count())->toBe(3);

    // Reconstitute from history
    $restored = TestOrder::fromHistory(...$events->all());

    expect($restored->id())->toBe($order->id());
    expect($restored->version())->toBe(3);
    expect($restored->status)->toBe('paid');
    expect($restored->total)->toBe(30.0);
    expect($restored->hasUncommittedEvents())->toBeFalse(); // No new events during replay
});

// 3. Snapshot Save/Restore Cycle
test('snapshot save and restore preserves aggregate state', function () {
    $id = AggregateRootId::generate();
    $order = TestOrder::create($id);
    $order->addItem('prod-1', 2, 5.0);
    $order->addItem('prod-2', 1, 10.0);

    $store = new InMemorySnapshotStore;

    // Should snapshot at version 3 (SnapshotPolicy every: 3)
    expect($order->shouldSnapshot())->toBeTrue();

    $order->createSnapshot($store);
    expect($store->has(TestOrder::class, $order->id()))->toBeTrue();

    // Restore from snapshot
    $snapshot = $store->load(TestOrder::class, $order->id());
    $restored = TestOrder::reconstituteFromSnapshot($snapshot, $id);

    expect($restored->status)->toBe('event:pending');
    expect($restored->total)->toBe(20.0);
    expect($restored->version())->toBe(3);
    expect(count($restored->items))->toBe(2);
});

test('snapshot round-trip serialization', function () {
    $snapshot = Snapshot::create(
        TestOrder::class,
        'order-123',
        50,
        ['status' => 'paid', 'total' => 99.99, 'items' => ['a', 'b']],
    );

    $array = $snapshot->toArray();
    $restored = Snapshot::fromArray($array);

    expect($restored->aggregateType)->toBe($snapshot->aggregateType);
    expect($restored->aggregateId)->toBe($snapshot->aggregateId);
    expect($restored->version)->toBe($snapshot->version);
    expect($restored->state)->toBe($snapshot->state);
    expect($restored->equals($snapshot))->toBeTrue();

    // JSON serialization
    $json = json_encode($snapshot);
    expect($json)->toBeJson();
    $decoded = json_decode($json, true);
    expect($decoded['aggregate_type'])->toBe(TestOrder::class);
});

// 4. Unit of Work Transactional Semantics
test('unit of work run auto-commits on success', function () {
    $uow = app(UnitOfWork::class);
    $repo = new InMemoryOrderRepository;

    $uow->setPersistenceCallback(function (array $committed, array $deleted) use ($repo): void {
        foreach ($committed as $aggregate) {
            $repo->save($aggregate);
        }
    });

    $id = AggregateRootId::generate();
    $result = $uow->run(function () use ($id): TestOrder {
        return TestOrder::create($id);
    });

    expect($result)->toBeInstanceOf(TestOrder::class);
    expect($repo->find($id->toString()))->not->toBeNull();
    expect($uow->isActive())->toBeFalse();
});

test('unit of work run auto-rollbacks on failure', function () {
    $uow = app(UnitOfWork::class);
    $repo = new InMemoryOrderRepository;

    $uow->setPersistenceCallback(function (array $committed, array $deleted) use ($repo): void {
        foreach ($committed as $aggregate) {
            $repo->save($aggregate);
        }
    });

    $id = AggregateRootId::generate();

    expect(fn () => $uow->run(function () use ($id): never {
        $order = TestOrder::create($id);
        throw new \RuntimeException('Simulated failure');
    }))->toThrow(\RuntimeException::class);

    // Order should NOT be persisted (callback was never invoked)
    expect($repo->find($id->toString()))->toBeNull();
    expect($uow->isActive())->toBeFalse();
});

test('unit of work nested savepoints', function () {
    $uow = app(UnitOfWork::class);
    $repo = new InMemoryOrderRepository;

    $committed = [];
    $uow->setPersistenceCallback(function (array $c, array $deleted) use (&$committed, $repo): void {
        foreach ($c as $aggregate) {
            $repo->save($aggregate);
            $committed[] = $aggregate->id();
        }
    });

    $id1 = AggregateRootId::generate();
    $id2 = AggregateRootId::generate();

    $uow->run(function () use ($uow, $id1, $id2): void {
        $order1 = TestOrder::create($id1);
        $uow->track($order1);

        // Nested savepoint
        $uow->run(function () use ($uow, $id2): void {
            $order2 = TestOrder::create($id2);
            $uow->track($order2);
        });
    });

    // Both should be persisted
    expect($repo->find($id1->toString()))->not->toBeNull();
    expect($repo->find($id2->toString()))->not->toBeNull();
});

test('unit of work track requires active scope', function () {
    $uow = app(UnitOfWork::class);

    $order = TestOrder::create(AggregateRootId::generate());
    expect(fn () => $uow->track($order))->toThrow(\RuntimeException::class);
});

test('unit of work clear resets all state', function () {
    $uow = app(UnitOfWork::class);
    $uow->begin();
    $order = TestOrder::create(AggregateRootId::generate());
    $uow->track($order);

    expect($uow->isActive())->toBeTrue();
    expect($uow->isTracking($order))->toBeTrue();

    $uow->clear();

    expect($uow->isActive())->toBeFalse();
    expect($uow->isTracking($order))->toBeFalse();
    expect($uow->getCommitted())->toBeEmpty();
    expect($uow->getDeleted())->toBeEmpty();
});

// 5. Repository with Snapshotting
test('snapshotting repository wraps inner repository', function () {
    $innerRepo = new InMemoryOrderRepository;
    $store = new InMemorySnapshotStore;
    $repo = new SnapshottingRepository($innerRepo, $store, TestOrder::class);

    $id = AggregateRootId::generate();
    $order = TestOrder::create($id);
    $order->addItem('prod-1', 2, 5.0);

    $repo->save($order);

    // At version 2, no snapshot yet (policy: every 3)
    expect($store->count(TestOrder::class))->toBe(0);

    $order->pay(10.0);
    $repo->save($order);

    // At version 3, snapshot should be created
    expect($store->count(TestOrder::class))->toBe(1);
});

// 6. Domain Exception Hierarchy
test('domain exceptions have unique error codes', function () {
    $codes = [
        InvalidStateDomainException::because('test')->errorCode(),
        InvalidArgumentDomainException::because('test')->errorCode(),
        NotFoundDomainException::because('test')->errorCode(),
        AggregateNotFoundException::for('Order', '123')->errorCode(),
        ConflictDomainException::because('test')->errorCode(),
        OptimisticLockException::for('id', 1, 2)->errorCode(),
        InvalidAggregateRootException::notAnAggregate(new \stdClass)->errorCode(),
    ];

    expect(array_unique($codes))->toHaveCount(7);
});

test('domain exception custom error code override', function () {
    $e = InvalidStateDomainException::because('test', code: 'CUSTOM_CODE');
    expect($e->errorCode())->toBe('CUSTOM_CODE');
});

test('domain exception RFC 9457 JSON serialization', function () {
    $e = InvalidStateDomainException::because('Order must be pending.');
    $arr = $e->toErrorArray();

    expect($arr)->toHaveKey('title');
    expect($arr)->toHaveKey('detail');
    expect($arr)->toHaveKey('code');
    expect($arr['title'])->toBe('InvalidStateDomainException');
    expect($arr['detail'])->toBe('Order must be pending.');
    expect($arr['code'])->toBe('INVALID_STATE');

    // JsonSerializable consistency
    $jsonArr = json_encode($e);
    $decoded = json_decode($jsonArr, true);
    expect($decoded['detail'])->toBe($e->toErrorArray()['detail']);
});

test('domain exception factory methods', function () {
    $e1 = NotFoundDomainException::forAggregate('Order', '123');
    expect($e1->getMessage())->toContain('Order');
    expect($e1->getMessage())->toContain('123');

    $e2 = AggregateNotFoundException::for('App\\Domain\\Order', '456');
    expect($e2->getMessage())->toContain('App\\Domain\\Order');

    $e3 = OptimisticLockException::for('id-789', expectedVersion: 5, actualVersion: 3);
    expect($e3->getMessage())->toContain('id-789');
    expect($e3->getMessage())->toContain('5');
    expect($e3->getMessage())->toContain('3');

    $e4 = InvalidAggregateRootException::notAnAggregate(new \stdClass);
    expect($e4->getMessage())->toContain('stdClass');
});

// 7. Identifier Serialization for API Responses
test('all identifier types implement JsonSerializable', function () {
    $uuid = OrderId::generate();
    $ulid = ProductId::generate();
    $string = StringIdentifier::from('my-slug');
    $integer = IntegerIdentifier::from(42);

    // JSON encode each
    $uuidJson = json_encode(['id' => $uuid]);
    $ulidJson = json_encode(['id' => $ulid]);
    $stringJson = json_encode(['slug' => $string]);
    $intJson = json_encode(['num' => $integer]);

    expect($uuidJson)->toBeJson();
    expect($ulidJson)->toBeJson();
    expect($stringJson)->toBeJson();
    expect($intJson)->toBeJson();

    // Verify values
    expect(json_decode($uuidJson, true)['id'])->toBe($uuid->toString());
    expect(json_decode($ulidJson, true)['id'])->toBe($ulid->toString());
    expect(json_decode($stringJson, true)['slug'])->toBe('my-slug');
    expect(json_decode($intJson, true)['num'])->toBe(42);
});

test('identifier round-trip fromString', function () {
    $original = OrderId::generate();
    $restored = OrderId::fromString($original->toString());
    expect($restored->equals($original))->toBeTrue();

    $slugOriginal = StringIdentifier::from('hello-world');
    $slugRestored = StringIdentifier::fromString('hello-world');
    expect($slugRestored->equals($slugOriginal))->toBeTrue();
});

test('identifier cross-type inequality', function () {
    $uuid = OrderId::generate();
    $ulid = ProductId::generate();
    $string = StringIdentifier::from('test');
    $integer = IntegerIdentifier::from(1);

    // None should be equal across types
    expect($uuid->equals($ulid))->toBeFalse();
    expect($uuid->equals($string))->toBeFalse();
    expect($uuid->equals($integer))->toBeFalse();

    // Same type, different values
    $uuid2 = OrderId::generate();
    expect($uuid->equals($uuid2))->toBeFalse();
});

test('identifier isValid pre-validation', function () {
    expect(OrderId::isValid('not-a-uuid'))->toBeFalse();
    expect(OrderId::isValid('550e8400-e29b-41d4-a716-446655440000'))->toBeTrue();

    expect(StringIdentifier::isValid(''))->toBeFalse();
    expect(StringIdentifier::isValid('valid'))->toBeTrue();

    expect(IntegerIdentifier::isValid('abc'))->toBeFalse();
    expect(IntegerIdentifier::isValid('42'))->toBeTrue();
});

// 8. Domain Invariant Enforcement
test('domain invariant — cannot ship unpaid order', function () {
    $id = AggregateRootId::generate();
    $order = TestOrder::create($id);
    $order->pullDomainEvents();

    expect(fn () => $order->ship())
        ->toThrow(InvalidStateDomainException::class);
});

test('domain invariant — cannot pay non-pending order', function () {
    $id = AggregateRootId::generate();
    $order = TestOrder::create($id);
    $order->pay(10.0);
    $order->pullDomainEvents();

    expect(fn () => $order->pay(20.0))
        ->toThrow(InvalidStateDomainException::class);
});

test('domain invariant — cannot add items with non-positive quantity', function () {
    $id = AggregateRootId::generate();
    $order = TestOrder::create($id);
    $order->pullDomainEvents();

    expect(fn () => $order->addItem('prod-1', -1, 5.0))
        ->toThrow(InvalidArgumentDomainException::class);
});

// 9. Aggregate Root toArray Serialization
test('aggregate root toArray includes id, version, and type', function () {
    $id = AggregateRootId::generate();
    $order = TestOrder::create($id);

    $array = $order->toArray();

    expect($array)->toHaveKey('id');
    expect($array)->toHaveKey('version');
    expect($array)->toHaveKey('type');
    expect($array['id'])->toBe($id->toString());
    expect($array['version'])->toBe(1);
    expect($array['type'])->toBe('TestOrder');
});

// 10. Domain Event Collection Operations
test('domain event collection functional operations', function () {
    $events = [
        DomainEvent::occur('order.placed', ['id' => '1']),
        DomainEvent::occur('order.paid', ['amount' => 50]),
        DomainEvent::occur('order.shipped', []),
    ];

    $collection = new \ZeroBoiler\Domain\DomainEventCollection($events);

    // Map
    $types = $collection->map(fn (DomainEvent $e) => $e->eventType);
    expect($types)->toBe(['order.placed', 'order.paid', 'order.shipped']);

    // Filter
    $orderEvents = $collection->filter(
        fn (DomainEvent $e) => str_starts_with($e->eventType, 'order.p'),
    );
    expect($orderEvents->count())->toBe(2);

    // Merge
    $merged = $collection->merge([DomainEvent::occur('order.canceled', [])]);
    expect($merged->count())->toBe(4);

    // Immutability — filter returns new instance
    expect($collection->count())->toBe(3);

    // JSON serialization
    $json = json_encode($collection);
    expect($json)->toBeJson();
    $decoded = json_decode($json, true);
    expect(count($decoded))->toBe(3);
});

// 11. InMemorySnapshotStore Full API
test('in-memory snapshot store full lifecycle', function () {
    $store = new InMemorySnapshotStore;

    $s1 = Snapshot::create(TestOrder::class, 'id-1', 10, ['status' => 'paid']);
    $s2 = Snapshot::create(TestOrder::class, 'id-2', 20, ['status' => 'shipped']);
    $s3 = Snapshot::create('Product', 'id-3', 5, ['name' => 'Widget']);

    $store->save($s1);
    $store->save($s2);
    $store->save($s3);

    expect($store->count())->toBe(3);
    expect($store->count(TestOrder::class))->toBe(2);
    expect($store->has(TestOrder::class, 'id-1'))->toBeTrue();
    expect($store->has(TestOrder::class, 'nonexistent'))->toBeFalse();

    $loaded = $store->load(TestOrder::class, 'id-1');
    expect($loaded->version)->toBe(10);

    $stats = $store->stats();
    expect($stats['total'])->toBe(3);
    expect($stats['by_type'][TestOrder::class])->toBe(2);

    // Delete older
    $store->deleteOlderThan(TestOrder::class, 'id-1', 15);
    expect($store->count(TestOrder::class))->toBe(1); // only s2 (version 20) remains

    // Purge by type
    $store->purge(TestOrder::class);
    expect($store->count(TestOrder::class))->toBe(0);
    expect($store->count())->toBe(1); // Product still exists
});

// 12. AggregateRootId Behavior
test('aggregate root id is readonly and generates UUID v4', function () {
    $id = AggregateRootId::generate();

    expect($id->toString())->toBeString();
    expect(strlen($id->toString()))->toBe(36); // UUID v4 format
    expect($id->equals($id))->toBeTrue();

    // fromString round-trip
    $restored = AggregateRootId::fromString($id->toString());
    expect($restored->equals($id))->toBeTrue();

    // JSON
    $json = json_encode($id);
    $decoded = json_decode($json, true);
    expect($decoded)->toBe($id->toString());
});

// 13. Non-destructive Peek
test('peek domain events does not consume them', function () {
    $id = AggregateRootId::generate();
    $order = TestOrder::create($id);

    $peeked = $order->peekDomainEvents();
    expect($peeked->count())->toBe(1);
    expect($order->hasUncommittedEvents())->toBeTrue();

    // Pull still returns the events
    $pulled = $order->pullDomainEvents();
    expect($pulled->count())->toBe(1);
    expect($order->hasUncommittedEvents())->toBeFalse();
});

// 14. Deprecated API still works
test('deprecated getVersion alias still works', function () {
    $id = AggregateRootId::generate();
    $order = TestOrder::create($id);

    // @phpstan-ignore call.deprecated
    expect($order->getVersion())->toBe(1);
});
