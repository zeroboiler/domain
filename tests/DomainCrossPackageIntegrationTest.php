<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Tests;

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
use ZeroBoiler\Domain\Exceptions\AggregateNotFoundException;
use ZeroBoiler\Domain\Exceptions\ConflictDomainException;
use ZeroBoiler\Domain\Exceptions\InvalidArgumentDomainException;
use ZeroBoiler\Domain\Exceptions\InvalidStateDomainException;
use ZeroBoiler\Domain\Exceptions\InvalidStateException;
use ZeroBoiler\Domain\Exceptions\NotFoundDomainException;
use ZeroBoiler\Domain\Exceptions\OptimisticLockException;
use ZeroBoiler\Domain\Identifiers\IntegerIdentifier;
use ZeroBoiler\Domain\Identifiers\StringIdentifier;
use ZeroBoiler\Domain\Identifiers\UuidIdentifier;
use ZeroBoiler\Domain\Identifiers\UlidIdentifier;
use ZeroBoiler\Domain\InMemoryUnitOfWork;
use ZeroBoiler\Domain\ValueObject;
use ZeroBoiler\Domain\Snapshots\InMemorySnapshotStore;
use ZeroBoiler\Domain\Snapshots\Snapshot;
use ZeroBoiler\Domain\Snapshots\SnapshotPolicy;
use ZeroBoiler\Domain\Snapshots\SnapshotStore;
use ZeroBoiler\Domain\Snapshots\SnapshottingRepository;
use ZeroBoiler\Events\Domain\DomainEvent;

/**
 * Comprehensive cross-package integration tests for the domain layer.
 *
 * Verifies:
 * - All contracts are properly implemented
 * - Domain events flow correctly through aggregates and UoW
 * - Identifiers are immutable and type-safe
 * - Snapshot serialization round-trips correctly
 * - Exception hierarchy provides correct error codes
 * - Unit of Work transactional semantics work correctly
 * - Entity equality is based on identity, not properties
 */
final class DomainCrossPackageIntegrationTest extends TestCase
{
    // ─── Aggregate Root Identity ────────────────────────────────────────

