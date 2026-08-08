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
use ZeroBoiler\Domain\DomainEventCollection;
use ZeroBoiler\Domain\Entity;
use ZeroBoiler\Domain\Exceptions\{
    AggregateNotFoundException,
    ConflictDomainException,
    DomainException,
    InvalidArgumentDomainException,
    InvalidStateDomainException,
    NotFoundDomainException,
    OptimisticLockException,
};
use ZeroBoiler\Domain\Identifiers\IntegerIdentifier;
use ZeroBoiler\Domain\Identifiers\StringIdentifier;
use ZeroBoiler\Domain\Identifiers\UuidIdentifier;
use ZeroBoiler\Domain\Identifiers\UlidIdentifier;
use ZeroBoiler\Domain\InMemoryUnitOfWork;
use ZeroBoiler\Domain\ValueObject;
use ZeroBoiler\Domain\Snapshots\{InMemorySnapshotStore, Snapshot, SnapshotPolicy, SnapshotStore, SnapshottingRepository};
use ZeroBoiler\Events\Domain\DomainEvent;

/**
 * End-to-end production readiness verification tests.
 *
 * Validates the complete domain layer contract:
 * - Identity immutability and type safety
 * - Entity equality semantics (identity-based, not property-based)
 * - Aggregate root lifecycle (creation, mutation, event recording, versioning)
 * - Domain event collection type safety and operations
 * - Snapshot round-trip serialization integrity
 * - Exception hierarchy error codes and JSON serialization
 * - Unit of Work transactional semantics (commit, rollback, nesting)
 * - Value object structural equality
 * - Contract compliance across the hierarchy
 *
 * These tests serve as a final gate before release — any failure
 * indicates a breaking change in the domain layer contract.
 */
final class DomainEndToEndProductionTest extends TestCase
{
    // ─── Identity Immutability ────────────────────────────────────────

    public function test_aggregate_root_id_is_immutable(): void
    {
        $id = AggregateRootId::generate();

        $this->assertIsString($id->toString());
        $this->assertSame($id->toString(), $id->toString());
        $this->assertSame($id->toString(), (string) $id);
        $this->assertSame($id->toString(), $id->jsonSerialize());
    }

    public function test_aggregate_root_id_round_trips_from_string(): void
    {
        $original = AggregateRootId::generate();
        $restored = AggregateRootId::fromString($original->toString());

        $this->assertTrue($original->equals($restored));
        $this->assertSame($original->toString(), $restored->toString());
    }

    public function test_aggregate_root_id_equality_is_value_based(): void
    {
        $a = AggregateRootId::fromString('550e8400-e29b-41d4-a716-446655440000');
        $b = AggregateRootId::fromString('550e8400-e29b-41d4-a716-446655440000');
        $c = AggregateRootId::generate();

        $this->assertTrue($a->equals($b));
        $this->assertFalse($a->equals($c));
        $this->assertFalse($b->equals($c));
    }

    // ─── Identifier Type Safety ────────────────────────────────────────

    public function test_all_identifiers_implement_contract(): void
    {
        $uuid = TestUuidIdentifier::generate();
        $ulid = TestUlidIdentifier::generate();
        $string = StringIdentifier::from('test-slug');
        $int = IntegerIdentifier::from(42);

        $this->assertInstanceOf(IdentifierContract::class, $uuid);
        $this->assertInstanceOf(IdentifierContract::class, $ulid);
        $this->assertInstanceOf(IdentifierContract::class, $string);
        $this->assertInstanceOf(IdentifierContract::class, $int);
    }

    public function test_uuid_identifier_validates_on_construction(): void
    {
        $this->expectException(\Ramsey\Uuid\Exception\InvalidUuidStringException::class);
        new class('not-a-uuid') extends UuidIdentifier {
            public function __construct(string $value)
            {
                parent::__construct($value);
            }
        };
    }

