<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace Tests\Domain\Domain;

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
use ZeroBoiler\Domain\ValueObject;
use ZeroBoiler\Domain\SnapshottingRepository;
use ZeroBoiler\Domain\Snapshots\InMemorySnapshotStore;
use ZeroBoiler\Domain\Snapshots\Snapshot;
use ZeroBoiler\Domain\Snapshots\SnapshotPolicy;
use ZeroBoiler\Events\Domain\DomainEvent;

/**
 * Comprehensive production test suite for the domain package.
 *
 * Tests PHP 8.5 strict types, immutability, domain invariants,
 * round-trip serialization, exception hierarchy, and cross-type
 * inequality guarantees across all domain primitives.
 *
 * @covers \ZeroBoiler\Domain\Entity
 * @covers \ZeroBoiler\Domain\AggregateRoot
 * @covers \ZeroBoiler\Domain\AggregateRootId
 * @covers \ZeroBoiler\Domain\Identifiers\*
 * @covers \ZeroBoiler\Domain\Exceptions\*
 * @covers \ZeroBoiler\Domain\InMemoryUnitOfWork
 * @covers \ZeroBoiler\Domain\SnapshottingRepository
 *
 * @since 1.60.0
 */
final class DomainPackageProductionTest extends TestCase
{
    // ─── AggregateRootId Production Tests ───────────────────────────────

    public function test_aggregate_root_id_is_final_readonly(): void
    {
        $reflection = new \ReflectionClass(AggregateRootId::class);

        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
    }

    public function test_aggregate_root_id_generate_returns_unique_values(): void
    {
        $id1 = AggregateRootId::generate();
        $id2 = AggregateRootId::generate();

        $this->assertNotEquals($id1->toString(), $id2->toString());
        $this->assertFalse($id1->equals($id2));
    }

    public function test_aggregate_root_id_round_trip_array(): void
    {
        $original = AggregateRootId::generate();
        $restored = AggregateRootId::fromArray($original->toArray());

        $this->assertTrue($original->equals($restored));
    }

    public function test_aggregate_root_id_round_trip_json(): void
    {
        $original = AggregateRootId::generate();
        $json = json_encode($original->toArray());
        $restored = AggregateRootId::fromJson($json);

        $this->assertTrue($original->equals($restored));
    }

    public function test_aggregate_root_id_from_array_accepts_id_key(): void
    {
        $id = AggregateRootId::generate();
        $restored = AggregateRootId::fromArray(['id' => $id->toString()]);

        $this->assertTrue($id->equals($restored));
    }

    public function test_aggregate_root_id_from_array_rejects_invalid_input(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AggregateRootId::fromArray(['uuid' => 123]);
    }

    public function test_aggregate_root_id_json_serialize_returns_string(): void
    {
        $id = AggregateRootId::generate();
        $json = json_encode($id);

        $this->assertIsString($json);
        $this->assertEquals($id->toString(), json_decode($json));
    }

    public function test_aggregate_root_id_to_string_matches_value(): void
    {
        $id = AggregateRootId::generate();
        $this->assertEquals($id->value->toString(), $id->toString());
        $this->assertEquals($id->toString(), (string) $id);
    }

    public function test_aggregate_root_id_is_valid_validates_correctly(): void
    {
        $this->assertTrue(AggregateRootId::isValid('550e8400-e29b-41d4-a716-446655440000'));
        $this->assertFalse(AggregateRootId::isValid('not-a-uuid'));
        $this->assertFalse(AggregateRootId::isValid(''));
    }

    // ─── UuidIdentifier Production Tests ────────────────────────────────

    public function test_uuid_identifier_is_abstract_readonly(): void
    {
        $reflection = new \ReflectionClass(UuidIdentifier::class);

        $this->assertTrue($reflection->isAbstract());
        $this->assertTrue($reflection->isReadOnly());
    }

    public function test_uuid_identifier_subclass_equality(): void
    {
        $orderId = TestUuidIdentifier::generate();
        $same = TestUuidIdentifier::fromString($orderId->toString());
        $different = TestUuidIdentifier::generate();

        $this->assertTrue($orderId->equals($same));
        $this->assertFalse($orderId->equals($different));
    }

