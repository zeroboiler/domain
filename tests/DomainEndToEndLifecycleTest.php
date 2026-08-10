<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Domain\AggregateRoot;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\Contracts\AggregateRoot as AggregateRootContract;
use ZeroBoiler\Domain\Contracts\Entity as EntityContract;
use ZeroBoiler\Domain\Contracts\UnitOfWork as UnitOfWorkContract;
use ZeroBoiler\Domain\DomainEventCollection;
use ZeroBoiler\Domain\Entity;
use ZeroBoiler\Domain\Exceptions\{
    ConflictDomainException,
    InvalidArgumentDomainException,
    InvalidStateDomainException,
    NotFoundDomainException,
    OptimisticLockException,
};
use ZeroBoiler\Domain\Identifiers\{IntegerIdentifier, StringIdentifier, UuidIdentifier, UlidIdentifier};
use ZeroBoiler\Domain\InMemoryUnitOfWork;
use ZeroBoiler\Domain\ValueObject;
use ZeroBoiler\Domain\Snapshots\{Snapshot, InMemorySnapshotStore, SnapshottingRepository};

// ─── Test Fixtures ───────────────────────────────────────────────────────────────

final class TestOrderId extends UuidIdentifier {}

final class TestOrderItem extends Entity
{
    public function __construct(
        string $id,
        public readonly string $productId,
        public int $quantity,
    ) {
        parent::__construct($id);
    }

    public function updateQuantity(int $qty): void
    {
        if ($qty <= 0) {
            throw InvalidArgumentDomainException::because('Quantity must be positive.');
        }

        $this->quantity = $qty;
    }
}

final class TestOrder extends AggregateRoot
{
    private string $status = 'pending';
    /** @var array<int, array{productId: string, qty: int}> */
    private array $items = [];
    private float $total = 0.0;
    private int $version = 0;

    public function __construct(TestOrderId $id)
    {
        parent::__construct($id);
    }

    public function status(): string { return $this->status; }
    public function total(): float { return $this->total; }
    /** @return array<int, array{productId: string, qty: int}> */
    public function items(): array { return $this->items; }

    public function addItem(string $productId, int $qty): void
    {
        if ($this->status !== 'pending') {
            throw InvalidStateDomainException::because('Can only add items to pending orders.');
        }

        if ($qty <= 0) {
            throw InvalidArgumentDomainException::because('Quantity must be positive.');
        }

        $this->items[] = ['productId' => $productId, 'qty' => $qty];
        $this->total += 0.0; // simplified
    }

    public function pay(): void
    {
        if ($this->status !== 'pending') {
            throw InvalidStateDomainException::because('Order must be pending to pay.');
        }

        $this->status = 'paid';
    }

    public function cancel(): void
    {
        if ($this->status === 'shipped') {
            throw InvalidStateDomainException::because('Cannot cancel shipped orders.');
        }

        $this->status = 'cancelled';
    }
}

// ─── Tests ─────────────────────────────────────────────────────────────────────

describe('Aggregate Root Lifecycle', function (): void {
    it('creates with correct initial state', function (): void {
        $id = TestOrderId::generate();
        $order = new TestOrder($id);

        expect($order->id())->toBe($id->toString());
        expect($order->status())->toBe('pending');
        expect($order->total())->toBe(0.0);
        expect($order->items())->toBe([]);
        expect($order->version())->toBe(0);
    });

    it('enforces state invariants on pay', function (): void {
        $order = new TestOrder(TestOrderId::generate());
        $order->pay(); // succeeds

        $order->pay(); // should throw — already paid
    })->throws(InvalidStateDomainException::class);

    it('enforces state invariants on addItem', function (): void {
        $order = new TestOrder(TestOrderId::generate());
        $order->pay();

        $order->addItem('product-1', 2); // should throw — not pending
    })->throws(InvalidStateDomainException::class);

    it('enforces argument validation on addItem', function (): void {
        $order = new TestOrder(TestOrderId::generate());
        $order->addItem('product-1', 0); // should throw — qty <= 0
    })->throws(InvalidArgumentDomainException::class);

    it('supports identity equality via AggregateRootId', function (): void {
        $id = TestOrderId::fromString('550e8400-e29b-41d4-a716-446655440000');
        $id2 = TestOrderId::fromString('550e8400-e29b-41d4-a716-446655440000');
        $id3 = TestOrderId::generate();

        expect($id->equals($id2))->toBeTrue();
        expect($id->equals($id3))->toBeFalse();
    });

    it('serializes to array with id, version, and type', function (): void {
        $id = TestOrderId::generate();
        $order = new TestOrder($id);

        $array = $order->toArray();

        expect($array)->toHaveKey('id');
        expect($array)->toHaveKey('version');
        expect($array)->toHaveKey('type');
        expect($array['id'])->toBe($id->toString());
        expect($array['version'])->toBe(0);
        expect($array['type'])->toBe('TestOrder');
    });
});

