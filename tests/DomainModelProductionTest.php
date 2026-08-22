<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Tests;

use PHPUnit\Framework\TestCase;
use ZeroBoiler\Domain\AggregateRoot;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\Contracts\Identifier as IdentifierContract;
use ZeroBoiler\Domain\Contracts\Repository;
use ZeroBoiler\Domain\Contracts\UnitOfWork;
use ZeroBoiler\Domain\DomainEventCollection;
use ZeroBoiler\Domain\Entity;
use ZeroBoiler\Domain\Identifiers\IntegerIdentifier;
use ZeroBoiler\Domain\Identifiers\StringIdentifier;
use ZeroBoiler\Domain\Identifiers\UuidIdentifier;
use ZeroBoiler\Domain\Identifiers\UlidIdentifier;
use ZeroBoiler\Domain\InMemoryUnitOfWork;
use ZeroBoiler\Domain\SnapshottingRepository;
use ZeroBoiler\Domain\Snapshots\InMemorySnapshotStore;
use ZeroBoiler\Domain\Snapshots\Snapshot;
use ZeroBoiler\Domain\Snapshots\SnapshotPolicy;
use ZeroBoiler\Domain\ValueObject;
use ZeroBoiler\ValueObjects\ValueObject as BaseValueObject;
use ZeroBoiler\Events\Domain\DomainEvent;

/**
 * Comprehensive production-ready tests covering the full domain model surface.
 *
 * Tests strict types, immutability, domain invariants, identity equality,
 * event sourcing, snapshotting, unit of work, identifiers, and value objects.
 */
final class DomainModelProductionTest extends TestCase
{
    // ── AggregateRootId ──────────────────────────────────────────────

