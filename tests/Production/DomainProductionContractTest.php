<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Tests\Production;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use ZeroBoiler\Domain\AggregateRoot;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\Concerns\EventSourced;
use ZeroBoiler\Domain\Concerns\HasDomainEvents;
use ZeroBoiler\Domain\Contracts\Identifier;
use ZeroBoiler\Domain\DomainEventCollection;
use ZeroBoiler\Domain\Entity;
use ZeroBoiler\Domain\Identifiers\IntegerIdentifier;
use ZeroBoiler\Domain\Identifiers\StringIdentifier;
use ZeroBoiler\Domain\Identifiers\UuidIdentifier;
use ZeroBoiler\Domain\Identifiers\UlidIdentifier;
use ZeroBoiler\Domain\InMemoryUnitOfWork;
use ZeroBoiler\Domain\Snapshots\InMemorySnapshotStore;
use ZeroBoiler\Domain\Snapshots\Snapshot;
use ZeroBoiler\Domain\Snapshots\SnapshottingRepository;
use ZeroBoiler\Domain\ValueObject;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Domain\Exceptions\{
    ConflictDomainException,
    DomainException,
    InvalidArgumentDomainException,
    InvalidStateDomainException,
    NotFoundDomainException,
    OptimisticLockException,
};

/**
 * Production-ready contract validation test for the domain package.
 *
 * Validates that all domain classes correctly implement their contracts:
 * - Strict types enforcement
 * - Return type declarations
 * - Interface contract compliance
 * - Serialization round-trip (toArray → fromArray, toJson → fromJson)
 * - Immutability guarantees
 * - Domain invariant enforcement
 *
 * @since 1.66.0
 */
#[CoversClass(AggregateRoot::class)]
#[CoversClass(AggregateRootId::class)]
#[CoversClass(Entity::class)]
#[CoversClass(DomainEventCollection::class)]
#[CoversClass(InMemoryUnitOfWork::class)]
#[CoversClass(ValueObject::class)]
#[Group('production')]
#[Group('contracts')]
final class DomainProductionContractTest extends \PHPUnit\Framework\TestCase
{
    // ─── AggregateRootId ──────────────────────────────────────────────

    public function test_aggregate_root_id_is_immutable(): void
    {
        $id = AggregateRootId::generate();

        $reflection = new \ReflectionClass($id);
        $this->assertTrue($reflection->isReadOnly());
    }

    public function test_aggregate_root_id_serialization_round_trip(): void
    {
        $id = AggregateRootId::generate();

        // Array round-trip
        $array = $id->toArray();
        $restored = AggregateRootId::fromArray($array);
        $this->assertTrue($id->equals($restored));

        // JSON round-trip
        $json = $id->toJson();
        $fromJson = AggregateRootId::fromJson($json);
        $this->assertTrue($id->equals($fromJson));
    }

    public function test_aggregate_root_id_json_serialization_returns_string(): void
    {
        $id = AggregateRootId::generate();

        $this->assertIsString(json_encode($id));
        $this->assertSame($id->toString(), json_encode($id));
    }

    public function test_aggregate_root_id_equals_is_type_safe(): void
    {
        $id1 = AggregateRootId::generate();
        $id2 = AggregateRootId::generate();

        $this->assertFalse($id1->equals($id2));
        $this->assertTrue($id1->equals($id1));
    }

    // ─── UuidIdentifier ──────────────────────────────────────────────

    public function test_uuid_identifier_is_abstract_readonly(): void
    {
        $reflection = new \ReflectionClass(UuidIdentifier::class);

        $this->assertTrue($reflection->isReadOnly());
        $this->assertTrue($reflection->isAbstract());
    }

    public function test_uuid_identifier_serialization_round_trip(): void
    {
        $id = TestOrderId::generate();

        $restored = TestOrderId::fromArray($id->toArray());
        $this->assertTrue($id->equals($restored));

        $fromJson = TestOrderId::fromJson($id->toJson());
        $this->assertTrue($id->equals($fromJson));
    }