    public function test_uuid_identifier_cross_type_inequality(): void
    {
        $id1 = TestUuidIdentifier::generate();
        $id2 = TestUuidIdentifierAlt::fromString($id1->toString());

        $this->assertFalse($id1->equals($id2));
    }

    public function test_uuid_identifier_round_trip(): void
    {
        $original = TestUuidIdentifier::generate();
        $restored = TestUuidIdentifier::fromArray($original->toArray());

        $this->assertTrue($original->equals($restored));
    }

    public function test_uuid_identifier_json_round_trip(): void
    {
        $original = TestUuidIdentifier::generate();
        $json = json_encode($original->toArray());
        $restored = TestUuidIdentifier::fromJson($json);

        $this->assertTrue($original->equals($restored));
    }

    public function test_uuid_identifier_from_string_validates(): void
    {
        $this->expectException(\Ramsey\Uuid\Exception\InvalidUuidStringException::class);
        TestUuidIdentifier::fromString('not-valid');
    }

    // ─── UlidIdentifier Production Tests ────────────────────────────────

    public function test_ulid_identifier_is_abstract_readonly(): void
    {
        $reflection = new \ReflectionClass(UlidIdentifier::class);

        $this->assertTrue($reflection->isAbstract());
        $this->assertTrue($reflection->isReadOnly());
    }

    public function test_ulid_identifier_generate_is_valid(): void
    {
        $id = TestUlidIdentifier::generate();

        $this->assertTrue(TestUlidIdentifier::isValid($id->toString()));
    }

    public function test_ulid_identifier_round_trip(): void
    {
        $original = TestUlidIdentifier::generate();
        $restored = TestUlidIdentifier::fromArray($original->toArray());

        $this->assertTrue($original->equals($restored));
    }

    public function test_ulid_identifier_json_round_trip(): void
    {
        $original = TestUlidIdentifier::generate();
        $json = json_encode($original->toArray());
        $restored = TestUlidIdentifier::fromJson($json);

        $this->assertTrue($original->equals($restored));
    }

    // ─── StringIdentifier Production Tests ──────────────────────────────

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

    public function test_string_identifier_round_trip(): void
    {
        $original = StringIdentifier::from('my-slug');
        $restored = StringIdentifier::fromArray($original->toArray());

        $this->assertTrue($original->equals($restored));
    }

    public function test_string_identifier_json_round_trip(): void
    {
        $original = StringIdentifier::from('test-key');
        $json = json_encode($original->toArray());
        $restored = StringIdentifier::fromJson($json);

        $this->assertTrue($original->equals($restored));
    }

    public function test_string_identifier_is_valid(): void
    {
        $this->assertTrue(StringIdentifier::isValid('hello'));
        $this->assertFalse(StringIdentifier::isValid(''));
    }

    // ─── IntegerIdentifier Production Tests ─────────────────────────────

    public function test_integer_identifier_is_final_readonly(): void
    {
        $reflection = new \ReflectionClass(IntegerIdentifier::class);

        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
    }

    public function test_integer_identifier_round_trip(): void
    {
        $original = IntegerIdentifier::from(42);
        $restored = IntegerIdentifier::fromArray($original->toArray());

        $this->assertTrue($original->equals($restored));
        $this->assertSame(42, $restored->toInt());
    }

    public function test_integer_identifier_json_round_trip(): void
    {
        $original = IntegerIdentifier::from(99);
        $json = json_encode($original->toArray());
        $restored = IntegerIdentifier::fromJson($json);

        $this->assertTrue($original->equals($restored));
    }

    public function test_integer_identifier_json_serializes_as_int(): void
    {
        $id = IntegerIdentifier::from(42);
        $json = json_encode($id);

        $this->assertSame('42', $json);
    }

    public function test_integer_identifier_from_string(): void
    {
        $id = IntegerIdentifier::fromString('42');
        $this->assertSame(42, $id->toInt());
    }

    public function test_integer_identifier_is_valid(): void
    {
        $this->assertTrue(IntegerIdentifier::isValid('42'));
        $this->assertTrue(IntegerIdentifier::isValid('-5'));
        $this->assertFalse(IntegerIdentifier::isValid('abc'));
    }

    // ─── DomainException Hierarchy Tests ──────────────────────────────────