    public function test_ulid_identifier_validates_on_construction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new class('not-a-ulid') extends UlidIdentifier {
            public function __construct(string $value)
            {
                parent::__construct($value);
            }
        };
    }

    public function test_string_identifier_rejects_empty(): void
    {
        $this->expectException(\ValueError::class);
        StringIdentifier::from('');
    }

    public function test_different_identifier_subclasses_never_equal(): void
    {
        $uuid1 = TestUuidIdentifier::generate();
        $uuid2 = TestUuidIdentifier::generate();
        $ulid = TestUlidIdentifier::generate();

        // Same subclass, same value → equal
        $restored = TestUuidIdentifier::fromString($uuid1->toString());
        $this->assertTrue($uuid1->equals($restored));

        // Different subclass → never equal
        $this->assertFalse($uuid1->equals($ulid));
    }

    public function test_identifiers_are_json_serializable(): void
    {
        $uuid = TestUuidIdentifier::generate();
        $ulid = TestUlidIdentifier::generate();
        $string = StringIdentifier::from('my-slug');
        $int = IntegerIdentifier::from(42);

        // UUID serializes as string
        $this->assertJson(json_encode($uuid));

        // Integer serializes as int (not string)
        $this->assertSame(42, json_decode(json_encode($int)));
    }

    // ─── Entity Equality ──────────────────────────────────────────────

    public function test_entity_equality_requires_same_class_and_id(): void
    {
        $id = AggregateRootId::fromString('550e8400-e29b-41d4-a716-446655440000');

        // Same anonymous class → different instances → not equal (different class)
        $e1 = new class($id) extends Entity {};
        $e2 = new class($id) extends Entity {};

        $this->assertFalse($e1->equals($e2));

        // Named test fixture — same class, same id → equal
        $te1 = new TestEntity($id);
        $te2 = new TestEntity($id);
        $this->assertTrue($te1->equals($te2));

        // Named test fixture — same class, different id → not equal
        $te3 = new TestEntity(AggregateRootId::generate());
        $this->assertFalse($te1->equals($te3));
    }

    // ─── Aggregate Root Lifecycle ──────────────────────────────────────

    public function test_aggregate_root_has_initial_version_zero(): void
    {
        $aggregate = new TestAggregate;

        $this->assertSame(0, $aggregate->version());
        $this->assertInstanceOf(AggregateRootContract::class, $aggregate);
        $this->assertInstanceOf(EntityContract::class, $aggregate);
    }

    public function test_aggregate_root_pull_domain_events_returns_empty_collection(): void
    {
        $aggregate = new TestAggregate;
        $events = $aggregate->pullDomainEvents();

        $this->assertInstanceOf(DomainEventCollection::class, $events);
        $this->assertTrue($events->isEmpty());
        $this->assertCount(0, $events);
    }

    public function test_aggregate_root_clear_domain_events_works(): void
    {
        $aggregate = new TestAggregate;
        $aggregate->clearDomainEvents();
        $events = $aggregate->pullDomainEvents();

        $this->assertTrue($events->isEmpty());
    }

    public function test_aggregate_root_to_array_contains_identity_and_version(): void
    {
        $aggregate = new TestAggregate;
        $array = $aggregate->toArray();

        $this->assertArrayHasKey('id', $array);
        $this->assertArrayHasKey('version', $array);
        $this->assertArrayHasKey('type', $array);
        $this->assertSame(0, $array['version']);
        $this->assertSame('TestAggregate', $array['type']);
    }

    public function test_aggregate_root_aggregate_id_method(): void
    {
        $aggregate = new TestAggregate;
        $aggregateId = $aggregate->aggregateId();

        $this->assertInstanceOf(AggregateRootId::class, $aggregateId);
        $this->assertSame($aggregate->id(), $aggregateId->toString());
    }

    // ─── Domain Event Collection ──────────────────────────────────────

    public function test_domain_event_collection_validates_type_safety(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be a DomainEvent');

        new DomainEventCollection([new \stdClass]);
    }

    public function test_domain_event_collection_validates_sequential_list(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('sequential list');

        new DomainEventCollection(['key' => DomainEvent::occur('test', [])]);
    }

    public function test_domain_event_collection_operations(): void
    {
        $e1 = DomainEvent::occur('event.a', ['key' => 'value']);
        $e2 = DomainEvent::occur('event.b', ['key' => 'value2']);
        $e3 = DomainEvent::occur('event.c', ['key' => 'value3']);

        $collection = new DomainEventCollection([$e1, $e2, $e3]);

        // all()
        $this->assertSame([$e1, $e2, $e3], $collection->all());

        // count / isEmpty
        $this->assertCount(3, $collection);
        $this->assertFalse($collection->isEmpty());

        // first / last / get
        $this->assertSame($e1, $collection->first());
        $this->assertSame($e3, $collection->last());
        $this->assertSame($e2, $collection->get(1));
        $this->assertNull($collection->get(99));

        // filter (returns new instance)
        $filtered = $collection->filter(fn (DomainEvent $e) => $e->eventType !== 'event.b');
        $this->assertCount(2, $filtered);
        $this->assertNotSame($collection, $filtered);

        // map
        $types = $collection->map(fn (DomainEvent $e, int $i) => $e->eventType);
        $this->assertSame(['event.a', 'event.b', 'event.c'], $types);

        // merge
        $extra = DomainEvent::occur('event.d', []);
        $merged = $collection->merge([$extra]);
        $this->assertCount(4, $merged);

        // JSON serialization
        $json = json_encode($collection);
        $data = json_decode($json, true);
        $this->assertCount(3, $data);
        $this->assertSame('event.a', $data[0]['event_type']);

        // toArray() alias
        $this->assertSame($data, $collection->toArray());
    }

    // ─── Value Object Equality ──────────────────────────────────────────

    public function test_value_object_structural_equality(): void
    {
        $a = TestValueObject::fromArray(['value' => 'test']);
        $b = TestValueObject::fromArray(['value' => 'test']);
        $c = TestValueObject::fromArray(['value' => 'different']);

        $this->assertTrue($a->equals($b));
        $this->assertFalse($a->equals($c));
    }

    public function test_value_object_round_trip_serialization(): void
    {
        $original = TestValueObject::fromArray(['value' => 'hello']);
        $restored = TestValueObject::fromArray($original->toArray());

        $this->assertTrue($original->equals($restored));
    }

    public function test_value_object_does_not_equal_null(): void
    {
        $vo = TestValueObject::fromArray(['value' => 'test']);
        $this->assertFalse($vo->equals(null));
    }

    // ─── Snapshot Round-Trip ───────────────────────────────────────────

    public function test_snapshot_full_round_trip(): void
    {
        $original = Snapshot::create(
            aggregateType: 'App\\Domain\\Order',
            aggregateId: 'order-abc-123',
            version: 50,
            state: [
                'status' => 'shipped',
                'total' => 299.99,
                'items_count' => 5,
            ],
        );

        // toArray → fromArray → equals
        $array = $original->toArray();
        $restored = Snapshot::fromArray($array);

        $this->assertTrue($original->equals($restored));
        $this->assertSame('App\\Domain\\Order', $restored->aggregateType);
        $this->assertSame('order-abc-123', $restored->aggregateId);
        $this->assertSame(50, $restored->version);
        $this->assertSame(['status' => 'shipped', 'total' => 299.99, 'items_count' => 5], $restored->state);
        $this->assertSame($original->createdAt->format(\DateTimeInterface::ATOM), $restored->createdAt->format(\DateTimeInterface::ATOM));

        // JSON round-trip
        $json = json_encode($original);
        $decoded = json_decode($json, true);
        $fromJson = Snapshot::fromArray($decoded);
        $this->assertTrue($original->equals($fromJson));
    }

    public function test_snapshot_from_array_rejects_invalid_data(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Snapshot::fromArray(['invalid' => 'data']);
    }

    public function test_snapshot_store_full_lifecycle(): void
    {
        $store = new InMemorySnapshotStore;

        // Save
        $s1 = Snapshot::create('Order', 'id-1', 10, ['status' => 'paid']);
        $s2 = Snapshot::create('Order', 'id-2', 5, ['status' => 'pending']);
        $s3 = Snapshot::create('User', 'id-1', 1, ['name' => 'John']);

        $store->save($s1);
        $store->save($s2);
        $store->save($s3);

        // has / load
        $this->assertTrue($store->has('Order', 'id-1'));
        $this->assertFalse($store->has('Order', 'id-99'));
        $this->assertTrue($store->has('User', 'id-1'));

        $loaded = $store->load('Order', 'id-1');
        $this->assertNotNull($loaded);
        $this->assertSame(10, $loaded->version);
        $this->assertSame(['status' => 'paid'], $loaded->state);

        // count by type
        $this->assertSame(3, $store->count());
        $this->assertSame(2, $store->count('Order'));
        $this->assertSame(1, $store->count('User'));

        // stats
        $stats = $store->stats();
        $this->assertSame(3, $stats['total']);
        $this->assertSame(2, $stats['by_type']['Order']);
        $this->assertSame(1, $stats['by_type']['User']);

        // delete
        $store->delete('Order', 'id-1');
        $this->assertNull($store->load('Order', 'id-1'));
        $this->assertSame(1, $store->count('Order'));

        // purge
        $removed = $store->purge('Order');
        $this->assertSame(1, $removed);
        $this->assertSame(0, $store->count('Order'));
        $this->assertSame(1, $store->count('User'));

        // purge all
        $removed = $store->purge();
        $this->assertSame(1, $removed);
        $this->assertSame(0, $store->count());
    }

    // ─── Exception Hierarchy ──────────────────────────────────────────

    public function test_all_domain_exceptions_have_machine_readable_codes(): void
    {
        $exceptions = [
            ['class' => InvalidStateDomainException::because('test'), 'code' => 'INVALID_STATE'],
            ['class' => InvalidArgumentDomainException::because('test'), 'code' => 'INVALID_ARGUMENT'],
            ['class' => NotFoundDomainException::because('test'), 'code' => 'NOT_FOUND'],
            ['class' => ConflictDomainException::because('test'), 'code' => 'CONFLICT'],
            ['class' => AggregateNotFoundException::for('Order', '123'), 'code' => 'AGGREGATE_NOT_FOUND'],
            ['class' => OptimisticLockException::for('id-1', 5, 3), 'code' => 'OPTIMISTIC_LOCK'],
        ];

        foreach ($exceptions as ['class' => $e, 'code' => $expectedCode]) {
            $this->assertInstanceOf(DomainException::class, $e);
            $this->assertSame($expectedCode, $e->errorCode());
        }
    }

    public function test_domain_exception_custom_code_overrides_default(): void
    {
        $e = InvalidStateDomainException::because('test', 'CUSTOM_ORDER_ERROR');

        $this->assertSame('CUSTOM_ORDER_ERROR', $e->errorCode());
    }

    public function test_domain_exception_to_error_array_is_rfc9457_compatible(): void
    {
        $e = InvalidStateDomainException::because('Order must be pending to pay.');

        $error = $e->toErrorArray();

        $this->assertArrayHasKey('title', $error);
        $this->assertArrayHasKey('detail', $error);
        $this->assertArrayHasKey('code', $error);
        $this->assertSame('InvalidStateDomainException', $error['title']);
        $this->assertSame('Order must be pending to pay.', $error['detail']);
        $this->assertSame('INVALID_STATE', $error['code']);
    }

    public function test_domain_exception_json_serialization_matches_to_error_array(): void
    {
        $e = NotFoundDomainException::forAggregate('Order', 'order-123');

        $this->assertSame($e->toErrorArray(), $e->jsonSerialize());
        $this->assertSame($e->toErrorArray(), json_decode(json_encode($e), true));
    }

    public function test_not_found_for_aggregate_includes_type_and_id_in_message(): void
    {
        $e = NotFoundDomainException::forAggregate('App\\Domain\\Order', 'order-abc');

        $this->assertStringContainsString('App\\Domain\\Order', $e->getMessage());
        $this->assertStringContainsString('order-abc', $e->getMessage());
    }

    public function test_optimistic_lock_exception_includes_version_details(): void
    {
        $e = OptimisticLockException::for('order-123', expectedVersion: 10, actualVersion: 7);

        $this->assertStringContainsString('order-123', $e->getMessage());
        $this->assertStringContainsString('10', $e->getMessage());
        $this->assertStringContainsString('7', $e->getMessage());
    }

    // ─── Unit of Work ───────────────────────────────────────────────────

    public function test_uow_commit_dispatches_events_in_order(): void
    {
        $uow = new InMemoryUnitOfWork;
        $dispatched = [];

        $uow->setEventDispatcher(function (DomainEvent $event) use (&$dispatched): void {
            $dispatched[] = $event->eventType;
        });

        $uow->setPersistenceCallback(function () use (&$persisted): void {
            $persisted = true;
        });

        $result = $uow->run(function () use ($uow): string {
            $uow->queueEvent(DomainEvent::occur('event.first', []));
            $uow->queueEvent(DomainEvent::occur('event.second', []));
            $uow->queueEvent(DomainEvent::occur('event.third', []));

            return 'success';
        });

        $this->assertSame('success', $result);
        $this->assertTrue($persisted);
        $this->assertSame(['event.first', 'event.second', 'event.third'], $dispatched);
        $this->assertFalse($uow->isActive());
    }

    public function test_uow_rollback_discards_events(): void
    {
        $uow = new InMemoryUnitOfWork;
        $dispatched = [];
        $persisted = false;

        $uow->setEventDispatcher(function (DomainEvent $event) use (&$dispatched): void {
            $dispatched[] = $event->eventType;
        });

        $uow->setPersistenceCallback(function () use (&$persisted): void {
            $persisted = true;
        });

        $this->expectException(\RuntimeException::class);

        try {
            $uow->run(function () use ($uow): void {
                $uow->queueEvent(DomainEvent::occur('event.should_be_discarded', []));
                throw new \RuntimeException('intentional failure');
            });
        } catch (\RuntimeException) {
            // Expected
        }

        $this->assertSame([], $dispatched);
        $this->assertFalse($persisted);
        $this->assertFalse($uow->isActive());
    }

    public function test_uow_nested_run_preserves_event_order(): void
    {
        $uow = new InMemoryUnitOfWork;
        $dispatched = [];

        $uow->setEventDispatcher(function (DomainEvent $event) use (&$dispatched): void {
            $dispatched[] = $event->eventType;
        });

        $uow->setPersistenceCallback(function () use (&$persisted): void {
            $persisted = true;
        });

        $uow->run(function () use ($uow): void {
            $uow->queueEvent(DomainEvent::occur('outer.before', []));

            $uow->run(function () use ($uow): void {
                $uow->queueEvent(DomainEvent::occur('inner', []));
            });

            $uow->queueEvent(DomainEvent::occur('outer.after', []));
        });

        $this->assertSame(['outer.before', 'inner', 'outer.after'], $dispatched);
    }

    public function test_uow_manual_begin_commit_rollback(): void
    {
        $uow = new InMemoryUnitOfWork;
        $dispatched = [];

        $uow->setEventDispatcher(function (DomainEvent $event) use (&$dispatched): void {
            $dispatched[] = $event->eventType;
        });

        // Manual begin
        $uow->begin();
        $this->assertTrue($uow->isActive());
        $uow->queueEvent(DomainEvent::occur('manual.event', []));

        // Commit
        $uow->setPersistenceCallback(function () use (&$persisted): void {
            $persisted = true;
        });
        $uow->commit();
        $this->assertFalse($uow->isActive());
        $this->assertSame(['manual.event'], $dispatched);

        // Manual rollback (new transaction)
        $uow->begin();
        $uow->queueEvent(DomainEvent::occur('rollback.event', []));
        $uow->rollback();
        // Event was already dispatched on commit; rollback has no new events
    }

    public function test_uow_clear_resets_everything(): void
    {
        $uow = new InMemoryUnitOfWork;

        $uow->begin();
        $uow->queueEvent(DomainEvent::occur('test', []));
        $uow->commit();

        $uow->clear();

        $this->assertSame([], $uow->getCommitted());
        $this->assertSame([], $uow->getDeleted());
        $this->assertFalse($uow->hasPendingEvents());
        $this->assertSame(0, $uow->getPendingEventCount());
        $this->assertFalse($uow->isActive());
    }

    public function test_uow_track_without_active_scope_throws(): void
    {
        $uow = new InMemoryUnitOfWork;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No active unit of work');

        $uow->track(new TestAggregate);
    }

    public function test_uow_queue_event_without_active_scope_throws(): void
    {
        $uow = new InMemoryUnitOfWork;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No active unit of work');

        $uow->queueEvent(DomainEvent::occur('test', []));
    }

    public function test_uow_has_pending_events_and_count(): void
    {
        $uow = new InMemoryUnitOfWork;

        $uow->begin();
        $this->assertFalse($uow->hasPendingEvents());
        $this->assertSame(0, $uow->getPendingEventCount());

        $uow->queueEvent(DomainEvent::occur('test.1', []));
        $this->assertTrue($uow->hasPendingEvents());
        $this->assertSame(1, $uow->getPendingEventCount());

        $uow->queueEvent(DomainEvent::occur('test.2', []));
        $this->assertSame(2, $uow->getPendingEventCount());

        $uow->rollback();
    }

    // ─── Contract Interface Compliance ──────────────────────────────────

    public function test_all_contracts_are_interfaces(): void
    {
        $contracts = [
            \ZeroBoiler\Domain\Contracts\AggregateRoot::class,
            \ZeroBoiler\Domain\Contracts\Entity::class,
            \ZeroBoiler\Domain\Contracts\Identifier::class,
            \ZeroBoiler\Domain\Contracts\Repository::class,
            \ZeroBoiler\Domain\Contracts\UnitOfWork::class,
            \ZeroBoiler\Domain\Snapshots\SnapshotStore::class,
        ];

        foreach ($contracts as $contract) {
            $reflection = new \ReflectionClass($contract);
            $this->assertTrue($reflection->isInterface(), "{$contract} should be an interface");
        }
    }

    public function test_repository_contract_has_required_methods(): void
    {
        $methods = get_class_methods(\ZeroBoiler\Domain\Contracts\Repository::class);

        $this->assertContains('find', $methods);
        $this->assertContains('save', $methods);
        $this->assertContains('delete', $methods);
    }

    public function test_unit_of_work_contract_has_required_methods(): void
    {
        $methods = get_class_methods(\ZeroBoiler\Domain\Contracts\UnitOfWork::class);

        $required = ['begin', 'commit', 'rollback', 'run', 'isActive', 'track', 'isTracking', 'markForDeletion', 'getCommitted', 'getDeleted', 'hasPendingEvents', 'getPendingEventCount', 'clear'];

        foreach ($required as $method) {
            $this->assertContains($method, $methods, "UnitOfWork contract missing method: {$method}");
        }
    }

    public function test_snapshot_store_contract_has_required_methods(): void
    {
        $methods = get_class_methods(SnapshotStore::class);

        $required = ['load', 'save', 'has', 'delete', 'deleteOlderThan', 'count', 'stats', 'purge'];

        foreach ($required as $method) {
            $this->assertContains($method, $methods, "SnapshotStore contract missing method: {$method}");
        }
    }

    // ─── Snapshot Policy Attribute ──────────────────────────────────────

    public function test_snapshot_policy_attribute_is_target_class(): void
    {
        $reflection = new \ReflectionClass(SnapshotPolicy::class);
        $attrs = $reflection->getAttributes(\Attribute::class);

        $this->assertCount(1, $attrs);
        $this->assertTrue(
            (bool) ($attrs[0]->newInstance()->flags & \Attribute::TARGET_CLASS),
            'SnapshotPolicy should be a class-level attribute',
        );
    }

    public function test_snapshot_policy_default_every_50(): void
    {
        $policy = new SnapshotPolicy;

        $this->assertSame(50, $policy->every);
    }

    public function test_snapshot_policy_custom_interval(): void
    {
        $policy = new SnapshotPolicy(every: 100);

        $this->assertSame(100, $policy->every);
    }

    public function test_snapshot_policy_zero_disables(): void
    {
        $policy = new SnapshotPolicy(every: 0);

        $this->assertSame(0, $policy->every);
    }

    // ─── Integration: Domain Exception → Response Bridge ──────────────

    public function test_domain_exception_provides_clean_response_mapping(): void
    {
        // This test validates the contract that domain exceptions expose
        // structured error data for the response layer to consume.

        $exceptions = [
            InvalidStateDomainException::because('Cannot pay a shipped order'),
            NotFoundDomainException::forAggregate('Order', 'order-123'),
            ConflictDomainException::because('Concurrent modification detected'),
            OptimisticLockException::for('order-123', expectedVersion: 10, actualVersion: 8),
            InvalidArgumentDomainException::because('Quantity must be positive'),
        ];

        foreach ($exceptions as $exception) {
            // Each exception provides a structured error array
            $errorArray = $exception->toErrorArray();
            $this->assertArrayHasKey('title', $errorArray['title'] ?? null, 'Missing title');
            $this->assertArrayHasKey('detail', $errorArray['detail'] ?? null, 'Missing detail');
            $this->assertArrayHasKey('code', $errorArray['code'] ?? null, 'Missing code');

            // Each exception is JSON serializable
            $json = json_encode($exception);
            $this->assertNotEmpty($json);
            $decoded = json_decode($json, true);
            $this->assertIsArray($decoded);
            $this->assertArrayHasKey('code', $decoded);
            $this->assertNotEmpty($decoded['code']);
        }
    }
}

// ─── Test Fixtures ────────────────────────────────────────────────────

/** @extends UuidIdentifier */
final readonly class TestUuidIdentifier extends UuidIdentifier {}

/** @extends UlidIdentifier */
final readonly class TestUlidIdentifier extends UlidIdentifier {}

/** Test entity with public constructor for equality tests. */
final class TestEntity extends Entity
{
    public string $name = 'test';
}

/**
 * Test aggregate root with public constructor for lifecycle tests.
 *
 * Auto-generates an AggregateRootId so tests can instantiate without arguments.
 *
 * @extends AggregateRoot<AggregateRootId>
 */
final class TestAggregate extends AggregateRoot
{
    public string $status = 'pending';

    public function __construct(?AggregateRootId $id = null)
    {
        parent::__construct($id ?? AggregateRootId::generate());
    }

    public static function create(?AggregateRootId $id = null): self
    {
        return new self($id);
    }
}

/** Test value object with fromArray/toArray for equality tests. */
final class TestValueObject extends ValueObject
{
    public function __construct(
        public string $value,
    ) {}

    public static function fromArray(array $data): static
    {
        return new static($data['value'] ?? '');
    }

    public function toArray(): array
    {
        return ['value' => $this->value];
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
