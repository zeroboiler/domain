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
use ZeroBoiler\Domain\Snapshots\{InMemorySnapshotStore, Snapshot, SnapshotPolicy, SnapshottingRepository, SnapshotStore};
use ZeroBoiler\ValueObjects\ValueObject as BaseValueObject;
use ZeroBoiler\Events\Domain\DomainEvent;

/**
 * Production readiness tests for domain entities, identifiers, and exceptions.
 *
 * Validates: strict types, immutability, domain invariants, serialization,
 * equality, and cross-package contract compliance.
 *
 * @covers \ZeroBoiler\Domain\Entity
 * @covers \ZeroBoiler\Domain\AggregateRoot
 * @covers \ZeroBoiler\Domain\AggregateRootId
 * @covers \ZeroBoiler\Domain\DomainEventCollection
 * @covers \ZeroBoiler\Domain\Identifiers\UuidIdentifier
 * @covers \ZeroBoiler\Domain\Identifiers\UlidIdentifier
 * @covers \ZeroBoiler\Domain\Identifiers\StringIdentifier
 * @covers \ZeroBoiler\Domain\Identifiers\IntegerIdentifier
 * @covers \ZeroBoiler\Domain\Exceptions\DomainException
 * @covers \ZeroBoiler\Domain\Exceptions\InvalidStateDomainException
 * @covers \ZeroBoiler\Domain\Exceptions\InvalidArgumentDomainException
 * @covers \ZeroBoiler\Domain\Exceptions\NotFoundDomainException
 * @covers \ZeroBoiler\Domain\Exceptions\ConflictDomainException
 * @covers \ZeroBoiler\Domain\Exceptions\OptimisticLockException
 * @covers \ZeroBoiler\Domain\Exceptions\AggregateNotFoundException
 * @covers \ZeroBoiler\Domain\Exceptions\InvalidAggregateRootException
 * @covers \ZeroBoiler\Domain\Exceptions\InvalidStateException
 * @covers \ZeroBoiler\Domain\InMemoryUnitOfWork
 * @covers \ZeroBoiler\Domain\Snapshots\Snapshot
 * @covers \ZeroBoiler\Domain\Snapshots\InMemorySnapshotStore
 * @covers \ZeroBoiler\Domain\Snapshots\SnapshottingRepository
 * @covers \ZeroBoiler\Domain\Snapshots\SnapshotPolicy
 *
 * @since 1.71.0
 */
final class DomainEntitiesProductionReadinessTest extends TestCase
{
    // ─── AggregateRootId Tests ───────────────────────────────────────

    public function test_aggregate_root_id_is_immutable(): void
    {
        $id = AggregateRootId::generate();

        $reflection = new \ReflectionClass($id);
        $property = $reflection->getProperty('value');
        $this->assertTrue($property->isReadOnly(), 'AggregateRootId::$value must be readonly');
    }

    public function test_aggregate_root_id_generate_returns_unique(): void
    {
        $id1 = AggregateRootId::generate();
        $id2 = AggregateRootId::generate();

        $this->assertFalse($id1->equals($id2));
    }

    public function test_aggregate_root_id_from_string_round_trip(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $id = AggregateRootId::fromString($uuid);

        $this->assertSame($uuid, $id->toString());
        $this->assertSame($uuid, (string) $id);
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
        $this->assertSame($id->toString(), json_decode($encoded, associative: true));
    }

    public function test_aggregate_root_id_serialize_unserialize_round_trip(): void
    {
        $id = AggregateRootId::generate();
        $serialized = serialize($id);
        $restored = unserialize($serialized);

        $this->assertInstanceOf(AggregateRootId::class, $restored);
        $this->assertTrue($id->equals($restored));
    }

    public function test_aggregate_root_id_is_valid(): void
    {
        $this->assertTrue(AggregateRootId::isValid('550e8400-e29b-41d4-a716-446655440000'));
        $this->assertFalse(AggregateRootId::isValid('not-a-uuid'));
        $this->assertFalse(AggregateRootId::isValid(''));
    }

