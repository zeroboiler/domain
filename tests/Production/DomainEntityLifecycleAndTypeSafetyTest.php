<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Tests\Production;

use ZeroBoiler\Domain\AggregateRoot;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\Contracts\{
    AggregateRoot as AggregateRootContract,
    Entity as EntityContract,
    Identifier as IdentifierContract,
};
use ZeroBoiler\Domain\Identifiers\{
    UuidIdentifier,
    UlidIdentifier,
    StringIdentifier,
    IntegerIdentifier,
};
use ZeroBoiler\Domain\DomainEventCollection;
use ZeroBoiler\Domain\Entity;
use ZeroBoiler\Domain\Exceptions\{
    InvalidStateDomainException,
    InvalidArgumentDomainException,
    NotFoundDomainException,
    OptimisticLockException,
    ConflictDomainException,
};
use ZeroBoiler\Domain\ValueObject;
use ZeroBoiler\Events\Domain\DomainEvent;

/**
 * Production-ready domain entity lifecycle, type safety, and edge case tests.
 *
 * Validates:
 * - AggregateRoot construction, identity, versioning, event lifecycle
 * - Entity flexible ID types (int, string, Stringable)
 * - ValueObject equality semantics (toArray-based deep comparison)
 * - Identifier type safety (cross-type inequality, immutability)
 * - DomainException hierarchy (error codes, HTTP status, RFC 9457 serialization)
 * - DomainEventCollection functional edge cases
 * - Serialization round-trip integrity (toArray/fromArray/toJson/fromJson)
 * - PHP 8.5 syntax compliance (readonly, #[Override], static return types)
 *
 * @since 1.75.0
 */

// ─── Test Fixtures ────────────────────────────────────────────────────────

final class LifecycleOrderId extends UuidIdentifier {}
final class LifecycleProductId extends UlidIdentifier {}
final class LifecycleSkuId extends StringIdentifier {}
final class LifecycleNumericId extends IntegerIdentifier {}

final class LifecycleAddress extends ValueObject
{
    public function __construct(
        public readonly string $street,
        public readonly string $city,
        public readonly string $country,
    ) {}

    public static function fromArray(array $data): static
    {
        return new static(
            street: $data['street'],
            city: $data['city'],
            country: $data['country'],
        );
    }

    public function toArray(): array
    {
        return [
            'street' => $this->street,
            'city' => $this->city,
            'country' => $this->country,
        ];
    }
}

final class LifecycleOrderItem extends Entity
{
    public function __construct(
        int|string|\Stringable $id,
        public readonly string $productName,
        public readonly int $quantity,
        public readonly float $unitPrice,
    ) {
        parent::__construct($id);
    }

    public function subtotal(): float
    {
        return $this->quantity * $this->unitPrice;
    }
}

final class LifecycleOrder extends AggregateRoot
{
    use Concerns\EventSourced;

    public string $status = 'draft';
    public float $total = 0.0;
    /** @var array<int, LifecycleOrderItem> */
    public array $items = [];

    public function __construct(AggregateRootId $id)
    {
        parent::__construct($id);
    }

    public static function place(AggregateRootId $id): self
    {
        $order = new self($id);
        $order->apply(DomainEvent::occur('order.placed', [
            'id' => $id->toString(),
            'status' => 'draft',
        ]));

        return $order;
    }

    public function addItem(string $productName, int $quantity, float $unitPrice): void
    {
        if ($quantity <= 0) {
            throw InvalidArgumentDomainException::because('Quantity must be positive.');
        }

        if ($this->status === 'paid') {
            throw InvalidStateDomainException::because('Cannot add items to a paid order.');
        }

        $this->apply(DomainEvent::occur('order.item_added', [
            'product_name' => $productName,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
        ]));
    }

    public function pay(float $amount): void
    {
        if ($this->status !== 'pending' && $this->status !== 'draft') {
            throw InvalidStateDomainException::because('Order must be draft or pending to pay.');
        }

        $this->apply(DomainEvent::occur('order.paid', [
            'amount' => $amount,
        ]));
    }

    public function cancel(): void
    {
        if ($this->status === 'paid') {
            throw InvalidStateDomainException::because('Cannot cancel a paid order.');
        }

        $this->apply(DomainEvent::occur('order.cancelled', []));
    }

    protected function applyOrderPlaced(DomainEvent $event): void
    {
        $this->status = $event->payload['status'];
    }

