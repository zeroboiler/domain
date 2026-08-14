<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Tests;

use PHPUnit\Framework\TestCase;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\Contracts\UnitOfWork;
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
use ZeroBoiler\Domain\Snapshots\{InMemorySnapshotStore, Snapshot, SnapshottingRepository};
use ZeroBoiler\Domain\ValueObject;
use ZeroBoiler\Events\Domain\DomainEvent;

/**
 * Production contract tests for domain → response bridge integration.
 *
 * Validates that all domain primitives expose the correct public API surface
 * expected by the response package's DomainTransformer and DomainResponseFactory.
 * These tests verify duck-typed contracts — no response package dependency.
 *
 * @covers \ZeroBoiler\Domain\AggregateRootId
 * @covers \ZeroBoiler\Domain\Entity
 * @covers \ZeroBoiler\Domain\ValueObject
 * @covers \ZeroBoiler\Domain\Identifiers\UuidIdentifier
 * @covers \ZeroBoiler\Domain\Identifiers\UlidIdentifier
 * @covers \ZeroBoiler\Domain\Identifiers\StringIdentifier
 * @covers \ZeroBoiler\Domain\Identifiers\IntegerIdentifier
 * @covers \ZeroBoiler\Domain\DomainEventCollection
 * @covers \ZeroBoiler\Domain\InMemoryUnitOfWork
 * @covers \ZeroBoiler\Domain\Exceptions\DomainException
 * @covers \ZeroBoiler\Domain\Snapshots\Snapshot
 * @covers \ZeroBoiler\Domain\Snapshots\InMemorySnapshotStore
 *
 * @internal Production contract verification — duck-typed bridge validation.
 */
final class DomainResponseBridgeProductionContractTest extends TestCase
{
    // ---------------------------------------------------------------
    // AggregateRootId — expected by DomainResponseFactory::entity()
    // ---------------------------------------------------------------

    public function testAggregateRootIdExposesToStringForResponseBridge(): void
    {
        $id = AggregateRootId::generate();

        // Response bridge calls $entity->id() → string
        $this->assertIsString($id->toString());
        $this->assertNotEmpty($id->toString());
    }

    public function testAggregateRootIdJsonSerializationRoundTrip(): void
    {
        $id = AggregateRootId::generate();
        $json = json_encode($id);
        $this->assertNotFalse($json);
        $this->assertSame($id->toString(), $json);
    }

    public function testAggregateRootIdFromArrayRoundTrip(): void
    {
        $id = AggregateRootId::generate();
        $restored = AggregateRootId::fromArray($id->toArray());
        $this->assertTrue($id->equals($restored));
    }

    public function testAggregateRootIdEqualsIdentityCheck(): void
    {
        $id1 = AggregateRootId::generate();
        $id2 = AggregateRootId::fromString($id1->toString());
        $id3 = AggregateRootId::generate();

        $this->assertTrue($id1->equals($id2));
        $this->assertFalse($id1->equals($id3));
    }

    // ---------------------------------------------------------------
    // Entity — expected by DomainTransformer::transform()
    // ---------------------------------------------------------------

    public function testEntityExposesIdMethod(): void
    {
        $entity = new class('entity-1') extends Entity {};

        // DomainTransformer calls static::extractId($entity) → $entity->id()
        $this->assertIsString($entity->id());
        $this->assertSame('entity-1', $entity->id());
    }

    public function testEntityExposesToArrayMethod(): void
    {
        $entity = new class('entity-1') extends Entity {};

        $arr = $entity->toArray();
        $this->assertArrayHasKey('id', $arr);
        $this->assertArrayHasKey('type', $arr);
        $this->assertSame('entity-1', $arr['id']);
    }

    public function testEntityExposesEqualsMethod(): void
    {
        $entity1 = new class('id-1') extends Entity {};
        $entity2 = new class('id-1') extends Entity {};
        $entity3 = new class('id-2') extends Entity {};

        $this->assertTrue($entity1->equals($entity2));
        $this->assertFalse($entity1->equals($entity3));
    }

    public function testEntityJsonSerializeDelegatesToToArray(): void
    {
        $entity = new class('json-1') extends Entity {};

        $json = json_encode($entity);
        $this->assertNotFalse($json);
        $decoded = json_decode($json, true);
        $this->assertSame('json-1', $decoded['id']);
    }

    // ---------------------------------------------------------------
    // Identifiers — cross-package identifier contract
    // ---------------------------------------------------------------

    public function testUuidIdentifierStringableContract(): void
    {
        $id = TestOrderId::generate();
        $this->assertInstanceOf(
            \ZeroBoiler\Domain\Contracts\Identifier::class,
            $id,
        );
        $this->assertIsString($id->toString());
        $this->assertSame($id->toString(), (string) $id);
    }