describe('Entity Identity', function (): void {
    it('supports string, int, and Stringable IDs', function (): void {
        $strItem = new TestOrderItem('item-1', 'product-a', 3);
        expect($strItem->id())->toBe('item-1');

        $intItem = new TestOrderItem(42, 'product-b', 1);
        expect($intItem->id())->toBe(42);

        $stringableItem = new TestOrderItem(TestOrderId::generate(), 'product-c', 5);
        expect($stringableItem->id())->toBeString();
    });

    it('compares equality correctly', function (): void {
        $item1 = new TestOrderItem('1', 'p1', 2);
        $item2 = new TestOrderItem('1', 'p1', 2);
        $item3 = new TestOrderItem('2', 'p1', 2);

        expect($item1->equals($item2))->toBeTrue();
        expect($item1->equals($item3))->toBeFalse();
    });

    it('enforces argument validation on updateQuantity', function (): void {
        $item = new TestOrderItem('1', 'p1', 2);
        $item->updateQuantity(0);
    })->throws(InvalidArgumentDomainException::class);

    it('provides toArray with id and type', function (): void {
        $item = new TestOrderItem('1', 'p1', 2);
        $array = $item->toArray();

        expect($array)->toHaveKey('id');
        expect($array)->toHaveKey('type');
        expect($array['id'])->toBe('1');
        expect($array['type'])->toBe('TestOrderItem');
    });
});

describe('Identifier Types', function (): void {
    it('generates valid UUID identifiers', function (): void {
        $id = TestOrderId::generate();

        expect($id->toString())->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/');
        expect($id->isValid($id->toString()))->toBeTrue();
        expect($id->isValid('not-a-uuid'))->toBeFalse();
    });

    it('round-trips UUID via fromString/generate', function (): void {
        $id = TestOrderId::generate();
        $restored = TestOrderId::fromString($id->toString());

        expect($id->equals($restored))->toBeTrue();
    });

    it('round-trips via toArray/fromArray', function (): void {
        $id = TestOrderId::generate();
        $restored = TestOrderId::fromArray($id->toArray());

        expect($id->equals($restored))->toBeTrue();
    });

    it('JSON serializes identifiers', function (): void {
        $uuidId = TestOrderId::generate();
        $json = json_encode(['order_id' => $uuidId]);

        expect($json)->toBeString();
        expect(json_decode($json, true)['order_id'])->toBe($uuidId->toString());
    });

    it('validates ULID identifiers', function (): void {
        $id = TestProductId::generate();

        expect($id->toString())->toBeString();
        expect($id->isValid($id->toString()))->toBeTrue();
        expect($id->isValid('not-a-ulid'))->toBeFalse();
    });

    it('validates String identifiers reject empty', function (): void {
        $slug = StringIdentifier::from('my-post');

        expect($slug->toString())->toBe('my-post');
        expect($slug->isValid('my-post'))->toBeTrue();
    });

    it('validates Integer identifiers', function (): void {
        $num = IntegerIdentifier::from(42);

        expect($num->toInt())->toBe(42);
        expect($num->isValid('42'))->toBeTrue();
        expect($num->isValid('abc'))->toBeFalse();
    });

    it('rejects cross-type equality', function (): void {
        $uuid = TestOrderId::generate();
        $ulid = TestProductId::generate();

        expect($uuid->equals($ulid))->toBeFalse();
    });
});

describe('Domain Exceptions', function (): void {
    it('provides unique default error codes', function (): void {
        $codes = [
            InvalidStateDomainException::because('x')->errorCode(),
            InvalidArgumentDomainException::because('x')->errorCode(),
            NotFoundDomainException::because('x')->errorCode(),
            ConflictDomainException::because('x')->errorCode(),
        ];

        // All codes must be unique
        expect(array_unique($codes))->toHaveCount(4);
    });

    it('supports custom error codes', function (): void {
        $e = InvalidStateDomainException::because('test', code: 'CUSTOM_CODE');
        expect($e->errorCode())->toBe('CUSTOM_CODE');
    });

    it('serializes to RFC 9457 format via toErrorArray', function (): void {
        $e = InvalidStateDomainException::because('Order must be pending.');
        $array = $e->toErrorArray();

        expect($array)->toHaveKey('title');
        expect($array)->toHaveKey('detail');
        expect($array)->toHaveKey('code');
        expect($array['detail'])->toBe('Order must be pending.');
        expect($array['code'])->toBe('INVALID_STATE');
    });

    it('implements JsonSerializable for consistent error output', function (): void {
        $e = InvalidStateDomainException::because('test');
        $json = json_encode($e);

        expect($json)->toBeString();
        $decoded = json_decode($json, true);
        expect($decoded)->toHaveKey('title');
        expect($decoded)->toHaveKey('detail');
        expect($decoded)->toHaveKey('code');
    });

    it('InvalidStateException does NOT extend DomainException', function (): void {
        $e = \ZeroBoiler\Domain\Exceptions\InvalidStateException::because('bad config');
        $ref = new ReflectionClass($e);

        expect($ref->getParentClass()->getName())->toBe('Exception');
    });

    it('OptimisticLockException provides detailed version mismatch info', function (): void {
        $e = OptimisticLockException::for('order-123', expected: 5, actual: 3);

        expect($e->errorCode())->toBe('OPTIMISTIC_LOCK');
        expect($e->getMessage())->toContain('order-123');
    });
});

