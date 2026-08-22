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
use ZeroBoiler\Domain\Exceptions\InvalidAggregateRootException;
use ZeroBoiler\Domain\Exceptions\InvalidArgumentDomainException;
use ZeroBoiler\Domain\Exceptions\InvalidStateDomainException;
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
use ZeroBoiler\Domain\ValueObject;
use ZeroBoiler\Events\Domain\DomainEvent;

/**
 * Contract compliance tests — verifies every class correctly implements its interface contract.
 *
 * @internal Contract compliance verification suite.
 */
final class DomainContractComplianceTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Identifier Contract — all identifiers must implement IdentifierContract
    // -------------------------------------------------------------------------

    public function test_uuid_identifier_implements_identifier_contract(): void
    {
        $id = TestUuidId::generate();

        self::assertInstanceOf(IdentifierContract::class, $id);
        self::assertSame($id->toString(), TestUuidId::fromString($id->toString())->toString());
        self::assertTrue($id->equals(TestUuidId::fromString($id->toString())));
        self::assertTrue(UuidIdentifier::isValid($id->toString()));
        self::assertFalse(UuidIdentifier::isValid('not-a-uuid'));
    }

    public function test_ulid_identifier_implements_identifier_contract(): void
    {
        $id = TestUlidId::generate();

        self::assertInstanceOf(IdentifierContract::class, $id);
        self::assertSame($id->toString(), TestUlidId::fromString($id->toString())->toString());
        self::assertTrue($id->equals(TestUlidId::fromString($id->toString())));
        self::assertTrue(UlidIdentifier::isValid($id->toString()));
        self::assertFalse(UlidIdentifier::isValid('not-a-ulid'));
    }

    public function test_string_identifier_implements_identifier_contract(): void
    {
        $id = StringIdentifier::from('my-slug');

        self::assertInstanceOf(IdentifierContract::class, $id);
        self::assertSame('my-slug', $id->toString());
        self::assertTrue($id->equals(StringIdentifier::from('my-slug')));
        self::assertTrue(StringIdentifier::isValid('hello'));
        self::assertFalse(StringIdentifier::isValid(''));
    }

    public function test_integer_identifier_implements_identifier_contract(): void
    {
        $id = IntegerIdentifier::from(42);

        self::assertInstanceOf(IdentifierContract::class, $id);
        self::assertSame('42', $id->toString());
        self::assertSame(42, $id->toInt());
        self::assertTrue($id->equals(IntegerIdentifier::from(42)));
        self::assertFalse($id->equals(IntegerIdentifier::from(43)));
        self::assertTrue(IntegerIdentifier::isValid('42'));
        self::assertTrue(IntegerIdentifier::isValid('-5'));
        self::assertFalse(IntegerIdentifier::isValid('abc'));
    }

    public function test_cross_identifier_types_are_never_equal(): void
    {
        $uuid = TestUuidId::generate();
        $ulid = TestUlidId::generate();
        $string = StringIdentifier::from('test');
        $int = IntegerIdentifier::from(1);

        self::assertFalse($uuid->equals($ulid));
        self::assertFalse($uuid->equals($string));
        self::assertFalse($uuid->equals($int));
        self::assertFalse($ulid->equals($string));
        self::assertFalse($ulid->equals($int));
        self::assertFalse($string->equals($int));
    }

    public function test_identifiers_implement_stringable(): void
    {
        $uuid = TestUuidId::generate();
        $ulid = TestUlidId::generate();
        $string = StringIdentifier::from('slug');
        $int = IntegerIdentifier::from(99);

        self::assertInstanceOf(\Stringable::class, $uuid);
        self::assertInstanceOf(\Stringable::class, $ulid);
        self::assertInstanceOf(\Stringable::class, $string);
        self::assertInstanceOf(\Stringable::class, $int);
    }

    // -------------------------------------------------------------------------
    // Entity Contract — id(): string, equals(): bool
    // -------------------------------------------------------------------------

    public function test_entity_contract_id_returns_string(): void
    {
        $entity = new TestEntity('my-id');

        self::assertInstanceOf(EntityContract::class, $entity);
        self::assertSame('my-id', $entity->id());
    }

    public function test_entity_contract_equality(): void
    {
        $a = new TestEntity('same-id');
        $b = new TestEntity('same-id');
        $c = new TestEntity('other-id');

        self::assertTrue($a->equals($b));
        self::assertFalse($a->equals($c));
    }

    public function test_entity_supports_int_id(): void
    {
        $entity = new TestEntity(42);

        self::assertSame('42', $entity->id());
    }

    public function test_entity_supports_stringable_id(): void
    {
        $id = TestUuidId::generate();
        $entity = new TestEntity($id);

        self::assertSame($id->toString(), $entity->id());
    }

    // -------------------------------------------------------------------------
    // AggregateRoot Contract — version(), pullDomainEvents(), incrementVersion()
    // -------------------------------------------------------------------------

    public function test_aggregate_root_contract_version(): void
    {
        $root = TestAggregate::create(AggregateRootId::generate());

        self::assertInstanceOf(AggregateRootContract::class, $root);
        self::assertSame(1, $root->version());
    }

    public function test_aggregate_root_contract_pull_domain_events(): void
    {
        $root = TestAggregate::create(AggregateRootId::generate());
        $events = $root->pullDomainEvents();

        self::assertInstanceOf(DomainEventCollection::class, $events);
        self::assertSame(1, $events->count());
        self::assertFalse($root->hasUncommittedEvents());
    }

    public function test_aggregate_root_contract_increment_version(): void
    {
        $root = TestAggregate::create(AggregateRootId::generate());
        $initialVersion = $root->version();
        $root->incrementVersion();

        self::assertSame($initialVersion + 1, $root->version());
    }

    public function test_aggregate_root_contract_clear_domain_events(): void
    {
        $root = TestAggregate::create(AggregateRootId::generate());
        self::assertTrue($root->hasUncommittedEvents());
        $root->clearDomainEvents();
        self::assertFalse($root->hasUncommittedEvents());
    }

    // -------------------------------------------------------------------------
    // UnitOfWork Contract — begin/commit/rollback/run/track/clear
    // -------------------------------------------------------------------------

    public function test_uow_contract_begin_commit_lifecycle(): void
    {
        $uow = new InMemoryUnitOfWork;

        self::assertInstanceOf(UnitOfWorkContract::class, $uow);
        self::assertFalse($uow->isActive());

        $uow->begin();
        self::assertTrue($uow->isActive());

        $uow->commit();
        self::assertFalse($uow->isActive());
    }

    public function test_uow_contract_run(): void
    {
        $uow = new InMemoryUnitOfWork;
        $result = $uow->run(fn (): int => 42);

        self::assertSame(42, $result);
        self::assertFalse($uow->isActive());
    }

    public function test_uow_contract_rollback(): void
    {
        $uow = new InMemoryUnitOfWork;
        $uow->begin();
        self::assertTrue($uow->isActive());

        $uow->rollback();
        self::assertFalse($uow->isActive());
    }

    public function test_uow_contract_clear(): void
    {
        $uow = new InMemoryUnitOfWork;
        $uow->begin();
        $uow->commit();

        $uow->clear();
        self::assertSame([], $uow->getCommitted());
        self::assertSame([], $uow->getDeleted());
        self::assertFalse($uow->hasPendingEvents());
    }

    // -------------------------------------------------------------------------
    // SnapshotStore Contract — load/save/has/delete/count/stats/purge
    // -------------------------------------------------------------------------

    public function test_snapshot_store_contract_full_lifecycle(): void
    {
        $store = new InMemorySnapshotStore;

        self::assertInstanceOf(SnapshotStore::class, $store);

        $snapshot = Snapshot::create('TestAggregate', 'uuid-1', 10, ['status' => 'active']);
        $store->save($snapshot);

        self::assertTrue($store->has('TestAggregate', 'uuid-1'));
        self::assertNotNull($store->load('TestAggregate', 'uuid-1'));
        self::assertSame(1, $store->count());
        self::assertSame(1, $store->count('TestAggregate'));
        self::assertSame(0, $store->count('OtherAggregate'));

        $stats = $store->stats();
        self::assertSame(1, $stats['total']);
        self::assertArrayHasKey('TestAggregate', $stats['by_type']);
        self::assertSame(1, $stats['by_type']['TestAggregate']);

        $store->delete('TestAggregate', 'uuid-1');
        self::assertFalse($store->has('TestAggregate', 'uuid-1'));
        self::assertSame(0, $store->count());
    }

    public function test_snapshot_store_purge_by_type(): void
    {
        $store = new InMemorySnapshotStore;
        $store->save(Snapshot::create('Order', 'id-1', 1, []));
        $store->save(Snapshot::create('Order', 'id-2', 1, []));
        $store->save(Snapshot::create('User', 'id-1', 1, []));

        $removed = $store->purge('Order');
        self::assertSame(2, $removed);
        self::assertSame(1, $store->count());
        self::assertTrue($store->has('User', 'id-1'));
    }

    public function test_snapshot_store_purge_all(): void
    {
        $store = new InMemorySnapshotStore;
        $store->save(Snapshot::create('A', '1', 1, []));
        $store->save(Snapshot::create('B', '2', 1, []));

        $removed = $store->purge();
        self::assertSame(2, $removed);
        self::assertSame(0, $store->count());
    }

    // -------------------------------------------------------------------------
    // Domain Exception Hierarchy — all extend DomainException, provide errorCode()
    // -------------------------------------------------------------------------

    public function test_invalid_state_exception_error_code(): void
    {
        $e = InvalidStateDomainException::because('test');
        self::assertSame('INVALID_STATE', $e->errorCode());
    }

    public function test_invalid_argument_exception_error_code(): void
    {
        $e = InvalidArgumentDomainException::because('test');
        self::assertSame('INVALID_ARGUMENT', $e->errorCode());
    }

    public function test_not_found_exception_error_code(): void
    {
        $e = NotFoundDomainException::because('test');
        self::assertSame('NOT_FOUND', $e->errorCode());
    }

    public function test_not_found_exception_for_aggregate(): void
    {
        $e = NotFoundDomainException::forAggregate('Order', 'uuid-123');
        self::assertSame('NOT_FOUND', $e->errorCode());
        self::assertStringContainsString('Order', $e->getMessage());
        self::assertStringContainsString('uuid-123', $e->getMessage());
    }

    public function test_conflict_exception_error_code(): void
    {
        $e = ConflictDomainException::because('test');
        self::assertSame('CONFLICT', $e->errorCode());
    }

    public function test_optimistic_lock_exception_error_code(): void
    {
        $e = OptimisticLockException::for('id-1', expectedVersion: 5, actualVersion: 3);
        self::assertSame('OPTIMISTIC_LOCK', $e->errorCode());
        self::assertStringContainsString('id-1', $e->getMessage());
        self::assertStringContainsString('5', $e->getMessage());
        self::assertStringContainsString('3', $e->getMessage());
    }

    public function test_aggregate_not_found_exception_error_code(): void
    {
        $e = AggregateNotFoundException::for('App\\Order', 'uuid-456');
        self::assertSame('AGGREGATE_NOT_FOUND', $e->errorCode());
    }

    public function test_invalid_aggregate_root_exception_error_code(): void
    {
        $e = InvalidAggregateRootException::notAnAggregate(new \stdClass);
        self::assertSame('INVALID_AGGREGATE_ROOT', $e->errorCode());
    }

    public function test_custom_error_code_overrides_default(): void
    {
        $e = InvalidStateDomainException::because('test', code: 'ORDER_NOT_PENDING');
        self::assertSame('ORDER_NOT_PENDING', $e->errorCode());
    }

    public function test_all_exception_error_codes_are_unique(): void
    {
        $codes = [
            InvalidStateDomainException::because('')->errorCode(),
            InvalidArgumentDomainException::because('')->errorCode(),
            NotFoundDomainException::because('')->errorCode(),
            ConflictDomainException::because('')->errorCode(),
            OptimisticLockException::for('id', 1, 2)->errorCode(),
            AggregateNotFoundException::for('A', '1')->errorCode(),
            InvalidAggregateRootException::notAnAggregate(new \stdClass)->errorCode(),
        ];

        self::assertSame($codes, array_unique($codes));
    }

    // -------------------------------------------------------------------------
    // DomainEventCollection — readonly, filter/merge/map operations return new instance
    // -------------------------------------------------------------------------

    public function test_domain_event_collection_filter_returns_new_instance(): void
    {
        $e1 = DomainEvent::occur('a', []);
        $e2 = DomainEvent::occur('b', []);
        $collection = new DomainEventCollection([$e1, $e2]);

        $filtered = $collection->filter(fn (DomainEvent $e): bool => $e->eventType === 'a');

        self::assertNotSame($collection, $filtered);
        self::assertSame(1, $filtered->count());
        self::assertSame('a', $filtered->first()?->eventType);
    }

    public function test_domain_event_collection_merge_returns_new_instance(): void
    {
        $e1 = DomainEvent::occur('a', []);
        $collection = new DomainEventCollection([$e1]);
        $e2 = DomainEvent::occur('b', []);

        $merged = $collection->merge([$e2]);

        self::assertNotSame($collection, $merged);
        self::assertSame(2, $merged->count());
    }

    // -------------------------------------------------------------------------
    // Snapshot — readonly, fromArray/toArray round-trip, JSON serialization
    // -------------------------------------------------------------------------

    public function test_snapshot_from_array_to_array_round_trip(): void
    {
        $original = Snapshot::create('Order', 'uuid-1', 50, ['status' => 'shipped', 'total' => 99.99]);
        $restored = Snapshot::fromArray($original->toArray());

        self::assertTrue($original->equals($restored));
        self::assertSame('Order', $restored->aggregateType);
        self::assertSame('uuid-1', $restored->aggregateId);
        self::assertSame(50, $restored->version);
        self::assertSame(['status' => 'shipped', 'total' => 99.99], $restored->state);
    }

    public function test_snapshot_json_serialization(): void
    {
        $snapshot = Snapshot::create('Order', 'uuid-1', 10, ['key' => 'value']);
        $json = json_encode($snapshot);
        $data = json_decode($json, true);

        self::assertArrayHasKey('aggregate_type', $data);
        self::assertArrayHasKey('aggregate_id', $data);
        self::assertArrayHasKey('version', $data);
        self::assertArrayHasKey('state', $data);
        self::assertArrayHasKey('created_at', $data);
    }

    // -------------------------------------------------------------------------
    // Nested UnitOfWork — savepoint semantics
    // -------------------------------------------------------------------------

    public function test_nested_uow_run_creates_savepoints(): void
    {
        $uow = new InMemoryUnitOfWork;
        $events = [];

        $uow->setEventDispatcher(function (DomainEvent $event) use (&$events): void {
            $events[] = $event->eventType;
        });

        $uow->setPersistenceCallback(function (array $committed, array $deleted): void {
            // No-op for test
        });

        $uow->run(function () use ($uow): void {
            $uow->run(function () use ($uow): void {
                // Inner transaction
            });
        });

        // Events dispatched after outermost commit
        self::assertEmpty($events); // No events raised in this test
        self::assertFalse($uow->isActive());
    }

    public function test_uow_rollback_discards_pending_events(): void
    {
        $uow = new InMemoryUnitOfWork;
        $uow->begin();
        $uow->queueEvent(DomainEvent::occur('test.event', []));

        self::assertTrue($uow->hasPendingEvents());

        $uow->rollback();

        self::assertFalse($uow->hasPendingEvents());
    }
}

// -------------------------------------------------------------------------
// Test fixtures
// -------------------------------------------------------------------------

final readonly class TestUuidId extends UuidIdentifier {}

final readonly class TestUlidId extends UlidIdentifier {}

final class TestEntity extends Entity
{
    public function __construct(
        int|string|\Stringable $id,
        public string $name = 'test',
    ) {
        parent::__construct($id);
    }
}

final class TestAggregate extends AggregateRoot
{
    public string $status = 'pending';

    protected function __construct(AggregateRootId $id)
    {
        parent::__construct($id);
    }

    public static function create(AggregateRootId $id): self
    {
        $aggregate = new self($id);
        $aggregate->apply(DomainEvent::occur('test.created', [
            'id' => $id->toString(),
        ]));

        return $aggregate;
    }

    protected function applyTestCreated(DomainEvent $event): void
    {
        $this->status = $event->payload['status'] ?? 'pending';
    }
}