    // ─── UUID Identifier Tests ────────────────────────────────────────

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
        $this->assertFalse($id->equals(TestProductId::generate()));
    }

    // ─── ULID Identifier Tests ────────────────────────────────────────

    public function test_ulid_identifier_subclass_round_trip(): void
    {
        $id = TestProductId::generate();
        $this->assertTrue(TestProductId::isValid($id->toString()));

        $restored = TestProductId::fromString($id->toString());
        $this->assertTrue($id->equals($restored));
    }

    // ─── String Identifier Tests ─────────────────────────────────────

    public function test_string_identifier_rejects_empty(): void
    {
        $this->expectException(\ValueError::class);
        StringIdentifier::from('');
    }

    public function test_string_identifier_round_trip(): void
    {
        $id = StringIdentifier::from('my-slug');
        $restored = StringIdentifier::fromArray($id->toArray());

        $this->assertTrue($id->equals($restored));
    }

    // ─── Integer Identifier Tests ────────────────────────────────────

    public function test_integer_identifier_round_trip(): void
    {
        $id = IntegerIdentifier::from(42);
        $restored = IntegerIdentifier::fromArray($id->toArray());

        $this->assertTrue($id->equals($restored));
        $this->assertSame(42, $restored->toInt());
        $this->assertSame('42', $restored->toString());
    }

    public function test_integer_identifier_json_serialize_returns_int(): void
    {
        $id = IntegerIdentifier::from(42);
        $this->assertSame(42, $id->jsonSerialize());
    }

    // ─── Entity Tests ────────────────────────────────────────────────

    public function test_entity_with_string_id(): void
    {
        $entity = new TestSimpleEntity('entity-123');

        $this->assertSame('entity-123', $entity->id());
        $this->assertSame('entity-123', $entity->toArray()['id']);
        $this->assertSame('TestSimpleEntity', $entity->toArray()['type']);
    }

    public function test_entity_with_int_id(): void
    {
        $entity = new TestSimpleEntity(42);

        $this->assertSame('42', $entity->id());
    }

    public function test_entity_with_stringable_id(): void
    {
        $id = AggregateRootId::generate();
        $entity = new TestSimpleEntity($id);

        $this->assertSame($id->toString(), $entity->id());
    }

    public function test_entity_equality_same_id(): void
    {
        $entity1 = new TestSimpleEntity('abc');
        $entity2 = new TestSimpleEntity('abc');

        $this->assertTrue($entity1->equals($entity2));
    }

    public function test_entity_inequality_different_id(): void
    {
        $entity1 = new TestSimpleEntity('abc');
        $entity2 = new TestSimpleEntity('def');

        $this->assertFalse($entity1->equals($entity2));
    }

    public function test_entity_inequality_different_type(): void
    {
        $entity = new TestSimpleEntity('abc');
        $other = new AnotherTestEntity('abc');

        $this->assertFalse($entity->equals($other));
    }

    public function test_entity_from_array_round_trip(): void
    {
        $entity = new TestSimpleEntityWithProps('id-1', 'John', 25);
        $array = $entity->toArray();
        $restored = TestSimpleEntityWithProps::fromArray($array);

        $this->assertInstanceOf(TestSimpleEntityWithProps::class, $restored);
        $this->assertTrue($entity->equals($restored));
        $this->assertSame('John', $restored->name);
        $this->assertSame(25, $restored->age);
    }

    public function test_entity_from_json_round_trip(): void
    {
        $entity = new TestSimpleEntityWithProps('id-1', 'John', 25);
        $json = $entity->toJson();
        $restored = TestSimpleEntityWithProps::fromJson($json);

        $this->assertTrue($entity->equals($restored));
    }

    public function test_entity_json_serialize(): void
    {
        $entity = new TestSimpleEntityWithProps('id-1', 'John', 25);
        $encoded = json_encode($entity);
        $data = json_decode($encoded, true);

        $this->assertSame('id-1', $data['id']);
        $this->assertSame('John', $data['name']);
        $this->assertSame(25, $data['age']);
        $this->assertSame('TestSimpleEntityWithProps', $data['type']);
    }

    public function test_entity_id_is_readonly(): void
    {
        $reflection = new \ReflectionClass(Entity::class);
        $property = $reflection->getProperty('id');
        $this->assertTrue($property->isReadOnly());
    }

    // ─── DomainException Hierarchy Tests ─────────────────────────────

    public function test_domain_exception_implements_json_serializable(): void
    {
        $e = InvalidStateDomainException::because('test');
        $this->assertInstanceOf(\JsonSerializable::class, $e);

        $data = json_encode($e);
        $decoded = json_decode($data, true);

        $this->assertSame('InvalidStateDomainException', $decoded['title']);
        $this->assertSame('test', $decoded['detail']);
        $this->assertSame('INVALID_STATE', $decoded['code']);
        $this->assertSame(422, $decoded['status']);
    }

    public function test_domain_exception_error_code(): void
    {
        $this->assertSame('INVALID_STATE', InvalidStateDomainException::because('x')->errorCode());
        $this->assertSame('INVALID_ARGUMENT', InvalidArgumentDomainException::because('x')->errorCode());
        $this->assertSame('NOT_FOUND', NotFoundDomainException::because('x')->errorCode());
        $this->assertSame('CONFLICT', ConflictDomainException::because('x')->errorCode());
        $this->assertSame('OPTIMISTIC_LOCK', OptimisticLockException::for('id', 1, 2)->errorCode());
        $this->assertSame('AGGREGATE_NOT_FOUND', AggregateNotFoundException::for('Order', 'id')->errorCode());
        $this->assertSame('INVALID_AGGREGATE_ROOT', InvalidAggregateRootException::notAnAggregate(new \stdClass)->errorCode());
        $this->assertSame('INVALID_STATE_SYSTEM', InvalidStateException::because('x')->errorCode());
    }

    public function test_domain_exception_http_status(): void
    {
        $this->assertSame(422, InvalidStateDomainException::because('x')->httpStatus());
        $this->assertSame(422, InvalidArgumentDomainException::because('x')->httpStatus());
        $this->assertSame(404, NotFoundDomainException::because('x')->httpStatus());
        $this->assertSame(409, ConflictDomainException::because('x')->httpStatus());
        $this->assertSame(409, OptimisticLockException::for('id', 1, 2)->httpStatus());
        $this->assertSame(404, AggregateNotFoundException::for('Order', 'id')->httpStatus());
        $this->assertSame(500, InvalidAggregateRootException::notAnAggregate(new \stdClass)->httpStatus());
        $this->assertSame(500, InvalidStateException::because('x')->httpStatus());
    }

    public function test_domain_exception_to_error_array_rfc9457(): void
    {
        $e = NotFoundDomainException::forAggregate('Order', '123');
        $error = $e->toErrorArray();

        $this->assertArrayHasKey('title', $error);
        $this->assertArrayHasKey('detail', $error);
        $this->assertArrayHasKey('code', $error);
        $this->assertArrayHasKey('status', $error);
        $this->assertSame('NOT_FOUND', $error['code']);
        $this->assertSame(404, $error['status']);
    }

    public function test_domain_exception_from_array_round_trip(): void
    {
        $original = InvalidStateDomainException::because('Test reason');
        $restored = DomainException::fromArray($original->toArray(), InvalidStateDomainException::class);

        $this->assertSame($original->getMessage(), $restored->getMessage());
        $this->assertSame($original->errorCode(), $restored->errorCode());
    }

    public function test_domain_exception_from_json_round_trip(): void
    {
        $original = InvalidStateDomainException::because('Test reason');
        $restored = DomainException::fromJson($original->toJson(), InvalidStateDomainException::class);

        $this->assertSame($original->getMessage(), $restored->getMessage());
    }

    public function test_not_found_domain_exception_for_aggregate(): void
    {
        $e = NotFoundDomainException::forAggregate('Order', 'uuid-here');
        $this->assertStringContainsString('Order', $e->getMessage());
        $this->assertStringContainsString('uuid-here', $e->getMessage());
    }

    public function test_not_found_domain_exception_for_id(): void
    {
        $e = NotFoundDomainException::forId('order-123');
        $this->assertStringContainsString('order-123', $e->getMessage());
    }

    public function test_optimistic_lock_exception_for(): void
    {
        $e = OptimisticLockException::for('aggregate-id', expectedVersion: 5, actualVersion: 3);
        $this->assertStringContainsString('aggregate-id', $e->getMessage());
        $this->assertStringContainsString('5', $e->getMessage());
        $this->assertStringContainsString('3', $e->getMessage());
    }

    public function test_custom_error_code_override(): void
    {
        $e = InvalidStateDomainException::because('test', 'CUSTOM_CODE');
        $this->assertSame('CUSTOM_CODE', $e->errorCode());
    }

    // ─── DomainEventCollection Tests ─────────────────────────────────

    public function test_event_collection_is_readonly_class(): void
    {
        $reflection = new \ReflectionClass(DomainEventCollection::class);
        $this->assertTrue($reflection->isReadOnly());
        $this->assertTrue($reflection->isFinal());
    }

    public function test_event_collection_validates_sequential_list(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DomainEventCollection(['key' => DomainEvent::occur('test', [])]);
    }

    public function test_event_collection_validates_item_types(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DomainEventCollection(['not-an-event']);
    }

    public function test_event_collection_count_and_is_empty(): void
    {
        $empty = new DomainEventCollection;
        $this->assertTrue($empty->isEmpty());
        $this->assertCount(0, $empty);

        $e1 = DomainEvent::occur('test.1', []);
        $e2 = DomainEvent::occur('test.2', []);
        $collection = new DomainEventCollection([$e1, $e2]);
        $this->assertFalse($collection->isEmpty());
        $this->assertCount(2, $collection);
    }

    public function test_event_collection_filter_returns_new_instance(): void
    {
        $e1 = DomainEvent::occur('test.a', []);
        $e2 = DomainEvent::occur('test.b', []);

        $collection = new DomainEventCollection([$e1, $e2]);
        $filtered = $collection->filter(fn (DomainEvent $e): bool => $e->eventType === 'test.a');

        $this->assertCount(1, $filtered);
        $this->assertCount(2, $collection); // Original unchanged
    }

    public function test_event_collection_map(): void
    {
        $e1 = DomainEvent::occur('test.a', ['value' => 1]);
        $e2 = DomainEvent::occur('test.b', ['value' => 2]);

        $collection = new DomainEventCollection([$e1, $e2]);
        $values = $collection->map(fn (DomainEvent $e): int => $e->payload['value']);

        $this->assertSame([1, 2], $values);
    }

    public function test_event_collection_merge(): void
    {
        $e1 = DomainEvent::occur('test.a', []);
        $e2 = DomainEvent::occur('test.b', []);

        $c1 = new DomainEventCollection([$e1]);
        $c2 = new DomainEventCollection([$e2]);
        $merged = $c1->merge($c2);

        $this->assertCount(2, $merged);
    }

    public function test_event_collection_some_none_find(): void
    {
        $e1 = DomainEvent::occur('test.a', []);
        $e2 = DomainEvent::occur('test.b', []);

        $collection = new DomainEventCollection([$e1, $e2]);
        $this->assertTrue($collection->some(fn (DomainEvent $e): bool => $e->eventType === 'test.a'));
        $this->assertFalse($collection->none(fn (DomainEvent $e): bool => $e->eventType === 'test.a'));
        $this->assertSame($e1, $collection->find(fn (DomainEvent $e): bool => $e->eventType === 'test.a'));
    }

    public function test_event_collection_has_type(): void
    {
        $e1 = DomainEvent::occur('order.placed', []);
        $collection = new DomainEventCollection([$e1]);

        $this->assertTrue($collection->hasType('order.placed'));
        $this->assertFalse($collection->hasType('order.paid'));
    }

    public function test_event_collection_types(): void
    {
        $e1 = DomainEvent::occur('order.placed', []);
        $e2 = DomainEvent::occur('order.item_added', []);
        $e3 = DomainEvent::occur('order.placed', []);

        $collection = new DomainEventCollection([$e1, $e2, $e3]);
        $types = $collection->types();

        $this->assertSame(['order.placed', 'order.item_added'], $types);
    }

    public function test_event_collection_reduce(): void
    {
        $e1 = DomainEvent::occur('test', ['amount' => 10]);
        $e2 = DomainEvent::occur('test', ['amount' => 20]);

        $collection = new DomainEventCollection([$e1, $e2]);
        $total = $collection->reduce(
            fn (int $sum, DomainEvent $e): int => $sum + $e->payload['amount'],
            0,
        );

        $this->assertSame(30, $total);
    }

    public function test_event_collection_first_last_get(): void
    {
        $e1 = DomainEvent::occur('test.a', []);
        $e2 = DomainEvent::occur('test.b', []);
        $e3 = DomainEvent::occur('test.c', []);

        $collection = new DomainEventCollection([$e1, $e2, $e3]);

        $this->assertSame($e1, $collection->first());
        $this->assertSame($e3, $collection->last());
        $this->assertSame($e2, $collection->get(1));
        $this->assertNull($collection->get(10));
    }

    public function test_event_collection_to_array_from_array_round_trip(): void
    {
        $e1 = DomainEvent::occur('test.a', ['key' => 'val1']);
        $e2 = DomainEvent::occur('test.b', ['key' => 'val2']);

        $collection = new DomainEventCollection([$e1, $e2]);
        $array = $collection->toArray();
        $restored = DomainEventCollection::fromArray($array);

        $this->assertCount(2, $restored);
    }

    public function test_event_collection_json_serialize(): void
    {
        $e1 = DomainEvent::occur('test', ['data' => true]);
        $collection = new DomainEventCollection([$e1]);

        $encoded = json_encode($collection);
        $decoded = json_decode($encoded, true);

        $this->assertIsArray($decoded);
        $this->assertCount(1, $decoded);
    }

    // ─── InMemoryUnitOfWork Tests ─────────────────────────────────────

    public function test_unit_of_work_run_auto_commit(): void
    {
        $uow = new InMemoryUnitOfWork;
        $events = [];

        $uow->setEventDispatcher(function (DomainEvent $event) use (&$events): void {
            $events[] = $event->eventType;
        });

        $result = $uow->run(function () use ($uow): string {
            $aggregate = new TestOrderAggregate(AggregateRootId::generate());
            $uow->track($aggregate);
            $aggregate->recordThat(DomainEvent::occur('order.placed', []));

            return 'success';
        });

        $this->assertSame('success', $result);
        $this->assertSame(['order.placed'], $events);
    }

    public function test_unit_of_work_run_auto_rollback_on_exception(): void
    {
        $uow = new InMemoryUnitOfWork;
        $dispatched = false;

        $uow->setEventDispatcher(function (DomainEvent $event) use (&$dispatched): void {
            $dispatched = true;
        });

        try {
            $uow->run(function () use ($uow): void {
                $aggregate = new TestOrderAggregate(AggregateRootId::generate());
                $uow->track($aggregate);
                $aggregate->recordThat(DomainEvent::occur('order.placed', []));
                throw new \RuntimeException('fail');
            });
        } catch (\RuntimeException) {
            // Expected
        }

        $this->assertFalse($dispatched, 'Events should not be dispatched after rollback');
    }

    public function test_unit_of_work_nested_run(): void
    {
        $uow = new InMemoryUnitOfWork;
        $events = [];

        $uow->setEventDispatcher(function (DomainEvent $event) use (&$events): void {
            $events[] = $event->eventType;
        });

        $uow->run(function () use ($uow): void {
            $uow->run(function () use ($uow): void {
                $aggregate = new TestOrderAggregate(AggregateRootId::generate());
                $uow->track($aggregate);
                $aggregate->recordThat(DomainEvent::occur('order.nested', []));
            });
        });

        $this->assertSame(['order.nested'], $events);
    }

    public function test_unit_of_work_manual_transaction(): void
    {
        $uow = new InMemoryUnitOfWork;
        $events = [];

        $uow->setEventDispatcher(function (DomainEvent $event) use (&$events): void {
            $events[] = $event->eventType;
        });

        $aggregate = new TestOrderAggregate(AggregateRootId::generate());

        $uow->begin();
        $uow->track($aggregate);
        $aggregate->recordThat(DomainEvent::occur('order.manual', []));
        $uow->commit();

        $this->assertSame(['order.manual'], $events);
    }

    public function test_unit_of_work_mark_for_deletion(): void
    {
        $uow = new InMemoryUnitOfWork;

        $aggregate1 = new TestOrderAggregate(AggregateRootId::generate());
        $aggregate2 = new TestOrderAggregate(AggregateRootId::generate());

        $uow->begin();
        $uow->track($aggregate1);
        $uow->track($aggregate2);
        $uow->markForDeletion($aggregate1);
        $uow->commit();

        $committed = $uow->getCommitted();
        $deleted = $uow->getDeleted();

        $this->assertCount(1, $committed);
        $this->assertCount(1, $deleted);
    }

    public function test_unit_of_work_is_active_is_tracking(): void
    {
        $uow = new InMemoryUnitOfWork;
        $this->assertFalse($uow->isActive());

        $aggregate = new TestOrderAggregate(AggregateRootId::generate());

        $uow->begin();
        $this->assertTrue($uow->isActive());
        $this->assertFalse($uow->isTracking($aggregate));

        $uow->track($aggregate);
        $this->assertTrue($uow->isTracking($aggregate));

        $uow->commit();
    }

    public function test_unit_of_work_pending_events_peek(): void
    {
        $uow = new InMemoryUnitOfWork;
        $aggregate = new TestOrderAggregate(AggregateRootId::generate());

        $uow->begin();
        $uow->track($aggregate);
        $aggregate->recordThat(DomainEvent::occur('test.event', []));

        $this->assertTrue($uow->hasPendingEvents());
        $this->assertSame(1, $uow->getPendingEventCount());
        $this->assertCount(1, $uow->getPendingEvents());

        $uow->commit();
    }

    // ─── Snapshot Tests ──────────────────────────────────────────────

    public function test_snapshot_is_readonly_class(): void
    {
        $reflection = new \ReflectionClass(Snapshot::class);
        $this->assertTrue($reflection->isReadOnly());
        $this->assertTrue($reflection->isFinal());
    }

    public function test_snapshot_to_array_from_array_round_trip(): void
    {
        $snapshot = Snapshot::create(
            aggregateType: TestOrderAggregate::class,
            aggregateId: 'uuid-here',
            version: 10,
            state: ['status' => 'paid', 'total' => 1000],
        );

        $restored = Snapshot::fromArray($snapshot->toArray());
        $this->assertTrue($snapshot->equals($restored));
    }

    public function test_snapshot_from_json_round_trip(): void
    {
        $snapshot = Snapshot::create(
            aggregateType: TestOrderAggregate::class,
            aggregateId: 'uuid-here',
            version: 10,
            state: ['status' => 'paid'],
        );

        $restored = Snapshot::fromJson($snapshot->toJson());
        $this->assertTrue($snapshot->equals($restored));
    }

    public function test_snapshot_serialize_unserialize_round_trip(): void
    {
        $snapshot = Snapshot::create(
            aggregateType: TestOrderAggregate::class,
            aggregateId: 'uuid-here',
            version: 10,
            state: ['status' => 'paid'],
        );

        $restored = unserialize(serialize($snapshot));
        $this->assertInstanceOf(Snapshot::class, $restored);
        $this->assertTrue($snapshot->equals($restored));
    }

    public function test_snapshot_equality(): void
    {
        $s1 = Snapshot::create('Order', 'id-1', 5, ['a' => 1]);
        $s2 = Snapshot::create('Order', 'id-1', 5, ['a' => 1]);
        $s3 = Snapshot::create('Order', 'id-1', 6, ['a' => 1]);

        $this->assertTrue($s1->equals($s2));
        $this->assertFalse($s1->equals($s3));
    }

    // ─── InMemorySnapshotStore Tests ──────────────────────────────────

    public function test_snapshot_store_save_load_delete(): void
    {
        $store = new InMemorySnapshotStore;
        $snapshot = Snapshot::create('Order', 'uuid-1', 1, []);

        $this->assertFalse($store->has('Order', 'uuid-1'));

        $store->save($snapshot);

        $this->assertTrue($store->has('Order', 'uuid-1'));

        $loaded = $store->load('Order', 'uuid-1');
        $this->assertInstanceOf(Snapshot::class, $loaded);
        $this->assertTrue($snapshot->equals($loaded));

        $store->delete('Order', 'uuid-1');
        $this->assertFalse($store->has('Order', 'uuid-1'));
    }

    public function test_snapshot_store_count_and_stats(): void
    {
        $store = new InMemorySnapshotStore;
        $store->save(Snapshot::create('Order', 'id-1', 1, []));
        $store->save(Snapshot::create('Order', 'id-2', 2, []));
        $store->save(Snapshot::create('Product', 'id-3', 1, []));

        $this->assertSame(3, $store->count());
        $this->assertSame(2, $store->count('Order'));
        $this->assertSame(1, $store->count('Product'));

        $stats = $store->stats();
        $this->assertSame(3, $stats['total']);
        $this->assertSame(2, $stats['by_type']['Order']);
        $this->assertSame(1, $stats['by_type']['Product']);
    }

    public function test_snapshot_store_purge(): void
    {
        $store = new InMemorySnapshotStore;
        $store->save(Snapshot::create('Order', 'id-1', 1, []));
        $store->save(Snapshot::create('Product', 'id-2', 1, []));

        $removed = $store->purge('Order');
        $this->assertSame(1, $removed);
        $this->assertSame(1, $store->count());
        $this->assertSame(1, $store->count('Product'));
    }

    public function test_snapshot_store_delete_older_than(): void
    {
        $store = new InMemorySnapshotStore;
        $store->save(Snapshot::create('Order', 'id-1', 5, []));

        $store->deleteOlderThan('Order', 'id-1', 10);
        $this->assertNull($store->load('Order', 'id-1'));
    }

    // ─── Interface Contract Tests ────────────────────────────────────

    public function test_entity_contract_implemented(): void
    {
        $reflection = new \ReflectionClass(Entity::class);
        $this->assertTrue($reflection->implementsInterface(EntityContract::class));
    }

    public function test_aggregate_root_contract_implemented(): void
    {
        $reflection = new \ReflectionClass(AggregateRoot::class);
        $this->assertTrue($reflection->implementsInterface(AggregateRootContract::class));
    }

    public function test_identifier_contract_implemented(): void
    {
        $this->assertInstanceOf(IdentifierContract::class, UuidIdentifier::generate());
        $this->assertInstanceOf(IdentifierContract::class, UlidIdentifier::generate());
        $this->assertInstanceOf(IdentifierContract::class, StringIdentifier::from('test'));
        $this->assertInstanceOf(IdentifierContract::class, IntegerIdentifier::from(42));
        $this->assertInstanceOf(IdentifierContract::class, AggregateRootId::generate());
    }

    public function test_all_identifiers_are_json_serializable(): void
    {
        $identifiers = [
            UuidIdentifier::generate(),
            UlidIdentifier::generate(),
            StringIdentifier::from('test'),
            IntegerIdentifier::from(42),
            AggregateRootId::generate(),
        ];

        foreach ($identifiers as $id) {
            $this->assertInstanceOf(\JsonSerializable::class, $id, get_class($id) . ' must implement JsonSerializable');
            $encoded = json_encode($id);
            $this->assertNotFalse($encoded, get_class($id) . ' must be JSON-encodable');
        }
    }

    public function test_all_identifiers_are_stringable(): void
    {
        $identifiers = [
            UuidIdentifier::generate(),
            UlidIdentifier::generate(),
            StringIdentifier::from('test'),
            IntegerIdentifier::from(42),
            AggregateRootId::generate(),
        ];

        foreach ($identifiers as $id) {
            $this->assertInstanceOf(\Stringable::class, $id, get_class($id) . ' must be Stringable');
        }
    }

    public function test_all_identifiers_have_toArray_fromArray(): void
    {
        $identifiers = [
            UuidIdentifier::generate(),
            UlidIdentifier::generate(),
            StringIdentifier::from('test'),
            IntegerIdentifier::from(42),
            AggregateRootId::generate(),
        ];

        foreach ($identifiers as $id) {
            $this->assertIsArray($id->toArray(), get_class($id) . '::toArray() must return array');
        }
    }

    public function test_all_identifiers_have_toJson_fromJson(): void
    {
        $identifiers = [
            UuidIdentifier::generate(),
            UlidIdentifier::generate(),
            StringIdentifier::from('test'),
            IntegerIdentifier::from(42),
            AggregateRootId::generate(),
        ];

        foreach ($identifiers as $id) {
            $json = $id->toJson();
            $this->assertNotEmpty($json, get_class($id) . '::toJson() must return non-empty string');
        }
    }

    // ─── declare(strict_types=1) validation ──────────────────────────

    public function test_all_domain_files_have_strict_types(): void
    {
        $srcDir = dirname(__DIR__, 3) . '/src';
        $files = glob($srcDir . '/**/*.php', GLOB_BRACE);

        foreach ($files as $file) {
            // Skip stubs
            if (str_contains($file, 'stubs/')) {
                continue;
            }

            $content = file_get_contents($file);
            $this->assertStringContainsString(
                'declare(strict_types=1)',
                $content,
                basename($file) . ' must have declare(strict_types=1)',
            );
        }
    }
}

// ─── Test Fixtures ──────────────────────────────────────────────────

class TestOrderId extends UuidIdentifier {}

class TestProductId extends UlidIdentifier {}

class TestSimpleEntity extends Entity
{
    public function __construct(
        int|string|\Stringable $id,
    ) {
        parent::__construct($id);
    }
}

class AnotherTestEntity extends Entity
{
    public function __construct(
        int|string|\Stringable $id,
    ) {
        parent::__construct($id);
    }
}

class TestSimpleEntityWithProps extends Entity
{
    public function __construct(
        int|string|\Stringable $id,
        public readonly string $name,
        public readonly int $age,
    ) {
        parent::__construct($id);
    }

    public function toArray(): array
    {
        return [
            ...parent::toArray(),
            'name' => $this->name,
            'age' => $this->age,
        ];
    }
}

class TestOrderAggregate extends AggregateRoot
{
    public static function create(AggregateRootId $id = null): self
    {
        return new self($id ?? AggregateRootId::generate());
    }

    public function toArray(): array
    {
        return [
            ...parent::toArray(),
        ];
    }
}