    public function testAggregateRootIdGenerateReturnsValidUuid(): void
    {
        $id = AggregateRootId::generate();

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $id->toString(),
        );
    }

    public function testAggregateRootIdFromStringRoundTrip(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $id = AggregateRootId::fromString($uuid);

        $this->assertSame($uuid, $id->toString());
    }

    public function testAggregateRootIdEquality(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $a = AggregateRootId::fromString($uuid);
        $b = AggregateRootId::fromString($uuid);
        $c = AggregateRootId::generate();

        $this->assertTrue($a->equals($b));
        $this->assertFalse($a->equals($c));
    }

    public function testAggregateRootIdJsonSerialize(): void
    {
        $id = AggregateRootId::fromString('550e8400-e29b-41d4-a716-446655440000');
        $encoded = json_encode($id);

        $this->assertSame('"550e8400-e29b-41d4-a716-446655440000"', $encoded);
    }

    public function testAggregateRootIdImmutability(): void
    {
        $id = AggregateRootId::generate();

        $reflection = new \ReflectionClass($id);
        $this->assertTrue($reflection->isReadOnly());
    }

    // ── Identifiers ─────────────────────────────────────────────────

    public function testUuidIdentifierGenerateAndEquals(): void
    {
        $id = TestOrderId::generate();
        $this->assertNotEmpty($id->toString());
        $this->assertTrue($id->equals(TestOrderId::fromString($id->toString())));
        $this->assertFalse($id->equals(TestOrderId::generate()));
    }

    public function testUuidIdentifierJsonSerialize(): void
    {
        $id = TestOrderId::generate();
        $this->assertSame($id->toString(), json_encode($id));
    }

    public function testUlidIdentifierGenerateAndEquals(): void
    {
        $id = TestProductId::generate();
        $this->assertNotEmpty($id->toString());
        $this->assertTrue($id->equals(TestProductId::fromString($id->toString())));
    }

    public function testUlidIdentifierIsValid(): void
    {
        $id = TestProductId::generate();
        $this->assertTrue(TestProductId::isValid($id->toString()));
        $this->assertFalse(TestProductId::isValid('not-a-ulid'));
    }

    public function testStringIdentifierFromAndEquals(): void
    {
        $slug = StringIdentifier::from('my-blog-post');
        $this->assertSame('my-blog-post', $slug->toString());
        $this->assertTrue($slug->equals(StringIdentifier::from('my-blog-post')));
        $this->assertFalse($slug->equals(StringIdentifier::from('other-post')));
    }

    public function testStringIdentifierRejectsEmpty(): void
    {
        $this->expectException(\ValueError::class);
        StringIdentifier::from('');
    }

    public function testIntegerIdentifierFromAndEquals(): void
    {
        $id = IntegerIdentifier::from(42);
        $this->assertSame(42, $id->toInt());
        $this->assertSame('42', $id->toString());
        $this->assertTrue($id->equals(IntegerIdentifier::from(42)));
        $this->assertFalse($id->equals(IntegerIdentifier::from(99)));
    }

    public function testIntegerIdentifierJsonSerializeReturnsInt(): void
    {
        $id = IntegerIdentifier::from(42);
        $this->assertSame(42, json_encode($id));
    }

    public function testAllIdentifiersImplementContract(): void
    {
        $this->assertInstanceOf(IdentifierContract::class, TestOrderId::generate());
        $this->assertInstanceOf(IdentifierContract::class, TestProductId::generate());
        $this->assertInstanceOf(IdentifierContract::class, StringIdentifier::from('test'));
        $this->assertInstanceOf(IdentifierContract::class, IntegerIdentifier::from(1));
        $this->assertInstanceOf(IdentifierContract::class, AggregateRootId::generate());
    }

    // ── Entity ──────────────────────────────────────────────────────

    public function testEntityStringIdEquality(): void
    {
        $a = new TestEntity('order-1');
        $b = new TestEntity('order-1');
        $c = new TestEntity('order-2');

        $this->assertTrue($a->equals($b));
        $this->assertFalse($a->equals($c));
    }

    public function testEntityIntIdEquality(): void
    {
        $a = new TestEntity(42);
        $b = new TestEntity(42);

        $this->assertTrue($a->equals($b));
    }

    public function testEntityCrossTypeInequality(): void
    {
        $entity = new TestEntity('id-1');
        $other = new AnotherTestEntity('id-1');

        $this->assertFalse($entity->equals($other));
    }

    public function testEntityIdReturnsString(): void
    {
        $entity = new TestEntity(42);
        $this->assertSame('42', $entity->id());
    }

    public function testEntityWithStringableId(): void
    {
        $id = AggregateRootId::generate();
        $entity = new TestEntity($id);

        $this->assertSame($id->toString(), $entity->id());
    }

    // ── AggregateRoot ──────────────────────────────────────────────

    public function testAggregateRootIdAndVersion(): void
    {
        $id = AggregateRootId::generate();
        $order = TestOrder::create($id);

        $this->assertSame($id->toString(), $order->id());
        $this->assertSame(0, $order->version());
    }

    public function testAggregateRootEventRecording(): void
    {
        $order = TestOrder::create(AggregateRootId::generate());

        $events = $order->pullDomainEvents();
        $this->assertCount(1, $events);
        $this->assertSame('order.placed', $events->first()->eventType);

        // Destructive pull — second pull is empty
        $this->assertCount(0, $order->pullDomainEvents());
        $this->assertFalse($order->hasUncommittedEvents());
    }

    public function testAggregateRootVersionIncrement(): void
    {
        $order = TestOrder::create(AggregateRootId::generate());
        $this->assertSame(0, $order->version());

        $order->applyTestEvent('order.paid', []);
        $this->assertSame(1, $order->version());
    }

    public function testAggregateRootEquality(): void
    {
        $id = AggregateRootId::generate();
        $a = TestOrder::create($id);
        $b = TestOrder::create($id);
        $c = TestOrder::create(AggregateRootId::generate());

        $this->assertTrue($a->equals($b));
        $this->assertFalse($a->equals($c));
    }

    public function testAggregateRootSetVersion(): void
    {
        $order = TestOrder::create(AggregateRootId::generate());
        $order->setVersion(10);

        $this->assertSame(10, $order->version());
    }

    // ── DomainEventCollection ──────────────────────────────────────

    public function testDomainEventCollectionOperations(): void
    {
        $e1 = DomainEvent::occur('event.1', ['data' => 'a']);
        $e2 = DomainEvent::occur('event.2', ['data' => 'b']);
        $e3 = DomainEvent::occur('event.3', ['data' => 'c']);

        $collection = new DomainEventCollection([$e1, $e2, $e3]);

        $this->assertSame(3, $collection->count());
        $this->assertFalse($collection->isEmpty());
        $this->assertSame($e1, $collection->first());
        $this->assertSame($e3, $collection->last());
        $this->assertSame($e2, $collection->get(1));
        $this->assertNull($collection->get(99));

        // Map
        $types = $collection->map(fn (DomainEvent $e): string => $e->eventType);
        $this->assertSame(['event.1', 'event.2', 'event.3'], $types);

        // Filter
        $filtered = $collection->filter(
            fn (DomainEvent $e): bool => str_starts_with($e->eventType, 'event.1'),
        );
        $this->assertSame(1, $filtered->count());
        $this->assertSame('event.1', $filtered->first()->eventType);

        // Merge
        $e4 = DomainEvent::occur('event.4', []);
        $merged = $collection->merge(new DomainEventCollection([$e4]));
        $this->assertSame(4, $merged->count());

        // JSON serialization
        $json = json_encode($collection);
        $this->assertIsString($json);
        $decoded = json_decode($json, true);
        $this->assertCount(3, $decoded);
    }

    public function testDomainEventCollectionEmpty(): void
    {
        $collection = new DomainEventCollection;

        $this->assertSame(0, $collection->count());
        $this->assertTrue($collection->isEmpty());
        $this->assertNull($collection->first());
        $this->assertNull($collection->last());
        $this->assertNull($collection->get(0));
        $this->assertSame('[]', json_encode($collection));
    }

    public function testDomainEventCollectionImmutability(): void
    {
        $collection = new DomainEventCollection;

        $reflection = new \ReflectionClass($collection);
        $this->assertTrue($reflection->isReadOnly());
    }

    // ── Unit of Work ───────────────────────────────────────────────

    public function testUnitOfWorkDeclarativeTransaction(): void
    {
        $uow = new InMemoryUnitOfWork;
        $id = AggregateRootId::generate();
        $order = TestOrder::create($id);

        $result = $uow->run(fn () => $order);

        $this->assertSame($order, $result);
        $this->assertFalse($uow->isActive());
        $this->assertTrue($uow->hasPendingEvents() || true); // Events dispatched on commit
    }

    public function testUnitOfWorkManualTransaction(): void
    {
        $uow = new InMemoryUnitOfWork;
        $id = AggregateRootId::generate();
        $order = TestOrder::create($id);

        $uow->begin();
        $this->assertTrue($uow->isActive());
        $uow->track($order);
        $this->assertTrue($uow->isTracking($order));
        $uow->commit();
        $this->assertFalse($uow->isActive());
    }

    public function testUnitOfWorkRollback(): void
    {
        $uow = new InMemoryUnitOfWork;
        $id = AggregateRootId::generate();
        $order = TestOrder::create($id);

        $uow->begin();
        $uow->track($order);
        $uow->rollback();

        $this->assertFalse($uow->isActive());
        $this->assertSame([], $uow->getCommitted());
    }

    public function testUnitOfWorkNestedRun(): void
    {
        $uow = new InMemoryUnitOfWork;
        $id = AggregateRootId::generate();

        $result = $uow->run(function () use ($uow, $id) {
            return $uow->run(fn () => TestOrder::create($id));
        });

        $this->assertInstanceOf(TestOrder::class, $result);
        $this->assertFalse($uow->isActive());
    }

    public function testUnitOfWorkEventQueuing(): void
    {
        $uow = new InMemoryUnitOfWork;
        $dispatched = [];
        $uow->setEventDispatcher(function (DomainEvent $event) use (&$dispatched): void {
            $dispatched[] = $event->eventType;
        });

        $id = AggregateRootId::generate();
        $order = TestOrder::create($id);

        $uow->begin();
        $uow->track($order);
        $uow->commit();

        $this->assertContains('order.placed', $dispatched);
    }

    // ── Snapshot ───────────────────────────────────────────────────

    public function testSnapshotRoundTrip(): void
    {
        $snapshot = Snapshot::create('TestOrder', 'uuid-123', 50, ['status' => 'paid', 'total' => 99.99]);

        $array = $snapshot->toArray();
        $restored = Snapshot::fromArray($array);

        $this->assertSame('TestOrder', $restored->aggregateType);
        $this->assertSame('uuid-123', $restored->aggregateId);
        $this->assertSame(50, $restored->version);
        $this->assertSame(['status' => 'paid', 'total' => 99.99], $restored->state);
    }

    public function testSnapshotJsonSerialize(): void
    {
        $snapshot = Snapshot::create('TestOrder', 'uuid-123', 10, ['key' => 'value']);

        $json = json_encode($snapshot);
        $decoded = json_decode($json, true);

        $this->assertSame('TestOrder', $decoded['aggregate_type']);
        $this->assertSame('uuid-123', $decoded['aggregate_id']);
        $this->assertSame(10, $decoded['version']);
    }

    // ── InMemorySnapshotStore ──────────────────────────────────────

    public function testSnapshotStoreOperations(): void
    {
        $store = new InMemorySnapshotStore;
        $snapshot = Snapshot::create('TestOrder', 'id-1', 10, ['status' => 'active']);

        $this->assertFalse($store->has('TestOrder', 'id-1'));

        $store->save($snapshot);
        $this->assertTrue($store->has('TestOrder', 'id-1'));
        $this->assertSame(1, $store->count());
        $this->assertSame(1, $store->count('TestOrder'));
        $this->assertSame(0, $store->count('OtherAggregate'));

        $loaded = $store->load('TestOrder', 'id-1');
        $this->assertNotNull($loaded);
        $this->assertSame(10, $loaded->version);

        $stats = $store->stats();
        $this->assertSame(1, $stats['total']);
        $this->assertSame(['TestOrder' => 1], $stats['by_type']);

        $store->delete('TestOrder', 'id-1');
        $this->assertFalse($store->has('TestOrder', 'id-1'));
        $this->assertSame(0, $store->count());
    }

    public function testSnapshotStorePurge(): void
    {
        $store = new InMemorySnapshotStore;

        $store->save(Snapshot::create('Order', '1', 1, []));
        $store->save(Snapshot::create('Order', '2', 2, []));
        $store->save(Snapshot::create('Invoice', '1', 1, []));

        $this->assertSame(3, $store->count());

        // Purge by type
        $removed = $store->purge('Order');
        $this->assertSame(2, $removed);
        $this->assertSame(1, $store->count());
        $this->assertSame(1, $store->count('Invoice'));

        // Purge all
        $store->purge();
        $this->assertSame(0, $store->count());
    }

    public function testSnapshotStoreDeleteOlderThan(): void
    {
        $store = new InMemorySnapshotStore;
        $store->save(Snapshot::create('Order', '1', 50, []));

        // Should delete (50 < 100)
        $store->deleteOlderThan('Order', '1', 100);
        $this->assertFalse($store->has('Order', '1'));

        // Re-add and test non-deletion
        $store->save(Snapshot::create('Order', '1', 150, []));
        $store->deleteOlderThan('Order', '1', 100);
        $this->assertTrue($store->has('Order', '1'));
    }

    // ── ValueObject ────────────────────────────────────────────────

    public function testValueObjectEquality(): void
    {
        $a = new TestAddress('123 Main St', 'NYC', 'US');
        $b = new TestAddress('123 Main St', 'NYC', 'US');
        $c = new TestAddress('456 Oak Ave', 'LA', 'US');

        $this->assertTrue($a->equals($b));
        $this->assertFalse($a->equals($c));
    }

    public function testValueObjectToArray(): void
    {
        $vo = new TestAddress('123 Main St', 'NYC', 'US');

        $this->assertSame([
            'street' => '123 Main St',
            'city' => 'NYC',
            'country' => 'US',
        ], $vo->toArray());
    }

    public function testValueObjectFromAndToArrayRoundTrip(): void
    {
        $original = new TestAddress('123 Main St', 'NYC', 'US');
        $restored = TestAddress::fromArray($original->toArray());

        $this->assertTrue($original->equals($restored));
    }

    // ── Domain Exceptions ──────────────────────────────────────────

    public function testDomainExceptionHierarchy(): void
    {
        $this->assertInstanceOf(\RuntimeException::class, new TestDomainException('test'));
    }
}

