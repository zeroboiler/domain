<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace Tests\Domain;

use PHPUnit\Framework\TestCase;
use ZeroBoiler\Domain\AggregateRoot;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\DomainEventCollection;
use ZeroBoiler\Domain\Entity;
use ZeroBoiler\Domain\Exceptions\{
    ConflictDomainException,
    DomainException,
    InvalidArgumentDomainException,
    InvalidStateDomainException,
    NotFoundDomainException,
    OptimisticLockException,
    AggregateNotFoundException,
    InvalidAggregateRootException,
};
use ZeroBoiler\Domain\Identifiers\{
    IntegerIdentifier,
    StringIdentifier,
    UlidIdentifier,
    UuidIdentifier,
};
use ZeroBoiler\Domain\InMemoryUnitOfWork;
use ZeroBoiler\Domain\Snapshots\{
    InMemorySnapshotStore,
    Snapshot,
    SnapshotPolicy,
    SnapshottingRepository,
};
use ZeroBoiler\Domain\ValueObject;
use ZeroBoiler\Events\Domain\DomainEvent;

/**
 * Comprehensive domain → response bridge tests.
 *
 * Verifies that domain entities, value objects, identifiers, and exceptions
 * serialize correctly for API response consumption. This test file
 * validates the data contracts that the response package depends on.
 *
 * Covers:
 * - AggregateRoot serialization (toArray, jsonSerialize, id, version)
 * - AggregateRootId serialization (toString, jsonSerialize, fromArray round-trip)
 * - Entity serialization (toArray, id, equals)
 * - ValueObject equality and serialization
 * - All Identifier types (UUID, ULID, String, Integer) serde
 * - DomainException hierarchy (errorCode, toErrorArray, jsonSerialize)
 * - DomainEventCollection serialization
 * - Snapshot round-trip serialization
 * - UnitOfWork event queuing and pending event inspection
 * - Cross-package data format compatibility
 *
 * @see \ZeroBoiler\Domain\AggregateRoot
 * @see \ZeroBoiler\Domain\AggregateRootId
 * @see \ZeroBoiler\Domain\Entity
 * @see \ZeroBoiler\Domain\ValueObject
 */
final class DomainResponseBridgeComprehensiveTest extends TestCase
{
    // ─── AggregateRootId Serialization ────────────────────────────────────

    public function test_aggregate_root_id_json_serializes_to_string(): void
    {
        $id = AggregateRootId::generate();
        $encoded = json_encode($id);

        self::assertIsString($encoded);
        self::assertNotEmpty($encoded);
        self::assertEquals($id->toString(), $encoded);
    }

    public function test_aggregate_root_id_from_string_round_trip(): void
    {
        $id = AggregateRootId::generate();
        $restored = AggregateRootId::fromString($id->toString());

        self::assertTrue($id->equals($restored));
        self::assertSame($id->toString(), $restored->toString());
    }

    public function test_aggregate_root_id_from_array_round_trip(): void
    {
        $id = AggregateRootId::generate();
        $array = $id->toArray();
        $restored = AggregateRootId::fromArray($array);

        self::assertTrue($id->equals($restored));
        self::assertSame($id->toString(), $restored->toString());
    }

    public function test_aggregate_root_id_from_array_accepts_id_key(): void
    {
        $id = AggregateRootId::generate();
        $restored = AggregateRootId::fromArray(['id' => $id->toString()]);

        self::assertTrue($id->equals($restored));
    }

    public function test_aggregate_root_id_to_array_has_uuid_key(): void
    {
        $id = AggregateRootId::generate();
        $array = $id->toArray();

        self::assertArrayHasKey('uuid', $array);
        self::assertSame($id->toString(), $array['uuid']);
    }

    // ─── Entity Serialization ─────────────────────────────────────────────

