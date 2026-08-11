<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Domain\{AggregateRoot, AggregateRootId, Entity, ValueObject, DomainEventCollection};
use ZeroBoiler\Domain\Identifiers\{UuidIdentifier, UlidIdentifier, StringIdentifier, IntegerIdentifier};
use ZeroBoiler\Domain\Exceptions\{
    DomainException,
    InvalidStateDomainException,
    InvalidArgumentDomainException,
    NotFoundDomainException,
    ConflictDomainException,
    OptimisticLockException,
    AggregateNotFoundException,
    InvalidAggregateRootException,
};
use ZeroBoiler\Domain\Snapshots\{Snapshot, InMemorySnapshotStore, SnapshottingRepository};
use ZeroBoiler\Domain\Contracts\{Repository, AggregateRoot as AggregateRootContract, Entity as EntityContract, Identifier as IdentifierContract};
use ZeroBoiler\Domain\InMemoryUnitOfWork;
use ZeroBoiler\Events\Domain\DomainEvent;

// ─── Test Fixtures ───────────────────────────────────────────────────────────────

/** Test entity with typed constructor parameters for fromArray hydration */
final class HydratableItem extends Entity
{
    public function __construct(
        int|string|\Stringable $id,
        public readonly string $sku,
        public readonly int $quantity,
        public readonly float $unitPrice,
    ) {
        parent::__construct($id);
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id(),
            'type' => class_basename(static::class),
            'sku' => $this->sku,
            'quantity' => $this->quantity,
            'unit_price' => $this->unitPrice,
        ];
    }
}

/** Test aggregate root with business invariants */
final class InvariantOrder extends AggregateRoot
{
    public string $status = 'pending';
    public float $total = 0.0;
    public int $maxItems = 10;

    public function addItem(string $productId, int $qty, float $price): void
    {
        if ($this->status !== 'pending') {
            throw InvalidStateDomainException::because(
                sprintf('Order must be pending to add items; current status is "%s".', $this->status),
                code: 'ORDER_NOT_PENDING',
            );
        }

        if ($qty <= 0) {
            throw InvalidArgumentDomainException::because('Quantity must be positive.');
        }

        if ($price < 0) {
            throw InvalidArgumentDomainException::because('Price cannot be negative.');
        }

        $this->apply(DomainEvent::occur('order.item_added', [
            'id' => $this->id(),
            'product_id' => $productId,
            'qty' => $qty,
            'price' => $price,
        ]));
    }

    public function pay(): void
    {
        if ($this->status !== 'pending') {
            throw InvalidStateDomainException::because(
                sprintf('Cannot pay order in "%s" status.', $this->status),
            );
        }

        if ($this->total <= 0) {
            throw InvalidStateDomainException::because('Order total must be positive to pay.');
        }

        $this->apply(DomainEvent::occur('order.paid', [
            'id' => $this->id(),
            'total' => $this->total,
        ]));
    }

    public function ship(): void
    {
        if ($this->status !== 'paid') {
            throw InvalidStateDomainException::because(
                sprintf('Cannot ship order in "%s" status.', $this->status),
            );
        }

        $this->apply(DomainEvent::occur('order.shipped', [
            'id' => $this->id(),
        ]));
    }

    protected function applyOrderItemAdded(DomainEvent $event): void
    {
        $this->total += $event->payload['qty'] * $event->payload['price'];
    }

    protected function applyOrderPaid(DomainEvent $event): void
    {
        $this->status = 'paid';
    }

    protected function applyOrderShipped(DomainEvent $event): void
    {
        $this->status = 'shipped';
    }
}

/** Test identifier subclass */
final class OrderId extends UuidIdentifier {}

/** Test value object */
final class Money extends ValueObject
{
    public function __construct(
        public readonly string $currency,
        public readonly int $amount,
    ) {}

    public static function fromArray(array $data): static
    {
        return new static(
            currency: $data['currency'],
            amount: $data['amount'],
        );
    }

    public function toArray(): array
    {
        return [
            'currency' => $this->currency,
            'amount' => $this->amount,
        ];
    }
}

// ─── Entity fromArray Hydration Tests ──────────────────────────────────────────