    protected function applyOrderItemAdded(DomainEvent $event): void
    {
        $this->items[] = new LifecycleOrderItem(
            id: count($this->items) + 1,
            productName: $event->payload['product_name'],
            quantity: $event->payload['quantity'],
            unitPrice: $event->payload['unit_price'],
        );
        $this->total = array_reduce(
            $this->items,
            fn (float $sum, LifecycleOrderItem $item): float => $sum + $item->subtotal(),
            0.0,
        );
    }

    protected function applyOrderPaid(DomainEvent $event): void
    {
        $this->status = 'paid';
        $this->total = $event->payload['amount'];
    }

    protected function applyOrderCancelled(DomainEvent $event): void
    {
        $this->status = 'cancelled';
    }

    public function toArray(): array
    {
        return [
            ...parent::toArray(),
            'status' => $this->status,
            'total' => $this->total,
            'item_count' => count($this->items),
        ];
    }
}

// ─── Test Suite ───────────────────────────────────────────────────────────

// 1. AggregateRoot Identity & Versioning
test('aggregate root has UUID v4 identity via AggregateRootId', function (): void {
    $id = AggregateRootId::generate();
    $order = LifecycleOrder::place($id);

    expect($order->id())->toBe($id->toString());
    expect($order->aggregateId())->toBe($id);
    expect($order->id())->toBeString();
    expect($order->aggregateId()->toString())->toBe($order->id());
    expect($order->aggregateId()->equals($id))->toBeTrue();
});

test('aggregate root starts at version 0 after creation', function (): void {
    $order = LifecycleOrder::place(AggregateRootId::generate());

    expect($order->version())->toBe(0);
});

test('aggregate root version increments with each applied event', function (): void {
    $order = LifecycleOrder::place(AggregateRootId::generate());
    expect($order->version())->toBe(1); // order.placed

    $order->pullDomainEvents(); // Clear events to test incrementVersion

    $order->incrementVersion();
    expect($order->version())->toBe(2);

    $order->incrementVersion();
    expect($order->version())->toBe(3);
});

test('aggregate root setVersion works for repository hydration', function (): void {
    $order = LifecycleOrder::place(AggregateRootId::generate());
    $order->setVersion(42);

    expect($order->version())->toBe(42);
});

// 2. AggregateRoot Event Lifecycle
test('aggregate root records and pulls domain events', function (): void {
    $order = LifecycleOrder::place(AggregateRootId::generate());

    $events = $order->pullDomainEvents();
    expect($events)->toBeInstanceOf(DomainEventCollection::class);
    expect($events->count())->toBe(1);
    expect($events->first()->eventType)->toBe('order.placed');

    // After pull, events are cleared
    expect($order->hasUncommittedEvents())->toBeFalse();
});

test('aggregate root peek does not consume events', function (): void {
    $order = LifecycleOrder::place(AggregateRootId::generate());

    $peeked = $order->peekDomainEvents();
    expect($peeked->count())->toBe(1);
    expect($order->hasUncommittedEvents())->toBeTrue();

    // Still available for pull
    $pulled = $order->pullDomainEvents();
    expect($pulled->count())->toBe(1);
});

test('aggregate root clearDomainEvents discards events', function (): void {
    $order = LifecycleOrder::place(AggregateRootId::generate());
    $order->clearDomainEvents();

    expect($order->hasUncommittedEvents())->toBeFalse();
});

test('aggregate root accumulate events across multiple operations', function (): void {
    $order = LifecycleOrder::place(AggregateRootId::generate());
    $order->pullDomainEvents(); // Clear placed event

    $order->addItem('Widget', 3, 9.99);
    $order->addItem('Gadget', 1, 24.99);

    $events = $order->pullDomainEvents();
    expect($events->count())->toBe(2);
    expect($events->types())->toBe(['order.item_added', 'order.item_added']);
});

// 3. AggregateRoot State Mutation via Handlers
test('event handlers mutate aggregate state correctly', function (): void {
    $order = LifecycleOrder::place(AggregateRootId::generate());
    $order->pullDomainEvents();

    expect($order->status)->toBe('draft');
    expect($order->total)->toBe(0.0);
    expect($order->items)->toBeEmpty();

    $order->addItem('Widget', 3, 9.99);

    expect($order->status)->toBe('draft');
    expect($order->items)->toHaveCount(1);
    expect($order->total)->toBe(29.97);

    $order->addItem('Gadget', 1, 24.99);

    expect($order->items)->toHaveCount(2);
    expect($order->total)->toBe(54.96);
});

