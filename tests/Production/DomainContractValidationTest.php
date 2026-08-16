<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Tests\Production;

use PHPUnit\Framework\TestCase;
use ZeroBoiler\Domain\AggregateRoot;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\Contracts\AggregateRoot as AggregateRootContract;
use ZeroBoiler\Domain\Contracts\Entity as EntityContract;
use ZeroBoiler\Domain\Contracts\Identifier as IdentifierContract;
use ZeroBoiler\Domain\Contracts\Repository as RepositoryContract;
use ZeroBoiler\Domain\Contracts\UnitOfWork as UnitOfWorkContract;
use ZeroBoiler\Domain\DomainEventCollection;
use ZeroBoiler\Domain\Entity;
use ZeroBoiler\Domain\Exceptions\{
    AggregateNotFoundException,
    ConflictDomainException,
    DomainException,
    InvalidAggregateRootException,
    InvalidArgumentDomainException,
    InvalidStateDomainException,
    InvalidStateException,
    NotFoundDomainException,
    OptimisticLockException,
};
use ZeroBoiler\Domain\Identifiers\{IntegerIdentifier, StringIdentifier, UlidIdentifier, UuidIdentifier};
use ZeroBoiler\Domain\InMemoryUnitOfWork;
use ZeroBoiler\Domain\Snapshots\{InMemorySnapshotStore, Snapshot, SnapshottingRepository};
use ZeroBoiler\ValueObjects\ValueObject as BaseValueObject;
use ZeroBoiler\Events\Domain\DomainEvent;

/**
 * Comprehensive production contract validation for the domain package.
 *
 * Validates strict types, immutability, domain invariants, serialization,
 * equality, interface compliance, and PHP 8.5 features.
 *
 * @covers \\ZeroBoiler\\Domain\\Entity
 * @covers \\ZeroBoiler\\Domain\\AggregateRoot
 * @covers \\ZeroBoiler\\Domain\\AggregateRootId
 * @covers \\ZeroBoiler\\Domain\\DomainEventCollection
 * @covers \\ZeroBoiler\\Domain\\Identifiers\\UuidIdentifier
 * @covers \\ZeroBoiler\\Domain\\Identifiers\\UlidIdentifier
 * @covers \\ZeroBoiler\\Domain\\Identifiers\\StringIdentifier
 * @covers \\ZeroBoiler\\Domain\\Identifiers\\IntegerIdentifier
 * @covers \\ZeroBoiler\\Domain\\Exceptions\\DomainException
 * @covers \\ZeroBoiler\\Domain\\InMemoryUnitOfWork
 * @covers \\ZeroBoiler\\Domain\\Snapshots\\Snapshot
 * @covers \\ZeroBoiler\\Domain\\Snapshots\\InMemorySnapshotStore
 *
 * @since 1.71.0
 */