describe('Entity fromArray Hydration', function (): void {
    it('hydrates from array with all constructor params', function (): void {
        $item = HydratableItem::fromArray([
            'id' => 'item-42',
            'sku' => 'SKU-001',
            'quantity' => 5,
            'unitPrice' => 19.99,
        ]);

        expect($item->id())->toBe('item-42');
        expect($item->sku)->toBe('SKU-001');
        expect($item->quantity)->toBe(5);
        expect($item->unitPrice)->toBe(19.99);
    });

    it('round-trips toArray → fromArray → toArray', function (): void {
        $original = new HydratableItem('item-99', 'SKU-002', 3, 29.99);
        $array = $original->toArray();

        $restored = HydratableItem::fromArray($array);

        expect($restored->id())->toBe($original->id());
        expect($restored->sku)->toBe($original->sku);
        expect($restored->quantity)->toBe($original->quantity);
        expect($restored->unitPrice)->toBe($original->unitPrice);
        expect($restored->toArray())->toBe($array);
    });

    it('hydrates with int ID', function (): void {
        $item = HydratableItem::fromArray([
            'id' => 123,
            'sku' => 'SKU-INT',
            'quantity' => 1,
            'unitPrice' => 9.99,
        ]);

        expect($item->id())->toBe('123');
    });

    it('throws on missing required constructor param', function (): void {
        HydratableItem::fromArray([
            'id' => 'item-1',
            'sku' => 'SKU-001',
            // quantity and unitPrice missing
        ]);
    })->throws(\ArgumentCountError::class);

    it('ignores extra keys in data array', function (): void {
        $item = HydratableItem::fromArray([
            'id' => 'item-1',
            'sku' => 'SKU-001',
            'quantity' => 2,
            'unitPrice' => 5.00,
            'extra_field' => 'ignored',
            'another_extra' => 42,
        ]);

        expect($item->sku)->toBe('SKU-001');
    });

    it('preserves equality after round-trip', function (): void {
        $original = new HydratableItem('id-abc', 'SKU-XYZ', 10, 100.0);
        $restored = HydratableItem::fromArray($original->toArray());

        expect($original->equals($restored))->toBeTrue();
    });

    it('serializes to JSON via JsonSerializable', function (): void {
        $item = new HydratableItem('id-json', 'SKU-JSON', 3, 50.0);
        $json = json_encode($item);

        expect($json)->toBeJson();
        $decoded = json_decode($json, true);
        expect($decoded['id'])->toBe('id-json');
        expect($decoded['sku'])->toBe('SKU-JSON');
        expect($decoded['type'])->toBe('HydratableItem');
    });
});

// ─── Domain Invariant Tests ─────────────────────────────────────────────────────

describe('Aggregate Root Domain Invariants', function (): void {
    it('enforces state invariant: cannot add items to paid order', function (): void {
        $order = new InvariantOrder($id = AggregateRootId::generate());
        $order->addItem('prod-1', 2, 10.0);
        $order->pay();

        $order->addItem('prod-2', 1, 5.0);
    })->throws(InvalidStateDomainException::class);

    it('enforces state invariant: cannot pay already paid order', function (): void {
        $order = new InvariantOrder($id = AggregateRootId::generate());
        $order->addItem('prod-1', 2, 10.0);
        $order->pay();

        $order->pay();
    })->throws(InvalidStateDomainException::class);

    it('enforces state invariant: cannot ship unpaid order', function (): void {
        $order = new InvariantOrder($id = AggregateRootId::generate());
        $order->addItem('prod-1', 2, 10.0);

        $order->ship();
    })->throws(InvalidStateDomainException::class);

    it('enforces argument invariant: quantity must be positive', function (): void {
        $order = new InvariantOrder($id = AggregateRootId::generate());

        $order->addItem('prod-1', 0, 10.0);
    })->throws(InvalidArgumentDomainException::class);

    it('enforces argument invariant: price cannot be negative', function (): void {
        $order = new InvariantOrder($id = AggregateRootId::generate());

        $order->addItem('prod-1', 1, -5.0);
    })->throws(InvalidArgumentDomainException::class);

    it('allows valid complete lifecycle: add → pay → ship', function (): void {
        $order = new InvariantOrder($id = AggregateRootId::generate());

        $order->addItem('prod-1', 2, 10.0);
        expect($order->status)->toBe('pending');
        expect($order->total)->toBe(20.0);

        $order->pay();
        expect($order->status)->toBe('paid');

        $order->ship();
        expect($order->status)->toBe('shipped');
    });

    it('increments version on each apply', function (): void {
        $order = new InvariantOrder($id = AggregateRootId::generate());
        expect($order->version())->toBe(0);

        $order->addItem('prod-1', 2, 10.0);
        expect($order->version())->toBe(1);

        $order->addItem('prod-2', 1, 5.0);
        expect($order->version())->toBe(2);

        $order->pay();
        expect($order->version())->toBe(3);
    });

    it('records domain events for each action', function (): void {
        $order = new InvariantOrder($id = AggregateRootId::generate());

        $order->addItem('prod-1', 2, 10.0);
        $order->pay();

        $events = $order->pullDomainEvents();
        expect($events)->toHaveCount(2);
        expect($events->first()->eventType)->toBe('order.item_added');
        expect($events->last()->eventType)->toBe('order.paid');
    });

    it('preserves aggregate identity through toArray', function (): void {
        $order = new InvariantOrder($id = AggregateRootId::generate());
        $order->addItem('prod-1', 1, 25.0);

        $array = $order->toArray();

        expect($array['id'])->toBe($id->toString());
        expect($array['version'])->toBe(1);
        expect($array['type'])->toBe('InvariantOrder');
    });
});

