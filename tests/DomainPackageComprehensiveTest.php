<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace Tests\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ZeroBoiler\Domain\AggregateRoot;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\Contracts\AggregateRoot as AggregateRootContract;
use ZeroBoiler\Domain\Contracts\Entity as EntityContract;
use ZeroBoiler\Domain\Contracts\Identifier as IdentifierContract;
use ZeroBoiler\Domain\Contracts\Repository;
use ZeroBoiler\Domain\Contracts\UnitOfWork as UnitOfWorkContract;
use ZeroBoiler\Domain\DomainEventCollection;
use ZeroBoiler\Domain\Entity;
use ZeroBoiler\Domain\Exceptions\AggregateNotFoundException;
use ZeroBoiler\Domain\Exceptions\ConflictDomainException;
use ZeroBoiler\Domain\Exceptions\DomainException;
use ZeroBoiler\Domain\Exceptions\InvalidArgumentDomainException;
use ZeroBoiler\Domain\Exceptions\InvalidStateDomainException;
use ZeroBoiler\Domain\Exceptions\NotFoundDomainException;
use ZeroBoiler\Domain\Exceptions\OptimisticLockException;
use ZeroBoiler\Domain\Identifiers\IntegerIdentifier;
use ZeroBoiler\Domain\Identifiers\StringIdentifier;
use ZeroBoiler\Domain\Identifiers\UlidIdentifier;
use ZeroBoiler\Domain\Identifiers\UuidIdentifier;
use ZeroBoiler\Domain\InMemoryUnitOfWork;
use ZeroBoiler\Domain\Snapshots\InMemorySnapshotStore;
use ZeroBoiler\Domain\Snapshots\Snapshot;
use ZeroBoiler\Domain\Snapshots\SnapshotPolicy;
use ZeroBoiler\Domain\Snapshots\SnapshotStore;
use ZeroBoiler\Domain\ValueObject;
use ZeroBoiler\Events\Domain\DomainEvent;

// phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses

// ---------------------------------------------------------------------------
// Test Doubles
// ---------------------------------------------------------------------------

/**
 * Concrete aggregate root for testing.
 */
#[CoversClass(AggregateRoot::class)]
class TestOrder extends AggregateRoot
{
    public string $status = 'pending';

    public float $total = 0.0;

    public static function place(string $customerId): self
    {
        $order = new self(AggregateRootId::generate());
        $order->apply(DomainEvent::occur('order.placed', [
            'id' => $order->id(),
            'customer_id' => $customerId,
        ]));

        return $order;
    }

    public function addItem(string $productId, int $quantity, float $price): void
    {
        $this->apply(DomainEvent::occur('order.item_added', [
            'id' => $this->id(),
            'product_id' => $productId,
            'quantity' => $quantity,
            'price' => $price,
        ]));
    }

    public function pay(): void
    {
        $this->apply(DomainEvent::occur('order.paid', [
            'id' => $this->id(),
            'total' => $this->total,
        ]));
    }

    protected function applyOrderPlaced(DomainEvent $event): void
    {
        $this->status = 'pending';
    }

    protected function applyOrderItemAdded(DomainEvent $event): void
    {
        $this->total += $event->payload['price'] * $event->payload['quantity'];
    }

    protected function applyOrderPaid(DomainEvent $event): void
    {
        $this->status = 'paid';
    }

    #[\Override]
    public function toArray(): array
    {
        return [
            ...parent::toArray(),
            'status' => $this->status,
            'total' => $this->total,
        ];
    }
}

/**
 * Concrete UUID identifier for testing.
 */
#[CoversClass(UuidIdentifier::class)]
class TestOrderId extends UuidIdentifier {}

/**
 * Concrete ULID identifier for testing.
 */
#[CoversClass(UlidIdentifier::class)]
class TestProductId extends UlidIdentifier {}

/**
 * Concrete value object for testing.
 */
#[CoversClass(ValueObject::class)]
class TestAddress extends ValueObject
{
    public function __construct(
        public readonly string $street,
        public readonly string $city,
        public readonly string $country,
    ) {}

    #[\Override]
    public static function fromArray(array $data): static
    {
        return new static(
            street: $data['street'],
            city: $data['city'],
            country: $data['country'],
        );
    }

    #[\Override]
    public function toArray(): array
    {
        return [
            'street' => $this->street,
            'city' => $this->city,
            'country' => $this->country,
        ];
    }
}

/**
 * Concrete entity (non-aggregate) for testing.
 */
#[CoversClass(Entity::class)]
class TestOrderItem extends Entity
{
    public function __construct(
        int|string|\Stringable $id,
        public readonly string $productId,
        public readonly int $quantity,
    ) {
        parent::__construct($id);
    }

    #[\Override]
    public function toArray(): array
    {
        return [
            ...parent::toArray(),
            'product_id' => $this->productId,
            'quantity' => $this->quantity,
        ];
    }
}