    public function test_entity_to_array_has_id_and_type(): void
    {
        $entity = new TestBridgeEntity('entity-123');

        $array = $entity->toArray();

        self::assertArrayHasKey('id', $array);
        self::assertSame('entity-123', $array['id']);
        self::assertArrayHasKey('type', $array);
        self::assertSame('TestBridgeEntity', $array['type']);
    }

    public function test_entity_equality_same_id(): void
    {
        $a = new TestBridgeEntity('id-1');
        $b = new TestBridgeEntity('id-1');

        self::assertTrue($a->equals($b));
    }

    public function test_entity_inequality_different_id(): void
    {
        $a = new TestBridgeEntity('id-1');
        $b = new TestBridgeEntity('id-2');

        self::assertFalse($a->equals($b));
    }

    public function test_entity_inequality_different_type(): void
    {
        $a = new TestBridgeEntity('id-1');
        $b = new AnotherBridgeEntity('id-1');

        self::assertFalse($a->equals($b));
    }

    // ─── ValueObject Serialization ────────────────────────────────────────

    public function test_value_object_equality_same_values(): void
    {
        $a = TestBridgeValueObject::fromArray(['name' => 'John', 'age' => 30]);
        $b = TestBridgeValueObject::fromArray(['name' => 'John', 'age' => 30]);

        self::assertTrue($a->equals($b));
    }

    public function test_value_object_inequality_different_values(): void
    {
        $a = TestBridgeValueObject::fromArray(['name' => 'John', 'age' => 30]);
        $b = TestBridgeValueObject::fromArray(['name' => 'Jane', 'age' => 30]);

        self::assertFalse($a->equals($b));
    }

    public function test_value_object_to_array_round_trip(): void
    {
        $original = TestBridgeValueObject::fromArray(['name' => 'John', 'age' => 30]);
        $restored = TestBridgeValueObject::fromArray($original->toArray());

        self::assertTrue($original->equals($restored));
    }

    // ─── Identifier Serialization ────────────────────────────────────────

    public function test_uuid_identifier_json_serialize(): void
    {
        $id = TestUuidId::generate();
        $encoded = json_encode($id);

        self::assertIsString($encoded);
        self::assertSame($id->toString(), $encoded);
    }

    public function test_uuid_identifier_from_array_round_trip(): void
    {
        $id = TestUuidId::generate();
        $restored = TestUuidId::fromArray($id->toArray());

        self::assertTrue($id->equals($restored));
    }

    public function test_ulid_identifier_json_serialize(): void
    {
        $id = TestUlidId::generate();
        $encoded = json_encode($id);

        self::assertIsString($encoded);
        self::assertSame($id->toString(), $encoded);
    }

    public function test_ulid_identifier_from_array_round_trip(): void
    {
        $id = TestUlidId::generate();
        $restored = TestUlidId::fromArray($id->toArray());

        self::assertTrue($id->equals($restored));
    }

    public function test_string_identifier_json_serialize(): void
    {
        $id = StringIdentifier::from('my-slug');
        $encoded = json_encode($id);

        self::assertSame('my-slug', $encoded);
    }

    public function test_string_identifier_from_array_round_trip(): void
    {
        $id = StringIdentifier::from('my-slug');
        $restored = StringIdentifier::fromArray($id->toArray());

        self::assertTrue($id->equals($restored));
    }

    public function test_integer_identifier_json_serialize(): void
    {
        $id = IntegerIdentifier::from(42);
        $encoded = json_encode($id);

        self::assertSame(42, $encoded);
    }

    public function test_integer_identifier_from_array_round_trip(): void
    {
        $id = IntegerIdentifier::from(42);
        $restored = IntegerIdentifier::fromArray($id->toArray());

        self::assertTrue($id->equals($restored));
    }

    public function test_integer_identifier_from_string_array(): void
    {
        $id = IntegerIdentifier::fromArray(['id' => '99']);

        self::assertSame(99, $id->toInt());
    }

    // ─── DomainException Serialization ────────────────────────────────────