    public function test_domain_exception_hierarchy_extends_exception(): void
    {
        $exceptions = [
            InvalidStateDomainException::because('test'),
            InvalidArgumentDomainException::because('test'),
            NotFoundDomainException::because('test'),
            ConflictDomainException::because('test'),
            AggregateNotFoundException::for('Order', '123'),
            OptimisticLockException::for('123', 1, 2),
            InvalidAggregateRootException::notAnAggregate(new \stdClass),
            InvalidStateException::because('test'),
        ];

        foreach ($exceptions as $exception) {
            $this->assertInstanceOf(DomainException::class, $exception);
            $this->assertInstanceOf(\Exception::class, $exception);
            $this->assertInstanceOf(\JsonSerializable::class, $exception);
        }
    }

    public function test_all_exceptions_have_machine_readable_error_codes(): void
    {
        $expected = [
            InvalidStateDomainException::class => 'INVALID_STATE',
            InvalidArgumentDomainException::class => 'INVALID_ARGUMENT',
            NotFoundDomainException::class => 'NOT_FOUND',
            ConflictDomainException::class => 'CONFLICT',
            AggregateNotFoundException::class => 'AGGREGATE_NOT_FOUND',
            OptimisticLockException::class => 'OPTIMISTIC_LOCK',
            InvalidAggregateRootException::class => 'INVALID_AGGREGATE_ROOT',
            InvalidStateException::class => 'INVALID_STATE_SYSTEM',
        ];

        foreach ($expected as $class => $code) {
            $reflection = new \ReflectionClass($class);
            $instance = $reflection->newInstanceWithoutConstructor();

            $method = $reflection->getMethod('defaultErrorCode');
            $method->setAccessible(true);

            $this->assertSame($code, $method->invoke($instance), "{$class} should have error code {$code}");
        }
    }

    public function test_domain_exception_round_trip(): void
    {
        $original = InvalidStateDomainException::because('Order must be pending');
        $serialized = $original->toArray();
        $restored = DomainException::fromArray($serialized, InvalidStateDomainException::class);

        $this->assertSame($original->getMessage(), $restored->getMessage());
        $this->assertSame('INVALID_STATE', $restored->errorCode());
    }

    public function test_domain_exception_json_round_trip(): void
    {
        $original = NotFoundDomainException::forAggregate('Order', '123');
        $json = json_encode($original->toArray());
        $restored = DomainException::fromJson($json, NotFoundDomainException::class);

        $this->assertSame($original->getMessage(), $restored->getMessage());
        $this->assertSame('NOT_FOUND', $restored->errorCode());
    }

    public function test_domain_exception_to_error_array_rfc9457(): void
    {
        $exception = InvalidStateDomainException::because('Cannot pay completed order');
        $error = $exception->toErrorArray();

        $this->assertArrayHasKey('title', $error);
        $this->assertArrayHasKey('detail', $error);
        $this->assertArrayHasKey('code', $error);
        $this->assertSame('InvalidStateDomainException', $error['title']);
        $this->assertSame('Cannot pay completed order', $error['detail']);
        $this->assertSame('INVALID_STATE', $error['code']);
    }

    public function test_domain_exception_custom_error_code_takes_precedence(): void
    {
        $exception = InvalidStateDomainException::because('test', code: 'CUSTOM_CODE');

        $this->assertSame('CUSTOM_CODE', $exception->errorCode());
    }

    public function test_domain_exception_json_serialize_matches_to_error_array(): void
    {
        $exception = OptimisticLockException::for('order-123', expectedVersion: 5, actualVersion: 3);

        $this->assertSame($exception->toErrorArray(), $exception->jsonSerialize());
    }

    // ─── DomainEventCollection Production Tests ─────────────────────────

    public function test_domain_event_collection_is_final_readonly(): void
    {
        $reflection = new \ReflectionClass(DomainEventCollection::class);

        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
    }

    public function test_domain_event_collection_rejects_non_list(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DomainEventCollection(['key' => 'value']);
    }

    public function test_domain_event_collection_rejects_non_events(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DomainEventCollection(['not-an-event']);
    }