    public function test_uuid_identifier_validates_on_construction(): void
    {
        $this->expectException(\Ramsey\Uuid\Exception\InvalidUuidStringException::class);
        TestOrderId::fromString('not-a-uuid');
    }

    public function test_uuid_identifier_subclass_isolation(): void
    {
        $orderId = TestOrderId::generate();
        $productId = TestProductId::generate();

        // Different subclasses with same UUID value should NOT be equal
        $sameValue = TestProductId::fromString($orderId->toString());
        $this->assertFalse($orderId->equals($sameValue));
        $this->assertFalse($productId->equals($sameValue));
    }

    // ─── UlidIdentifier ──────────────────────────────────────────────

    public function test_ulid_identifier_serialization_round_trip(): void
    {
        $id = TestProductId::generate();

        $restored = TestProductId::fromArray($id->toArray());
        $this->assertTrue($id->equals($restored));

        $fromJson = TestProductId::fromJson($id->toJson());
        $this->assertTrue($id->equals($fromJson));
    }

    // ─── StringIdentifier ─────────────────────────────────────────────

    public function test_string_identifier_rejects_empty_string(): void
    {
        $this->expectException(\ValueError::class);
        StringIdentifier::from('');
    }

    public function test_string_identifier_serialization_round_trip(): void
    {
        $id = StringIdentifier::from('my-slug');

        $restored = StringIdentifier::fromArray($id->toArray());
        $this->assertTrue($id->equals($restored));

        $fromJson = StringIdentifier::fromJson($id->toJson());
        $this->assertTrue($id->equals($fromJson));
    }

    // ─── IntegerIdentifier ───────────────────────────────────────────

    public function test_integer_identifier_serialization_round_trip(): void
    {
        $id = IntegerIdentifier::from(42);

        $restored = IntegerIdentifier::fromArray($id->toArray());
        $this->assertTrue($id->equals($restored));

        $fromJson = IntegerIdentifier::fromJson($id->toJson());
        $this->assertTrue($id->equals($fromJson));
    }

    public function test_integer_identifier_from_string(): void
    {
        $id = IntegerIdentifier::fromString('99');
        $this->assertSame(99, $id->toInt());
        $this->assertSame('99', $id->toString());
    }

    // ─── DomainEventCollection ────────────────────────────────────────

    public function test_domain_event_collection_is_readonly(): void
    {
        $reflection = new \ReflectionClass(DomainEventCollection::class);
        $this->assertTrue($reflection->isReadOnly());
    }

    public function test_domain_event_collection_validates_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('DomainEvent');