// ─── Identifier Serialization Consistency ──────────────────────────────────────

describe('Identifier Serialization Consistency', function (): void {
    it('round-trips UuidIdentifier', function (): void {
        $id = OrderId::generate();
        $restored = OrderId::fromArray($id->toArray());

        expect($id->equals($restored))->toBeTrue();
        expect($id->toString())->toBe($restored->toString());
    });

    it('round-trips StringIdentifier', function (): void {
        $id = StringIdentifier::from('my-slug');
        $restored = StringIdentifier::fromArray($id->toArray());

        expect($id->equals($restored))->toBeTrue();
    });

    it('round-trips IntegerIdentifier', function (): void {
        $id = IntegerIdentifier::from(42);
        $restored = IntegerIdentifier::fromArray($id->toArray());

        expect($id->equals($restored))->toBeTrue();
    });

    it('JSON encodes consistently', function (): void {
        $uuid = OrderId::generate();
        $string = StringIdentifier::from('test');
        $integer = IntegerIdentifier::from(99);

        $uuidJson = json_encode($uuid);
        $stringJson = json_encode($string);
        $integerJson = json_encode($integer);

        expect($uuidJson)->toBeJson();
        expect($stringJson)->toBe('"test"');
        expect($integerJson)->toBe('99');
    });

    it('cross-type identifiers are never equal', function (): void {
        $uuid = OrderId::generate();
        $string = StringIdentifier::from($uuid->toString());
        $integer = IntegerIdentifier::from(1);

        expect($uuid->equals($string))->toBeFalse();
        expect($uuid->equals($integer))->toBeFalse();
        expect($string->equals($integer))->toBeFalse();
    });
});

// ─── Domain Exception Hierarchy & RFC 9457 ──────────────────────────────────────

describe('Domain Exception Hierarchy', function (): void {
    it('all exceptions have unique default error codes', function (): void {
        $exceptions = [
            new class ('msg') extends InvalidStateDomainException {},
            new class ('msg') extends InvalidArgumentDomainException {},
            new class ('msg') extends NotFoundDomainException {},
            new class ('msg') extends ConflictDomainException {},
            OptimisticLockException::for('id', 1, 2),
            AggregateNotFoundException::for('TestAggregate', 'id-1'),
            new class ('msg') extends InvalidAggregateRootException {},
        ];

        $codes = array_map(fn (DomainException $e): string => $e->errorCode(), $exceptions);
        $uniqueCodes = array_unique($codes);

        expect($uniqueCodes)->toHaveCount(count($codes));
    });

    it('custom error code overrides default', function (): void {
        $e = InvalidStateDomainException::because('test', code: 'CUSTOM_CODE');
        expect($e->errorCode())->toBe('CUSTOM_CODE');
    });

    it('toErrorArray returns RFC 9457 structure', function (): void {
        $e = InvalidStateDomainException::because('Order must be pending.');
        $array = $e->toErrorArray();

        expect($array)->toHaveKey('title');
        expect($array)->toHaveKey('detail');
        expect($array)->toHaveKey('code');
        expect($array['title'])->toBe('InvalidStateDomainException');
        expect($array['detail'])->toBe('Order must be pending.');
        expect($array['code'])->toBe('INVALID_STATE');
    });

    it('jsonSerialize matches toErrorArray', function (): void {
        $e = NotFoundDomainException::because('Resource not found.');
        $json = json_encode($e);

        $decoded = json_decode($json, true);
        $errorArray = $e->toErrorArray();

        expect($decoded)->toBe($errorArray);
    });

    it('round-trips through toArray/fromArray', function (): void {
        $e = InvalidStateDomainException::because('Test reason.', code: 'TEST_CODE');
        $array = $e->toArray();

        $restored = InvalidStateDomainException::fromArray($array, InvalidStateDomainException::class);

        expect($restored->getMessage())->toBe('Test reason.');
        expect($restored->errorCode())->toBe('TEST_CODE');
    });

    it('factory methods produce descriptive messages', function (): void {
        $lock = OptimisticLockException::for('order-123', expected: 5, actual: 3);
        expect($lock->getMessage())->toContain('order-123');
        expect($lock->getMessage())->toContain('expected version 5');
        expect($lock->getMessage())->toContain('current version 3');

        $notFound = AggregateNotFoundException::for('App\\Domain\\Order', 'id-1');
        expect($notFound->getMessage())->toContain('App\\Domain\\Order');
        expect($notFound->getMessage())->toContain('id-1');
    });
});