// ---------------------------------------------------------------------------
// Test Suite
// ---------------------------------------------------------------------------

/**
 * Comprehensive domain package production readiness test.
 *
 * Validates strict types, immutability, domain invariants, serialization,
 * round-trip guarantees, identifier contracts, and exception hierarchy.
 *
 * @Group domain
 * @Group production
 */
#[Group('domain')]
#[Group('production')]
class DomainPackageComprehensiveTest extends TestCase
{
    // -----------------------------------------------------------------------
    // Aggregate Root
    // -----------------------------------------------------------------------

    public function test_aggregate_root_creates_with_generated_id(): void
    {
        $order = TestOrder::place('customer-123');

        $this->assertNotEmpty($order->id());
        $this->assertSame(0, $order->getVersion());
        $this->assertSame('pending', $order->status);
        $this->assertTrue($order->hasUncommittedEvents());
    }

    public function test_aggregate_root_records_domain_events(): void
    {
        $order = TestOrder::place('customer-123');
        $events = $order->pullDomainEvents();

        $this->assertCount(1, $events);
        $this->assertSame('order.placed', $events->first()?->eventType);
        $this->assertFalse($order->hasUncommittedEvents());
    }

    public function test_aggregate_root_peek_does_not_consume_events(): void
    {
        $order = TestOrder::place('customer-123');

        $peeked = $order->peekDomainEvents();
        $this->assertCount(1, $peeked);

        $pulled = $order->pullDomainEvents();
        $this->assertCount(1, $pulled);
    }

    public function test_aggregate_root_version_increments_on_apply(): void
    {
        $order = TestOrder::place('customer-123');
        $order->pullDomainEvents(); // Clear placed event

        $order->addItem('prod-1', 2, 9.99);
        $this->assertSame(1, $order->getVersion());

        $order->addItem('prod-2', 1, 19.99);
        $this->assertSame(2, $order->getVersion());

        // Total should be 2*9.99 + 1*19.99 = 39.97
        $this->assertSame(39.97, $order->total);
    }

    public function test_aggregate_root_clear_events(): void
    {
        $order = TestOrder::place('customer-123');
        $this->assertTrue($order->hasUncommittedEvents());

        $order->clearDomainEvents();
        $this->assertFalse($order->hasUncommittedEvents());
    }

    public function test_aggregate_root_equality(): void
    {
        $id = AggregateRootId::generate();
        $order1 = new class ($id) extends AggregateRoot {
            public static function create(AggregateRootId $id): static { return new static($id); }
        };
        $order2 = new class ($id) extends AggregateRoot {
            public static function create(AggregateRootId $id): static { return new static($id); }
        };

        // Different concrete classes → not equal
        $this->assertFalse($order1->equals($order2));
    }

    public function test_aggregate_root_to_array_includes_version(): void
    {
        $order = TestOrder::place('customer-123');
        $array = $order->toArray();

        $this->assertArrayHasKey('id', $array);
        $this->assertArrayHasKey('version', $array);
        $this->assertArrayHasKey('type', $array);
        $this->assertSame('TestOrder', $array['type']);
        $this->assertArrayHasKey('status', $array);
    }

    public function test_aggregate_root_to_json_round_trip(): void
    {
        $order = TestOrder::place('customer-123');
        $json = $order->toJson();

        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
        $this->assertSame('TestOrder', $decoded['type']);
        $this->assertSame('pending', $decoded['status']);
    }

    public function test_aggregate_root_json_serializable(): void
    {
        $order = TestOrder::place('customer-123');
        $json = json_encode($order);

        $this->assertNotFalse($json);
        $decoded = json_decode($json, true);
        $this->assertSame('TestOrder', $decoded['type']);
    }

    public function test_aggregate_root_implements_contracts(): void
    {
        $order = TestOrder::place('customer-123');

        $this->assertInstanceOf(AggregateRootContract::class, $order);
        $this->assertInstanceOf(EntityContract::class, $order);
        $this->assertInstanceOf(\JsonSerializable::class, $order);
    }

    // -----------------------------------------------------------------------
    // Aggregate Root ID
    // -----------------------------------------------------------------------

    public function test_aggregate_root_id_generate_creates_valid_uuid(): void
    {
        $id = AggregateRootId::generate();

        $this->assertTrue(AggregateRootId::isValid($id->toString()));
        $this->assertNotEmpty($id->toString());
    }

    public function test_aggregate_root_id_from_string_parses_uuid(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $id = AggregateRootId::fromString($uuid);

        $this->assertSame($uuid, $id->toString());
        $this->assertTrue(AggregateRootId::isValid($uuid));
        $this->assertFalse(AggregateRootId::isValid('not-a-uuid'));
    }

    public function test_aggregate_root_id_equality(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $id1 = AggregateRootId::fromString($uuid);
        $id2 = AggregateRootId::fromString($uuid);

        $this->assertTrue($id1->equals($id2));
    }