    public function test_domain_exception_to_error_array_structure(): void
    {
        $e = InvalidStateDomainException::because('Order must be pending.');
        $array = $e->toErrorArray();

        self::assertArrayHasKey('title', $array);
        self::assertArrayHasKey('detail', $array);
        self::assertArrayHasKey('code', $array);
        self::assertSame('INVALID_STATE', $array['code']);
        self::assertSame('Order must be pending.', $array['detail']);
        self::assertSame('InvalidStateDomainException', $array['title']);
    }

    public function test_domain_exception_json_serialize_matches_to_error_array(): void
    {
        $e = InvalidStateDomainException::because('Test.');
        $json = json_encode($e);
        $decoded = json_decode($json, true);

        self::assertSame($e->toErrorArray(), $decoded);
    }

    public function test_all_domain_exceptions_have_unique_error_codes(): void
    {
        $exceptions = [
            InvalidStateDomainException::because(''),
            InvalidArgumentDomainException::because(''),
            NotFoundDomainException::because(''),
            ConflictDomainException::because(''),
            OptimisticLockException::for('id', 1, 2),
            AggregateNotFoundException::for('Type', 'id'),
        ];

        $codes = array_map(fn (DomainException $e): string => $e->errorCode(), $exceptions);

        self::assertCount(count($codes), array_unique($codes), 'All domain exceptions must have unique error codes.');
    }

    public function test_custom_error_code_override(): void
    {
        $e = InvalidStateDomainException::because('Test.', code: 'CUSTOM_CODE');
        self::assertSame('CUSTOM_CODE', $e->errorCode());
    }

    public function test_optimistic_lock_exception_message(): void
    {
        $e = OptimisticLockException::for('uuid-123', expectedVersion: 5, actualVersion: 3);

        self::assertStringContainsString('uuid-123', $e->getMessage());
        self::assertStringContainsString('5', $e->getMessage());
        self::assertStringContainsString('3', $e->getMessage());
    }

    public function test_not_found_for_aggregate_message(): void
    {
        $e = NotFoundDomainException::forAggregate('Order', 'uuid-456');

        self::assertStringContainsString('Order', $e->getMessage());
        self::assertStringContainsString('uuid-456', $e->getMessage());
    }

    public function test_domain_exception_to_array_includes_debug_info(): void
    {
        $e = InvalidStateDomainException::because('Test.');
        $array = $e->toArray();

        self::assertArrayHasKey('error_code', $array);
        self::assertArrayHasKey('message', $array);
        self::assertArrayHasKey('file', $array);
        self::assertArrayHasKey('line', $array);
    }

    // ─── DomainEventCollection Serialization ──────────────────────────────

    public function test_event_collection_json_serialize(): void
    {
        $e1 = DomainEvent::occur('order.placed', ['id' => '1']);
        $e2 = DomainEvent::occur('order.paid', ['id' => '1', 'amount' => 100]);

        $collection = new DomainEventCollection([$e1, $e2]);
        $json = json_encode($collection);
        $decoded = json_decode($json, true);

        self::assertIsArray($decoded);
        self::assertCount(2, $decoded);
    }

    public function test_event_collection_from_array_round_trip(): void
    {
        $e1 = DomainEvent::occur('user.created', ['name' => 'John']);
        $e2 = DomainEvent::occur('user.updated', ['name' => 'Jane']);

        $original = new DomainEventCollection([$e1, $e2]);
        $restored = DomainEventCollection::fromArray($original->toArray());

        self::assertSame($original->count(), $restored->count());
        self::assertSame($original->first()->eventType, $restored->first()->eventType);
        self::assertSame($original->last()->eventType, $restored->last()->eventType);
    }