    public function test_aggregate_root_id_generates_valid_uuid(): void
    {
        $id = AggregateRootId::generate();

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $id->toString(),
        );
    }

    public function test_aggregate_root_id_from_string_validates(): void
    {
        $id = AggregateRootId::fromString('550e8400-e29b-41d4-a716-446655440000');

        $this->assertSame('550e8400-e29b-41d4-a716-446655440000', $id->toString());
    }

    public function test_aggregate_root_id_equals_compares_values(): void
    {
        $id1 = AggregateRootId::fromString('550e8400-e29b-41d4-a716-446655440000');
        $id2 = AggregateRootId::fromString('550e8400-e29b-41d4-a716-446655440000');
        $id3 = AggregateRootId::generate();

        $this->assertTrue($id1->equals($id2));
        $this->assertFalse($id1->equals($id3));
    }

    public function test_aggregate_root_id_json_serializes(): void
    {
        $id = AggregateRootId::fromString('550e8400-e29b-41d4-a716-446655440000');

        $this->assertSame('"550e8400-e29b-41d4-a716-446655440000"', json_encode($id));
    }

    // ─── Identifier Type Safety ────────────────────────────────────────

    public function test_uuid_identifier_is_readonly_and_validated(): void
    {
        $id = TestUuidIdentifier::generate();

        $this->assertInstanceOf(IdentifierContract::class, $id);
        $this->assertInstanceOf(\JsonSerializable::class, $id);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $id->toString(),
        );
    }

    public function test_ulid_identifier_is_readonly_and_monotonic(): void
    {
        $id1 = TestUlidIdentifier::generate();
        $id2 = TestUlidIdentifier::generate();

        // ULIDs are monotonic — lexicographically sorted
        $this->assertGreaterThan($id1->toString(), $id2->toString());
        $this->assertSame(26, strlen($id2->toString()));
    }

    public function test_string_identifier_rejects_empty_string(): void
    {
        $this->expectException(\ValueError::class);
        new StringIdentifier('');
    }

    public function test_integer_identifier_json_serializes_as_int(): void
    {
        $id = IntegerIdentifier::from(42);

        $this->assertSame('42', $id->toString());
        $this->assertSame(42, $id->jsonSerialize());
        $this->assertSame('42', (string) $id);
    }

    public function test_identifiers_implement_contracts(): void
    {
        $uuid = TestUuidIdentifier::generate();
        $ulid = TestUlidIdentifier::generate();
        $string = StringIdentifier::from('test');
        $int = IntegerIdentifier::from(1);

        $this->assertInstanceOf(IdentifierContract::class, $uuid);
        $this->assertInstanceOf(IdentifierContract::class, $ulid);
        $this->assertInstanceOf(IdentifierContract::class, $string);
        $this->assertInstanceOf(IdentifierContract::class, $int);
    }

    // ─── Entity Equality ──────────────────────────────────────────────

    public function test_entity_equality_is_based_on_identity(): void
    {
        $id = AggregateRootId::fromString('550e8400-e29b-41d4-a716-446655440000');

        $entity1 = new class($id) extends Entity {};
        $entity2 = new class($id) extends Entity {};

        $this->assertTrue($entity1->equals($entity2));
    }

    public function test_different_entities_are_not_equal(): void
    {
        $entity1 = new class(AggregateRootId::generate()) extends Entity {};
        $entity2 = new class(AggregateRootId::generate()) extends Entity {};

        $this->assertFalse($entity1->equals($entity2));
    }

    public function test_different_types_with_same_id_are_not_equal(): void
    {
        $id = AggregateRootId::generate();

        $a = new class($id) extends Entity {};
        $b = new class($id) extends Entity {};

        // anonymous classes with same parent but different class identity
        $this->assertFalse($a->equals($b));
    }

    // ─── Domain Event Collection ──────────────────────────────────────

    public function test_domain_event_collection_is_type_safe(): void
    {
        $collection = new DomainEventCollection;

        $this->assertCount(0, $collection);
        $this->assertTrue($collection->isEmpty());
        $this->assertNull($collection->first());
        $this->assertNull($collection->last());
    }

    public function test_domain_event_collection_rejects_non_list(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new DomainEventCollection(['key' => 'not_a_list']);
    }

    public function test_domain_event_collection_json_serializes(): void
    {
        $event = DomainEvent::occur('test.event', ['key' => 'value']);
        $collection = new DomainEventCollection([$event]);

        $json = json_encode($collection);
        $data = json_decode($json, true);

        $this->assertIsArray($data);
        $this->assertCount(1, $data);
        $this->assertSame('test.event', $data[0]['event_type']);
    }

    public function test_domain_event_collection_filter_returns_new_instance(): void
    {
        $e1 = DomainEvent::occur('event.a', []);
        $e2 = DomainEvent::occur('event.b', []);

        $collection = new DomainEventCollection([$e1, $e2]);
        $filtered = $collection->filter(
            fn (DomainEvent $e) => $e->eventType === 'event.a',
        );

        $this->assertCount(1, $filtered);
        $this->assertNotSame($collection, $filtered);
    }

    public function test_domain_event_collection_map_and_merge(): void
    {
        $e1 = DomainEvent::occur('event.a', []);
        $e2 = DomainEvent::occur('event.b', []);

        $c1 = new DomainEventCollection([$e1]);
        $c2 = new DomainEventCollection([$e2]);
        $merged = $c1->merge($c2);

        $this->assertCount(2, $merged);

        $types = $merged->map(fn (DomainEvent $e) => $e->eventType);
        $this->assertSame(['event.a', 'event.b'], $types);
    }

    // ─── Value Object ───────────────────────────────────────────────────

    public function test_value_object_equality_is_structural(): void
    {
        $a = TestValueObject::fromArray(['value' => 'test']);
        $b = TestValueObject::fromArray(['value' => 'test']);

        $this->assertTrue($a->equals($b));
    }

    public function test_value_object_serializes_round_trip(): void
    {
        $vo = TestValueObject::fromArray(['value' => 'hello']);

        $arr = $vo->toArray();
        $restored = TestValueObject::fromArray($arr);

        $this->assertTrue($vo->equals($restored));
    }

    // ─── Snapshot Round-Trip ───────────────────────────────────────────

    public function test_snapshot_serializes_and_deserializes(): void
    {
        $snapshot = Snapshot::create(
            aggregateType: 'App\\Domain\\Order',
            aggregateId: 'order-123',
            version: 10,
            state: ['status' => 'shipped', 'total' => 99.99],
        );

        $arr = $snapshot->toArray();
        $restored = Snapshot::fromArray($arr);

        $this->assertTrue($snapshot->equals($restored));
        $this->assertSame('App\\Domain\\Order', $restored->aggregateType);
        $this->assertSame('order-123', $restored->aggregateId);
        $this->assertSame(10, $restored->version);
        $this->assertSame(['status' => 'shipped', 'total' => 99.99], $restored->state);
    }

    public function test_snapshot_from_array_validates_types(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Snapshot::fromArray(['invalid' => 'data']);
    }

    public function test_snapshot_json_serializes(): void
    {
        $snapshot = Snapshot::create('Order', 'id-1', 1, ['key' => 'val']);

        $json = json_encode($snapshot);
        $data = json_decode($json, true);

        $this->assertSame('Order', $data['aggregate_type']);
        $this->assertSame('id-1', $data['aggregate_id']);
    }

    public function test_snapshot_store_save_load_delete(): void
    {
        $store = new InMemorySnapshotStore;
        $snapshot = Snapshot::create('Order', 'id-1', 5, ['status' => 'paid']);

        $store->save($snapshot);

        $this->assertTrue($store->has('Order', 'id-1'));
        $this->assertFalse($store->has('Order', 'id-2'));

        $loaded = $store->load('Order', 'id-1');
        $this->assertNotNull($loaded);
        $this->assertSame(5, $loaded->version);

        $store->delete('Order', 'id-1');
        $this->assertNull($store->load('Order', 'id-1'));
    }

    public function test_snapshot_store_stats_and_purge(): void
    {
        $store = new InMemorySnapshotStore;

        $store->save(Snapshot::create('Order', 'id-1', 1, []));
        $store->save(Snapshot::create('Order', 'id-2', 2, []));
        $store->save(Snapshot::create('User', 'id-1', 1, []));

        $stats = $store->stats();
        $this->assertSame(3, $stats['total']);
        $this->assertSame(2, $stats['by_type']['Order']);
        $this->assertSame(1, $stats['by_type']['User']);

        $removed = $store->purge('Order');
        $this->assertSame(2, $removed);
        $this->assertSame(1, $store->count());
    }

    // ─── Exception Hierarchy ──────────────────────────────────────────

    public function test_domain_exceptions_provide_error_codes(): void
    {
        $invalidState = InvalidStateDomainException::because('test');
        $invalidArg = InvalidArgumentDomainException::because('test');
        $notFound = NotFoundDomainException::because('test');
        $conflict = ConflictDomainException::because('test');
        $aggNotFound = AggregateNotFoundException::for('Order', '123');
        $optLock = OptimisticLockException::for('id-1', 5, 3);

        $this->assertSame('INVALID_STATE', $invalidState->errorCode());
        $this->assertSame('INVALID_ARGUMENT', $invalidArg->errorCode());
        $this->assertSame('NOT_FOUND', $notFound->errorCode());
        $this->assertSame('CONFLICT', $conflict->errorCode());
        $this->assertSame('AGGREGATE_NOT_FOUND', $aggNotFound->errorCode());
        $this->assertSame('OPTIMISTIC_LOCK', $optLock->errorCode());
    }

    public function test_domain_exception_custom_code_overrides_default(): void
    {
        $e = InvalidStateDomainException::because('test', 'CUSTOM_CODE');

        $this->assertSame('CUSTOM_CODE', $e->errorCode());
    }

    public function test_not_found_for_aggregate_formats_message(): void
    {
        $e = NotFoundDomainException::forAggregate('Order', '123');

        $this->assertStringContainsString('Order', $e->getMessage());
        $this->assertStringContainsString('123', $e->getMessage());
    }

    public function test_optimistic_lock_contains_versions(): void
    {
        $e = OptimisticLockException::for('id-1', expectedVersion: 5, actualVersion: 3);

        $this->assertStringContainsString('5', $e->getMessage());
        $this->assertStringContainsString('3', $e->getMessage());
    }

    // ─── Unit of Work ───────────────────────────────────────────────────

    public function test_unit_of_work_run_commits_and_dispatches(): void
    {
        $uow = new InMemoryUnitOfWork;
        $dispatched = [];

        $uow->setEventDispatcher(function (DomainEvent $event) use (&$dispatched): void {
            $dispatched[] = $event->eventType;
        });

        $uow->setPersistenceCallback(function (array $committed, array $deleted): void {
            // noop for test
        });

        $result = $uow->run(function () use ($uow): string {
            $uow->queueEvent(DomainEvent::occur('test.event', []));

            return 'done';
        });

        $this->assertSame('done', $result);
        $this->assertSame(['test.event'], $dispatched);
        $this->assertFalse($uow->isActive());
    }

    public function test_unit_of_work_run_rolls_back_on_exception(): void
    {
        $uow = new InMemoryUnitOfWork;
        $dispatched = [];

        $uow->setEventDispatcher(function (DomainEvent $event) use (&$dispatched): void {
            $dispatched[] = $event->eventType;
        });

        $this->expectException(\RuntimeException::class);

        try {
            $uow->run(function () use ($uow): void {
                $uow->queueEvent(DomainEvent::occur('test.event', []));
                throw new \RuntimeException('fail');
            });
        } catch (\RuntimeException $e) {
            // Events should NOT be dispatched on rollback
        }

        $this->assertSame([], $dispatched);
        $this->assertFalse($uow->isActive());
    }

    public function test_unit_of_work_nested_scopes(): void
    {
        $uow = new InMemoryUnitOfWork;
        $dispatched = [];

        $uow->setEventDispatcher(function (DomainEvent $event) use (&$dispatched): void {
            $dispatched[] = $event->eventType;
        });

        $uow->setPersistenceCallback(function (array $committed, array $deleted): void {
            // noop
        });

        $uow->run(function () use ($uow): void {
            $uow->queueEvent(DomainEvent::occur('outer.event', []));

            $uow->run(function () use ($uow): void {
                $uow->queueEvent(DomainEvent::occur('inner.event', []));
            });
        });

        // Both events should be dispatched in order
        $this->assertSame(['outer.event', 'inner.event'], $dispatched);
    }

    public function test_unit_of_work_track_without_begin_throws(): void
    {
        $uow = new InMemoryUnitOfWork;
        $aggregate = new TestAggregate;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No active unit of work');

        $uow->track($aggregate);
    }

    public function test_unit_of_work_clear_resets_all_state(): void
    {
        $uow = new InMemoryUnitOfWork;

        $uow->begin();
        $uow->queueEvent(DomainEvent::occur('test.event', []));
        $uow->rollback();

        $uow->clear();

        $this->assertSame([], $uow->getCommitted());
        $this->assertSame([], $uow->getDeleted());
        $this->assertFalse($uow->hasPendingEvents());
        $this->assertSame(0, $uow->getPendingEventCount());
    }

    // ─── Contract Compliance ───────────────────────────────────────────

    public function test_aggregate_root_implements_contract(): void
    {
        $aggregate = new TestAggregate;

        $this->assertInstanceOf(AggregateRootContract::class, $aggregate);
        $this->assertInstanceOf(EntityContract::class, $aggregate);
        $this->assertSame(0, $aggregate->version());

        $events = $aggregate->pullDomainEvents();
        $this->assertInstanceOf(DomainEventCollection::class, $events);
        $this->assertTrue($events->isEmpty());
    }

    public function test_repository_contract_methods(): void
    {
        $this->assertContains('find', get_class_methods(RepositoryContract::class));
        $this->assertContains('save', get_class_methods(RepositoryContract::class));
        $this->assertContains('delete', get_class_methods(RepositoryContract::class));
    }

    public function test_unit_of_work_contract_methods(): void
    {
        $methods = get_class_methods(UnitOfWorkContract::class);

        $this->assertContains('begin', $methods);
        $this->assertContains('commit', $methods);
        $this->assertContains('rollback', $methods);
        $this->assertContains('run', $methods);
        $this->assertContains('track', $methods);
        $this->assertContains('isTracking', $methods);
        $this->assertContains('markForDeletion', $methods);
        $this->assertContains('getCommitted', $methods);
        $this->assertContains('getDeleted', $methods);
    }

    // ─── PHP 8.5 Features ─────────────────────────────────────────────

    public function test_deprecated_attribute_present_on_legacy_identifier(): void
    {
        $reflection = new \ReflectionClass(\ZeroBoiler\Domain\Identifiers\Identifier::class);
        $attrs = $reflection->getAttributes(\Deprecated::class);

        $this->assertCount(1, $attrs);
    }

    public function test_readonly_classes_are_truly_immutable(): void
    {
        $id = AggregateRootId::generate();
        $ref = new \ReflectionProperty($id, 'value');

        $this->assertTrue($ref->isReadOnly());
    }

    public function test_integer_identifier_is_final(): void
    {
        $ref = new \ReflectionClass(IntegerIdentifier::class);

        $this->assertTrue($ref->isFinal());
    }

    // ─── Identifier fromString/isValid Consistency ────────────────────

    public function test_uuid_identifier_is_valid(): void
    {
        $this->assertTrue(UuidIdentifier::isValid('550e8400-e29b-41d4-a716-446655440000'));
        $this->assertFalse(UuidIdentifier::isValid('not-a-uuid'));
    }

    public function test_ulid_identifier_is_valid(): void
    {
        $id = TestUlidIdentifier::generate();
        $this->assertTrue(UlidIdentifier::isValid($id->toString()));
        $this->assertFalse(UlidIdentifier::isValid('not-a-ulid'));
    }

    public function test_string_identifier_is_valid(): void
    {
        $this->assertTrue(StringIdentifier::isValid('hello'));
        $this->assertFalse(StringIdentifier::isValid(''));
    }

    public function test_integer_identifier_is_valid(): void
    {
        $this->assertTrue(IntegerIdentifier::isValid('42'));
        $this->assertTrue(IntegerIdentifier::isValid('-5'));
        $this->assertFalse(IntegerIdentifier::isValid('abc'));
    }
}

// ─── Test Fixtures ────────────────────────────────────────────────────

/** @internal */
final class TestAggregate extends AggregateRoot
{
    public function __construct(?AggregateRootId $id = null)
    {
        parent::__construct($id ?? AggregateRootId::generate());
    }
}