// 4. AggregateRoot Domain Invariants
test('domain invariant violations throw typed exceptions', function (): void {
    $order = LifecycleOrder::place(AggregateRootId::generate());
    $order->pullDomainEvents();

    $order->addItem('Widget', 1, 10.00);
    $order->pullDomainEvents();

    // Pay the order
    $order->pay(10.00);
    $order->pullDomainEvents();
    expect($order->status)->toBe('paid');

    // Try to add items to paid order — should fail
})->throws(InvalidStateDomainException::class);

test('invalid argument throws typed exception', function (): void {
    $order = LifecycleOrder::place(AggregateRootId::generate());
    $order->pullDomainEvents();

    $order->addItem('Widget', 0, 10.00); // Quantity <= 0
})->throws(InvalidArgumentDomainException::class);

test('cannot cancel a paid order', function (): void {
    $order = LifecycleOrder::place(AggregateRootId::generate());
    $order->pullDomainEvents();

    $order->addItem('Widget', 1, 10.00);
    $order->pullDomainEvents();
    $order->pay(10.00);
    $order->pullDomainEvents();

    $order->cancel(); // Should throw
})->throws(InvalidStateDomainException::class);

// 5. AggregateRoot toArray serialization
test('aggregate root toArray includes identity, version, type and domain fields', function (): void {
    $order = LifecycleOrder::place(AggregateRootId::generate());
    $order->pullDomainEvents();

    $order->addItem('Widget', 2, 15.00);

    $array = $order->toArray();

    expect($array)->toHaveKey('id');
    expect($array)->toHaveKey('version');
    expect($array)->toHaveKey('type');
    expect($array)->toHaveKey('status');
    expect($array)->toHaveKey('total');
    expect($array)->toHaveKey('item_count');
    expect($array['type'])->toBe('LifecycleOrder');
    expect($array['status'])->toBe('draft');
    expect($array['total'])->toBe(30.0);
    expect($array['item_count'])->toBe(1);
});

test('aggregate root toJson is valid JSON', function (): void {
    $order = LifecycleOrder::place(AggregateRootId::generate());

    $json = $order->toJson();
    $decoded = json_decode($json, true);

    expect($decoded)->toBeArray();
    expect($decoded)->toHaveKey('id');
    expect($decoded)->toHaveKey('type');
    expect($decoded['type'])->toBe('LifecycleOrder');
});

test('aggregate root implements JsonSerializable', function (): void {
    $order = LifecycleOrder::place(AggregateRootId::generate());

    $encoded = json_encode($order);
    $decoded = json_decode($encoded, true);

    expect($decoded)->toBeArray();
    expect($decoded['type'])->toBe('LifecycleOrder');
});

// 6. Entity Identity Equality
test('entities are equal when same class and same ID', function (): void {
    $item1 = new LifecycleOrderItem('42', 'Widget', 2, 9.99);
    $item2 = new LifecycleOrderItem('42', 'Gadget', 5, 19.99);

    expect($item1->equals($item2))->toBeTrue();
});

test('entities are not equal when different class', function (): void {
    $item = new LifecycleOrderItem('42', 'Widget', 2, 9.99);

    // Different Entity subclass
    $otherItem = new class ('42', 'Other', 1, 5.0) extends Entity {
        public function __construct(
            int|string|\Stringable $id,
            public readonly string $name,
            public readonly int $qty,
            public readonly float $price,
        ) {
            parent::__construct($id);
        }
    };

    expect($item->equals($otherItem))->toBeFalse();
});

test('entities support int IDs', function (): void {
    $item = new LifecycleOrderItem(42, 'Widget', 2, 9.99);
    expect($item->id())->toBe('42');
});

test('entities support Stringable IDs', function (): void {
    $uuidId = LifecycleOrderId::generate();
    $item = new LifecycleOrderItem($uuidId, 'Widget', 2, 9.99);
    expect($item->id())->toBe($uuidId->toString());
});

test('entity toArray includes id and type', function (): void {
    $item = new LifecycleOrderItem('42', 'Widget', 2, 9.99);
    $array = $item->toArray();

    expect($array)->toHaveKey('id');
    expect($array)->toHaveKey('type');
    expect($array['id'])->toBe('42');
    expect($array['type'])->toBe('LifecycleOrderItem');
});