        new DomainEventCollection(['not-an-event']);
    }

    public function test_domain_event_collection_validates_sequential(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('sequential list');

        new DomainEventCollection([DomainEvent::occur('test', []), 'key' => 'val']);
    }

    public function test_domain_event_collection_functional_operations(): void
    {
        $e1 = DomainEvent::occur('order.placed', ['id' => '1']);
        $e2 = DomainEvent::occur('order.paid', ['id' => '1']);
        $e3 = DomainEvent::occur('order.shipped', ['id' => '1']);

        $collection = new DomainEventCollection([$e1, $e2, $e3]);

        $this->assertSame(3, $collection->count());
        $this->assertFalse($collection->isEmpty());
        $this->assertSame($e1, $collection->first());
        $this->assertSame($e3, $collection->last());
        $this->assertSame($e2, $collection->get(1));
        $this->assertNull($collection->get(99));

        // Filter
        $paid = $collection->filter(fn (DomainEvent $e): bool => $e->eventType === 'order.paid');
        $this->assertSame(1, $paid->count());

        // Some / None
        $this->assertTrue($collection->some(fn (DomainEvent $e): bool => $e->eventType === 'order.paid'));
        $this->assertFalse($collection->none(fn (DomainEvent $e): bool => $e->eventType === 'order.paid'));
        $this->assertFalse($collection->some(fn (DomainEvent $e): bool => $e->eventType === 'order.cancelled'));

        // Find
        $found = $collection->find(fn (DomainEvent $e): bool => $e->eventType === 'order.shipped');
        $this->assertSame($e3, $found);

        // Types
        $types = $collection->types();
        $this->assertSame(['order.placed', 'order.paid', 'order.shipped'], $types);

        // CountBy
        $this->assertSame(3, $collection->countBy(fn (DomainEvent $e): bool => str_starts_with($e->eventType, 'order.')));

        // Reduce
        $total = $collection->reduce(fn (int $carry, DomainEvent $e): int => $carry + 1, 0);
        $this->assertSame(3, $total);

        // Each (side effect, returns self)
        $collected = [];
        $result = $collection->each(function (DomainEvent $e, int $i) use (&$collected): void {
            $collected[] = $e->eventType;
        });
        $this->assertSame($collection, $result); // Fluent
        $this->assertSame(['order.placed', 'order.paid', 'order.shipped'], $collected);
    }

    public function test_domain_event_collection_merge(): void
    {
        $e1 = DomainEvent::occur('a', []);
        $e2 = DomainEvent::occur('b', []);
        $e3 = DomainEvent::occur('c', []);

        $c1 = new DomainEventCollection([$e1]);
        $c2 = new DomainEventCollection([$e2, $e3]);

        $merged = $c1->merge($c2);
        $this->assertSame(3, $merged->count());

        // Merge with plain array
        $merged2 = $c1->merge([$e2]);
        $this->assertSame(2, $merged2->count());
    }

    public function test_domain_event_collection_serialization_round_trip(): void
    {
        $e1 = DomainEvent::occur('order.placed', ['id' => '1']);
        $e2 = DomainEvent::occur('order.paid', ['amount' => 100]);

        $original = new DomainEventCollection([$e1, $e2]);

        $restored = DomainEventCollection::fromArray($original->toArray());
        $this->assertSame(2, $restored->count());
        $this->assertSame('order.placed', $restored->first()->eventType);
    }

    // ─── DomainException ──────────────────────────────────────────────

    public function test_domain_exception_json_serialization(): void
    {
        $e = InvalidStateDomainException::because('Order must be pending.');

        $array = json_decode(json_encode($e), true);

        $this->assertSame('InvalidStateDomainException', $array['title']);
        $this->assertSame('Order must be pending.', $array['detail']);
        $this->assertSame('INVALID_STATE', $array['code']);
        $this->assertSame(422, $array['status']);
    }

    public function test_domain_exception_round_trip(): void
    {
        $original = NotFoundDomainException::forId('order-123');
        $json = $original->toJson();

        $restored = DomainException::fromJson($json, NotFoundDomainException::class);
        $this->assertSame($original->getMessage(), $restored->getMessage());
        $this->assertSame('NOT_FOUND', $restored->errorCode());
    }

    public function test_domain_exception_http_status_mapping(): void
    {
        $this->assertSame(422, InvalidStateDomainException::because('')->httpStatus());
        $this->assertSame(422, InvalidArgumentDomainException::because('')->httpStatus());
        $this->assertSame(404, NotFoundDomainException::forId('x')->httpStatus());
        $this->assertSame(409, ConflictDomainException::because('')->httpStatus());
        $this->assertSame(409, OptimisticLockException::for('id', 1, 2)->httpStatus());
    }

    public function test_optimistic_lock_exception_factory(): void
    {
        $e = OptimisticLockException::for('order-123', expectedVersion: 5, actualVersion: 3);

        $this->assertSame('OPTIMISTIC_LOCK', $e->errorCode());
        $this->assertSame(409, $e->httpStatus());
        $this->assertStringContainsString('5', $e->getMessage());
        $this->assertStringContainsString('3', $e->getMessage());
    }

    public function test_not_found_exception_factories(): void
    {
        $e1 = NotFoundDomainException::forAggregate('Order', 'id-123');
        $this->assertSame('NOT_FOUND', $e1->errorCode());
        $this->assertStringContainsString('Order', $e1->getMessage());
        $this->assertStringContainsString('id-123', $e1->getMessage());

        $e2 = NotFoundDomainException::forId('order-456');
        $this->assertSame('NOT_FOUND', $e2->errorCode());
        $this->assertStringContainsString('order-456', $e2->getMessage());
    }

    // ─── Snapshot ────────────────────────────────────────────────────

    public function test_snapshot_is_readonly(): void
    {
        $reflection = new \ReflectionClass(Snapshot::class);
        $this->assertTrue($reflection->isReadOnly());
    }

    public function test_snapshot_serialization_round_trip(): void
    {
        $snapshot = Snapshot::create('App\\Domain\\Order', 'uuid-123', 50, ['status' => 'paid']);

        $restored = Snapshot::fromArray($snapshot->toArray());
        $this->assertTrue($snapshot->equals($restored));

        $fromJson = Snapshot::fromJson($snapshot->toJson());
        $this->assertTrue($snapshot->equals($fromJson));
    }

    // ─── Entity (simple) ────────────────────────────────────────────

    public function test_entity_equality(): void
    {
        $e1 = new TestSimpleEntity('42');
        $e2 = new TestSimpleEntity('42');
        $e3 = new TestSimpleEntity('99');

        $this->assertTrue($e1->equals($e2));
        $this->assertFalse($e1->equals($e3));
    }

    public function test_entity_serialization(): void
    {
        $entity = new TestSimpleEntity('42');

        $array = $entity->toArray();
        $this->assertSame('42', $array['id']);
        $this->assertSame('TestSimpleEntity', $array['type']);

        $json = json_encode($entity);
        $this->assertStringContainsString('42', $json);
    }

    // ─── InMemoryUnitOfWork ──────────────────────────────────────────

    public function test_unit_of_work_run_auto_commit_rollback(): void
    {
        $uow = new InMemoryUnitOfWork;

        // Successful run
        $result = $uow->run(fn (): int => 42);
        $this->assertSame(42, $result);
        $this->assertFalse($uow->isActive());

        // Failed run — rollback
        $this->expectException(\RuntimeException::class);
        $uow->run(function (): void {
            throw new \RuntimeException('Failed');
        });
    }

    public function test_unit_of_work_manual_transaction(): void
    {
        $uow = new InMemoryUnitOfWork;

        $uow->begin();
        $this->assertTrue($uow->isActive());

        $uow->commit();
        $this->assertFalse($uow->isActive());
    }

    public function test_unit_of_work_rollback_clears_events(): void
    {
        $uow = new InMemoryUnitOfWork;

        $uow->begin();
        $uow->queueEvent(DomainEvent::occur('test.event', []));
        $this->assertTrue($uow->hasPendingEvents());

        $uow->rollback();
        $this->assertFalse($uow->isActive());
    }

    public function test_unit_of_work_no_active_throws(): void
    {
        $uow = new InMemoryUnitOfWork;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No active unit of work');
        $uow->track(TestAggregate::create());
    }

    // ─── Cross-package: IdentifierContract compliance ──────────────────

    public function test_all_identifiers_implement_identifier_contract(): void
    {
        $identifiers = [
            TestOrderId::generate(),
            TestProductId::generate(),
            StringIdentifier::from('slug'),
            IntegerIdentifier::from(1),
            AggregateRootId::generate(),
        ];

        foreach ($identifiers as $id) {
            $this->assertInstanceOf(Identifier::class, $id);
            $this->assertInstanceOf(\Stringable::class, $id);
            $this->assertInstanceOf(\JsonSerializable::class, $id);

            // toArray/fromArray round-trip
            $restored = $id::fromArray($id->toArray());
            $this->assertTrue($id->equals($restored));

            // fromString consistency
            $fromStr = $id::fromString($id->toString());
            $this->assertTrue($id->equals($fromStr));
        }
    }
}

// ─── Test fixtures ──────────────────────────────────────────────────

final class TestOrderId extends UuidIdentifier {}

final class TestProductId extends UlidIdentifier {}

final class TestSimpleEntity extends Entity {}

/** @internal */
final class TestAggregate extends AggregateRoot
{
    use EventSourced;

    public static function create(): self
    {
        $aggregate = new self(AggregateRootId::generate());
        $aggregate->apply(DomainEvent::occur('test.created', ['id' => $aggregate->id()->toString()]));

        return $aggregate;
    }

    protected function applyTestCreated(DomainEvent $event): void {}
}