    public function test_aggregate_root_id_stringable(): void
    {
        $id = AggregateRootId::generate();
        $this->assertSame($id->toString(), (string) $id);
    }

    public function test_aggregate_root_id_json_serializable(): void
    {
        $id = AggregateRootId::generate();
        $this->assertSame($id->toString(), $id->jsonSerialize());
    }

    public function test_aggregate_root_id_array_round_trip(): void
    {
        $id = AggregateRootId::generate();
        $restored = AggregateRootId::fromArray($id->toArray());

        $this->assertTrue($id->equals($restored));
    }

    public function test_aggregate_root_id_json_round_trip(): void
    {
        $id = AggregateRootId::generate();
        $json = $id->toJson();
        $restored = AggregateRootId::fromJson($json);

        $this->assertTrue($id->equals($restored));
    }

    public function test_aggregate_root_id_from_array_accepts_id_key(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $id = AggregateRootId::fromArray(['id' => $uuid]);

        $this->assertSame($uuid, $id->toString());
    }

    public function test_aggregate_root_id_from_array_rejects_invalid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AggregateRootId::fromArray(['uuid' => 123]);
    }

    // -----------------------------------------------------------------------
    // Entity (non-aggregate)
    // -----------------------------------------------------------------------

    public function test_entity_with_int_id(): void
    {
        $item = new TestOrderItem(42, 'prod-1', 3);

        $this->assertSame('42', $item->id());
        $this->assertSame('prod-1', $item->productId);
        $this->assertSame(3, $item->quantity);
    }

    public function test_entity_with_string_id(): void
    {
        $item = new TestOrderItem('item-123', 'prod-1', 1);

        $this->assertSame('item-123', $item->id());
    }

    public function test_entity_with_identifier_id(): void
    {
        $id = IntegerIdentifier::from(99);
        $item = new TestOrderItem($id, 'prod-1', 1);

        $this->assertSame('99', $item->id());
    }

    public function test_entity_equality(): void
    {
        $item1 = new TestOrderItem(42, 'prod-1', 3);
        $item2 = new TestOrderItem(42, 'prod-2', 5);

        // Same class + same ID → equal (regardless of other fields)
        $this->assertTrue($item1->equals($item2));
    }

    public function test_entity_equality_different_class(): void
    {
        $item = new TestOrderItem(42, 'prod-1', 3);
        $order = TestOrder::place('customer-123');

        // Different concrete classes → not equal
        $this->assertFalse($item->equals($order));
    }

    public function test_entity_to_array(): void
    {
        $item = new TestOrderItem(42, 'prod-1', 3);
        $array = $item->toArray();

        $this->assertSame('42', $array['id']);
        $this->assertSame('TestOrderItem', $array['type']);
        $this->assertSame('prod-1', $array['product_id']);
        $this->assertSame(3, $array['quantity']);
    }

    public function test_entity_from_array_round_trip(): void
    {
        $original = new TestOrderItem(42, 'prod-1', 3);
        $array = $original->toArray();

        $restored = TestOrderItem::fromArray($array);
        $this->assertSame($original->id(), $restored->id());
        $this->assertSame($original->productId, $restored->productId);
        $this->assertSame($original->quantity, $restored->quantity);
    }

    public function test_entity_from_json_round_trip(): void
    {
        $original = new TestOrderItem(42, 'prod-1', 3);
        $json = $original->toJson();

        $restored = TestOrderItem::fromJson($json);
        $this->assertSame($original->id(), $restored->id());
    }

    public function test_entity_json_serializable(): void
    {
        $item = new TestOrderItem(42, 'prod-1', 3);
        $json = json_encode($item);

        $this->assertNotFalse($json);
        $decoded = json_decode($json, true);
        $this->assertSame('42', $decoded['id']);
    }

    public function test_entity_from_array_missing_required_throws(): void
    {
        $this->expectException(\ArgumentCountError::class);
        TestOrderItem::fromArray(['id' => 42]); // Missing productId and quantity
    }

    // -----------------------------------------------------------------------
    // UUID Identifier
    // -----------------------------------------------------------------------

    public function test_uuid_identifier_generate(): void
    {
        $id = TestOrderId::generate();

        $this->assertTrue(UuidIdentifier::isValid($id->toString()));
        $this->assertInstanceOf(IdentifierContract::class, $id);
        $this->assertInstanceOf(\JsonSerializable::class, $id);
    }

    public function test_uuid_identifier_from_string(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $id = TestOrderId::fromString($uuid);

        $this->assertSame($uuid, $id->toString());
    }

    public function test_uuid_identifier_from_string_validates(): void
    {
        $this->expectException(\Ramsey\Uuid\Exception\InvalidUuidStringException::class);
        TestOrderId::fromString('not-a-uuid');
    }

    public function test_uuid_identifier_equality_same_class(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $id1 = TestOrderId::fromString($uuid);
        $id2 = TestOrderId::fromString($uuid);

        $this->assertTrue($id1->equals($id2));
    }

    public function test_uuid_identifier_equality_different_subclass(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';

        // Create another UUID identifier subclass
        $otherId = new class($uuid) extends UuidIdentifier {};

        $id = TestOrderId::fromString($uuid);
        $this->assertFalse($id->equals($otherId));
    }

    public function test_uuid_identifier_array_round_trip(): void
    {
        $id = TestOrderId::generate();
        $restored = TestOrderId::fromArray($id->toArray());

        $this->assertTrue($id->equals($restored));
    }

    public function test_uuid_identifier_json_round_trip(): void
    {
        $id = TestOrderId::generate();
        $json = $id->toJson();
        $restored = TestOrderId::fromJson($json);

        $this->assertTrue($id->equals($restored));
    }

    public function test_uuid_identifier_to_uuid(): void
    {
        $id = TestOrderId::generate();
        $ramsey = $id->toUuid();

        $this->assertSame($id->toString(), $ramsey->toString());
    }

    // -----------------------------------------------------------------------
    // ULID Identifier
    // -----------------------------------------------------------------------

    public function test_ulid_identifier_generate(): void
    {
        $id = TestProductId::generate();

        $this->assertTrue(UlidIdentifier::isValid($id->toString()));
        $this->assertInstanceOf(IdentifierContract::class, $id);
    }

    public function test_ulid_identifier_from_string_validates(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TestProductId::fromString('not-a-ulid');
    }

    public function test_ulid_identifier_array_round_trip(): void
    {
        $id = TestProductId::generate();
        $restored = TestProductId::fromArray($id->toArray());

        $this->assertTrue($id->equals($restored));
    }

    public function test_ulid_identifier_json_round_trip(): void
    {
        $id = TestProductId::generate();
        $json = $id->toJson();
        $restored = TestProductId::fromJson($json);

        $this->assertTrue($id->equals($restored));
    }

    // -----------------------------------------------------------------------
    // String Identifier
    // -----------------------------------------------------------------------

    public function test_string_identifier_from(): void
    {
        $id = StringIdentifier::from('my-slug');

        $this->assertSame('my-slug', $id->toString());
        $this->assertInstanceOf(IdentifierContract::class, $id);
    }

    public function test_string_identifier_rejects_empty(): void
    {
        $this->expectException(\ValueError::class);
        StringIdentifier::from('');
    }

    public function test_string_identifier_is_valid(): void
    {
        $this->assertTrue(StringIdentifier::isValid('hello'));
        $this->assertFalse(StringIdentifier::isValid(''));
    }

    public function test_string_identifier_array_round_trip(): void
    {
        $id = StringIdentifier::from('my-slug');
        $restored = StringIdentifier::fromArray($id->toArray());

        $this->assertTrue($id->equals($restored));
    }

    public function test_string_identifier_json_round_trip(): void
    {
        $id = StringIdentifier::from('my-slug');
        $json = $id->toJson();
        $restored = StringIdentifier::fromJson($json);

        $this->assertTrue($id->equals($restored));
    }

    // -----------------------------------------------------------------------
    // Integer Identifier
    // -----------------------------------------------------------------------

    public function test_integer_identifier_from(): void
    {
        $id = IntegerIdentifier::from(42);

        $this->assertSame(42, $id->toInt());
        $this->assertSame('42', $id->toString());
        $this->assertInstanceOf(IdentifierContract::class, $id);
    }

    public function test_integer_identifier_is_valid(): void
    {
        $this->assertTrue(IntegerIdentifier::isValid('42'));
        $this->assertTrue(IntegerIdentifier::isValid('-5'));
        $this->assertFalse(IntegerIdentifier::isValid('abc'));
    }

    public function test_integer_identifier_json_serializable_returns_int(): void
    {
        $id = IntegerIdentifier::from(42);
        $this->assertSame(42, $id->jsonSerialize());
    }

    public function test_integer_identifier_array_round_trip(): void
    {
        $id = IntegerIdentifier::from(42);
        $restored = IntegerIdentifier::fromArray($id->toArray());

        $this->assertTrue($id->equals($restored));
    }

    public function test_integer_identifier_json_round_trip(): void
    {
        $id = IntegerIdentifier::from(42);
        $json = $id->toJson();
        $restored = IntegerIdentifier::fromJson($json);

        $this->assertTrue($id->equals($restored));
    }

    public function test_integer_identifier_from_array_accepts_string_id(): void
    {
        $id = IntegerIdentifier::fromArray(['id' => '42']);
        $this->assertSame(42, $id->toInt());
    }

    // -----------------------------------------------------------------------
    // Value Object
    // -----------------------------------------------------------------------

    public function test_value_object_equality(): void
    {
        $a1 = new TestAddress('123 Main', 'NYC', 'US');
        $a2 = new TestAddress('123 Main', 'NYC', 'US');
        $a3 = new TestAddress('456 Oak', 'LA', 'US');

        $this->assertTrue($a1->equals($a2));
        $this->assertFalse($a1->equals($a3));
    }

    public function test_value_object_to_array(): void
    {
        $addr = new TestAddress('123 Main', 'NYC', 'US');
        $array = $addr->toArray();

        $this->assertSame('123 Main', $array['street']);
        $this->assertSame('NYC', $array['city']);
        $this->assertSame('US', $array['country']);
    }

    public function test_value_object_from_array_round_trip(): void
    {
        $original = new TestAddress('123 Main', 'NYC', 'US');
        $restored = TestAddress::fromArray($original->toArray());

        $this->assertTrue($original->equals($restored));
    }

    public function test_value_object_json_round_trip(): void
    {
        $original = new TestAddress('123 Main', 'NYC', 'US');
        $json = $original->toJson();
        $restored = TestAddress::fromJson($json);

        $this->assertTrue($original->equals($restored));
    }

    // -----------------------------------------------------------------------
    // Domain Event Collection
    // -----------------------------------------------------------------------

    public function test_domain_event_collection_iteration(): void
    {
        $e1 = DomainEvent::occur('test.event1', ['key' => 'val1']);
        $e2 = DomainEvent::occur('test.event2', ['key' => 'val2']);

        $collection = new DomainEventCollection([$e1, $e2]);

        $this->assertCount(2, $collection);
        $this->assertFalse($collection->isEmpty());

        $types = $collection->types();
        $this->assertSame(['test.event1', 'test.event2'], $types);
    }

    public function test_domain_event_collection_filter(): void
    {
        $e1 = DomainEvent::occur('order.placed', []);
        $e2 = DomainEvent::occur('order.paid', []);
        $e3 = DomainEvent::occur('order.placed', []);

        $collection = new DomainEventCollection([$e1, $e2, $e3]);
        $placed = $collection->filter(fn (DomainEvent $e): bool => $e->eventType === 'order.placed');

        $this->assertCount(2, $placed);
    }

    public function test_domain_event_collection_some_none(): void
    {
        $e1 = DomainEvent::occur('order.placed', []);
        $e2 = DomainEvent::occur('order.paid', []);

        $collection = new DomainEventCollection([$e1, $e2]);

        $this->assertTrue($collection->some(fn (DomainEvent $e): bool => $e->eventType === 'order.placed'));
        $this->assertFalse($collection->some(fn (DomainEvent $e): bool => $e->eventType === 'order.shipped'));
        $this->assertTrue($collection->none(fn (DomainEvent $e): bool => $e->eventType === 'order.shipped'));
    }

    public function test_domain_event_collection_reduce(): void
    {
        $e1 = DomainEvent::occur('test', ['amount' => 10]);
        $e2 = DomainEvent::occur('test', ['amount' => 20]);

        $collection = new DomainEventCollection([$e1, $e2]);
        $total = $collection->reduce(
            fn (int $sum, DomainEvent $e): int => $sum + ($e->payload['amount'] ?? 0),
            0,
        );

        $this->assertSame(30, $total);
    }

    public function test_domain_event_collection_json_serializable(): void
    {
        $e1 = DomainEvent::occur('test.event', ['key' => 'val']);
        $collection = new DomainEventCollection([$e1]);

        $json = json_encode($collection);
        $this->assertNotFalse($json);

        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
        $this->assertSame('test.event', $decoded[0]['event_type']);
    }

    public function test_domain_event_collection_rejects_non_list(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DomainEventCollection(['key' => DomainEvent::occur('test', [])]);
    }

    public function test_domain_event_collection_rejects_non_event(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DomainEventCollection(['not-an-event']);
    }

    public function test_domain_event_collection_merge(): void
    {
        $e1 = DomainEvent::occur('a', []);
        $e2 = DomainEvent::occur('b', []);
        $e3 = DomainEvent::occur('c', []);

        $c1 = new DomainEventCollection([$e1]);
        $c2 = new DomainEventCollection([$e2, $e3]);

        $merged = $c1->merge($c2);
        $this->assertCount(3, $merged);
    }

    public function test_domain_event_collection_array_round_trip(): void
    {
        $e1 = DomainEvent::occur('order.placed', ['customer_id' => 'c1']);
        $e2 = DomainEvent::occur('order.paid', ['total' => 99.99]);

        $original = new DomainEventCollection([$e1, $e2]);
        $restored = DomainEventCollection::fromArray($original->toArray());

        $this->assertCount(2, $restored);
        $this->assertSame('order.placed', $restored->first()?->eventType);
    }

    public function test_domain_event_collection_json_round_trip(): void
    {
        $e1 = DomainEvent::occur('order.placed', []);
        $collection = new DomainEventCollection([$e1]);

        $json = $collection->toJson();
        $restored = DomainEventCollection::fromJson($json);

        $this->assertCount(1, $restored);
    }

    // -----------------------------------------------------------------------
    // Snapshot
    // -----------------------------------------------------------------------

    public function test_snapshot_create_and_serialize(): void
    {
        $snapshot = Snapshot::create(
            'TestOrder',
            '550e8400-e29b-41d4-a716-446655440000',
            50,
            ['status' => 'paid', 'total' => 99.99],
        );

        $array = $snapshot->toArray();
        $this->assertSame('TestOrder', $array['aggregate_type']);
        $this->assertSame(50, $array['version']);
        $this->assertSame('paid', $array['state']['status']);
    }

    public function test_snapshot_array_round_trip(): void
    {
        $original = Snapshot::create('TestOrder', 'uuid-123', 10, ['status' => 'pending']);
        $restored = Snapshot::fromArray($original->toArray());

        $this->assertTrue($original->equals($restored));
    }

    public function test_snapshot_json_round_trip(): void
    {
        $original = Snapshot::create('TestOrder', 'uuid-123', 10, ['status' => 'pending']);
        $json = $original->toJson();
        $restored = Snapshot::fromJson($json);

        $this->assertTrue($original->equals($restored));
    }

    public function test_snapshot_rejects_invalid_data(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Snapshot::fromArray(['invalid' => 'data']);
    }

    // -----------------------------------------------------------------------
    // In-Memory Snapshot Store
    // -----------------------------------------------------------------------

    public function test_snapshot_store_save_and_load(): void
    {
        $store = new InMemorySnapshotStore();
        $snapshot = Snapshot::create('TestOrder', 'uuid-1', 10, ['status' => 'paid']);

        $store->save($snapshot);

        $loaded = $store->load('TestOrder', 'uuid-1');
        $this->assertNotNull($loaded);
        $this->assertTrue($snapshot->equals($loaded));
    }

    public function test_snapshot_store_has_and_delete(): void
    {
        $store = new InMemorySnapshotStore();
        $snapshot = Snapshot::create('TestOrder', 'uuid-1', 5, []);

        $this->assertFalse($store->has('TestOrder', 'uuid-1'));

        $store->save($snapshot);
        $this->assertTrue($store->has('TestOrder', 'uuid-1'));

        $store->delete('TestOrder', 'uuid-1');
        $this->assertFalse($store->has('TestOrder', 'uuid-1'));
    }

    public function test_snapshot_store_stats(): void
    {
        $store = new InMemorySnapshotStore();
        $store->save(Snapshot::create('Order', '1', 1, []));
        $store->save(Snapshot::create('Order', '2', 2, []));
        $store->save(Snapshot::create('User', '1', 1, []));

        $stats = $store->stats();
        $this->assertSame(3, $stats['total']);
        $this->assertSame(2, $stats['by_type']['Order']);
        $this->assertSame(1, $stats['by_type']['User']);
    }

    public function test_snapshot_store_purge_by_type(): void
    {
        $store = new InMemorySnapshotStore();
        $store->save(Snapshot::create('Order', '1', 1, []));
        $store->save(Snapshot::create('User', '1', 1, []));

        $removed = $store->purge('Order');
        $this->assertSame(1, $removed);
        $this->assertSame(1, $store->count());
    }

    public function test_snapshot_store_purge_all(): void
    {
        $store = new InMemorySnapshotStore();
        $store->save(Snapshot::create('A', '1', 1, []));
        $store->save(Snapshot::create('B', '2', 2, []));

        $removed = $store->purge();
        $this->assertSame(2, $removed);
        $this->assertSame(0, $store->count());
    }

    public function test_snapshot_store_delete_older_than(): void
    {
        $store = new InMemorySnapshotStore();
        $store->save(Snapshot::create('Order', '1', 5, []));

        $store->deleteOlderThan('Order', '1', 10);
        $this->assertNull($store->load('Order', '1'));

        $store->save(Snapshot::create('Order', '1', 15, []));
        $store->deleteOlderThan('Order', '1', 10);
        $this->assertNotNull($store->load('Order', '1'));
    }

    // -----------------------------------------------------------------------
    // Domain Exceptions
    // -----------------------------------------------------------------------

    public function test_invalid_argument_exception_error_code(): void
    {
        $e = InvalidArgumentDomainException::because('Quantity must be positive.', code: 'NEGATIVE_QTY');

        $this->assertSame('NEGATIVE_QTY', $e->errorCode());
        $this->assertSame(422, $e->httpStatus());
    }

    public function test_invalid_argument_exception_default_code(): void
    {
        $e = InvalidArgumentDomainException::because('Bad value.');

        $this->assertSame('INVALID_ARGUMENT', $e->errorCode());
    }

    public function test_invalid_state_exception_error_code(): void
    {
        $e = InvalidStateDomainException::because('Order must be pending.', code: 'WRONG_STATUS');

        $this->assertSame('WRONG_STATUS', $e->errorCode());
        $this->assertSame(422, $e->httpStatus());
    }

    public function test_not_found_exception(): void
    {
        $e = NotFoundDomainException::because('Order not found.');

        $this->assertSame('NOT_FOUND', $e->errorCode());
        $this->assertSame(404, $e->httpStatus());
    }

    public function test_conflict_exception(): void
    {
        $e = ConflictDomainException::because('Resource conflict.');

        $this->assertSame('CONFLICT', $e->errorCode());
        $this->assertSame(409, $e->httpStatus());
    }

    public function test_optimistic_lock_exception(): void
    {
        $e = OptimisticLockException::for('uuid-123', expectedVersion: 5, actualVersion: 3);

        $this->assertSame('OPTIMISTIC_LOCK', $e->errorCode());
        $this->assertSame(409, $e->httpStatus());
        $this->assertStringContainsString('uuid-123', $e->getMessage());
    }

    public function test_domain_exception_to_error_array_rfc9457(): void
    {
        $e = InvalidStateDomainException::because('Cannot pay cancelled order.');
        $error = $e->toErrorArray();

        $this->assertArrayHasKey('title', $error);
        $this->assertArrayHasKey('detail', $error);
        $this->assertArrayHasKey('code', $error);
        $this->assertArrayHasKey('status', $error);
        $this->assertSame('INVALID_STATE', $error['code']);
        $this->assertSame(422, $error['status']);
    }

    public function test_domain_exception_json_serializable(): void
    {
        $e = NotFoundDomainException::because('User not found.');
        $json = json_encode($e);

        $this->assertNotFalse($json);
        $decoded = json_decode($json, true);
        $this->assertSame('NOT_FOUND', $decoded['code']);
        $this->assertSame(404, $decoded['status']);
    }

    public function test_domain_exception_array_round_trip(): void
    {
        $original = InvalidStateDomainException::because('Test error.', code: 'TEST_CODE');
        $array = $original->toArray();

        $restored = DomainException::fromArray($array, InvalidStateDomainException::class);
        $this->assertSame('Test error.', $restored->getMessage());
        $this->assertSame('TEST_CODE', $restored->errorCode());
    }

    public function test_domain_exception_json_round_trip(): void
    {
        $original = NotFoundDomainException::because('Not found.');
        $json = $original->toJson();

        $restored = DomainException::fromJson($json, NotFoundDomainException::class);
        $this->assertSame('Not found.', $restored->getMessage());
    }

    public function test_domain_exception_from_error_array_format(): void
    {
        $e = InvalidStateDomainException::because('State error.', code: 'CUSTOM');
        $errorArray = $e->toErrorArray();

        $restored = DomainException::fromArray($errorArray, InvalidStateDomainException::class);
        $this->assertSame('State error.', $restored->getMessage());
        $this->assertSame('CUSTOM', $restored->errorCode());
    }

    // -----------------------------------------------------------------------
    // Unit of Work
    // -----------------------------------------------------------------------

    public function test_unit_of_work_run_commits(): void
    {
        $uow = new InMemoryUnitOfWork;
        $committed = false;

        $uow->setPersistenceCallback(function (array $c, array $d) use (&$committed): void {
            $committed = true;
        });

        $uow->run(function () use ($uow): void {
            $this->assertTrue($uow->isActive());
        });

        $this->assertTrue($committed);
        $this->assertFalse($uow->isActive());
    }

    public function test_unit_of_work_run_rollback_on_exception(): void
    {
        $uow = new InMemoryUnitOfWork;
        $persisted = false;

        $uow->setPersistenceCallback(function () use (&$persisted): void {
            $persisted = true;
        });

        try {
            $uow->run(function (): never {
                throw new \RuntimeException('Fail');
            });
        } catch (\RuntimeException $e) {
            // Expected
        }

        $this->assertFalse($persisted);
    }

    public function test_unit_of_work_track_aggregate(): void
    {
        $uow = new InMemoryUnitOfWork;
        $order = TestOrder::place('customer-1');

        $uow->begin();
        $uow->track($order);
        $this->assertTrue($uow->isTracking($order));
        $uow->commit();

        $committed = $uow->getCommitted();
        $this->assertCount(1, $committed);
    }

    public function test_unit_of_work_mark_for_deletion(): void
    {
        $uow = new InMemoryUnitOfWork;
        $order = TestOrder::place('customer-1');

        $uow->begin();
        $uow->track($order);
        $uow->markForDeletion($order);
        $uow->commit();

        $this->assertCount(1, $uow->getDeleted());
        $this->assertCount(0, $uow->getCommitted());
    }

    public function test_unit_of_work_nested_run(): void
    {
        $uow = new InMemoryUnitOfWork;
        $log = [];

        $uow->run(function () use ($uow, &$log): void {
            $log[] = 'outer begin';
            $uow->run(function () use (&$log): void {
                $log[] = 'inner begin';
                $log[] = 'inner end';
            });
            $log[] = 'outer end';
        });

        $this->assertSame(['outer begin', 'inner begin', 'inner end', 'outer end'], $log);
    }

    public function test_unit_of_work_clear(): void
    {
        $uow = new InMemoryUnitOfWork;
        $order = TestOrder::place('customer-1');

        $uow->begin();
        $uow->track($order);
        $uow->commit();

        $this->assertNotEmpty($uow->getCommitted());

        $uow->clear();
        $this->assertEmpty($uow->getCommitted());
        $this->assertFalse($uow->isActive());
    }

    public function test_unit_of_work_to_array(): void
    {
        $uow = new InMemoryUnitOfWork;
        $uow->begin();

        $array = $uow->toArray();
        $this->assertTrue($array['is_active']);
        $this->assertSame(1, $array['nesting_depth']);
    }

    public function test_unit_of_work_json_serializable(): void
    {
        $uow = new InMemoryUnitOfWork;
        $uow->begin();

        $json = json_encode($uow);
        $this->assertNotFalse($json);

        $decoded = json_decode($json, true);
        $this->assertTrue($decoded['is_active']);
    }

    public function test_unit_of_work_queue_event(): void
    {
        $uow = new InMemoryUnitOfWork;
        $dispatched = [];

        $uow->setEventDispatcher(function (DomainEvent $e) use (&$dispatched): void {
            $dispatched[] = $e->eventType;
        });

        $uow->run(function () use ($uow): void {
            $uow->queueEvent(DomainEvent::occur('test.queued', []));
        });

        $this->assertSame(['test.queued'], $dispatched);
    }

    // -----------------------------------------------------------------------
    // Guards Trait (tested via TestOrderWithGuards)
    // -----------------------------------------------------------------------

    public function test_guard_assert_not_empty_string(): void
    {
        $this->expectException(InvalidArgumentDomainException::class);
        $order = new class (AggregateRootId::generate()) extends AggregateRoot {
            use \ZeroBoiler\Domain\Concerns\Guards;

            public function testGuard(): void
            {
                $this->assertNotEmptyString('', 'name');
            }
        };
        $order->testGuard();
    }

    public function test_guard_assert_positive_integer(): void
    {
        $this->expectException(InvalidArgumentDomainException::class);
        $order = new class (AggregateRootId::generate()) extends AggregateRoot {
            use \ZeroBoiler\Domain\Concerns\Guards;

            public function testGuard(): void
            {
                $this->assertPositiveInteger(-1, 'quantity');
            }
        };
        $order->testGuard();
    }

    public function test_guard_assert_range(): void
    {
        $this->expectException(InvalidArgumentDomainException::class);
        $order = new class (AggregateRootId::generate()) extends AggregateRoot {
            use \ZeroBoiler\Domain\Concerns\Guards;

            public function testGuard(): void
            {
                $this->assertRange(150, 0, 100, 'percentage');
            }
        };
        $order->testGuard();
    }

    public function test_guard_assert_in(): void
    {
        $this->expectException(InvalidArgumentDomainException::class);
        $order = new class (AggregateRootId::generate()) extends AggregateRoot {
            use \ZeroBoiler\Domain\Concerns\Guards;

            public function testGuard(): void
            {
                $this->assertIn(['USD', 'EUR'], 'TRY', 'currency');
            }
        };
        $order->testGuard();
    }

    public function test_guard_assert_state_is(): void
    {
        $this->expectException(InvalidStateDomainException::class);
        $order = new class (AggregateRootId::generate()) extends AggregateRoot {
            use \ZeroBoiler\Domain\Concerns\Guards;

            public function testGuard(): void
            {
                $this->assertStateIs('pending', 'shipped', 'pay');
            }
        };
        $order->testGuard();
    }

    // -----------------------------------------------------------------------
    // Contract Interface Compliance
    // -----------------------------------------------------------------------

    public function test_identifier_contract_implementation(): void
    {
        $classes = [
            AggregateRootId::generate()::class,
            TestOrderId::generate()::class,
            TestProductId::generate()::class,
            StringIdentifier::from('test')::class,
            IntegerIdentifier::from(1)::class,
        ];

        foreach ($classes as $class) {
            $this->assertTrue(
                is_subclass_of($class, IdentifierContract::class),
                "{$class} does not implement IdentifierContract",
            );
        }
    }

    public function test_snapshot_store_contract(): void
    {
        $store = new InMemorySnapshotStore;
        $this->assertInstanceOf(SnapshotStore::class, $store);
    }

    public function test_unit_of_work_contract(): void
    {
        $uow = new InMemoryUnitOfWork;
        $this->assertInstanceOf(UnitOfWorkContract::class, $uow);
        $this->assertInstanceOf(\JsonSerializable::class, $uow);
    }
}
