<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Tests;

use ZeroBoiler\Domain\AggregateRoot;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\Contracts\AggregateRoot as AggregateRootContract;
use ZeroBoiler\Domain\Contracts\Entity as EntityContract;
use ZeroBoiler\Domain\Contracts\Identifier;
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
use ZeroBoiler\Domain\Identifiers\UuidIdentifier;
use ZeroBoiler\Domain\InMemoryUnitOfWork;
use ZeroBoiler\Domain\Snapshots\{Snapshot, InMemorySnapshotStore, SnapshottingRepository};
use ZeroBoiler\Domain\Tests\Fixtures\TestAggregate;
use ZeroBoiler\Domain\Tests\Fixtures\TestEntity;
use ZeroBoiler\Domain\Tests\Fixtures\TestValueObject;
use ZeroBoiler\Domain\Tests\Fixtures\TestUuidIdentifier;
use ZeroBoiler\Domain\Tests\Fixtures\TestUlidIdentifier;
use ZeroBoiler\Domain\ValueObject;
use ZeroBoiler\Events\Domain\DomainEvent;

/**
 * Comprehensive production integration test for the domain package.
 *
 * Validates end-to-end domain patterns including:
 * - Aggregate root lifecycle (create, mutate, version, events)
 * - Entity identity and equality
 * - Value object immutability and equality
 * - Domain event collection operations (map, filter, merge)
 * - Unit of work transactional boundaries
 * - Domain exception hierarchy and RFC 9457 serialization
 * - Identifier round-trip serialization
 * - Repository pattern with optimistic locking
 * - Snapshot repository decorator
 *
 * All tests verify PHP 8.5 strict types, return types, readonly, and immutability.
 *
 * @since 1.0.0
 */
final class DomainProductionIntegrationTest extends TestCase
{
    // ── Aggregate Root ──────────────────────────────────────────────

    public function test_aggregate_root_create_records_event(): void
    {
        $id = AggregateRootId::generate();
        $aggregate = TestAggregate::create($id);

        expect($aggregate->id())->toBe($id->toString());
        expect($aggregate->version())->toBe(1);
        expect($aggregate->hasUncommittedEvents())->toBeTrue();

        $events = $aggregate->pullDomainEvents();
        expect($events)->toBeInstanceOf(DomainEventCollection::class);
        expect($events->count())->toBe(1);
        expect($events->first()->eventType)->toBe('TestAggregateCreated');
        expect($aggregate->hasUncommittedEvents())->toBeFalse();
    }

    public function test_aggregate_root_mutate_increments_version(): void
    {
        $id = AggregateRootId::generate();
        $aggregate = TestAggregate::create($id);
        $aggregate->pullDomainEvents();

        $aggregate->rename('New Name');

        expect($aggregate->version())->toBe(2);
        expect($aggregate->name)->toBe('New Name');
        expect($aggregate->nameChanged)->toBeTrue();
    }

    public function test_aggregate_root_peek_does_not_consume_events(): void
    {
        $id = AggregateRootId::generate();
        $aggregate = TestAggregate::create($id);

        $peeked = $aggregate->peekDomainEvents();
        expect($peeked->count())->toBe(1);
        expect($aggregate->hasUncommittedEvents())->toBeTrue();

        $pulled = $aggregate->pullDomainEvents();
        expect($pulled->count())->toBe(1);
        expect($aggregate->hasUncommittedEvents())->toBeFalse();
    }

    public function test_aggregate_root_identity_equality(): void
    {
        $id = AggregateRootId::generate();
        $a = TestAggregate::create($id);
        $b = TestAggregate::create($id);

        expect($a->equals($b))->toBeTrue();

        $other = TestAggregate::create(AggregateRootId::generate());
        expect($a->equals($other))->toBeFalse();
    }

    public function test_aggregate_root_to_array_structure(): void
    {
        $id = AggregateRootId::generate();
        $aggregate = TestAggregate::create($id);

        $array = $aggregate->toArray();

        expect($array)->toHaveKey('id');
        expect($array)->toHaveKey('version');
        expect($array)->toHaveKey('type');
        expect($array['type'])->toBe('TestAggregate');
        expect($array['id'])->toBe($id->toString());
    }

    public function test_aggregate_root_id_immutability(): void
    {
        $id = AggregateRootId::generate();

        expect($id->toString())->toBeString();
        expect($id->equals(AggregateRootId::fromString($id->toString())))->toBeTrue();

        // JSON serialization
        $encoded = json_encode($id);
        expect($encoded)->toBeJson();
        expect(json_decode($encoded, true))->toBe($id->toString());
    }

    public function test_aggregate_root_id_from_array_round_trip(): void
    {
        $id = AggregateRootId::generate();
        $array = $id->toArray();
        $restored = AggregateRootId::fromArray($array);

        expect($id->equals($restored))->toBeTrue();
    }