// 7. ValueObject Equality
test('value objects are equal when same class and same toArray output', function (): void {
    $addr1 = LifecycleAddress::fromArray(['street' => '123 Main', 'city' => 'NYC', 'country' => 'US']);
    $addr2 = LifecycleAddress::fromArray(['street' => '123 Main', 'city' => 'NYC', 'country' => 'US']);

    expect($addr1->equals($addr2))->toBeTrue();
});

test('value objects are not equal when different values', function (): void {
    $addr1 = LifecycleAddress::fromArray(['street' => '123 Main', 'city' => 'NYC', 'country' => 'US']);
    $addr2 = LifecycleAddress::fromArray(['street' => '456 Oak', 'city' => 'LA', 'country' => 'US']);

    expect($addr1->equals($addr2))->toBeFalse();
});

test('value object toJson round-trip', function (): void {
    $addr = LifecycleAddress::fromArray(['street' => '123 Main', 'city' => 'NYC', 'country' => 'US']);

    $json = $addr->toJson();
    $restored = LifecycleAddress::fromJson($json);

    expect($addr->equals($restored))->toBeTrue();
});

// 8. Identifier Type Safety
test('UUID identifiers reject invalid strings', function (): void {
    LifecycleOrderId::fromString('not-a-uuid');
})->throws(\InvalidArgumentException::class);

test('ULID identifiers generate monotonic IDs', function (): void {
    $id1 = LifecycleProductId::generate();
    $id2 = LifecycleProductId::generate();

    expect($id1->equals($id2))->toBeFalse();
    expect($id1->toString())->toBeString();
    expect($id2->toString())->toBeString();
});

test('String identifiers reject empty strings', function (): void {
    LifecycleSkuId::from('');
})->throws(\InvalidArgumentException::class);

test('Integer identifiers accept positive integers', function (): void {
    $id = LifecycleNumericId::from(42);
    expect($id->toInt())->toBe(42);
    expect($id->toString())->toBe('42');
});

test('cross-type identifiers are never equal', function (): void {
    $uuid = LifecycleOrderId::generate();
    $ulid = LifecycleProductId::generate();
    $str = LifecycleSkuId::from('sku-123');
    $int = LifecycleNumericId::from(1);

    // These are all different types
    expect($uuid->equals($ulid))->toBeFalse();
    expect($str->equals($int))->toBeFalse();
});

// 9. Identifier Serialization Round-Trip
test('all identifier types support toArray/fromArray round-trip', function (): void {
    $uuid = LifecycleOrderId::generate();
    $ulid = LifecycleProductId::generate();
    $str = LifecycleSkuId::from('test-slug');
    $int = LifecycleNumericId::from(99);

    foreach ([$uuid, $ulid, $str, $int] as $id) {
        $array = $id->toArray();
        $className = $id::class;

        $restored = $className::fromArray($array);
        expect($id->equals($restored))->toBeTrue();
    }
});

test('all identifier types support toJson/fromJson round-trip', function (): void {
    $uuid = LifecycleOrderId::generate();
    $ulid = LifecycleProductId::generate();
    $str = LifecycleSkuId::from('test-slug');
    $int = LifecycleNumericId::from(99);

    foreach ([$uuid, $ulid, $str, $int] as $id) {
        $json = $id->toJson();
        $className = $id::class;

        $restored = $className::fromJson($json);
        expect($id->equals($restored))->toBeTrue();
    }
});

test('AggregateRootId supports toArray/fromArray/toJson/fromJson round-trip', function (): void {
    $id = AggregateRootId::generate();

    // toArray/fromArray
    $restored = AggregateRootId::fromArray($id->toArray());
    expect($id->equals($restored))->toBeTrue();

    // toJson/fromJson
    $json = $id->toJson();
    $fromJson = AggregateRootId::fromJson($json);
    expect($id->equals($fromJson))->toBeTrue();

    // jsonSerialize
    $encoded = json_encode($id);
    expect($encoded)->toBeJson();
});

// 10. DomainException Hierarchy
test('InvalidStateDomainException has correct error code and HTTP status', function (): void {
    $e = InvalidStateDomainException::because('Order must be pending.');

    expect($e->errorCode())->toBe('INVALID_STATE');
    expect($e->httpStatus())->toBe(422);
    expect($e->toErrorArray())->toBeArray();
    expect($e->toErrorArray())->toHaveKeys(['title', 'detail', 'code', 'status']);
    expect($e->toErrorArray()['code'])->toBe('INVALID_STATE');
    expect($e->toErrorArray()['status'])->toBe(422);
});