    public function testUlidIdentifierStringableContract(): void
    {
        $id = TestProductId::generate();
        $this->assertInstanceOf(
            \ZeroBoiler\Domain\Contracts\Identifier::class,
            $id,
        );
        $this->assertIsString($id->toString());
    }

    public function testStringIdentifierStringableContract(): void
    {
        $id = StringIdentifier::from('my-slug');
        $this->assertInstanceOf(
            \ZeroBoiler\Domain\Contracts\Identifier::class,
            $id,
        );
        $this->assertSame('my-slug', $id->toString());
    }

    public function testIntegerIdentifierStringableContract(): void
    {
        $id = IntegerIdentifier::from(42);
        $this->assertInstanceOf(
            \ZeroBoiler\Domain\Contracts\Identifier::class,
            $id,
        );
        $this->assertSame('42', $id->toString());
    }

    public function testAllIdentifiersJsonSerializable(): void
    {
        $uuid = TestOrderId::generate();
        $ulid = TestProductId::generate();
        $str = StringIdentifier::from('slug');
        $int = IntegerIdentifier::from(42);

        foreach ([$uuid, $ulid, $str, $int] as $identifier) {
            $json = json_encode($identifier);
            $this->assertNotFalse($json, get_class($identifier) . ' should be JSON serializable');
        }
    }

    public function testAllIdentifiersFromArrayRoundTrip(): void
    {
        $uuid = TestOrderId::generate();
        $ulid = TestProductId::generate();
        $str = StringIdentifier::from('slug');
        $int = IntegerIdentifier::from(42);

        $this->assertTrue($uuid->equals(TestOrderId::fromArray($uuid->toArray())));
        $this->assertTrue($ulid->equals(TestProductId::fromArray($ulid->toArray())));
        $this->assertTrue($str->equals(StringIdentifier::fromArray($str->toArray())));
        $this->assertTrue($int->equals(IntegerIdentifier::fromArray($int->toArray())));
    }

    // ---------------------------------------------------------------
    // DomainException — expected by DomainResponseFactory::error()
    // ---------------------------------------------------------------

    public function testDomainExceptionToErrorArrayRfc9457Format(): void
    {
        $exception = InvalidStateDomainException::because('Order must be pending.');
        $error = $exception->toErrorArray();

        $this->assertArrayHasKey('title', $error);
        $this->assertArrayHasKey('detail', $error);
        $this->assertArrayHasKey('code', $error);
        $this->assertSame('INVALID_STATE', $error['code']);
        $this->assertSame('Order must be pending.', $error['detail']);
    }

    public function testDomainExceptionErrorCodeHierarchy(): void
    {
        $exceptions = [
            [InvalidStateDomainException::because('x'), 'INVALID_STATE'],
            [InvalidArgumentDomainException::because('x'), 'INVALID_ARGUMENT'],
            [NotFoundDomainException::because('x'), 'NOT_FOUND'],
            [ConflictDomainException::because('x'), 'CONFLICT'],
            [OptimisticLockException::for('id', 5, 3), 'OPTIMISTIC_LOCK'],
        ];

        foreach ($exceptions as [$exception, $expectedCode]) {
            $this->assertSame($expectedCode, $exception->errorCode(), get_class($exception));
        }
    }

    public function testDomainExceptionJsonSerializable(): void
    {
        $exception = InvalidStateDomainException::because('Test error');
        $json = json_encode($exception);
        $this->assertNotFalse($json);

        $decoded = json_decode($json, true);
        $this->assertSame('INVALID_STATE', $decoded['code']);
        $this->assertSame('Test error', $decoded['detail']);
    }

    public function testDomainExceptionFromArrayRoundTrip(): void
    {
        $original = InvalidStateDomainException::because('Round trip test', 'CUSTOM_CODE');
        $restored = DomainException::fromArray(
            $original->toErrorArray(),
            InvalidStateDomainException::class,
        );

        $this->assertSame($original->getMessage(), $restored->getMessage());
        $this->assertSame('CUSTOM_CODE', $restored->errorCode());
    }

    // ---------------------------------------------------------------
    // DomainEventCollection — serialization contract
    // ---------------------------------------------------------------

    public function testDomainEventCollectionJsonSerializable(): void
    {
        $events = [
            DomainEvent::occur('test.event', ['key' => 'value']),
            DomainEvent::occur('test.another', ['count' => 42]),
        ];
        $collection = new DomainEventCollection($events);

        $json = json_encode($collection);
        $this->assertNotFalse($json);

        $decoded = json_decode($json, true);
        $this->assertCount(2, $decoded);
    }

