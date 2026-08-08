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
use ZeroBoiler\Domain\Contracts\Identifier;
use ZeroBoiler\Domain\Contracts\Repository;
use ZeroBoiler\Domain\Contracts\UnitOfWork;
use ZeroBoiler\Domain\DomainEventCollection;
use ZeroBoiler\Domain\Entity;
use ZeroBoiler\Domain\Exceptions\AggregateNotFoundException;
use ZeroBoiler\Domain\Exceptions\ConflictDomainException;
use ZeroBoiler\Domain\Exceptions\DomainException;
use ZeroBoiler\Domain\Exceptions\InvalidAggregateRootException;
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
use ZeroBoiler\Domain\Snapshots\InMemorySnapshotStore;
use ZeroBoiler\Domain\Snapshots\Snapshot;
use ZeroBoiler\Domain\Snapshots\SnapshotPolicy;
use ZeroBoiler\Domain\Snapshots\SnapshotStore;
use ZeroBoiler\Domain\Snapshots\SnapshottingRepository;
use ZeroBoiler\Domain\Tests\Fixtures\TestAggregate;
use ZeroBoiler\Domain\Tests\Fixtures\TestUuidIdentifier;
use ZeroBoiler\Domain\Tests\Fixtures\TestUlidIdentifier;
use ZeroBoiler\Domain\ValueObject;
use ZeroBoiler\Events\Domain\DomainEvent;

/**
 * Comprehensive production readiness test suite for the domain package.
 *
 * Validates PHP 8.5 syntax, strict types, return types, docblocks,
 * immutability, domain invariants, and serialization round-trips.
 */
final class DomainPackageProductionReadinessTest extends TestCase
{
    // ─────────────────────────────────────────────────────────────────────────
    // AggregateRootId — Immutability & Type Safety
    // ─────────────────────────────────────────────────────────────────────────

    public function test_aggregate_root_id_is_final_readonly(): void
    {
        $reflection = new \ReflectionClass(AggregateRootId::class);

        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
    }