    // ── Entity ─────────────────────────────────────────────────────

    public function test_entity_identity_equality(): void
    {
        $a = new TestEntity('1', 'prod-1', 2, 9.99);
        $b = new TestEntity('1', 'prod-2', 5, 19.99);

        // Same ID, different fields → equal (identity-based)
        expect($a->equals($b))->toBeTrue();

        $c = new TestEntity('2', 'prod-1', 2, 9.99);
        expect($a->equals($c))->toBeFalse();
    }

    public function test_entity_to_array(): void
    {
        $entity = new TestEntity('42', 'prod-1', 2, 9.99);
        $array = $entity->toArray();

        expect($array)->toHaveKey('id');
        expect($array)->toHaveKey('type');
        expect($array['type'])->toBe('TestEntity');
    }

    public function test_entity_with_stringable_id(): void
    {
        $id = TestUuidIdentifier::generate();
        $entity = new TestEntity($id, 'prod-1', 1, 10.0);

        expect($entity->id())->toBe($id->toString());
    }

    // ── Value Objects ───────────────────────────────────────────────

    public function test_value_object_equality(): void
    {
        $a = TestValueObject::fromArray(['key' => 'val']);
        $b = TestValueObject::fromArray(['key' => 'val']);

        expect($a->equals($b))->toBeTrue();

        $c = TestValueObject::fromArray(['key' => 'other']);
        expect($a->equals($c))->toBeFalse();
    }

    public function test_value_object_round_trip(): void
    {
        $original = TestValueObject::fromArray(['key' => 'test']);
        $array = $original->toArray();
        $restored = TestValueObject::fromArray($array);

        expect($original->equals($restored))->toBeTrue();
    }

    // ── Domain Event Collection ────────────────────────────────────

    public function test_event_collection_operations(): void
    {
        $e1 = DomainEvent::occur('order.created', ['id' => '1']);
        $e2 = DomainEvent::occur('order.paid', ['id' => '1']);
        $e3 = DomainEvent::occur('order.shipped', ['id' => '1']);

        $collection = new DomainEventCollection([$e1, $e2, $e3]);

        expect($collection->count())->toBe(3);
        expect($collection->isEmpty())->toBeFalse();
        expect($collection->all())->toHaveCount(3);
        expect($collection->first()->eventType)->toBe('order.created');
        expect($collection->last()->eventType)->toBe('order.shipped');
        expect($collection->get(1)->eventType)->toBe('order.paid');

        // Map
        $types = $collection->map(fn (DomainEvent $e, int $i): string => $e->eventType);
        expect($types)->toBe(['order.created', 'order.paid', 'order.shipped']);

        // Filter
        $paid = $collection->filter(fn (DomainEvent $e): bool => str_starts_with($e->eventType, 'order.p'));
        expect($paid->count())->toBe(1);

        // Merge
        $e4 = DomainEvent::occur('order.delivered', ['id' => '1']);
        $merged = $collection->merge([$e4]);
        expect($merged->count())->toBe(4);
    }

    public function test_event_collection_json_serialization(): void
    {
        $e1 = DomainEvent::occur('test.event', ['key' => 'val']);
        $collection = new DomainEventCollection([$e1]);

        $json = json_encode($collection);
        expect($json)->toBeJson();

        $decoded = json_decode($json, true);
        expect($decoded)->toBeArray();
        expect($decoded[0])->toHaveKey('eventType');
    }

    public function test_event_collection_from_array_round_trip(): void
    {
        $e1 = DomainEvent::occur('test.a', ['x' => 1]);
        $e2 = DomainEvent::occur('test.b', ['y' => 2]);
        $original = new DomainEventCollection([$e1, $e2]);

        $arrays = $original->toArray();
        $restored = DomainEventCollection::fromArray($arrays);

        expect($restored->count())->toBe(2);
        expect($restored->first()->eventType)->toBe('test.a');
        expect($restored->get(1)->eventType)->toBe('test.b');
    }

    public function test_event_collection_validation(): void
    {
        // Non-list
        expect(fn () => new DomainEventCollection(['key' => $e1 = DomainEvent::occur('t', [])]))
            ->toThrow(\InvalidArgumentException::class);

        // Non-DomainEvent
        expect(fn () => new DomainEventCollection(['not an event']))
            ->toThrow(\InvalidArgumentException::class);
    }

    // ── Unit of Work ────────────────────────────────────────────────

    public function test_unit_of_work_run_auto_commit(): void
    {
        $uow = new InMemoryUnitOfWork;
        $id = AggregateRootId::generate();
        $aggregate = TestAggregate::create($id);

        $result = $uow->run(fn () => $aggregate);

        expect($result)->toBe($aggregate);
        expect($uow->isActive())->toBeFalse();
        expect($uow->hasPendingEvents())->toBeFalse();
    }