// ─── Value Object Round-Trip ─────────────────────────────────────────────────────

describe('Value Object Round-Trip', function (): void {
    it('round-trips Money value object', function (): void {
        $original = Money::fromArray(['currency' => 'USD', 'amount' => 1999]);
        $restored = Money::fromArray($original->toArray());

        expect($original->equals($restored))->toBeTrue();
        expect($original->toArray())->toBe($restored->toArray());
    });

    it('equality is structural via toArray', function (): void {
        $a = new Money('USD', 100);
        $b = new Money('USD', 100);
        $c = new Money('EUR', 100);
        $d = new Money('USD', 200);

        expect($a->equals($b))->toBeTrue();
        expect($a->equals($c))->toBeFalse();
        expect($a->equals($d))->toBeFalse();
    });

    it('null comparison returns false', function (): void {
        $money = new Money('USD', 100);
        expect($money->equals(null))->toBeFalse();
    });

    it('different type comparison returns false', function (): void {
        $money = new Money('USD', 100);
        $other = new class extends ValueObject {
            public function toArray(): array
            {
                return ['currency' => 'USD', 'amount' => 100];
            }
        };

        expect($money->equals($other))->toBeFalse();
    });
});

// ─── Snapshot Round-Trip ──────────────────────────────────────────────────────

describe('Snapshot Round-Trip', function (): void {
    it('creates and restores snapshot', function (): void {
        $state = ['status' => 'paid', 'total' => 150.0, 'items_count' => 3];
        $snapshot = Snapshot::create(InvariantOrder::class, 'uuid-123', 5, $state);

        $array = $snapshot->toArray();
        $restored = Snapshot::fromArray($array);

        expect($snapshot->equals($restored))->toBeTrue();
        expect($restored->aggregateType)->toBe(InvariantOrder::class);
        expect($restored->aggregateId)->toBe('uuid-123');
        expect($restored->version)->toBe(5);
        expect($restored->state)->toBe($state);
    });

    it('serializes to JSON consistently', function (): void {
        $snapshot = Snapshot::create('TestClass', 'id-1', 1, ['key' => 'value']);

        $json = json_encode($snapshot);
        $decoded = json_decode($json, true);

        expect($decoded['aggregate_type'])->toBe('TestClass');
        expect($decoded['aggregate_id'])->toBe('id-1');
        expect($decoded['version'])->toBe(1);
        expect($decoded['state'])->toBe(['key' => 'value']);
    });

    it('rejects invalid snapshot data', function (): void {
        Snapshot::fromArray(['invalid' => 'data']);
    })->throws(\InvalidArgumentException::class);

    it('InMemorySnapshotStore CRUD operations', function (): void {
        $store = new InMemorySnapshotStore;
        $snapshot = Snapshot::create('Order', 'id-1', 10, ['status' => 'paid']);

        expect($store->has('Order', 'id-1'))->toBeFalse();

        $store->save($snapshot);
        expect($store->has('Order', 'id-1'))->toBeTrue();

        $loaded = $store->load('Order', 'id-1');
        expect($loaded)->not->toBeNull();
        expect($loaded->version)->toBe(10);

        expect($store->count('Order'))->toBe(1);
        expect($store->count())->toBe(1);

        $store->delete('Order', 'id-1');
        expect($store->has('Order', 'id-1'))->toBeFalse();
        expect($store->count())->toBe(0);
    });

    it('InMemorySnapshotStore stats and purge', function (): void {
        $store = new InMemorySnapshotStore;
        $store->save(Snapshot::create('Order', 'id-1', 1, []));
        $store->save(Snapshot::create('Order', 'id-2', 1, []));
        $store->save(Snapshot::create('User', 'id-1', 1, []));

        $stats = $store->stats();
        expect($stats['total'])->toBe(3);
        expect($stats['by_type']['Order'])->toBe(2);
        expect($stats['by_type']['User'])->toBe(1);

        $removed = $store->purge('Order');
        expect($removed)->toBe(2);
        expect($store->count())->toBe(1);
    });
});

// ─── Domain Event Collection Operations ─────────────────────────────────────────