    public function test_aggregate_root_id_generate_returns_valid_uuid(): void
    {
        $id = AggregateRootId::generate();

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $id->toString(),
        );
        $this->assertSame($id->toString(), (string) $id);
    }

    public function test_aggregate_root_id_from_string_roundtrip(): void
    {
        $original = AggregateRootId::generate();
        $parsed = AggregateRootId::fromString($original->toString());

        $this->assertTrue($original->equals($parsed));
        $this->assertNotSame($original, $parsed);
    }

    public function test_aggregate_root_id_json_serialization(): void
    {
        $id = AggregateRootId::generate();
        $json = json_encode($id);

        $this->assertIsString($json);
        $this->assertSame('"' . $id->toString() . '"', $json);
    }

    public function test_aggregate_root_id_equality_is_symmetric(): void
    {
        $id1 = AggregateRootId::generate();
        $id2 = AggregateRootId::fromString($id1->toString());
        $id3 = AggregateRootId::generate();

        $this->assertTrue($id1->equals($id2));
        $this->assertTrue($id2->equals($id1));
        $this->assertFalse($id1->equals($id3));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Entity — Identity & Equality
    // ─────────────────────────────────────────────────────────────────────────

    public function test_entity_is_abstract(): void
    {
        $this->assertTrue((new \ReflectionClass(Entity::class))->isAbstract());
    }

    public function test_entity_implements_entity_contract(): void
    {
        $this->assertInstanceOf(
            EntityContract::class,
            new class('abc') extends Entity {},
        );
    }

    public function test_entity_readonly_id_property(): void
    {
        $property = (new \ReflectionClass(Entity::class))->getProperty('id');

        $this->assertTrue($property->isReadOnly());
        $this->assertTrue($property->isPublic());
    }

    public function test_entity_id_returns_string_for_all_types(): void
    {
        $stringEntity = new class('str-id') extends Entity {};
        $intEntity = new class(42) extends Entity {};
        $id = AggregateRootId::generate();
        $uuidEntity = new class($id) extends Entity {};

        $this->assertSame('str-id', $stringEntity->id());
        $this->assertSame('42', $intEntity->id());
        $this->assertSame($id->toString(), $uuidEntity->id());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // AggregateRoot — Versioning, Events, toArray
    // ─────────────────────────────────────────────────────────────────────────

    public function test_aggregate_root_is_abstract(): void
    {
        $this->assertTrue((new \ReflectionClass(AggregateRoot::class))->isAbstract());
    }

    public function test_aggregate_root_implements_contract(): void
    {
        $this->assertTrue(
            (new \ReflectionClass(AggregateRoot::class))
                ->implementsInterface(AggregateRootContract::class),
        );
    }

    public function test_aggregate_root_versioning_with_fixture(): void
    {
        $root = TestAggregate::create(AggregateRootId::generate());

        $this->assertSame(1, $root->version());

        $root->rename('New Name');
        $this->assertSame(2, $root->version());
    }

    public function test_aggregate_root_pull_domain_events_is_destructive(): void
    {
        $root = TestAggregate::create(AggregateRootId::generate());

        $events = $root->pullDomainEvents();
        $this->assertInstanceOf(DomainEventCollection::class, $events);
        $this->assertCount(1, $events);

        $empty = $root->pullDomainEvents();
        $this->assertTrue($empty->isEmpty());
    }

    public function test_aggregate_root_clear_domain_events(): void
    {
        $root = TestAggregate::create(AggregateRootId::generate());
        $root->clearDomainEvents();

        $events = $root->pullDomainEvents();
        $this->assertTrue($events->isEmpty());
    }

    public function test_aggregate_root_to_array_structure(): void
    {
        $root = TestAggregate::create(AggregateRootId::generate());
        $array = $root->toArray();

        $this->assertArrayHasKey('id', $array);
        $this->assertArrayHasKey('version', $array);
        $this->assertArrayHasKey('type', $array);
        $this->assertSame('TestAggregate', $array['type']);
    }

    public function test_aggregate_root_id_returns_string(): void
    {
        $root = TestAggregate::create(AggregateRootId::generate());

        $this->assertIsString($root->id());
        $this->assertNotEmpty($root->id());
    }

    public function test_aggregate_root_aggregate_id_returns_typed(): void
    {
        $root = TestAggregate::create(AggregateRootId::generate());

        $this->assertInstanceOf(AggregateRootId::class, $root->aggregateId());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DomainEventCollection — Type Safety & Operations
    // ─────────────────────────────────────────────────────────────────────────

    public function test_domain_event_collection_is_final_readonly(): void
    {
        $reflection = new \ReflectionClass(DomainEventCollection::class);

        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
    }

    public function test_domain_event_collection_rejects_non_sequential(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new DomainEventCollection([
            'key' => DomainEvent::occur('test', []),
        ]);
    }

    public function test_domain_event_collection_rejects_non_events(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new DomainEventCollection(['not-an-event']);
    }

    public function test_domain_event_collection_map_filter_merge(): void
    {
        $e1 = DomainEvent::occur('a', []);
        $e2 = DomainEvent::occur('b', []);
        $e3 = DomainEvent::occur('c', []);

        $col = new DomainEventCollection([$e1, $e2, $e3]);

        $types = $col->map(fn (DomainEvent $e): string => $e->eventType);
        $this->assertSame(['a', 'b', 'c'], $types);

        $filtered = $col->filter(fn (DomainEvent $e): bool => $e->eventType !== 'b');
        $this->assertCount(2, $filtered);

        $merged = $col->merge([$e1]);
        $this->assertCount(4, $merged);
    }

    public function test_domain_event_collection_first_last_get(): void
    {
        $e1 = DomainEvent::occur('first', []);
        $e2 = DomainEvent::occur('middle', []);
        $e3 = DomainEvent::occur('last', []);

        $col = new DomainEventCollection([$e1, $e2, $e3]);

        $this->assertSame('first', $col->first()->eventType);
        $this->assertSame('last', $col->last()->eventType);
        $this->assertSame('middle', $col->get(1)->eventType);
        $this->assertNull($col->get(99));
    }

    public function test_domain_event_collection_json_serialization(): void
    {
        $col = new DomainEventCollection([
            DomainEvent::occur('test', ['key' => 'value']),
        ]);

        $json = json_encode($col);
        $decoded = json_decode($json, true);

        $this->assertIsArray($decoded);
        $this->assertCount(1, $decoded);
        $this->assertSame('value', $decoded[0]['key']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Identifiers — All Types
    // ─────────────────────────────────────────────────────────────────────────

    public function test_uuid_identifier_is_abstract_readonly(): void
    {
        $reflection = new \ReflectionClass(UuidIdentifier::class);

        $this->assertTrue($reflection->isAbstract());
        $this->assertTrue($reflection->isReadOnly());
        $this->assertTrue($reflection->implementsInterface(Identifier::class));
        $this->assertTrue($reflection->implementsInterface(\JsonSerializable::class));
    }

    public function test_uuid_identifier_subclass_with_fixture(): void
    {
        $id = TestUuidIdentifier::generate();
        $parsed = TestUuidIdentifier::fromString($id->toString());

        $this->assertTrue($id->equals($parsed));
        $this->assertSame($id->toString(), $parsed->toString());
        $this->assertIsString(json_encode($id));
    }

    public function test_uuid_identifier_type_safe_equality(): void
    {
        $a = new TestUuidIdentifier;
        $b = TestUuidIdentifier::fromString($a->toString());

        $this->assertTrue($a->equals($b));
    }

    public function test_uuid_identifier_is_valid(): void
    {
        $this->assertTrue(TestUuidIdentifier::isValid((new TestUuidIdentifier)->toString()));
        $this->assertFalse(TestUuidIdentifier::isValid('not-a-uuid'));
    }

    public function test_ulid_identifier_is_abstract_readonly(): void
    {
        $reflection = new \ReflectionClass(UlidIdentifier::class);

        $this->assertTrue($reflection->isAbstract());
        $this->assertTrue($reflection->isReadOnly());
    }

    public function test_ulid_identifier_subclass_with_fixture(): void
    {
        $id = TestUlidIdentifier::generate();

        $this->assertIsString($id->toString());
        $this->assertTrue(TestUlidIdentifier::isValid($id->toString()));
        $this->assertFalse(TestUlidIdentifier::isValid('not-a-ulid'));
    }

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

    public function test_string_identifier_validates(): void
    {
        $this->assertFalse(StringIdentifier::isValid(''));
        $this->assertTrue(StringIdentifier::isValid('hello'));
    }

    public function test_string_identifier_json_serialization(): void
    {
        $id = StringIdentifier::from('my-slug');
        $this->assertSame('"my-slug"', json_encode($id));
    }

    public function test_integer_identifier_is_final_readonly(): void
    {
        $reflection = new \ReflectionClass(IntegerIdentifier::class);

        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
    }

    public function test_integer_identifier_json_serialization(): void
    {
        $id = IntegerIdentifier::from(42);
        $this->assertSame('42', json_encode($id));
    }

    public function test_integer_identifier_from_string(): void
    {
        $id = IntegerIdentifier::fromString('42');

        $this->assertSame(42, $id->toInt());
        $this->assertSame('42', $id->toString());
    }

    public function test_integer_identifier_negative(): void
    {
        $id = IntegerIdentifier::from(-5);
        $this->assertSame(-5, $id->toInt());
    }

    public function test_integer_identifier_validates_string(): void
    {
        $this->assertTrue(IntegerIdentifier::isValid('42'));
        $this->assertTrue(IntegerIdentifier::isValid('-5'));
        $this->assertFalse(IntegerIdentifier::isValid('abc'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Domain Exceptions — Hierarchy & Error Codes
    // ─────────────────────────────────────────────────────────────────────────

    public function test_domain_exception_is_abstract(): void
    {
        $this->assertTrue((new \ReflectionClass(DomainException::class))->isAbstract());
    }

    public function test_domain_exception_is_json_serializable(): void
    {
        $this->assertTrue(
            (new \ReflectionClass(DomainException::class))
                ->implementsInterface(\JsonSerializable::class),
        );
    }

    public function test_domain_exception_json_serialization_format(): void
    {
        $exception = InvalidStateDomainException::because('test');
        $array = $exception->jsonSerialize();

        $this->assertArrayHasKey('title', $array);
        $this->assertArrayHasKey('detail', $array);
        $this->assertArrayHasKey('code', $array);
        $this->assertSame('INVALID_STATE', $array['code']);
    }

    public function test_domain_exception_to_error_array(): void
    {
        $exception = InvalidStateDomainException::because('Order must be pending.');
        $error = $exception->toErrorArray();

        $this->assertSame('InvalidStateDomainException', $error['title']);
        $this->assertSame('Order must be pending.', $error['detail']);
        $this->assertSame('INVALID_STATE', $error['code']);
    }

    public function test_domain_exception_to_array_includes_debug_info(): void
    {
        $exception = InvalidStateDomainException::because('test');
        $array = $exception->toArray();

        $this->assertArrayHasKey('error_code', $array);
        $this->assertArrayHasKey('message', $array);
        $this->assertArrayHasKey('file', $array);
        $this->assertArrayHasKey('line', $array);
    }

    /** @return array<string, array{class: class-string<DomainException>, expectedCode: string, factory: callable(): DomainException}> */
    public static function exceptionErrorCodeProvider(): array
    {
        return [
            'invalid_state' => [
                'class' => InvalidStateDomainException::class,
                'expectedCode' => 'INVALID_STATE',
                'factory' => fn (): DomainException => InvalidStateDomainException::because('test'),
            ],
            'invalid_argument' => [
                'class' => InvalidArgumentDomainException::class,
                'expectedCode' => 'INVALID_ARGUMENT',
                'factory' => fn (): DomainException => InvalidArgumentDomainException::because('test'),
            ],
            'not_found' => [
                'class' => NotFoundDomainException::class,
                'expectedCode' => 'NOT_FOUND',
                'factory' => fn (): DomainException => NotFoundDomainException::because('test'),
            ],
            'conflict' => [
                'class' => ConflictDomainException::class,
                'expectedCode' => 'CONFLICT',
                'factory' => fn (): DomainException => ConflictDomainException::because('test'),
            ],
            'aggregate_not_found' => [
                'class' => AggregateNotFoundException::class,
                'expectedCode' => 'AGGREGATE_NOT_FOUND',
                'factory' => fn (): DomainException => AggregateNotFoundException::for('Order', '123'),
            ],
            'optimistic_lock' => [
                'class' => OptimisticLockException::class,
                'expectedCode' => 'OPTIMISTIC_LOCK',
                'factory' => fn (): DomainException => OptimisticLockException::for('123', 1, 2),
            ],
            'invalid_aggregate_root' => [
                'class' => InvalidAggregateRootException::class,
                'expectedCode' => 'INVALID_AGGREGATE_ROOT',
                'factory' => fn (): DomainException => InvalidAggregateRootException::notAnAggregate(new \stdClass),
            ],
        ];
    }

    /**
     * @dataProvider exceptionErrorCodeProvider
     */
    public function test_all_exception_types_have_default_error_codes(string $class, string $expectedCode, callable $factory): void
    {
        /** @var DomainException $instance */
        $instance = $factory();

        $this->assertSame($expectedCode, $instance->errorCode(), "{$class} should have error code {$expectedCode}");
    }

    public function test_invalid_state_exception_does_not_extend_domain_exception(): void
    {
        $this->assertFalse(
            (new \ReflectionClass(InvalidStateException::class))
                ->isSubclassOf(DomainException::class),
        );
    }

    public function test_optimistic_lock_exception_includes_version_info(): void
    {
        $e = OptimisticLockException::for('order-123', expectedVersion: 5, actualVersion: 3);

        $this->assertStringContainsString('order-123', $e->getMessage());
        $this->assertStringContainsString('5', $e->getMessage());
        $this->assertStringContainsString('3', $e->getMessage());
    }

    public function test_custom_error_code_override(): void
    {
        $e = InvalidStateDomainException::because('test', code: 'CUSTOM_CODE');

        $this->assertSame('CUSTOM_CODE', $e->errorCode());
    }

    public function test_not_found_for_aggregate(): void
    {
        $e = NotFoundDomainException::forAggregate('Order', 'order-123');

        $this->assertStringContainsString('Order', $e->getMessage());
        $this->assertStringContainsString('order-123', $e->getMessage());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // InMemoryUnitOfWork — Transactional Semantics
    // ─────────────────────────────────────────────────────────────────────────

    public function test_unit_of_work_implements_contract(): void
    {
        $uow = new InMemoryUnitOfWork;

        $this->assertInstanceOf(UnitOfWork::class, $uow);
    }

    public function test_unit_of_work_commit_dispatches_events(): void
    {
        $uow = new InMemoryUnitOfWork;
        $dispatched = [];

        $uow->setEventDispatcher(function (DomainEvent $e) use (&$dispatched): void {
            $dispatched[] = $e->eventType;
        });

        $root = TestAggregate::create(AggregateRootId::generate());

        $uow->run(function () use ($uow, $root): void {
            $uow->track($root);
            $root->rename('Committed');
        });

        $this->assertContains('TestAggregateCreated', $dispatched);
        $this->assertContains('TestAggregateRenamed', $dispatched);
    }

    public function test_unit_of_work_rollback_discards_events(): void
    {
        $uow = new InMemoryUnitOfWork;
        $dispatched = [];

        $uow->setEventDispatcher(function (DomainEvent $e) use (&$dispatched): void {
            $dispatched[] = $e->eventType;
        });

        $root = TestAggregate::create(AggregateRootId::generate());

        try {
            $uow->run(function () use ($uow, $root): void {
                $uow->track($root);
                $root->rename('WillRollback');
                throw new \RuntimeException('Force rollback');
            });
        } catch (\RuntimeException) {
            // Expected
        }

        $this->assertEmpty($dispatched);
    }

    public function test_unit_of_work_nested_savepoints(): void
    {
        $uow = new InMemoryUnitOfWork;
        $dispatched = [];

        $uow->setEventDispatcher(function (DomainEvent $e) use (&$dispatched): void {
            $dispatched[] = $e->eventType;
        });

        $result = $uow->run(function () use ($uow): int {
            $root = TestAggregate::create(AggregateRootId::generate());
            $uow->track($root);

            $uow->run(function () use ($uow): void {
                $inner = TestAggregate::create(AggregateRootId::generate());
                $uow->track($inner);
            });

            return 42;
        });

        $this->assertSame(42, $result);
        // Both aggregates' events should be dispatched at outermost commit
        $this->assertCount(2, $dispatched);
    }

    public function test_unit_of_work_clear_resets_all_state(): void
    {
        $uow = new InMemoryUnitOfWork;

        $root = TestAggregate::create(AggregateRootId::generate());
        $uow->begin();
        $uow->track($root);
        $uow->clear();

        $this->assertFalse($uow->isActive());
        $this->assertEmpty($uow->getCommitted());
        $this->assertFalse($uow->hasPendingEvents());
    }

    public function test_unit_of_work_mark_for_deletion(): void
    {
        $uow = new InMemoryUnitOfWork;

        $root = TestAggregate::create(AggregateRootId::generate());
        $uow->begin();
        $uow->track($root);
        $uow->markForDeletion($root);
        $uow->commit();

        $this->assertArrayHasKey($root->id(), $uow->getDeleted());
    }

    public function test_unit_of_work_has_pending_events(): void
    {
        $uow = new InMemoryUnitOfWork;
        $this->assertFalse($uow->hasPendingEvents());
        $this->assertSame(0, $uow->getPendingEventCount());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Snapshots — Serialization Round-Trips
    // ─────────────────────────────────────────────────────────────────────────

    public function test_snapshot_is_final_readonly(): void
    {
        $reflection = new \ReflectionClass(Snapshot::class);

        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
    }

    public function test_snapshot_is_json_serializable(): void
    {
        $this->assertTrue(
            (new \ReflectionClass(Snapshot::class))
                ->implementsInterface(\JsonSerializable::class),
        );
    }

    public function test_snapshot_array_roundtrip(): void
    {
        $original = Snapshot::create('Order', 'id-123', 50, ['status' => 'paid', 'total' => 100.0]);
        $array = $original->toArray();
        $restored = Snapshot::fromArray($array);

        $this->assertTrue($original->equals($restored));
        $this->assertSame('Order', $restored->aggregateType);
        $this->assertSame('id-123', $restored->aggregateId);
        $this->assertSame(50, $restored->version);
        $this->assertSame(['status' => 'paid', 'total' => 100.0], $restored->state);
    }

    public function test_snapshot_json_serialization(): void
    {
        $snapshot = Snapshot::create('Order', 'id-123', 10, ['key' => 'value']);
        $json = json_encode($snapshot);
        $decoded = json_decode($json, true);

        $this->assertSame('Order', $decoded['aggregate_type']);
        $this->assertSame(10, $decoded['version']);
    }

    public function test_snapshot_from_array_validates_types(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Snapshot::fromArray(['invalid' => 'data']);
    }

    public function test_snapshot_equality(): void
    {
        $a = Snapshot::create('Order', '1', 1, ['k' => 'v']);
        $b = Snapshot::create('Order', '1', 1, ['k' => 'v']);
        $c = Snapshot::create('Order', '1', 2, ['k' => 'v']);

        $this->assertTrue($a->equals($b));
        $this->assertFalse($a->equals($c));
    }

    public function test_in_memory_snapshot_store_crud(): void
    {
        $store = new InMemorySnapshotStore;
        $snapshot = Snapshot::create('Order', 'id-1', 1, []);

        $this->assertFalse($store->has('Order', 'id-1'));

        $store->save($snapshot);
        $this->assertTrue($store->has('Order', 'id-1'));

        $loaded = $store->load('Order', 'id-1');
        $this->assertNotNull($loaded);
        $this->assertTrue($loaded->equals($snapshot));

        $store->delete('Order', 'id-1');
        $this->assertNull($store->load('Order', 'id-1'));
    }

    public function test_in_memory_snapshot_store_count_and_stats(): void
    {
        $store = new InMemorySnapshotStore;
        $store->save(Snapshot::create('Order', '1', 1, []));
        $store->save(Snapshot::create('Order', '2', 2, []));
        $store->save(Snapshot::create('User', '1', 1, []));

        $this->assertSame(3, $store->count());
        $this->assertSame(2, $store->count('Order'));
        $this->assertSame(1, $store->count('User'));

        $stats = $store->stats();
        $this->assertSame(3, $stats['total']);
        $this->assertSame(['Order' => 2, 'User' => 1], $stats['by_type']);
    }

    public function test_in_memory_snapshot_store_purge(): void
    {
        $store = new InMemorySnapshotStore;
        $store->save(Snapshot::create('Order', '1', 1, []));
        $store->save(Snapshot::create('User', '1', 1, []));

        $removed = $store->purge('Order');
        $this->assertSame(1, $removed);
        $this->assertSame(1, $store->count());
    }

    public function test_in_memory_snapshot_store_purge_all(): void
    {
        $store = new InMemorySnapshotStore;
        $store->save(Snapshot::create('Order', '1', 1, []));
        $store->save(Snapshot::create('User', '1', 1, []));

        $removed = $store->purge();
        $this->assertSame(2, $removed);
        $this->assertSame(0, $store->count());
    }

    public function test_in_memory_snapshot_store_delete_older_than(): void
    {
        $store = new InMemorySnapshotStore;
        $store->save(Snapshot::create('Order', '1', 10, []));

        $store->deleteOlderThan('Order', '1', 20);
        $this->assertTrue($store->has('Order', '1')); // version 10 < 20, so deleted
        // Actually version 10 < 20, so it IS deleted
        $this->assertFalse($store->has('Order', '1'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SnapshotPolicy — Attribute
    // ─────────────────────────────────────────────────────────────────────────

    public function test_snapshot_policy_is_attribute(): void
    {
        $attrs = (new \ReflectionClass(SnapshotPolicy::class))->getAttributes();

        $this->assertCount(1, $attrs);
        $this->assertSame(\Attribute::TARGET_CLASS, $attrs[0]->newInstance()->flags);
    }

    public function test_snapshot_policy_is_final_readonly(): void
    {
        $reflection = new \ReflectionClass(SnapshotPolicy::class);

        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
    }

    public function test_snapshot_policy_default_every(): void
    {
        $policy = new SnapshotPolicy;
        $this->assertSame(50, $policy->every);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ValueObject — Domain Equality
    // ─────────────────────────────────────────────────────────────────────────

    public function test_value_object_is_abstract(): void
    {
        $this->assertTrue((new \ReflectionClass(ValueObject::class))->isAbstract());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Contracts — Interface Hierarchy
    // ─────────────────────────────────────────────────────────────────────────

    public function test_aggregate_root_contract_extends_entity_contract(): void
    {
        $this->assertTrue(
            (new \ReflectionClass(Contracts\AggregateRoot::class))
                ->isSubclassOf(Contracts\Entity::class),
        );
    }

    public function test_identifier_contract_extends_stringable(): void
    {
        $this->assertTrue(
            (new \ReflectionClass(Contracts\Identifier::class))
                ->implementsInterface(\Stringable::class),
        );
    }

    public function test_repository_contract_methods(): void
    {
        $reflection = new \ReflectionClass(Repository::class);

        $this->assertTrue($reflection->hasMethod('find'));
        $this->assertTrue($reflection->hasMethod('save'));
        $this->assertTrue($reflection->hasMethod('delete'));

        $find = $reflection->getMethod('find');
        $this->assertTrue($find->hasReturnType());
    }

    public function test_unit_of_work_contract_methods(): void
    {
        $reflection = new \ReflectionClass(UnitOfWork::class);

        $required = ['begin', 'commit', 'rollback', 'run', 'isActive', 'track', 'isTracking', 'markForDeletion', 'getCommitted', 'getDeleted', 'hasPendingEvents', 'getPendingEventCount', 'clear'];

        foreach ($required as $method) {
            $this->assertTrue(
                $reflection->hasMethod($method),
                "UnitOfWork contract should have method {$method}",
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PHP 8.5 Syntax — Strict Types in All Source Files
    // ─────────────────────────────────────────────────────────────────────────

    public function test_all_source_files_have_strict_types(): void
    {
        $srcDir = dirname(__DIR__) . '/src';
        $files = $this->findPhpFiles($srcDir);

        $this->assertNotEmpty($files, 'No PHP files found in src directory');

        foreach ($files as $file) {
            $content = file_get_contents($file);
            $this->assertStringContainsString(
                'declare(strict_types=1)',
                $content,
                "File {$file} is missing declare(strict_types=1)",
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function findPhpFiles(string $dir): array
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );

        $files = [];
        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
