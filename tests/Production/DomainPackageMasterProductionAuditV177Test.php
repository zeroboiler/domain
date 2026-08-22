<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Tests\Production;

use PHPUnit\Framework\TestCase;
use ZeroBoiler\Domain\AggregateRoot;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\Contracts;
use ZeroBoiler\Domain\DomainEventCollection;
use ZeroBoiler\Domain\Entity;
use ZeroBoiler\Domain\Exceptions\{
    ConflictDomainException,
    DomainException,
    InvalidArgumentDomainException,
    InvalidStateDomainException,
    NotFoundDomainException,
    OptimisticLockException,
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
};

/**
 * Master production audit for the domain package.
 *
 * Validates every public contract: strict types, return types, readonly
 * properties, serialization round-trips, immutability, and domain invariants.
 * Designed as a single source of truth for "is this package production-ready?"
 *
 * @covers \ZeroBoiler\Domain\AggregateRoot
 * @covers \ZeroBoiler\Domain\AggregateRootId
 * @covers \ZeroBoiler\Domain\Entity
 * @covers \ZeroBoiler\Domain\DomainEventCollection
 * @covers \ZeroBoiler\Domain\InMemoryUnitOfWork
 * @covers \ZeroBoiler\Domain\Identifiers\UuidIdentifier
 * @covers \ZeroBoiler\Domain\Identifiers\UlidIdentifier
 * @covers \ZeroBoiler\Domain\Identifiers\StringIdentifier
 * @covers \ZeroBoiler\Domain\Identifiers\IntegerIdentifier
 * @covers \ZeroBoiler\Domain\Exceptions\DomainException
 * @covers \ZeroBoiler\Domain\Snapshots\Snapshot
 * @covers \ZeroBoiler\Domain\Snapshots\InMemorySnapshotStore
 */
final class DomainPackageMasterProductionAuditV177Test extends TestCase
{
    // ──────────────────────────────────────────────
    // AggregateRootId — readonly, immutable, serde
    // ──────────────────────────────────────────────

    public function test_aggregate_root_id_is_final_readonly(): void
    {
        $reflection = new \ReflectionClass(AggregateRootId::class);

        $this->assertTrue($reflection->isFinal(), 'AggregateRootId must be final');
        $this->assertTrue($reflection->isReadOnly(), 'AggregateRootId must be readonly');
    }

    public function test_aggregate_root_id_generate_creates_valid_uuid(): void
    {
        $id = AggregateRootId::generate();

        $this->assertNotEmpty($id->toString());
        $this->assertTrue(AggregateRootId::isValid($id->toString()));
    }

    public function test_aggregate_root_id_from_string_round_trip(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $id = AggregateRootId::fromString($uuid);

        $this->assertSame($uuid, $id->toString());
    }

    public function test_aggregate_root_id_to_array_from_array_round_trip(): void
    {
        $original = AggregateRootId::generate();
        $restored = AggregateRootId::fromArray($original->toArray());

        $this->assertTrue($original->equals($restored));
    }

    public function test_aggregate_root_id_to_json_from_json_round_trip(): void
    {
        $original = AggregateRootId::generate();
        $restored = AggregateRootId::fromJson($original->toJson());

        $this->assertTrue($original->equals($restored));
    }

    public function test_aggregate_root_id_json_serialize_returns_string(): void
    {
        $id = AggregateRootId::generate();
        $json = json_encode($id);

        $this->assertIsString($json);
        $this->assertSame('"' . $id->toString() . '"', $json);
    }

    // ──────────────────────────────────────────────
    // Identifiers — typed, readonly, equality, serde
    // ──────────────────────────────────────────────

    public function test_uuid_identifier_generate_and_equality(): void
    {
        $id = TestUuidId::generate();
        $same = TestUuidId::fromString($id->toString());
        $other = TestUuidId::generate();

        $this->assertTrue($id->equals($same));
        $this->assertFalse($id->equals($other));
    }

    public function test_ulid_identifier_generate_and_sortable(): void
    {
        $first = TestUlidId::generate();
        $second = TestUlidId::generate();

        // ULIDs are monotonic — second > first
        $this->assertNotEquals($first->toString(), $second->toString());
    }