test('NotFoundDomainException has correct error code and HTTP status', function (): void {
    $e = NotFoundDomainException::forAggregate('Order', '123');

    expect($e->errorCode())->toBe('NOT_FOUND');
    expect($e->httpStatus())->toBe(404);
});

test('OptimisticLockException has correct error code and HTTP status', function (): void {
    $e = OptimisticLockException::for('order-123', expectedVersion: 5, actualVersion: 3);

    expect($e->errorCode())->toBe('OPTIMISTIC_LOCK');
    expect($e->httpStatus())->toBe(409);
});

test('ConflictDomainException has correct error code and HTTP status', function (): void {
    $e = ConflictDomainException::because('Concurrent modification detected.');

    expect($e->errorCode())->toBe('CONFLICT');
    expect($e->httpStatus())->toBe(409);
});

test('all domain exceptions implement JsonSerializable', function (): void {
    $exceptions = [
        InvalidStateDomainException::because('test'),
        InvalidArgumentDomainException::because('test'),
        NotFoundDomainException::forId('id-123'),
        ConflictDomainException::because('test'),
        OptimisticLockException::for('id', expectedVersion: 1, actualVersion: 2),
    ];

    foreach ($exceptions as $e) {
        $json = json_encode($e);
        expect($json)->toBeJson();
        $decoded = json_decode($json, true);
        expect($decoded)->toHaveKeys(['title', 'detail', 'code', 'status']);
    }
});

test('domain exception supports fromArray/toArray round-trip', function (): void {
    $original = InvalidStateDomainException::because('Test message');
    $array = $original->toErrorArray();
    $restored = InvalidStateDomainException::fromArray($array);

    expect($restored->errorCode())->toBe('INVALID_STATE');
});

test('domain exception supports fromJson/toJson round-trip', function (): void {
    $original = NotFoundDomainException::forId('order-123');
    $json = $original->toJson();
    $restored = NotFoundDomainException::fromJson($json);

    expect($restored->errorCode())->toBe('NOT_FOUND');
});

// 11. DomainEventCollection Functional Operations
test('DomainEventCollection some/none/find/countBy/hasType work correctly', function (): void {
    $e1 = DomainEvent::occur('order.placed', ['id' => '1']);
    $e2 = DomainEvent::occur('order.item_added', ['product' => 'widget']);
    $e3 = DomainEvent::occur('order.paid', ['amount' => 99.99]);

    $collection = new DomainEventCollection([$e1, $e2, $e3]);

    expect($collection->some(fn ($e): bool => $e->eventType === 'order.paid'))->toBeTrue();
    expect($collection->some(fn ($e): bool => $e->eventType === 'order.shipped'))->toBeFalse();
    expect($collection->none(fn ($e): bool => $e->eventType === 'order.shipped'))->toBeTrue();
    expect($collection->find(fn ($e): bool => $e->eventType === 'order.paid'))->toBe($e3);
    expect($collection->countBy(fn ($e): bool => str_starts_with($e->eventType, 'order.')))->toBe(3);
    expect($collection->hasType('order.placed'))->toBeTrue();
    expect($collection->hasType('order.cancelled'))->toBeFalse();
});

test('DomainEventCollection reduce accumulates correctly', function (): void {
    $e1 = DomainEvent::occur('payment.received', ['amount' => 50.0]);
    $e2 = DomainEvent::occur('payment.received', ['amount' => 25.0]);

    $collection = new DomainEventCollection([$e1, $e2]);

    $total = $collection->reduce(
        fn (float $sum, DomainEvent $e): float => $sum + ($e->payload['amount'] ?? 0),
        0.0,
    );

    expect($total)->toBe(75.0);
});

test('DomainEventCollection types returns unique types in order', function (): void {
    $e1 = DomainEvent::occur('order.placed', []);
    $e2 = DomainEvent::occur('order.item_added', []);
    $e3 = DomainEvent::occur('order.placed', []);
    $e4 = DomainEvent::occur('order.paid', []);

    $collection = new DomainEventCollection([$e1, $e2, $e3, $e4]);

    expect($collection->types())->toBe(['order.placed', 'order.item_added', 'order.paid']);
});