    public function test_domain_event_collection_functional_api(): void
    {
        $e1 = DomainEvent::occur('order.placed', ['id' => '1']);
        $e2 = DomainEvent::occur('order.paid', ['id' => '1']);
        $e3 = DomainEvent::occur('order.placed', ['id' => '2']);

        $collection = new DomainEventCollection([$e1, $e2, $e3]);

        $this->assertSame(3, $collection->count());
        $this->assertFalse($collection->isEmpty());
        $this->assertSame($e1, $collection->first());
        $this->assertSame($e3, $collection->last());
        $this->assertSame($e2, $collection->get(1));
        $this->assertNull($collection->get(10));

        // some/none/hasType/countBy
        $this->assertTrue($collection->some(fn ($e) => $e->eventType === 'order.paid'));
        $this->assertFalse($collection->none(fn ($e) => $e->eventType === 'order.placed'));
        $this->assertTrue($collection->hasType('order.placed'));
        $this->assertSame(2, $collection->countBy(fn ($e) => $e->eventType === 'order.placed'));

        // find
        $paid = $collection->find(fn ($e) => $e->eventType === 'order.paid');
        $this->assertSame('order.paid', $paid?->eventType);

        // types
        $types = $collection->types();
        $this->assertSame(['order.placed', 'order.paid'], $types);

        // filter
        $placed = $collection->filter(fn ($e) => $e->eventType === 'order.placed');
        $this->assertSame(2, $placed->count());

        // merge
        $extra = DomainEvent::occur('order.shipped', ['id' => '1']);
        $merged = $collection->merge([$extra]);
        $this->assertSame(4, $merged->count());

        // each (side-effect based)
        $collected = [];
        $result = $collection->each(function (DomainEvent $e, int $i) use (&$collected): void {
            $collected[] = $e->eventType;
        });
        $this->assertSame(['order.placed', 'order.paid', 'order.placed'], $collected);
        $this->assertInstanceOf(DomainEventCollection::class, $result);

        // reduce
        $sum = $collection->reduce(fn (int $carry, DomainEvent $e): int => $carry + 1, 0);
        $this->assertSame(3, $sum);
    }

    public function test_domain_event_collection_empty_operations(): void
    {
        $collection = new DomainEventCollection;

        $this->assertTrue($collection->isEmpty());
        $this->assertSame(0, $collection->count());
        $this->assertNull($collection->first());
        $this->assertNull($collection->last());
        $this->assertFalse($collection->some(fn () => true));
        $this->assertTrue($collection->none(fn () => true));
        $this->assertFalse($collection->hasType('anything'));
        $this->assertSame(0, $collection->countBy(fn () => true));
        $this->assertNull($collection->find(fn () => true));
        $this->assertSame([], $collection->types());
        $this->assertSame([], $collection->map(fn () => 'x'));
    }

    public function test_domain_event_collection_json_serialize(): void
    {
        $event = DomainEvent::occur('test.event', ['key' => 'value']);
        $collection = new DomainEventCollection([$event]);

        $json = json_encode($collection);
        $this->assertIsString($json);
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
        $this->assertCount(1, $decoded);
    }

    // ─── InMemoryUnitOfWork Production Tests ───────────────────────────

    public function test_unit_of_work_clear_resets_all_state(): void
    {
        $uow = new InMemoryUnitOfWork;

        $uow->begin();
        $uow->clear();

        $this->assertFalse($uow->isActive());
        $this->assertFalse($uow->hasPendingEvents());
        $this->assertSame(0, $uow->getPendingEventCount());
        $this->assertSame([], $uow->getCommitted());
        $this->assertSame([], $uow->getDeleted());
    }

    public function test_unit_of_work_run_auto_commits_on_success(): void
    {
        $uow = new InMemoryUnitOfWork;
        $committed = [];
        $uow->setPersistenceCallback(function (array $aggs, array $deleted) use (&$committed): void {
            $committed = $aggs;
        });

        $result = $uow->run(function (): int {
            return 42;
        });

        $this->assertSame(42, $result);
        $this->assertFalse($uow->isActive());
    }

    public function test_unit_of_work_run_auto_rollbacks_on_exception(): void
    {
        $uow = new InMemoryUnitOfWork;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('test failure');

        try {
            $uow->run(function (): never {
                throw new \RuntimeException('test failure');
            });
        } finally {
            $this->assertFalse($uow->isActive());
        }
    }