    public function test_event_collection_operations(): void
    {
        $e1 = DomainEvent::occur('a', []);
        $e2 = DomainEvent::occur('b', []);
        $e3 = DomainEvent::occur('c', []);

        $collection = new DomainEventCollection([$e1, $e2, $e3]);

        self::assertSame(3, $collection->count());
        self::assertFalse($collection->isEmpty());
        self::assertSame($e1, $collection->first());
        self::assertSame($e3, $collection->last());
        self::assertSame($e2, $collection->get(1));

        $filtered = $collection->filter(fn (DomainEvent $e): bool => $e->eventType !== 'b');
        self::assertSame(2, $filtered->count());

        $merged = $collection->merge(new DomainEventCollection([DomainEvent::occur('d', [])]));
        self::assertSame(4, $merged->count());
    }

    // ─── Snapshot Serialization ────────────────────────────────────────────

    public function test_snapshot_to_array_round_trip(): void
    {
        $snapshot = Snapshot::create(
            aggregateType: 'Order',
            aggregateId: 'uuid-123',
            version: 50,
            state: ['status' => 'paid', 'total' => 99.99],
        );

        $array = $snapshot->toArray();
        $restored = Snapshot::fromArray($array);

        self::assertSame($snapshot->aggregateType, $restored->aggregateType);
        self::assertSame($snapshot->aggregateId, $restored->aggregateId);
        self::assertSame($snapshot->version, $restored->version);
        self::assertSame($snapshot->state, $restored->state);
    }

    public function test_snapshot_json_serialize(): void
    {
        $snapshot = Snapshot::create('Order', 'uuid-1', 1, ['status' => 'pending']);
        $json = json_encode($snapshot);
        $decoded = json_decode($json, true);

        self::assertArrayHasKey('aggregate_type', $decoded);
        self::assertArrayHasKey('aggregate_id', $decoded);
        self::assertArrayHasKey('version', $decoded);
        self::assertArrayHasKey('state', $decoded);
        self::assertArrayHasKey('created_at', $decoded);
    }

    public function test_snapshot_equality(): void
    {
        $a = Snapshot::create('Order', 'uuid-1', 5, ['status' => 'paid']);
        $b = Snapshot::create('Order', 'uuid-1', 5, ['status' => 'paid']);

        self::assertTrue($a->equals($b));
    }

    public function test_snapshot_inequality_different_state(): void
    {
        $a = Snapshot::create('Order', 'uuid-1', 5, ['status' => 'paid']);
        $b = Snapshot::create('Order', 'uuid-1', 5, ['status' => 'pending']);

        self::assertFalse($a->equals($b));
    }

    // ─── UnitOfWork Event Inspection ────────────────────────────────────────

    public function test_unit_of_work_pending_events_peek(): void
    {
        $uow = new InMemoryUnitOfWork();
        $uow->begin();
        $uow->queueEvent(DomainEvent::occur('test.event', ['key' => 'value']));

        self::assertTrue($uow->hasPendingEvents());
        self::assertSame(1, $uow->getPendingEventCount());

        $peeked = $uow->getPendingEvents();
        self::assertInstanceOf(DomainEventCollection::class, $peeked);
        self::assertSame(1, $peeked->count());
        self::assertSame('test.event', $peeked->first()->eventType);

        // Events still pending (peek is non-destructive)
        self::assertTrue($uow->hasPendingEvents());
    }

    public function test_unit_of_work_clear_resets_state(): void
    {
        $uow = new InMemoryUnitOfWork();
        $uow->begin();
        $uow->queueEvent(DomainEvent::occur('test', []));
        $uow->clear();

        self::assertFalse($uow->isActive());
        self::assertFalse($uow->hasPendingEvents());
    }

    // ─── AggregateRoot Serialization via toArray ───────────────────────────

    public function test_test_aggregate_to_array(): void
    {
        $id = AggregateRootId::generate();
        $aggregate = TestBridgeAggregate::create($id);

        $array = $aggregate->toArray();

        self::assertArrayHasKey('id', $array);
        self::assertArrayHasKey('version', $array);
        self::assertArrayHasKey('type', $array);
        self::assertSame($id->toString(), $array['id']);
        self::assertSame(0, $array['version']);
        self::assertSame('TestBridgeAggregate', $array['type']);
    }