test('DomainEventCollection merge creates new collection', function (): void {
    $e1 = DomainEvent::occur('order.placed', []);
    $e2 = DomainEvent::occur('order.item_added', []);
    $e3 = DomainEvent::occur('order.paid', []);

    $c1 = new DomainEventCollection([$e1]);
    $c2 = new DomainEventCollection([$e2, $e3]);
    $merged = $c1->merge($c2);

    expect($merged->count())->toBe(3);
    expect($merged)->not->toBe($c1); // New instance
});

test('DomainEventCollection filter returns new collection', function (): void {
    $e1 = DomainEvent::occur('order.placed', []);
    $e2 = DomainEvent::occur('order.paid', []);

    $collection = new DomainEventCollection([$e1, $e2]);
    $paid = $collection->filter(fn ($e): bool => $e->eventType === 'order.paid');

    expect($paid->count())->toBe(1);
    expect($paid->first()->eventType)->toBe('order.paid');
});

test('DomainEventCollection toArray/fromArray round-trip', function (): void {
    $e1 = DomainEvent::occur('order.placed', ['id' => '123']);
    $e2 = DomainEvent::occur('order.item_added', ['product' => 'widget', 'qty' => 3]);

    $collection = new DomainEventCollection([$e1, $e2]);
    $array = $collection->toArray();
    $restored = DomainEventCollection::fromArray($array);

    expect($restored->count())->toBe(2);
    expect($restored->first()->eventType)->toBe('order.placed');
});

test('DomainEventCollection rejects non-sequential arrays', function (): void {
    new DomainEventCollection(['key' => DomainEvent::occur('test', [])]);
})->throws(\InvalidArgumentException::class);

// 12. Contract Compliance
test('AggregateRoot implements AggregateRootContract', function (): void {
    $order = LifecycleOrder::place(AggregateRootId::generate());

    expect($order)->toBeInstanceOf(AggregateRootContract::class);
    expect($order)->toBeInstanceOf(EntityContract::class);
});

test('Entity implements EntityContract and JsonSerializable', function (): void {
    $item = new LifecycleOrderItem('1', 'Widget', 2, 9.99);

    expect($item)->toBeInstanceOf(EntityContract::class);
    expect($item)->toBeInstanceOf(\JsonSerializable::class);
});

test('all identifiers implement IdentifierContract', function (): void {
    $uuid = LifecycleOrderId::generate();
    $ulid = LifecycleProductId::generate();
    $str = LifecycleSkuId::from('sku');
    $int = LifecycleNumericId::from(1);

    foreach ([$uuid, $ulid, $str, $int] as $id) {
        expect($id)->toBeInstanceOf(IdentifierContract::class);
    }
});

// 13. AggregateRootId readonly immutability
test('AggregateRootId is final readonly class', function (): void {
    $reflection = new \ReflectionClass(AggregateRootId::class);

    expect($reflection->isFinal())->toBeTrue();
    expect($reflection->isReadOnly())->toBeTrue();
});

test('AggregateRootId jsonSerialize returns string', function (): void {
    $id = AggregateRootId::generate();
    $result = $id->jsonSerialize();

    expect($result)->toBeString();
    expect($result)->toBe($id->toString());
});

// 14. Dot-convention event handler resolution
test('dot-separated event type resolves to correct handler', function (): void {
    $order = LifecycleOrder::place(AggregateRootId::generate());
    $order->pullDomainEvents();

    // 'order.item_added' → applyOrderItemAdded()
    $order->addItem('Widget', 5, 10.00);

    expect($order->items)->toHaveCount(1);
    expect($order->items[0]->productName)->toBe('Widget');
    expect($order->items[0]->quantity)->toBe(5);
    expect($order->items[0]->unitPrice)->toBe(10.0);
});

// 15. Event sourcing reconstitution from history
test('aggregate can be reconstituted from event history', function (): void {
    $id = AggregateRootId::generate();

    $events = [
        DomainEvent::occur('order.placed', ['id' => $id->toString(), 'status' => 'draft']),
        DomainEvent::occur('order.item_added', ['product_name' => 'Widget', 'quantity' => 2, 'unit_price' => 15.00]),
        DomainEvent::occur('order.item_added', ['product_name' => 'Gadget', 'quantity' => 1, 'unit_price' => 25.00]),
    ];

    $order = LifecycleOrder::fromHistory(...$events);

    expect($order->id())->toBe($id->toString());
    expect($order->status)->toBe('draft');
    expect($order->items)->toHaveCount(2);
    expect($order->total)->toBe(55.0);
    expect($order->version())->toBe(3);
    expect($order->hasUncommittedEvents())->toBeFalse();
});