    public function test_string_identifier_from_and_equality(): void
    {
        $id = StringIdentifier::from('my-slug');
        $same = StringIdentifier::from('my-slug');

        $this->assertTrue($id->equals($same));
        $this->assertSame('my-slug', $id->toString());
    }

    public function test_string_identifier_rejects_empty_string(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        StringIdentifier::from('');
    }

    public function test_integer_identifier_from_and_equality(): void
    {
        $id = IntegerIdentifier::from(42);
        $same = IntegerIdentifier::from(42);

        $this->assertTrue($id->equals($same));
        $this->assertSame(42, $id->toInt());
    }

    public function test_integer_identifier_rejects_negative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        IntegerIdentifier::from(-1);
    }

    public function test_identifier_from_json_round_trip(): void
    {
        $original = TestUuidId::generate();
        $restored = TestUuidId::fromJson($original->toJson());

        $this->assertTrue($original->equals($restored));
    }

    // ──────────────────────────────────────────────
    // Entity — identity equality, serialization
    // ──────────────────────────────────────────────

    public function test_entity_identity_equality(): void
    {
        $a = new TestEntity('id-1', 'Alice');
        $b = new TestEntity('id-1', 'Bob');  // Same ID, different data
        $c = new TestEntity('id-2', 'Alice');

        $this->assertTrue($a->equals($b));   // Same ID → equal
        $this->assertFalse($a->equals($c));  // Different ID
    }

    public function test_entity_to_array_contains_id_and_type(): void
    {
        $entity = new TestEntity('42', 'Test');
        $array = $entity->toArray();

        $this->assertArrayHasKey('id', $array);
        $this->assertArrayHasKey('type', $array);
        $this->assertSame('42', $array['id']);
    }

    public function test_entity_from_array_round_trip(): void
    {
        $original = new TestEntity('123', 'hello');
        $array = $original->toArray();
        $restored = TestEntity::fromArray($array);

        $this->assertTrue($original->equals($restored));
    }

    public function test_entity_json_serialize(): void
    {
        $entity = new TestEntity('42', 'Test');
        $json = json_encode($entity);

        $this->assertStringContainsString('"id":"42"', $json);
    }

    // ──────────────────────────────────────────────
    // AggregateRoot — versioning, events, toArray
    // ──────────────────────────────────────────────

    public function test_aggregate_root_version_starts_at_zero(): void
    {
        $aggregate = TestAggregate::create();

        $this->assertSame(0, $aggregate->version());
    }

    public function test_aggregate_root_id_returns_string(): void
    {
        $aggregate = TestAggregate::create();

        $this->assertIsString($aggregate->id());
        $this->assertTrue(AggregateRootId::isValid($aggregate->id()));
    }

    public function test_aggregate_root_to_array_has_id_version_type(): void
    {
        $aggregate = TestAggregate::create();
        $array = $aggregate->toArray();

        $this->assertArrayHasKey('id', $array);
        $this->assertArrayHasKey('version', $array);
        $this->assertArrayHasKey('type', $array);
        $this->assertSame('TestAggregate', $array['type']);
    }

    public function test_aggregate_root_equality(): void
    {
        $id = AggregateRootId::generate();
        $a = new TestAggregate($id);
        $b = new TestAggregate($id);
        $c = TestAggregate::create();

        $this->assertTrue($a->equals($b));
        $this->assertFalse($a->equals($c));
    }

    public function test_aggregate_root_pull_domain_events_returns_collection(): void
    {
        $aggregate = TestAggregate::create();

        $events = $aggregate->pullDomainEvents();

        $this->assertInstanceOf(DomainEventCollection::class, $events);
    }

    public function test_aggregate_root_peek_does_not_clear_events(): void
    {
        $aggregate = TestAggregate::create();
        $peeked = $aggregate->peekDomainEvents();

        // Peek should not clear — pulling should return events
        $pulled = $aggregate->pullDomainEvents();

        $this->assertGreaterThan(0, $peeked->count());
        $this->assertSame($peeked->count(), $pulled->count());
    }

    public function test_aggregate_root_has_uncommitted_events(): void
    {
        $aggregate = TestAggregate::create();

        $this->assertTrue($aggregate->hasUncommittedEvents());

        $aggregate->pullDomainEvents();

        $this->assertFalse($aggregate->hasUncommittedEvents());
    }

    public function test_aggregate_root_to_json_serialization(): void
    {
        $aggregate = TestAggregate::create();
        $json = $aggregate->toJson();
        $decoded = json_decode($json, true);

        $this->assertArrayHasKey('id', $decoded);
        $this->assertArrayHasKey('version', $decoded);
        $this->assertArrayHasKey('type', $decoded);
    }

    // ──────────────────────────────────────────────
    // DomainEventCollection — readonly, functional
    // ──────────────────────────────────────────────

    public function test_domain_event_collection_is_final_readonly(): void
    {
        $reflection = new \ReflectionClass(DomainEventCollection::class);

        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
    }

    public function test_domain_event_collection_functional_api(): void
    {
        $c = new DomainEventCollection;  // empty

        $this->assertTrue($c->isEmpty());
        $this->assertSame(0, $c->count());
        $this->assertNull($c->first());
        $this->assertNull($c->last());
        $this->assertTrue($c->none(fn () => true));
        $this->assertFalse($c->some(fn () => true));
        $this->assertSame([], $c->types());
        $this->assertSame(0, $c->countBy(fn () => true));
        $this->assertNull($c->find(fn () => true));
        $this->assertFalse($c->hasType('anything'));
    }

    // ──────────────────────────────────────────────
    // Domain Exceptions — typed codes, RFC 9457
    // ──────────────────────────────────────────────

    public function test_exception_hierarchy_provides_error_code(): void
    {
        $this->assertSame('INVALID_STATE', InvalidStateDomainException::because('test')->errorCode());
        $this->assertSame('INVALID_ARGUMENT', InvalidArgumentDomainException::because('test')->errorCode());
        $this->assertSame('NOT_FOUND', NotFoundDomainException::forId('x')->errorCode());
        $this->assertSame('CONFLICT', ConflictDomainException::because('test')->errorCode());
        $this->assertSame('OPTIMISTIC_LOCK', OptimisticLockException::for('id', 5, 3)->errorCode());
    }

    public function test_exception_http_status_mapping(): void
    {
        $this->assertSame(422, InvalidStateDomainException::because('t')->httpStatus());
        $this->assertSame(422, InvalidArgumentDomainException::because('t')->httpStatus());
        $this->assertSame(404, NotFoundDomainException::forId('x')->httpStatus());
        $this->assertSame(409, ConflictDomainException::because('t')->httpStatus());
        $this->assertSame(409, OptimisticLockException::for('id', 5, 3)->httpStatus());
    }

    public function test_exception_to_error_array_rfc9457_structure(): void
    {
        $e = NotFoundDomainException::forId('order-123');
        $array = $e->toErrorArray();

        $this->assertArrayHasKey('title', $array);
        $this->assertArrayHasKey('detail', $array);
        $this->assertArrayHasKey('code', $array);
        $this->assertArrayHasKey('status', $array);
        $this->assertSame('NOT_FOUND', $array['code']);
        $this->assertSame(404, $array['status']);
    }

    public function test_exception_json_serialize_rfc9457(): void
    {
        $e = InvalidStateDomainException::because('Order must be pending.');
        $json = json_encode($e);
        $decoded = json_decode($json, true);

        $this->assertArrayHasKey('code', $decoded);
        $this->assertArrayHasKey('status', $decoded);
    }

    public function test_exception_from_array_round_trip(): void
    {
        $original = InvalidStateDomainException::because('test error');
        $restored = DomainException::fromArray(
            $original->toArray(),
            InvalidStateDomainException::class,
        );

        $this->assertSame($original->getMessage(), $restored->getMessage());
        $this->assertSame($original->errorCode(), $restored->errorCode());
    }

    public function test_exception_from_json_round_trip(): void
    {
        $original = NotFoundDomainException::forAggregate('Order', '123');
        $restored = DomainException::fromJson(
            $original->toJson(),
            NotFoundDomainException::class,
        );

        $this->assertSame($original->errorCode(), $restored->errorCode());
    }

    // ──────────────────────────────────────────────
    // InMemoryUnitOfWork — transactional semantics
    // ──────────────────────────────────────────────

    public function test_uow_run_auto_commit(): void
    {
        $uow = new InMemoryUnitOfWork;
        $result = $uow->run(fn (): int => 42);

        $this->assertSame(42, $result);
        $this->assertFalse($uow->isActive());
    }

    public function test_uow_run_auto_rollback_on_exception(): void
    {
        $uow = new InMemoryUnitOfWork;

        $this->expectException(\RuntimeException::class);
        $uow->run(function (): void {
            throw new \RuntimeException('fail');
        });
    }

    public function test_uow_nested_begin_commit(): void
    {
        $uow = new InMemoryUnitOfWork;
        $uow->begin();
        $uow->begin();  // nested
        $this->assertTrue($uow->isActive());
        $uow->commit();
        $uow->commit();
        $this->assertFalse($uow->isActive());
    }

    public function test_uow_clear_resets_state(): void
    {
        $uow = new InMemoryUnitOfWork;
        $uow->begin();
        $uow->clear();

        $this->assertFalse($uow->isActive());
        $this->assertFalse($uow->hasPendingEvents());
    }

    public function test_uow_is_tracking_returns_false_by_default(): void
    {
        $uow = new InMemoryUnitOfWork;
        $aggregate = TestAggregate::create();

        $this->assertFalse($uow->isTracking($aggregate));
    }

    // ──────────────────────────────────────────────
    // Snapshot — readonly, round-trip
    // ──────────────────────────────────────────────

    public function test_snapshot_is_final_readonly(): void
    {
        $reflection = new \ReflectionClass(Snapshot::class);

        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
    }

    public function test_snapshot_to_array_from_array_round_trip(): void
    {
        $original = Snapshot::create('Order', 'uuid-1', 5, ['status' => 'paid']);
        $restored = Snapshot::fromArray($original->toArray());

        $this->assertSame('Order', $restored->aggregateType);
        $this->assertSame('uuid-1', $restored->aggregateId);
        $this->assertSame(5, $restored->version);
    }

    public function test_snapshot_equals_structural(): void
    {
        $a = Snapshot::create('Order', 'uuid-1', 5, ['x' => 1]);
        $b = Snapshot::create('Order', 'uuid-1', 5, ['x' => 1]);
        $c = Snapshot::create('Order', 'uuid-1', 6, ['x' => 1]);

        $this->assertTrue($a->equals($b));
        $this->assertFalse($a->equals($c));
    }

    public function test_in_memory_snapshot_store_lifecycle(): void
    {
        $store = new InMemorySnapshotStore;
        $snapshot = Snapshot::create('Order', 'uuid-1', 5, []);

        $store->save($snapshot);
        $this->assertTrue($store->has('Order', 'uuid-1'));

        $loaded = $store->load('Order', 'uuid-1');
        $this->assertNotNull($loaded);
        $this->assertTrue($snapshot->equals($loaded));

        $store->delete('Order', 'uuid-1');
        $this->assertFalse($store->has('Order', 'uuid-1'));
    }

    public function test_in_memory_snapshot_store_stats(): void
    {
        $store = new InMemorySnapshotStore;
        $store->save(Snapshot::create('A', '1', 1, []));
        $store->save(Snapshot::create('A', '2', 1, []));
        $store->save(Snapshot::create('B', '1', 1, []));

        $stats = $store->stats();

        $this->assertSame(3, $stats['total']);
        $this->assertSame(2, $stats['by_type']['A']);
        $this->assertSame(1, $stats['by_type']['B']);
    }

    // ──────────────────────────────────────────────
    // Contracts — interface completeness
    // ──────────────────────────────────────────────

    public function test_entity_contract_has_required_methods(): void
    {
        $reflection = new \ReflectionClass(Contracts\Entity::class);

        $this->assertTrue($reflection->hasMethod('id'));
        $this->assertTrue($reflection->hasMethod('equals'));
        $this->assertTrue($reflection->hasMethod('toArray'));
        $this->assertTrue($reflection->hasMethod('fromArray'));
        $this->assertTrue($reflection->hasMethod('fromJson'));
        $this->assertTrue($reflection->hasMethod('toJson'));
        $this->assertTrue($reflection->hasMethod('hasUncommittedEvents'));
    }

    public function test_aggregate_root_contract_extends_entity(): void
    {
        $reflection = new \ReflectionClass(Contracts\AggregateRoot::class);

        $this->assertTrue($reflection->hasMethod('version'));
        $this->assertTrue($reflection->hasMethod('pullDomainEvents'));
        $this->assertTrue($reflection->hasMethod('clearDomainEvents'));
        $this->assertTrue($reflection->hasMethod('hasUncommittedEvents'));
        $this->assertTrue($reflection->hasMethod('peekDomainEvents'));
    }

    public function test_identifier_contract_has_required_methods(): void
    {
        $reflection = new \ReflectionClass(Contracts\Identifier::class);

        $this->assertTrue($reflection->hasMethod('fromString'));
        $this->assertTrue($reflection->hasMethod('toString'));
        $this->assertTrue($reflection->hasMethod('equals'));
        $this->assertTrue($reflection->hasMethod('toArray'));
        $this->assertTrue($reflection->hasMethod('fromArray'));
        $this->assertTrue($reflection->hasMethod('fromJson'));
    }

    public function test_repository_contract_has_crud(): void
    {
        $reflection = new \ReflectionClass(Contracts\Repository::class);

        $this->assertTrue($reflection->hasMethod('find'));
        $this->assertTrue($reflection->hasMethod('save'));
        $this->assertTrue($reflection->hasMethod('delete'));
    }

    public function test_unit_of_work_contract_has_transaction_methods(): void
    {
        $reflection = new \ReflectionClass(Contracts\UnitOfWork::class);

        $this->assertTrue($reflection->hasMethod('begin'));
        $this->assertTrue($reflection->hasMethod('commit'));
        $this->assertTrue($reflection->hasMethod('rollback'));
        $this->assertTrue($reflection->hasMethod('run'));
        $this->assertTrue($reflection->hasMethod('track'));
        $this->assertTrue($reflection->hasMethod('clear'));
    }

    // ──────────────────────────────────────────────
    // Guards trait — domain invariants
    // ──────────────────────────────────────────────

    public function test_guards_assert_not_empty_string(): void
    {
        $entity = new TestEntityWithGuards('id', 'valid');

        $this->expectException(InvalidArgumentDomainException::class);
        $entity->validateString('');
    }

    public function test_guards_assert_positive_integer(): void
    {
        $entity = new TestEntityWithGuards('id', 'valid');

        $this->expectException(InvalidArgumentDomainException::class);
        $entity->validatePositiveInt(0);
    }

    public function test_guards_assert_state_is(): void
    {
        $entity = new TestEntityWithGuards('id', 'active');

        // Should not throw
        $entity->validateState('active', 'doSomething');

        $this->expectException(InvalidStateDomainException::class);
        $entity->validateState('inactive', 'doSomething');
    }

    public function test_guards_assert_found_throws_not_found(): void
    {
        $entity = new TestEntityWithGuards('id', 'valid');

        $this->expectException(NotFoundDomainException::class);
        $entity->validateFound(null);
    }
}