    public function test_test_aggregate_pull_domain_events(): void
    {
        $id = AggregateRootId::generate();
        $aggregate = TestBridgeAggregate::create($id);

        $events = $aggregate->pullDomainEvents();

        self::assertInstanceOf(DomainEventCollection::class, $events);
        self::assertSame(1, $events->count());
        self::assertSame('test_aggregate.created', $events->first()->eventType);
    }

    public function test_test_aggregate_peek_domain_events_non_destructive(): void
    {
        $id = AggregateRootId::generate();
        $aggregate = TestBridgeAggregate::create($id);

        $peeked = $aggregate->peekDomainEvents();
        self::assertTrue($aggregate->hasUncommittedEvents());
        self::assertSame(1, $peeked->count());

        // Pull should still return events after peek
        $pulled = $aggregate->pullDomainEvents();
        self::assertSame(1, $pulled->count());
        self::assertFalse($aggregate->hasUncommittedEvents());
    }

    // ─── InMemorySnapshotStore Operations ─────────────────────────────────

    public function test_snapshot_store_operations(): void
    {
        $store = new InMemorySnapshotStore();
        $snapshot = Snapshot::create('Order', 'uuid-1', 1, ['status' => 'pending']);

        self::assertFalse($store->has('Order', 'uuid-1'));
        self::assertSame(0, $store->count());

        $store->save($snapshot);

        self::assertTrue($store->has('Order', 'uuid-1'));
        self::assertSame(1, $store->count());

        $loaded = $store->load('Order', 'uuid-1');
        self::assertNotNull($loaded);
        self::assertTrue($snapshot->equals($loaded));

        $stats = $store->stats();
        self::assertSame(1, $stats['total']);
        self::assertArrayHasKey('Order', $stats['by_type']);
        self::assertSame(1, $stats['by_type']['Order']);

        $removed = $store->purge('Order');
        self::assertSame(1, $removed);
        self::assertFalse($store->has('Order', 'uuid-1'));
    }
}

// ─── Test Fixtures ─────────────────────────────────────────────────────────

/** @internal Test entity for bridge tests */
final class TestBridgeEntity extends Entity
{
    public function __construct(
        int|string|\Stringable $id,
        public readonly string $name = 'test',
    ) {
        parent::__construct($id);
    }
}

/** @internal Another test entity for equality tests */
final class AnotherBridgeEntity extends Entity
{
    public function __construct(int|string|\Stringable $id)
    {
        parent::__construct($id);
    }
}

/** @internal Test value object for bridge tests */
final class TestBridgeValueObject extends ValueObject
{
    public function __construct(
        public readonly string $name,
        public readonly int $age,
    ) {}

    public static function fromArray(array $data): static
    {
        return new static(name: $data['name'], age: $data['age']);
    }

    public function toArray(): array
    {
        return ['name' => $this->name, 'age' => $this->age];
    }
}

/** @internal Test UUID identifier */
final class TestUuidId extends UuidIdentifier {}

/** @internal Test ULID identifier */
final class TestUlidId extends UlidIdentifier {}

/** @internal Test aggregate for bridge tests */
final class TestBridgeAggregate extends AggregateRoot
{
    public string $status = 'pending';

    public function __construct(AggregateRootId $id)
    {
        parent::__construct($id);
    }

    public static function create(AggregateRootId $id): self
    {
        $aggregate = new self($id);
        $aggregate->apply(DomainEvent::occur('test_aggregate.created', [
            'id' => $id->toString(),
        ]));

        return $aggregate;
    }

    protected function applyTestAggregateCreated(DomainEvent $event): void
    {
        $this->status = 'pending';
    }
}