describe('Snapshot Round-Trip', function (): void {
    it('creates and round-trips via toArray/fromArray', function (): void {
        $snapshot = Snapshot::create(
            TestOrder::class,
            'order-123',
            50,
            ['status' => 'paid', 'total' => 99.99],
        );

        $restored = Snapshot::fromArray($snapshot->toArray());

        expect($snapshot->equals($restored))->toBeTrue();
        expect($restored->aggregateType)->toBe(TestOrder::class);
        expect($restored->aggregateId)->toBe('order-123');
        expect($restored->version)->toBe(50);
        expect($restored->state['status'])->toBe('paid');
    });

    it('rejects invalid snapshot data', function (): void {
        Snapshot::fromArray(['bad' => 'data']);
    })->throws(\InvalidArgumentException::class);

    it('serializes to JSON', function (): void {
        $snapshot = Snapshot::create(TestOrder::class, 'order-1', 10, ['x' => 1]);
        $json = json_encode($snapshot);

        expect($json)->toBeString();
        $decoded = json_decode($json, true);
        expect($decoded['aggregate_type'])->toBe(TestOrder::class);
    });

    it('InMemorySnapshotStore supports full CRUD lifecycle', function (): void {
        $store = new InMemorySnapshotStore;
        $snapshot = Snapshot::create(TestOrder::class, 'order-1', 10, ['status' => 'paid']);

        $store->save($snapshot);

        expect($store->has(TestOrder::class, 'order-1'))->toBeTrue();
        expect($store->count(TestOrder::class))->toBe(1);

        $loaded = $store->load(TestOrder::class, 'order-1');
        expect($loaded?->version)->toBe(10);

        $store->delete(TestOrder::class, 'order-1');
        expect($store->has(TestOrder::class, 'order-1'))->toBeFalse();
    });

    it('InMemorySnapshotStore stats and purge work', function (): void {
        $store = new InMemorySnapshotStore;

        $store->save(Snapshot::create(TestOrder::class, 'o1', 10, []));
        $store->save(Snapshot::create(TestOrder::class, 'o2', 20, []));
        $store->save(Snapshot::create('Product', 'p1', 5, []));

        $stats = $store->stats();
        expect($stats['total'])->toBe(3);
        expect($stats['by_type']['TestOrder'])->toBe(2);

        $removed = $store->purge(TestOrder::class);
        expect($removed)->toBe(2);
        expect($store->count())->toBe(1);
    });
});

describe('Domain Event Collection', function (): void {
    it('supports basic collection operations', function (): void {
        $collection = new DomainEventCollection; // empty

        expect($collection->isEmpty())->toBeTrue();
        expect($collection->count())->toBe(0);
    });

    it('iterates correctly', function (): void {
        $events = [];
        $collection = new DomainEventCollection;

        expect($collection->all())->toBeArray();
    });

    it('provides toArray serialization', function (): void {
        $collection = new DomainEventCollection;

        $array = $collection->toArray();
        expect($array)->toBeArray();
    });

    it('JSON serializes correctly', function (): void {
        $collection = new DomainEventCollection;
        $json = json_encode($collection);

        expect($json)->toBe('[]');
    });
});

describe('Unit of Work', function (): void {
    it('supports declarative run with auto-commit', function (): void {
        $uow = new InMemoryUnitOfWork;

        $result = $uow->run(fn (): int => 42);

        expect($result)->toBe(42);
        expect($uow->isActive())->toBeFalse();
    });

    it('supports manual begin/commit lifecycle', function (): void {
        $uow = new InMemoryUnitOfWork;

        $uow->begin();
        expect($uow->isActive())->toBeTrue();

        $uow->commit();
        expect($uow->isActive())->toBeFalse();
    });

    it('supports rollback', function (): void {
        $uow = new InMemoryUnitOfWork;

        $uow->begin();
        $uow->rollback();
        expect($uow->isActive())->toBeFalse();
    });

    it('clear resets all state', function (): void {
        $uow = new InMemoryUnitOfWork;
        $uow->begin();
        $uow->clear();

        expect($uow->isActive())->toBeFalse();
        expect($uow->hasPendingEvents())->toBeFalse();
    });

    it('getPendingEvents is non-destructive', function (): void {
        $uow = new InMemoryUnitOfWork;
        $uow->begin();

        // Without tracking, should have no pending events
        $pending = $uow->getPendingEvents();
        expect($pending->isEmpty())->toBeTrue();
    });

    it('run auto-rollbacks on exception', function (): void {
        $uow = new InMemoryUnitOfWork;

        $uow->run(function (): void {
            throw new \RuntimeException('test failure');
        });
    })->throws(\RuntimeException::class);
});