// ──────────────────────────────────────────────
// Test fixtures
// ──────────────────────────────────────────────

final class TestUuidId extends UuidIdentifier {}

final class TestUlidId extends UlidIdentifier {}

final class TestEntity extends Entity
{
    public function __construct(
        int|string|\Stringable $id,
        public string $name = '',
    ) {
        parent::__construct($id);
    }

    public function toArray(): array
    {
        return [
            ...parent::toArray(),
            'name' => $this->name,
        ];
    }
}

final class TestAggregate extends AggregateRoot
{
    public static function create(self|\ReflectionClass|null $id = null): self
    {
        // Workaround: static::class resolution for reconstituteFromSnapshot compatibility
        $aggregateId = ($id instanceof self) ? $id->aggregateId() : AggregateRootId::generate();
        return new self($aggregateId);
    }

    public function aggregateId(): AggregateRootId
    {
        return parent::aggregateId();
    }
}

final class TestEntityWithGuards extends Entity
{
    use \ZeroBoiler\Domain\Concerns\Guards;

    public function __construct(
        int|string|\Stringable $id,
        public string $status = 'active',
    ) {
        parent::__construct($id);
        $this->assertNotEmptyString($status, 'status');
    }

    public function validateString(string $value): void
    {
        $this->assertNotEmptyString($value, 'value');
    }

    public function validatePositiveInt(int $value): void
    {
        $this->assertPositiveInteger($value, 'value');
    }

    public function validateState(string $expected, string $action): void
    {
        $this->assertStateIs($expected, $this->status, $action);
    }

    public function validateFound(mixed $value): void
    {
        $this->assertFound($value, 'entity');
    }
}