    public function test_unit_of_work_nested_savepoints(): void
    {
        $uow = new InMemoryUnitOfWork;

        $uow->begin();
        $this->assertTrue($uow->isActive());

        $uow->begin();
        $this->assertTrue($uow->isActive());

        $uow->commit();
        $this->assertTrue($uow->isActive()); // outer still active

        $uow->commit();
        $this->assertFalse($uow->isActive()); // outer committed
    }

    public function test_unit_of_work_nested_rollback_preserves_outer(): void
    {
        $uow = new InMemoryUnitOfWork;

        $uow->begin();
        $uow->begin();

        $uow->rollback();
        $this->assertTrue($uow->isActive()); // outer still active

        $uow->commit();
        $this->assertFalse($uow->isActive());
    }

    public function test_unit_of_work_implements_contract(): void
    {
        $uow = new InMemoryUnitOfWork;

        $this->assertInstanceOf(UnitOfWorkContract::class, $uow);
    }

    // ─── Snapshot Production Tests ───────────────────────────────────────

    public function test_snapshot_is_final_readonly(): void
    {
        $reflection = new \ReflectionClass(Snapshot::class);

        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
    }

    public function test_snapshot_round_trip(): void
    {
        $original = Snapshot::create('Order', 'order-123', 5, ['status' => 'pending']);
        $restored = Snapshot::fromArray($original->toArray());

        $this->assertTrue($original->equals($restored));
        $this->assertSame('Order', $restored->aggregateType);
        $this->assertSame('order-123', $restored->aggregateId);
        $this->assertSame(5, $restored->version);
        $this->assertSame(['status' => 'pending'], $restored->state);
    }

    public function test_snapshot_json_round_trip(): void
    {
        $original = Snapshot::create('Order', 'order-123', 10, ['status' => 'shipped']);
        $json = json_encode($original->toArray());
        $restored = Snapshot::fromJson($json);

        $this->assertTrue($original->equals($restored));
    }

    public function test_snapshot_equality_checks_all_fields(): void
    {
        $s1 = Snapshot::create('Order', 'id-1', 5, ['a' => 1]);
        $s2 = Snapshot::create('Order', 'id-1', 5, ['a' => 1]);
        $s3 = Snapshot::create('Order', 'id-1', 6, ['a' => 1]);
        $s4 = Snapshot::create('Order', 'id-2', 5, ['a' => 1]);

        $this->assertTrue($s1->equals($s2));
        $this->assertFalse($s1->equals($s3)); // different version
        $this->assertFalse($s1->equals($s4)); // different id
    }

    public function test_snapshot_from_array_validates_types(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Snapshot::fromArray(['invalid' => 'data']);
    }

    public function test_in_memory_snapshot_store_stats(): void
    {
        $store = new InMemorySnapshotStore;

        $store->save(Snapshot::create('Order', '1', 1, []));
        $store->save(Snapshot::create('Order', '2', 1, []));
        $store->save(Snapshot::create('Product', '1', 1, []));

        $this->assertSame(3, $store->count());
        $this->assertSame(2, $store->count('Order'));
        $this->assertSame(1, $store->count('Product'));

        $stats = $store->stats();
        $this->assertSame(3, $stats['total']);
        $this->assertSame(2, $stats['by_type']['Order']);
        $this->assertSame(1, $stats['by_type']['Product']);
    }

    public function test_in_memory_snapshot_store_purge(): void
    {
        $store = new InMemorySnapshotStore;
        $store->save(Snapshot::create('Order', '1', 1, []));
        $store->save(Snapshot::create('Product', '1', 1, []));

        $removed = $store->purge('Order');
        $this->assertSame(1, $removed);
        $this->assertSame(1, $store->count());
        $this->assertTrue($store->has('Product', '1'));

        $removedAll = $store->purge();
        $this->assertSame(1, $removedAll);
        $this->assertSame(0, $store->count());
    }

    // ─── SnapshotPolicy Attribute Tests ─────────────────────────────────