final class DomainContractValidationTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Helper: Test Aggregate Root
    // ═══════════════════════════════════════════════════════════════

    private function createTestOrder(?AggregateRootId $id = null): TestOrder
    {
        return TestOrder::create($id ?? AggregateRootId::generate());
    }

    private function createTestItem(string $id = '1'): TestItem
    {
        return new TestItem($id, 'product-1', 3, 9.99);
    }

    // ═══════════════════════════════════════════════════════════════
    // AggregateRootId — Immutability & Serialization
    // ═══════════════════════════════════════════════════════════════

    public function test_aggregate_root_id_is_final_readonly(): void
    {
        $reflection = new \ReflectionClass(AggregateRootId::class);
        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
    }

    public function test_aggregate_root_id_generate_creates_valid_uuid(): void
    {
        $id = AggregateRootId::generate();
        $this->assertTrue(AggregateRootId::isValid($id->toString()));
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $id->toString(),
        );
    }

    public function test_aggregate_root_id_equality(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $a = AggregateRootId::fromString($uuid);
        $b = AggregateRootId::fromString($uuid);
        $c = AggregateRootId::generate();

        $this->assertTrue($a->equals($b));
        $this->assertFalse($a->equals($c));
    }

    public function test_aggregate_root_id_to_array_round_trip(): void
    {
        $id = AggregateRootId::generate();
        $array = $id->toArray();
        $restored = AggregateRootId::fromArray($array);
        $this->assertTrue($id->equals($restored));
    }

    public function test_aggregate_root_id_from_json_round_trip(): void
    {
        $id = AggregateRootId::generate();
        $json = $id->toJson();
        $restored = AggregateRootId::fromJson($json);
        $this->assertTrue($id->equals($restored));
    }

    public function test_aggregate_root_id_json_serialize_returns_string(): void
    {
        $id = AggregateRootId::generate();
        $encoded = json_encode($id);
        $this->assertIsString($encoded);
        $this->assertSame($id->toString(), $encoded);
    }

    public function test_aggregate_root_id_to_string(): void
    {
        $id = AggregateRootId::fromString('550e8400-e29b-41d4-a716-446655440000');
        $this->assertSame('550e8400-e29b-41d4-a716-446655440000', $id->toString());
    }

    public function test_aggregate_root_id_php_serialize_round_trip(): void
    {
        $id = AggregateRootId::generate();
        $serialized = serialize($id);
        $restored = unserialize($serialized);
        $this->assertTrue($id->equals($restored));
    }

    public function test_aggregate_root_id_from_string_invalid_throws(): void
    {
        $this->expectException(\Ramsey\Uuid\Exception\InvalidUuidStringException::class);
        AggregateRootId::fromString('not-a-uuid');
    }

    // ═══════════════════════════════════════════════════════════════
    // UuidIdentifier — Abstract Readonly
    // ═══════════════════════════════════════════════════════════════

    public function test_uuid_identifier_is_abstract_readonly(): void
    {
        $reflection = new \ReflectionClass(UuidIdentifier::class);
        $this->assertTrue($reflection->isAbstract());
        $this->assertTrue($reflection->isReadOnly());
    }

    public function test_uuid_identifier_subclass_round_trip(): void
    {
        $id = TestOrderId::generate();
        $array = $id->toArray();
        $restored = TestOrderId::fromArray($array);
        $this->assertTrue($id->equals($restored));
    }

    public function test_uuid_identifier_json_round_trip(): void
    {
        $id = TestOrderId::generate();
        $json = $id->toJson();
        $restored = TestOrderId::fromJson($json);
        $this->assertTrue($id->equals($restored));
    }

    public function test_uuid_identifier_equality_same_class(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $a = TestOrderId::fromString($uuid);
        $b = TestProductId::fromString($uuid);
        $this->assertFalse($a->equals($b)); // Different concrete classes
    }

    public function test_uuid_identifier_php_serialize_round_trip(): void
    {
        $id = TestOrderId::generate();
        $serialized = serialize($id);
        $restored = unserialize($serialized);
        $this->assertTrue($id->equals($restored));
    }

    // ═══════════════════════════════════════════════════════════════
    // UlidIdentifier — Abstract Readonly
    // ═══════════════════════════════════════════════════════════════

    public function test_ulid_identifier_is_abstract_readonly(): void
    {
        $reflection = new \ReflectionClass(UlidIdentifier::class);
        $this->assertTrue($reflection->isAbstract());
        $this->assertTrue($reflection->isReadOnly());
    }

    public function test_ulid_identifier_generate_is_valid(): void
    {
        $id = TestProductId::generate();
        $this->assertTrue(UlidIdentifier::isValid($id->toString()));
        $this->assertSame(26, strlen($id->toString()));
    }

    public function test_ulid_identifier_round_trip(): void
    {
        $id = TestProductId::generate();
        $restored = TestProductId::fromArray($id->toArray());
        $this->assertTrue($id->equals($restored));
    }

    public function test_ulid_identifier_json_round_trip(): void
    {
        $id = TestProductId::generate();
        $restored = TestProductId::fromJson($id->toJson());
        $this->assertTrue($id->equals($restored));
    }

    public function test_ulid_identifier_php_serialize_round_trip(): void
    {
        $id = TestProductId::generate();
        $restored = unserialize(serialize($id));
        $this->assertTrue($id->equals($restored));
    }

    // ═══════════════════════════════════════════════════════════════
    // StringIdentifier — Final Readonly
    // ═══════════════════════════════════════════════════════════════

    public function test_string_identifier_is_final_readonly(): void
    {
        $reflection = new \ReflectionClass(StringIdentifier::class);
        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
    }

    public function test_string_identifier_rejects_empty(): void
    {
        $this->expectException(\ValueError::class);
        StringIdentifier::from('');
    }

    public function test_string_identifier_equality(): void
    {
        $a = StringIdentifier::from('slug-a');
        $b = StringIdentifier::from('slug-a');
        $this->assertTrue($a->equals($b));
    }

    public function test_string_identifier_round_trip(): void
    {
        $id = StringIdentifier::from('my-slug');
        $restored = StringIdentifier::fromArray($id->toArray());
        $this->assertTrue($id->equals($restored));
    }

    public function test_string_identifier_json_round_trip(): void
    {
        $id = StringIdentifier::from('my-slug');
        $restored = StringIdentifier::fromJson($id->toJson());
        $this->assertTrue($id->equals($restored));
    }

    public function test_string_identifier_php_serialize_round_trip(): void
    {
        $id = StringIdentifier::from('my-slug');
        $restored = unserialize(serialize($id));
        $this->assertTrue($id->equals($restored));
    }

    public function test_string_identifier_json_serialize_returns_string(): void
    {
        $id = StringIdentifier::from('slug');
        $this->assertSame('slug', json_encode($id));
    }

    // ═══════════════════════════════════════════════════════════════
    // IntegerIdentifier — Final Readonly
    // ═══════════════════════════════════════════════════════════════

    public function test_integer_identifier_is_final_readonly(): void
    {
        $reflection = new \ReflectionClass(IntegerIdentifier::class);
        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
    }

    public function test_integer_identifier_to_int(): void
    {
        $id = IntegerIdentifier::from(42);
        $this->assertSame(42, $id->toInt());
    }

    public function test_integer_identifier_from_string(): void
    {
        $id = IntegerIdentifier::fromString('42');
        $this->assertSame(42, $id->toInt());
    }

    public function test_integer_identifier_round_trip(): void
    {
        $id = IntegerIdentifier::from(42);
        $restored = IntegerIdentifier::fromArray($id->toArray());
        $this->assertTrue($id->equals($restored));
    }

    public function test_integer_identifier_json_round_trip(): void
    {
        $id = IntegerIdentifier::from(42);
        $restored = IntegerIdentifier::fromJson($id->toJson());
        $this->assertTrue($id->equals($restored));
    }

    public function test_integer_identifier_php_serialize_round_trip(): void
    {
        $id = IntegerIdentifier::from(42);
        $restored = unserialize(serialize($id));
        $this->assertTrue($id->equals($restored));
    }

    public function test_integer_identifier_json_serialize_returns_int(): void
    {
        $id = IntegerIdentifier::from(42);
        $this->assertSame(42, json_encode($id));
    }

    // ═══════════════════════════════════════════════════════════════
    // Entity — Immutability & Interface Compliance
    // ═══════════════════════════════════════════════════════════════

    public function test_entity_implements_entity_contract(): void
    {
        $item = $this->createTestItem();
        $this->assertInstanceOf(EntityContract::class, $item);
        $this->assertInstanceOf(\JsonSerializable::class, $item);
    }

    public function test_entity_id_property_is_readonly(): void
    {
        $property = new \ReflectionProperty(Entity::class, 'id');
        $this->assertTrue($property->isReadOnly());
        $this->assertTrue($property->isPublic());
    }

    public function test_entity_to_array(): void
    {
        $item = $this->createTestItem('42');
        $array = $item->toArray();
        $this->assertSame('42', $array['id']);
        $this->assertSame('TestItem', $array['type']);
        $this->assertSame('product-1', $array['productId']);
        $this->assertSame(3, $array['quantity']);
    }

    public function test_entity_from_array_round_trip(): void
    {
        $original = $this->createTestItem('42');
        $array = $original->toArray();
        $restored = TestItem::fromArray($array);
        $this->assertTrue($original->equals($restored));
    }

    public function test_entity_from_json_round_trip(): void
    {
        $original = $this->createTestItem('42');
        $json = $original->toJson();
        $restored = TestItem::fromJson($json);
        $this->assertTrue($original->equals($restored));
    }

    public function test_entity_equality(): void
    {
        $a = $this->createTestItem('1');
        $b = $this->createTestItem('1');
        $c = $this->createTestItem('2');
        $this->assertTrue($a->equals($b));
        $this->assertFalse($a->equals($c));
    }

    public function test_entity_json_serialize(): void
    {
        $item = $this->createTestItem('1');
        $encoded = json_encode($item);
        $decoded = json_decode($encoded, true);
        $this->assertSame('1', $decoded['id']);
        $this->assertSame('TestItem', $decoded['type']);
    }

    public function test_entity_with_string_id(): void
    {
        $item = new TestStringEntity('abc');
        $this->assertSame('abc', $item->id());
    }

    public function test_entity_with_int_id(): void
    {
        $item = new TestIntEntity(42);
        $this->assertSame('42', $item->id());
    }

    // ═══════════════════════════════════════════════════════════════
    // AggregateRoot — Interface Compliance & Events
    // ═══════════════════════════════════════════════════════════════

    public function test_aggregate_root_implements_contract(): void
    {
        $order = $this->createTestOrder();
        $this->assertInstanceOf(AggregateRootContract::class, $order);
        $this->assertInstanceOf(EntityContract::class, $order);
    }

    public function test_aggregate_root_constructor_is_protected(): void
    {
        $constructor = new \ReflectionMethod(AggregateRoot::class, '__construct');
        $this->assertTrue($constructor->isProtected());
    }

    public function test_aggregate_root_id_and_version(): void
    {
        $id = AggregateRootId::generate();
        $order = TestOrder::create($id);
        $this->assertSame($id->toString(), $order->id());
        $this->assertSame(1, $order->version());
    }

    public function test_aggregate_root_pull_domain_events(): void
    {
        $order = $this->createTestOrder();
        $events = $order->pullDomainEvents();
        $this->assertInstanceOf(DomainEventCollection::class, $events);
        $this->assertSame(1, $events->count());
        $this->assertSame('order.placed', $events->first()->eventType);
    }

    public function test_aggregate_root_pull_clears_events(): void
    {
        $order = $this->createTestOrder();
        $order->pullDomainEvents();
        $this->assertFalse($order->hasUncommittedEvents());
    }

    public function test_aggregate_root_peek_does_not_consume(): void
    {
        $order = $this->createTestOrder();
        $peeked = $order->peekDomainEvents();
        $this->assertSame(1, $peeked->count());
        $this->assertTrue($order->hasUncommittedEvents()); // Still true
    }

    public function test_aggregate_root_to_array(): void
    {
        $order = $this->createTestOrder();
        $array = $order->toArray();
        $this->assertArrayHasKey('id', $array);
        $this->assertArrayHasKey('version', $array);
        $this->assertArrayHasKey('type', $array);
        $this->assertSame('TestOrder', $array['type']);
    }

    public function test_aggregate_root_to_json(): void
    {
        $order = $this->createTestOrder();
        $json = $order->toJson();
        $decoded = json_decode($json, true);
        $this->assertArrayHasKey('id', $decoded);
        $this->assertArrayHasKey('version', $decoded);
    }

    public function test_aggregate_root_version_increments(): void
    {
        $order = $this->createTestOrder();
        $order->pullDomainEvents();
        $initialVersion = $order->version();

        $order->doApply(DomainEvent::occur('order.item_added', ['item' => 'x']));
        $this->assertSame($initialVersion + 1, $order->version());
    }

    public function test_aggregate_root_set_version_infrastructure(): void
    {
        $order = $this->createTestOrder();
        $order->setVersion(10);
        $this->assertSame(10, $order->version());
    }

    // ═══════════════════════════════════════════════════════════════
    // DomainEventCollection — Type Safety & Operations
    // ═══════════════════════════════════════════════════════════════

    public function test_event_collection_is_final_readonly(): void
    {
        $reflection = new \ReflectionClass(DomainEventCollection::class);
        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
    }

    public function test_event_collection_rejects_non_list(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DomainEventCollection(['key' => DomainEvent::occur('test', [])]);
    }

    public function test_event_collection_rejects_non_events(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DomainEventCollection(['not an event']);
    }

    public function test_event_collection_empty(): void
    {
        $col = new DomainEventCollection;
        $this->assertTrue($col->isEmpty());
        $this->assertCount(0, $col);
    }

    public function test_event_collection_count_and_iteration(): void
    {
        $events = [
            DomainEvent::occur('a', []),
            DomainEvent::occur('b', []),
            DomainEvent::occur('c', []),
        ];
        $col = new DomainEventCollection($events);
        $this->assertCount(3, $col);
        $this->assertFalse($col->isEmpty());
        $types = [];
        foreach ($col as $event) {
            $types[] = $event->eventType;
        }
        $this->assertSame(['a', 'b', 'c'], $types);
    }

    public function test_event_collection_filter(): void
    {
        $col = new DomainEventCollection([
            DomainEvent::occur('a', []),
            DomainEvent::occur('b', []),
        ]);
        $filtered = $col->filter(fn (DomainEvent $e): bool => $e->eventType === 'a');
        $this->assertCount(1, $filtered);
    }

    public function test_event_collection_map(): void
    {
        $col = new DomainEventCollection([
            DomainEvent::occur('a', []),
            DomainEvent::occur('b', []),
        ]);
        $types = $col->map(fn (DomainEvent $e): string => $e->eventType);
        $this->assertSame(['a', 'b'], $types);
    }

    public function test_event_collection_merge(): void
    {
        $a = new DomainEventCollection([DomainEvent::occur('a', [])]);
        $b = new DomainEventCollection([DomainEvent::occur('b', [])]);
        $merged = $a->merge($b);
        $this->assertCount(2, $merged);
    }

    public function test_event_collection_first_last_get(): void
    {
        $col = new DomainEventCollection([
            DomainEvent::occur('a', []),
            DomainEvent::occur('b', []),
        ]);
        $this->assertSame('a', $col->first()->eventType);
        $this->assertSame('b', $col->last()->eventType);
        $this->assertSame('b', $col->get(1)->eventType);
        $this->assertNull($col->get(2));
        $this->assertNull($col->first(fn (DomainEvent $e): bool => false));
    }

    public function test_event_collection_some_none_find(): void
    {
        $col = new DomainEventCollection([
            DomainEvent::occur('a', []),
            DomainEvent::occur('b', []),
        ]);
        $this->assertTrue($col->some(fn (DomainEvent $e): bool => $e->eventType === 'a'));
        $this->assertFalse($col->none(fn (DomainEvent $e): bool => $e->eventType === 'a'));
        $this->assertSame('a', $col->find(fn (DomainEvent $e): bool => $e->eventType === 'a')->eventType);
    }

    public function test_event_collection_has_type_count_by_types(): void
    {
        $col = new DomainEventCollection([
            DomainEvent::occur('order.placed', []),
            DomainEvent::occur('order.paid', []),
            DomainEvent::occur('order.placed', []),
        ]);
        $this->assertTrue($col->hasType('order.placed'));
        $this->assertFalse($col->hasType('order.shipped'));
        $this->assertSame(2, $col->countBy(fn (DomainEvent $e): bool => $e->eventType === 'order.placed'));
        $this->assertSame(['order.placed', 'order.paid'], $col->types());
    }

    public function test_event_collection_each_reduce(): void
    {
        $col = new DomainEventCollection([
            DomainEvent::occur('a', ['amount' => 10]),
            DomainEvent::occur('b', ['amount' => 20]),
        ]);
        $sum = $col->reduce(
            fn (int $carry, DomainEvent $e): int => $carry + ($e->payload['amount'] ?? 0),
            0,
        );
        $this->assertSame(30, $sum);

        $counted = 0;
        $col->each(function (DomainEvent $e, int $i) use (&$counted): void {
            $counted++;
        });
        $this->assertSame(2, $counted);
    }

    public function test_event_collection_to_array_from_array_round_trip(): void
    {
        $original = new DomainEventCollection([
            DomainEvent::occur('a', ['x' => 1]),
        ]);
        $array = $original->toArray();
        $restored = DomainEventCollection::fromArray($array);
        $this->assertCount(1, $restored);
        $this->assertSame('a', $restored->first()->eventType);
    }

    public function test_event_collection_json_serialize(): void
    {
        $col = new DomainEventCollection([
            DomainEvent::occur('a', ['x' => 1]),
        ]);
        $encoded = json_encode($col);
        $decoded = json_decode($encoded, true);
        $this->assertIsArray($decoded);
        $this->assertCount(1, $decoded);
    }

    // ═══════════════════════════════════════════════════════════════
    // Domain Exceptions — Hierarchy & RFC 9457
    // ═══════════════════════════════════════════════════════════════

    public function test_invalid_state_domain_exception_error_code(): void
    {
        $e = InvalidStateDomainException::because('test');
        $this->assertSame('INVALID_STATE', $e->errorCode());
        $this->assertSame(422, $e->httpStatus());
    }

    public function test_invalid_argument_domain_exception_error_code(): void
    {
        $e = InvalidArgumentDomainException::because('test');
        $this->assertSame('INVALID_ARGUMENT', $e->errorCode());
        $this->assertSame(422, $e->httpStatus());
    }

    public function test_not_found_domain_exception_error_code(): void
    {
        $e = NotFoundDomainException::forId('order-123');
        $this->assertSame('NOT_FOUND', $e->errorCode());
        $this->assertSame(404, $e->httpStatus());
    }

    public function test_conflict_domain_exception_error_code(): void
    {
        $e = ConflictDomainException::because('test');
        $this->assertSame('CONFLICT', $e->errorCode());
        $this->assertSame(409, $e->httpStatus());
    }

    public function test_optimistic_lock_exception_error_code(): void
    {
        $e = OptimisticLockException::for('id', expectedVersion: 5, actualVersion: 3);
        $this->assertSame('OPTIMISTIC_LOCK', $e->errorCode());
        $this->assertSame(409, $e->httpStatus());
    }

    public function test_aggregate_not_found_exception_error_code(): void
    {
        $e = AggregateNotFoundException::for('Order', 'id-123');
        $this->assertSame('AGGREGATE_NOT_FOUND', $e->errorCode());
        $this->assertSame(404, $e->httpStatus());
    }

    public function test_invalid_aggregate_root_exception_error_code(): void
    {
        $e = InvalidAggregateRootException::notAnAggregate(new \stdClass);
        $this->assertSame('INVALID_AGGREGATE_ROOT', $e->errorCode());
        $this->assertSame(500, $e->httpStatus());
    }

    public function test_invalid_state_exception_infrastructure_error_code(): void
    {
        $e = InvalidStateException::because('config broken');
        $this->assertSame('INVALID_STATE_SYSTEM', $e->errorCode());
        $this->assertSame(500, $e->httpStatus());
    }

    public function test_domain_exception_to_error_array_rfc9457(): void
    {
        $e = InvalidStateDomainException::because('Order must be pending.');
        $error = $e->toErrorArray();
        $this->assertArrayHasKey('title', $error);
        $this->assertArrayHasKey('detail', $error);
        $this->assertArrayHasKey('code', $error);
        $this->assertArrayHasKey('status', $error);
        $this->assertSame('INVALID_STATE', $error['code']);
        $this->assertSame(422, $error['status']);
    }

    public function test_domain_exception_json_serialize(): void
    {
        $e = NotFoundDomainException::forId('order-123');
        $encoded = json_encode($e);
        $decoded = json_decode($encoded, true);
        $this->assertSame('NOT_FOUND', $decoded['code']);
        $this->assertSame(404, $decoded['status']);
    }

    public function test_domain_exception_from_array_round_trip(): void
    {
        $original = InvalidStateDomainException::because('test reason');
        $array = $original->toArray();
        $restored = DomainException::fromArray($array, InvalidStateDomainException::class);
        $this->assertSame('test reason', $restored->getMessage());
    }

    public function test_domain_exception_from_json_round_trip(): void
    {
        $original = NotFoundDomainException::forId('order-123');
        $json = $original->toJson();
        $restored = DomainException::fromJson($json, NotFoundDomainException::class);
        $this->assertSame($original->getMessage(), $restored->getMessage());
    }

    // ═══════════════════════════════════════════════════════════════
    // Snapshot — Immutability & Round-Trip
    // ═══════════════════════════════════════════════════════════════

    public function test_snapshot_is_final_readonly(): void
    {
        $reflection = new \ReflectionClass(Snapshot::class);
        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
    }

    public function test_snapshot_create_and_to_array(): void
    {
        $snapshot = Snapshot::create('Order', 'id-1', 5, ['status' => 'paid']);
        $array = $snapshot->toArray();
        $this->assertSame('Order', $array['aggregate_type']);
        $this->assertSame('id-1', $array['aggregate_id']);
        $this->assertSame(5, $array['version']);
        $this->assertSame(['status' => 'paid'], $array['state']);
        $this->assertArrayHasKey('created_at', $array);
    }

    public function test_snapshot_from_array_round_trip(): void
    {
        $original = Snapshot::create('Order', 'id-1', 5, ['status' => 'paid']);
        $restored = Snapshot::fromArray($original->toArray());
        $this->assertTrue($original->equals($restored));
    }

    public function test_snapshot_from_json_round_trip(): void
    {
        $original = Snapshot::create('Order', 'id-1', 5, ['status' => 'paid']);
        $restored = Snapshot::fromJson($original->toJson());
        $this->assertTrue($original->equals($restored));
    }

    public function test_snapshot_equality(): void
    {
        $a = Snapshot::create('Order', 'id-1', 5, ['x' => 1]);
        $b = Snapshot::create('Order', 'id-1', 5, ['x' => 1]);
        $c = Snapshot::create('Order', 'id-1', 6, ['x' => 1]);
        $this->assertTrue($a->equals($b));
        $this->assertFalse($a->equals($c));
    }

    public function test_snapshot_php_serialize_round_trip(): void
    {
        $original = Snapshot::create('Order', 'id-1', 5, ['status' => 'paid']);
        $restored = unserialize(serialize($original));
        $this->assertTrue($original->equals($restored));
    }

    // ═══════════════════════════════════════════════════════════════
    // InMemorySnapshotStore — CRUD Operations
    // ═══════════════════════════════════════════════════════════════

    public function test_snapshot_store_save_load_has_delete(): void
    {
        $store = new InMemorySnapshotStore;
        $snapshot = Snapshot::create('Order', 'id-1', 5, ['status' => 'paid']);

        $this->assertFalse($store->has('Order', 'id-1'));
        $store->save($snapshot);
        $this->assertTrue($store->has('Order', 'id-1'));

        $loaded = $store->load('Order', 'id-1');
        $this->assertNotNull($loaded);
        $this->assertTrue($snapshot->equals($loaded));

        $store->delete('Order', 'id-1');
        $this->assertFalse($store->has('Order', 'id-1'));
        $this->assertNull($store->load('Order', 'id-1'));
    }

    public function test_snapshot_store_count_stats_purge(): void
    {
        $store = new InMemorySnapshotStore;
        $store->save(Snapshot::create('Order', '1', 1, []));
        $store->save(Snapshot::create('Order', '2', 2, []));
        $store->save(Snapshot::create('Product', '1', 1, []));

        $this->assertSame(3, $store->count());
        $this->assertSame(2, $store->count('Order'));

        $stats = $store->stats();
        $this->assertSame(3, $stats['total']);
        $this->assertSame(2, $stats['by_type']['Order']);

        $removed = $store->purge('Order');
        $this->assertSame(2, $removed);
        $this->assertSame(1, $store->count());
    }

    public function test_snapshot_store_delete_older_than(): void
    {
        $store = new InMemorySnapshotStore;
        $store->save(Snapshot::create('Order', '1', 5, []));
        $store->deleteOlderThan('Order', '1', 10);
        $this->assertNull($store->load('Order', '1')); // Deleted: version 5 < 10
    }

    // ═══════════════════════════════════════════════════════════════
    // InMemoryUnitOfWork — Transactional Semantics
    // ═══════════════════════════════════════════════════════════════

    public function test_uow_implements_contract(): void
    {
        $uow = new InMemoryUnitOfWork;
        $this->assertInstanceOf(UnitOfWorkContract::class, $uow);
    }

    public function test_uow_run_auto_commit_rollback(): void
    {
        $uow = new InMemoryUnitOfWork;

        // Successful run commits
        $result = $uow->run(fn (): string => 'success');
        $this->assertSame('success', $result);
        $this->assertFalse($uow->isActive());

        // Failed run rolls back
        $uow2 = new InMemoryUnitOfWork;
        try {
            $uow2->run(function (): void {
                throw new \RuntimeException('fail');
            });
        } catch (\RuntimeException) {
            // Expected
        }
        $this->assertFalse($uow2->isActive());
        $this->assertSame([], $uow2->getCommitted());
    }

    public function test_uow_manual_begin_track_commit(): void
    {
        $uow = new InMemoryUnitOfWork;
        $order = $this->createTestOrder();

        $uow->begin();
        $uow->track($order);
        $this->assertTrue($uow->isTracking($order));
        $uow->commit();

        $committed = $uow->getCommitted();
        $this->assertArrayHasKey($order->id(), $committed);
    }

    public function test_uow_rollback_restores_state(): void
    {
        $uow = new InMemoryUnitOfWork;
        $order = $this->createTestOrder();
        $initialVersion = $order->version();

        $uow->begin();
        $uow->track($order);
        $order->doApply(DomainEvent::occur('order.item_added', []));

        $uow->rollback();

        // Version restored to pre-transaction state
        $this->assertSame($initialVersion, $order->version());
    }

    public function test_uow_clear_resets_all(): void
    {
        $uow = new InMemoryUnitOfWork;
        $uow->begin();
        $uow->track($this->createTestOrder());
        $uow->clear();

        $this->assertFalse($uow->isActive());
        $this->assertSame([], $uow->getCommitted());
    }

    public function test_uow_pending_events(): void
    {
        $uow = new InMemoryUnitOfWork;
        $order = $this->createTestOrder();

        $uow->begin();
        $uow->track($order);
        $this->assertTrue($uow->hasPendingEvents());
        $this->assertSame(1, $uow->getPendingEventCount());

        $events = $uow->getPendingEvents();
        $this->assertInstanceOf(DomainEventCollection::class, $events);
    }

    public function test_uow_mark_for_deletion(): void
    {
        $uow = new InMemoryUnitOfWork;
        $order = $this->createTestOrder();

        $uow->begin();
        $uow->markForDeletion($order);
        $uow->commit();

        $deleted = $uow->getDeleted();
        $this->assertArrayHasKey($order->id(), $deleted);
    }

    public function test_uow_queue_event(): void
    {
        $uow = new InMemoryUnitOfWork;
        $event = DomainEvent::occur('side.effect', []);

        $uow->begin();
        $uow->queueEvent($event);
        $this->assertTrue($uow->hasPendingEvents());

        $uow->commit();
        $this->assertFalse($uow->hasPendingEvents());
    }

    public function test_uow_nested_run_savepoints(): void
    {
        $uow = new InMemoryUnitOfWork;
        $result = $uow->run(function () use ($uow): string {
            return $uow->run(fn (): string => 'inner');
        });
        $this->assertSame('inner', $result);
    }

    // ═══════════════════════════════════════════════════════════════
    // Interface Contracts — Method Signatures
    // ═══════════════════════════════════════════════════════════════

    public function test_entity_contract_methods_exist(): void
    {
        $methods = ['id', 'equals', 'toArray', 'fromArray', 'fromJson', 'toJson', 'hasUncommittedEvents'];
        foreach ($methods as $method) {
            $this->assertTrue(
                method_exists(EntityContract::class, $method),
                "EntityContract::{$method}() must exist",
            );
        }
    }

    public function test_aggregate_root_contract_methods_exist(): void
    {
        $methods = ['version', 'incrementVersion', 'pullDomainEvents', 'clearDomainEvents',
                   'hasUncommittedEvents', 'peekDomainEvents', 'toJson'];
        foreach ($methods as $method) {
            $this->assertTrue(
                method_exists(AggregateRootContract::class, $method),
                "AggregateRootContract::{$method}() must exist",
            );
        }
    }

    public function test_identifier_contract_methods_exist(): void
    {
        $methods = ['fromString', 'toString', 'equals', 'toArray', 'fromArray', 'fromJson'];
        foreach ($methods as $method) {
            $this->assertTrue(
                method_exists(IdentifierContract::class, $method),
                "IdentifierContract::{$method}() must exist",
            );
        }
    }

    public function test_identifier_contract_extends_stringable_and_json(): void
    {
        $this->assertTrue(
            (new \ReflectionClass(IdentifierContract::class))->implementsInterface(\Stringable::class),
        );
        $this->assertTrue(
            (new \ReflectionClass(IdentifierContract::class))->implementsInterface(\JsonSerializable::class),
        );
    }

    public function test_unit_of_work_contract_methods_exist(): void
    {
        $methods = ['begin', 'commit', 'rollback', 'run', 'isActive', 'track', 'isTracking',
                   'markForDeletion', 'getCommitted', 'getDeleted', 'hasPendingEvents',
                   'getPendingEventCount', 'getPendingEvents', 'clear'];
        foreach ($methods as $method) {
            $this->assertTrue(
                method_exists(UnitOfWorkContract::class, $method),
                "UnitOfWorkContract::{$method}() must exist",
            );
        }
    }
}

// ═══════════════════════════════════════════════════════════════
// Test Doubles
// ═══════════════════════════════════════════════════════════════

class TestOrder extends AggregateRoot
{
    use ZeroBoiler\Domain\Concerns\EventSourced;

    public string $status = 'pending';

    public static function create(?AggregateRootId $id = null): self
    {
        $id = $id ?? AggregateRootId::generate();
        $order = new self($id);
        $order->apply(DomainEvent::occur('order.placed', ['id' => $id->toString()]));

        return $order;
    }

    public function doApply(DomainEvent $event): void
    {
        $this->apply($event);
    }
}

class TestItem extends Entity
{
    public function __construct(
        int|string|\Stringable $id,
        public readonly string $productId,
        public readonly int $quantity,
        public readonly float $price,
    ) {
        parent::__construct($id);
    }
}

class TestStringEntity extends Entity {}

class TestIntEntity extends Entity {}

final readonly class TestOrderId extends UuidIdentifier {}

final readonly class TestProductId extends UlidIdentifier {}