describe('Domain Event Collection Operations', function (): void {
    it('creates from array round-trip', function (): void {
        $e1 = DomainEvent::occur('order.placed', ['id' => 'uuid-1']);
        $e2 = DomainEvent::occur('order.paid', ['id' => 'uuid-1', 'amount' => 100.0]);

        $collection = new DomainEventCollection([$e1, $e2]);
        $array = $collection->toArray();

        expect($array)->toBeArray();
        expect($array)->toHaveCount(2);

        $restored = DomainEventCollection::fromArray($array);
        expect($restored->count())->toBe(2);
    });

    it('filter returns new collection (immutable)', function (): void {
        $e1 = DomainEvent::occur('order.placed', []);
        $e2 = DomainEvent::occur('order.paid', []);
        $e3 = DomainEvent::occur('order.shipped', []);

        $collection = new DomainEventCollection([$e1, $e2, $e3]);
        $filtered = $collection->filter(fn (DomainEvent $e): bool => str_contains($e->eventType, 'order'));

        expect($filtered)->toHaveCount(3);
        expect($collection)->toHaveCount(3); // Original unchanged
    });

    it('merge creates new collection', function (): void {
        $c1 = new DomainEventCollection([DomainEvent::occur('a', [])]);
        $c2 = new DomainEventCollection([DomainEvent::occur('b', [])]);

        $merged = $c1->merge($c2);
        expect($merged->count())->toBe(2);
        expect($c1->count())->toBe(1); // Original unchanged
    });

    it('map applies callback', function (): void {
        $collection = new DomainEventCollection([
            DomainEvent::occur('order.placed', []),
            DomainEvent::occur('order.paid', []),
        ]);

        $types = $collection->map(fn (DomainEvent $e, int $i): string => $e->eventType);

        expect($types)->toBe(['order.placed', 'order.paid']);
    });

    it('rejects non-sequential arrays', function (): void {
        new DomainEventCollection([1 => DomainEvent::occur('test', [])]);
    })->throws(\InvalidArgumentException::class);

    it('rejects non-DomainEvent items', function (): void {
        new DomainEventCollection(['not-an-event']);
    })->throws(\InvalidArgumentException::class);
});

// ─── Unit of Work Lifecycle ─────────────────────────────────────────────────────

describe('Unit of Work Lifecycle', function (): void {
    it('auto-commits successful run', function (): void {
        $uow = new InMemoryUnitOfWork;
        $result = $uow->run(fn (): string => 'success');

        expect($result)->toBe('success');
        expect($uow->isActive())->toBeFalse();
    });

    it('auto-rollbacks on exception', function (): void {
        $uow = new InMemoryUnitOfWork;

        try {
            $uow->run(function (): void {
                throw new \RuntimeException('test failure');
            });
        } catch (\RuntimeException) {
            // Expected
        }

        expect($uow->isActive())->toBeFalse();
        expect($uow->hasPendingEvents())->toBeFalse();
    });

    it('tracks aggregates and clears on rollback', function (): void {
        $uow = new InMemoryUnitOfWork;
        $order = new InvariantOrder($id = AggregateRootId::generate());

        $uow->begin();
        $uow->track($order);
        expect($uow->isTracking($order))->toBeTrue();

        $uow->rollback();
        expect($uow->isActive())->toBeFalse();
    });

    it('clear resets all state', function (): void {
        $uow = new InMemoryUnitOfWork;
        $uow->begin();
        $uow->queueEvent(DomainEvent::occur('test', []));

        $uow->clear();

        expect($uow->isActive())->toBeFalse();
        expect($uow->hasPendingEvents())->toBeFalse();
    });

    it('nested run creates savepoints', function (): void {
        $uow = new InMemoryUnitOfWork;

        $result = $uow->run(function () use ($uow): string {
            expect($uow->isActive())->toBeTrue();

            return $uow->run(function (): string {
                expect($uow->isActive())->toBeTrue();

                return 'nested';
            });
        });

        expect($result)->toBe('nested');
        expect($uow->isActive())->toBeFalse();
    });

    it('queueEvent requires active scope', function (): void {
        $uow = new InMemoryUnitOfWork;

        $uow->queueEvent(DomainEvent::occur('test', []));
    })->throws(\RuntimeException::class);

    it('getPendingEvents peeks without consuming', function (): void {
        $uow = new InMemoryUnitOfWork;
        $uow->begin();
        $uow->queueEvent(DomainEvent::occur('test', []));

        $peeked = $uow->getPendingEvents();
        expect($peeked->count())->toBe(1);
        expect($uow->hasPendingEvents())->toBeTrue();

        $uow->rollback();
    });
});