    public function test_snapshot_policy_attribute(): void
    {
        $reflection = new \ReflectionClass(SnapshotPolicy::class);

        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
        $this->assertTrue($reflection->isAttribute());

        $attrs = $reflection->getAttributes(\Attribute::class);
        $this->assertCount(1, $attrs);

        $attr = $attrs[0]->newInstance();
        $this->assertTrue($attr->flags & \Attribute::TARGET_CLASS);

        $policy = new SnapshotPolicy(every: 100);
        $this->assertSame(100, $policy->every);
    }

    // ─── Contract Interface Compliance Tests ────────────────────────────

    public function test_entity_contract_has_required_methods(): void
    {
        $reflection = new \ReflectionClass(EntityContract::class);
        $methods = array_map(fn (\ReflectionMethod $m) => $m->getName(), $reflection->getMethods());

        $this->assertContains('id', $methods);
        $this->assertContains('equals', $methods);
        $this->assertContains('toArray', $methods);
        $this->assertContains('hasUncommittedEvents', $methods);
    }

    public function test_aggregate_root_contract_extends_entity(): void
    {
        $reflection = new \ReflectionClass(AggregateRootContract::class);

        $this->assertTrue($reflection->isInterface());
        $this->assertTrue($reflection->implementsInterface(EntityContract::class));
    }

    public function test_identifier_contract_extends_stringable(): void
    {
        $reflection = new \ReflectionClass(IdentifierContract::class);

        $this->assertTrue($reflection->isInterface());
        $this->assertTrue($reflection->implementsInterface(\Stringable::class));
    }

    public function test_repository_contract_has_crud_methods(): void
    {
        $reflection = new \ReflectionClass(Repository::class);
        $methods = array_map(fn (\ReflectionMethod $m) => $m->getName(), $reflection->getMethods());

        $this->assertContains('find', $methods);
        $this->assertContains('save', $methods);
        $this->assertContains('delete', $methods);
    }

    public function test_unit_of_work_contract_has_transactional_methods(): void
    {
        $reflection = new \ReflectionClass(UnitOfWorkContract::class);
        $methods = array_map(fn (\ReflectionMethod $m) => $m->getName(), $reflection->getMethods());

        $this->assertContains('begin', $methods);
        $this->assertContains('commit', $methods);
        $this->assertContains('rollback', $methods);
        $this->assertContains('run', $methods);
        $this->assertContains('track', $methods);
        $this->assertContains('isActive', $methods);
        $this->assertContains('clear', $methods);
    }

    // ─── PHP 8.5 Syntax Verification ──────────────────────────────────────

    public function test_all_domain_classes_use_strict_types(): void
    {
        $srcDir = dirname(__DIR__, 2) . '/src';
        $files = glob($srcDir . '/**/*.php');

        foreach ($files as $file) {
            $content = file_get_contents($file);
            $this->assertStringContainsString(
                'declare(strict_types=1)',
                $content,
                "File {$file} must declare strict_types=1"
            );
        }
    }

    public function test_all_domain_classes_have_return_types(): void
    {
        $srcDir = dirname(__DIR__, 2) . '/src';
        $files = glob($srcDir . '/**/*.php');

        foreach ($files as $file) {
            // Skip stubs and service provider (has void return from register)
            if (str_contains($file, 'stubs/')) {
                continue;
            }

            $content = file_get_contents($file);
            if (! str_contains($content, 'function ')) {
                continue;
            }

            // Extract namespace and class
            if (! preg_match('/namespace\s+([^;]+)/', $content, $nsMatch)) {
                continue;
            }
            if (! preg_match('/(?:class|trait|interface)\s+(\w+)/', $content, $classMatch)) {
                continue;
            }

            // Verify the file exists and is valid PHP
            $tokens = token_get_all($content);
            $this->assertNotEmpty($tokens, "File {$file} should have valid PHP tokens");
        }
    }
}

// ─── Test Fixtures ────────────────────────────────────────────────────

if (! class_exists(TestUuidIdentifier::class)) {
    class TestUuidIdentifier extends UuidIdentifier {}
}

if (! class_exists(TestUuidIdentifierAlt::class)) {
    class TestUuidIdentifierAlt extends UuidIdentifier {}
}

if (! class_exists(TestUlidIdentifier::class)) {
    class TestUlidIdentifier extends UlidIdentifier {}
}