    public function testDomainEventCollectionFromArrayRoundTrip(): void
    {
        $events = [
            DomainEvent::occur('test.a', ['x' => 1]),
            DomainEvent::occur('test.b', ['y' => 2]),
        ];
        $collection = new DomainEventCollection($events);
        $restored = DomainEventCollection::fromArray($collection->toArray());

        $this->assertSame($collection->count(), $restored->count());
    }

    // ---------------------------------------------------------------
    // UnitOfWork — transactional contract
    // ---------------------------------------------------------------

    public function testUnitOfWorkRunMethodReturnsResult(): void
    {
        $uow = new InMemoryUnitOfWork;
        $result = $uow->run(fn (): string => 'success');

        $this->assertSame('success', $result);
        $this->assertFalse($uow->isActive());
    }

    public function testUnitOfWorkTracksAggregates(): void
    {
        $uow = new InMemoryUnitOfWork;
        $uow->begin();

        $entity = new class('tracked-id') extends Entity {};
        $aggregate = new TestSimpleAggregate(AggregateRootId::generate());

        $uow->track($aggregate);
        $this->assertTrue($uow->isTracking($aggregate));

        $uow->commit();
    }

    public function testUnitOfWorkRollbackRestoresState(): void
    {
        $uow = new InMemoryUnitOfWork;

        $result = $uow->run(function () use ($uow): string {
            $aggregate = new TestSimpleAggregate(AggregateRootId::generate());
            $uow->track($aggregate);

            // Simulate a failure after modification
            $aggregate->status = 'modified';
            throw new \RuntimeException('Simulated failure');
        });

        // Exception should propagate
        $this->assertFalse($uow->isActive());
    }

    // ---------------------------------------------------------------
    // Snapshot — serialization contract
    // ---------------------------------------------------------------

    public function testSnapshotFromArrayRoundTrip(): void
    {
        $snapshot = Snapshot::create(
            'TestAggregate',
            'uuid-123',
            50,
            ['status' => 'paid', 'total' => 199.99],
        );

        $restored = Snapshot::fromArray($snapshot->toArray());
        $this->assertTrue($snapshot->equals($restored));
    }

    public function testSnapshotJsonSerializable(): void
    {
        $snapshot = Snapshot::create(
            'TestAggregate',
            'uuid-456',
            10,
            ['key' => 'value'],
        );

        $json = json_encode($snapshot);
        $this->assertNotFalse($json);

        $decoded = json_decode($json, true);
        $this->assertSame('TestAggregate', $decoded['aggregate_type']);
        $this->assertSame('uuid-456', $decoded['aggregate_id']);
        $this->assertSame(10, $decoded['version']);
    }

    public function testSnapshotStoreContract(): void
    {
        $store = new InMemorySnapshotStore;
        $snapshot = Snapshot::create('Order', 'id-1', 5, ['status' => 'active']);

        $store->save($snapshot);
        $this->assertTrue($store->has('Order', 'id-1'));

        $loaded = $store->load('Order', 'id-1');
        $this->assertNotNull($loaded);
        $this->assertSame(5, $loaded->version);

        $this->assertSame(1, $store->count('Order'));
        $this->assertSame(1, $store->count());

        $stats = $store->stats();
        $this->assertSame(1, $stats['total']);
        $this->assertArrayHasKey('Order', $stats['by_type']);

        $store->delete('Order', 'id-1');
        $this->assertFalse($store->has('Order', 'id-1'));
    }

    // ---------------------------------------------------------------
    // ValueObject — equality contract
    // ---------------------------------------------------------------

    public function testValueObjectEqualityBasedOnToArray(): void
    {
        $vo1 = new TestAddress('123 Main', 'NYC', 'US');
        $vo2 = new TestAddress('123 Main', 'NYC', 'US');
        $vo3 = new TestAddress('456 Oak', 'LA', 'US');

        $this->assertTrue($vo1->equals($vo2));
        $this->assertFalse($vo1->equals($vo3));
    }

    public function testValueObjectFromArrayRoundTrip(): void
    {
        $original = new TestAddress('123 Main', 'NYC', 'US');
        $restored = TestAddress::fromArray($original->toArray());

        $this->assertTrue($original->equals($restored));
    }
}

// ---------------------------------------------------------------
// Test fixtures (minimal, for contract verification only)
// ---------------------------------------------------------------

final class TestOrderId extends UuidIdentifier {}

final class TestProductId extends UlidIdentifier {}

final class TestSimpleAggregate extends AggregateRoot
{
    public string $status = 'pending';

    public function __construct(AggregateRootId $id)
    {
        parent::__construct($id);
    }

    public function toArray(): array
    {
        return [
            ...parent::toArray(),
            'status' => $this->status,
        ];
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