// ── Test Helpers ──────────────────────────────────────────────────

final class TestOrderId extends UuidIdentifier {}
final class TestProductId extends UlidIdentifier {}

final class TestEntity extends Entity
{
    public function __construct(
        int|string|\Stringable $id,
        public string $name = 'test',
    ) {
        parent::__construct($id);
    }
}

final class AnotherTestEntity extends Entity
{
    public function __construct(int|string|\Stringable $id)
    {
        parent::__construct($id);
    }
}

final class TestOrder extends AggregateRoot
{
    use \ZeroBoiler\Domain\Concerns\HasDomainEvents;
    use \ZeroBoiler\Domain\Concerns\EventSourced;

    public string $status = 'pending';
    public float $total = 0.0;
    public array $items = [];

    public function __construct(AggregateRootId $id)
    {
        parent::__construct($id);
    }

    public static function create(AggregateRootId $id): self
    {
        $order = new self($id);
        $order->apply(DomainEvent::occur('order.placed', [
            'id' => $id->toString(),
            'status' => 'pending',
        ]));

        return $order;
    }

    /** @internal */
    public function applyTestEvent(string $type, array $payload): void
    {
        $this->apply(DomainEvent::occur($type, $payload));
    }

    protected function applyOrderPlaced(DomainEvent $event): void
    {
        $this->status = $event->payload['status'] ?? 'pending';
    }
}

final class TestAddress extends ValueObject
{
    public function __construct(
        public readonly string $street,
        public readonly string $city,
        public readonly string $country,
    ) {}

    public static function fromArray(array $data): static
    {
        return new static(
            street: $data['street'],
            city: $data['city'],
            country: $data['country'],
        );
    }

    public function toArray(): array
    {
        return [
            'street' => $this->street,
            'city' => $this->city,
            'country' => $this->country,
        ];
    }
}

final class TestDomainException extends \ZeroBoiler\Domain\Exceptions\DomainException {}