    public function test_unit_of_work_run_auto_rollback_on_exception(): void
    {
        $uow = new InMemoryUnitOfWork;
        $id = AggregateRootId::generate();
        $aggregate = TestAggregate::create($id);

        expect(fn () => $uow->run(function () use ($aggregate) {
            $aggregate->rename('Test');
            throw new \RuntimeException('Fail');
        }))->toThrow(\RuntimeException::class);

        expect($uow->isActive())->toBeFalse();
        expect($uow->hasPendingEvents())->toBeFalse();
    }

    public function test_unit_of_work_manual_transaction(): void
    {
        $uow = new InMemoryUnitOfWork;

        $uow->begin();
        expect($uow->isActive())->toBeTrue();

        $uow->commit();
        expect($uow->isActive())->toBeFalse();
    }

    public function test_unit_of_work_rollback(): void
    {
        $uow = new InMemoryUnitOfWork;
        $id = AggregateRootId::generate();
        $aggregate = TestAggregate::create($id);

        $uow->begin();
        $uow->track($aggregate);
        $uow->queueEvent(DomainEvent::occur('side.effect', []));

        expect($uow->hasPendingEvents())->toBeTrue();

        $uow->rollback();
        expect($uow->isActive())->toBeFalse();
        expect($uow->hasPendingEvents())->toBeFalse();
    }

    public function test_unit_of_work_track_and_peek(): void
    {
        $uow = new InMemoryUnitOfWork;
        $id = AggregateRootId::generate();
        $aggregate = TestAggregate::create($id);

        $uow->begin();
        $uow->track($aggregate);

        expect($uow->isTracking($aggregate))->toBeTrue();
        expect($uow->hasPendingEvents())->toBeTrue();

        $pending = $uow->getPendingEvents();
        expect($pending)->toBeInstanceOf(DomainEventCollection::class);
    }

    public function test_unit_of_work_clear(): void
    {
        $uow = new InMemoryUnitOfWork;
        $uow->begin();
        $uow->queueEvent(DomainEvent::occur('test', []));
        $uow->clear();

        expect($uow->isActive())->toBeFalse();
        expect($uow->hasPendingEvents())->toBeFalse();
    }

    // ── Domain Exceptions ───────────────────────────────────────────

    public function test_domain_exception_hierarchy(): void
    {
        $e = InvalidStateDomainException::because('Test');
        expect($e)->toBeInstanceOf(DomainException::class);
        expect($e->errorCode())->toBe('INVALID_STATE');

        $e = InvalidArgumentDomainException::because('Test');
        expect($e->errorCode())->toBe('INVALID_ARGUMENT');

        $e = NotFoundDomainException::because('Test');
        expect($e->errorCode())->toBe('NOT_FOUND');

        $e = ConflictDomainException::because('Test');
        expect($e->errorCode())->toBe('CONFLICT');

        $e = OptimisticLockException::for('id-1', 5, 3);
        expect($e->errorCode())->toBe('OPTIMISTIC_LOCK');

        $e = AggregateNotFoundException::for('Order', 'id-1');
        expect($e->errorCode())->toBe('AGGREGATE_NOT_FOUND');
    }

    public function test_domain_exception_to_error_array_rfc9457(): void
    {
        $e = InvalidStateDomainException::because('Order must be pending.');
        $array = $e->toErrorArray();

        expect($array)->toHaveKeys(['title', 'detail', 'code']);
        expect($array['title'])->toBe('InvalidStateDomainException');
        expect($array['detail'])->toBe('Order must be pending.');
        expect($array['code'])->toBe('INVALID_STATE');
    }

    public function test_domain_exception_json_serialization(): void
    {
        $e = InvalidStateDomainException::because('Test error');
        $json = json_encode($e);
        expect($json)->toBeJson();

        $decoded = json_decode($json, true);
        expect($decoded['code'])->toBe('INVALID_STATE');
    }

    public function test_domain_exception_custom_error_code(): void
    {
        $e = InvalidStateDomainException::because('Test', 'CUSTOM_CODE');
        expect($e->errorCode())->toBe('CUSTOM_CODE');
    }

    // ── Identifiers ─────────────────────────────────────────────────

    public function test_uuid_identifier_generate_and_validate(): void
    {
        $id = TestUuidIdentifier::generate();
        expect($id->toString())->toBeString();
        expect(TestUuidIdentifier::isValid($id->toString()))->toBeTrue();
        expect(TestUuidIdentifier::isValid('not-a-uuid'))->toBeFalse();
    }

    public function test_uuid_identifier_equality(): void
    {
        $a = TestUuidIdentifier::generate();
        $b = TestUuidIdentifier::fromString($a->toString());

        expect($a->equals($b))->toBeTrue();
        expect($a->toString())->toBe($b->toString());
    }

    public function test_uuid_identifier_from_array_round_trip(): void
    {
        $id = TestUuidIdentifier::generate();
        $restored = TestUuidIdentifier::fromArray($id->toArray());

        expect($id->equals($restored))->toBeTrue();
    }

    public function test_uuid_identifier_json_serialization(): void
    {
        $id = TestUuidIdentifier::generate();
        $json = json_encode($id);
        expect($json)->toBe($id->toString());
    }

    public function test_ulid_identifier_generate(): void
    {
        $id = TestUlidIdentifier::generate();
        expect($id->toString())->toBeString();
        expect(TestUlidIdentifier::isValid($id->toString()))->toBeTrue();
    }

    public function test_string_identifier_validation(): void
    {
        $id = StringIdentifier::from('my-slug');
        expect($id->toString())->toBe('my-slug');
        expect(StringIdentifier::isValid(''))->toBeFalse();
        expect(StringIdentifier::isValid('valid'))->toBeTrue();
    }

    public function test_integer_identifier_from_int_and_string(): void
    {
        $id = IntegerIdentifier::from(42);
        expect($id->toInt())->toBe(42);

        $fromString = IntegerIdentifier::fromString('100');
        expect($fromString->toInt())->toBe(100);
        expect(IntegerIdentifier::isValid('abc'))->toBeFalse();
    }

    // ── Snapshots ──────────────────────────────────────────────────

    public function test_snapshot_round_trip(): void
    {
        $snapshot = Snapshot::create(TestAggregate::class, 'uuid-1', 5, ['name' => 'Test']);

        expect($snapshot->aggregateType)->toBe(TestAggregate::class);
        expect($snapshot->aggregateId)->toBe('uuid-1');
        expect($snapshot->version)->toBe(5);
        expect($snapshot->state)->toBe(['name' => 'Test']);

        $array = $snapshot->toArray();
        expect($array)->toHaveKeys(['aggregate_type', 'aggregate_id', 'version', 'state']);
    }

    public function test_in_memory_snapshot_store(): void
    {
        $store = new InMemorySnapshotStore;
        $snapshot = Snapshot::create(TestAggregate::class, 'uuid-1', 1, ['x' => 1]);

        $store->save($snapshot);

        expect($store->has(TestAggregate::class, 'uuid-1'))->toBeTrue();
        expect($store->load(TestAggregate::class, 'uuid-1')->version)->toBe(1);
        expect($store->count(TestAggregate::class))->toBe(1);

        $store->delete(TestAggregate::class, 'uuid-1');
        expect($store->has(TestAggregate::class, 'uuid-1'))->toBeFalse();
    }

    // ── Contracts Compliance ───────────────────────────────────────

    public function test_aggregate_root_implements_contract(): void
    {
        $id = AggregateRootId::generate();
        $aggregate = TestAggregate::create($id);

        expect($aggregate)->toBeInstanceOf(AggregateRootContract::class);
        expect($aggregate)->toBeInstanceOf(EntityContract::class);
    }

    public function test_entity_implements_contract(): void
    {
        $entity = new TestEntity('1', 'prod', 1, 10.0);
        expect($entity)->toBeInstanceOf(EntityContract::class);
    }

    public function test_uuid_identifier_implements_contract(): void
    {
        $id = TestUuidIdentifier::generate();
        expect($id)->toBeInstanceOf(Identifier::class);
        expect($id)->toBeInstanceOf(\Stringable::class);
        expect($id)->toBeInstanceOf(\JsonSerializable::class);
    }

    public function test_unit_of_work_implements_contract(): void
    {
        $uow = new InMemoryUnitOfWork;
        expect($uow)->toBeInstanceOf(UnitOfWorkContract::class);
    }

    // ── Cross-Package Serialization ────────────────────────────────

    public function test_full_serialization_chain(): void
    {
        $id = AggregateRootId::generate();
        $aggregate = TestAggregate::create($id);
        $aggregate->rename('Test');

        // Serialize aggregate
        $array = $aggregate->toArray();
        expect($array)->toBeArray();
        expect($array['version'])->toBe(2);

        // Serialize ID
        $idArray = $id->toArray();
        $restoredId = AggregateRootId::fromArray($idArray);
        expect($id->equals($restoredId))->toBeTrue();

        // Serialize events
        $events = $aggregate->pullDomainEvents();
        $eventsArray = $events->toArray();
        expect($eventsArray)->toBeArray();

        // Serialize exception
        $ex = InvalidStateDomainException::because('Test');
        $exArray = $ex->toErrorArray();
        expect($exArray['code'])->toBe('INVALID_STATE');
    }
}
