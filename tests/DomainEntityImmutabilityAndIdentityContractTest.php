<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ZeroBoiler\Domain\AggregateRoot;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\Contracts\AggregateRoot as AggregateRootContract;
use ZeroBoiler\Domain\Contracts\Entity as EntityContract;
use ZeroBoiler\Domain\Contracts\Identifier as IdentifierContract;
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
use ZeroBoiler\Domain\Snapshots\InMemorySnapshotStore;
use ZeroBoiler\Domain\Snapshots\Snapshot;
use ZeroBoiler\Domain\Snapshots\SnapshotStore;

/**
 * Domain entity immutability and identity contract verification.
 *
 * Verifies fundamental DDD invariants:
 * - Entity identity is stable (id() never changes after construction)
 * - Entity equality is identity-based, not property-based
 * - AggregateRoot versioning increments monotonically
 * - Identifiers are immutable and self-equal
 * - ValueObjects have structural equality
 * - All domain exceptions have unique error codes and consistent HTTP status
 * - Snapshot is fully readonly (no mutation possible)
 * - InMemorySnapshotStore implements SnapshotStore contract
 *
 * @coversNothing — integration/structural test
 */
final class DomainEntityImmutabilityAndIdentityContractTest extends TestCase
{
    // ──────────────────────────────────────────────────────
    // 1. Entity Identity Stability
    // ──────────────────────────────────────────────────────

    public function testEntityIdentityIsStableAfterConstruction(): void
    {
        $id = 'test-entity-id-123';
        $entity = new TestConcreteEntity($id, 'Original Name');

        $initialId = $entity->id();
        $this->assertSame($id, $initialId);

        // Identity must not change even if we could mutate properties
        // (the test fixture has a readonly name, but the id comes from parent)
        $this->assertSame($initialId, $entity->id());
        $this->assertSame($initialId, $entity->id());
    }

    public function testEntityIdentitySurvivesClone(): void
    {
        $entity = new TestConcreteEntity('clone-test-id', 'Clone Me');
        $cloned = clone $entity;

        // Cloned entity must have the same identity
        $this->assertSame($entity->id(), $cloned->id());
        // But they must be different object instances
        $this->assertNotSame($entity, $cloned);
        // And they must be equal by identity
        $this->assertTrue($entity->equals($cloned));
    }

    public function testEntityEqualityIsIdentityBasedNotPropertyBased(): void
    {
        $entityA = new TestConcreteEntity('same-id', 'Name A');
        $entityB = new TestConcreteEntity('same-id', 'Name B');
        $entityC = new TestConcreteEntity('different-id', 'Name A');

        // Same identity, different properties → equal
        $this->assertTrue($entityA->equals($entityB));

        // Different identity, same properties → not equal
        $this->assertFalse($entityA->equals($entityC));
    }

    public function testEntityEqualityRequiresSameType(): void
    {
        $entity = new TestConcreteEntity('shared-id', 'Test');

        // Create another concrete entity subclass with the same ID
        $otherEntity = new class ('shared-id') extends \ZeroBoiler\Domain\Entity {
            public function __construct(string $id) { parent::__construct($id); }
            public function toArray(): array { return [...parent::toArray()]; }
        };

        // Different types with same ID → not equal
        $this->assertFalse($entity->equals($otherEntity));
    }

    public function testEntityToArrayContainsIdAndType(): void
    {
        $entity = new TestConcreteEntity('array-test-id', 'Array Test');
        $array = $entity->toArray();

        $this->assertArrayHasKey('id', $array);
        $this->assertArrayHasKey('type', $array);
        $this->assertSame('array-test-id', $array['id']);
        $this->assertSame('TestConcreteEntity', $array['type']);
    }

    // ──────────────────────────────────────────────────────
    // 2. AggregateRoot Versioning
    // ──────────────────────────────────────────────────────

    public function testAggregateRootStartsAtVersionZero(): void
    {
        $aggregate = new TestConcreteAggregate(AggregateRootId::generate(), 'Test');
        $this->assertSame(0, $aggregate->version());
    }

    public function testAggregateRootVersionIncrementsMonotonically(): void
    {
        $aggregate = new TestConcreteAggregate(AggregateRootId::generate(), 'Test');

        $aggregate->incrementVersion();
        $this->assertSame(1, $aggregate->version());

        $aggregate->incrementVersion();
        $this->assertSame(2, $aggregate->version());

        $aggregate->incrementVersion();
        $this->assertSame(3, $aggregate->version());
    }

    public function testAggregateRootImplementsAggregateRootContract(): void
    {
        $aggregate = new TestConcreteAggregate(AggregateRootId::generate(), 'Test');

        $this->assertInstanceOf(AggregateRootContract::class, $aggregate);
        $this->assertInstanceOf(EntityContract::class, $aggregate);
    }

    public function testAggregateRootImplementsEntityContract(): void
    {
        $aggregate = new TestConcreteAggregate(AggregateRootId::generate(), 'Test');

        $this->assertTrue(method_exists($aggregate, 'id'));
        $this->assertTrue(method_exists($aggregate, 'equals'));
        $this->assertTrue(method_exists($aggregate, 'toArray'));
        $this->assertTrue(method_exists($aggregate, 'version'));
        $this->assertTrue(method_exists($aggregate, 'incrementVersion'));
        $this->assertTrue(method_exists($aggregate, 'pullDomainEvents'));
        $this->assertTrue(method_exists($aggregate, 'peekDomainEvents'));
        $this->assertTrue(method_exists($aggregate, 'hasUncommittedEvents'));
        $this->assertTrue(method_exists($aggregate, 'clearDomainEvents'));
        $this->assertTrue(method_exists($aggregate, 'toJson'));
    }

    public function testAggregateRootToArrayContainsVersion(): void
    {
        $aggregate = new TestConcreteAggregate(AggregateRootId::generate(), 'Test');
        $aggregate->incrementVersion();

        $array = $aggregate->toArray();

        $this->assertArrayHasKey('version', $array);
        $this->assertSame(1, $array['version']);
    }

    // ──────────────────────────────────────────────────────
    // 3. Identifier Immutability
    // ──────────────────────────────────────────────────────

    public function testUuidIdentifierIsSelfEqual(): void
    {
        $id = TestUuidIdentifier::generate();
        $this->assertTrue($id->equals($id));
    }

    public function testUuidIdentifierDifferentInstancesNotEqual(): void
    {
        $idA = TestUuidIdentifier::generate();
        $idB = TestUuidIdentifier::generate();

        $this->assertFalse($idA->equals($idB));
        $this->assertNotSame($idA->toString(), $idB->toString());
    }

    public function testUuidIdentifierRoundTrip(): void
    {
        $original = TestUuidIdentifier::generate();
        $restored = TestUuidIdentifier::fromString($original->toString());

        $this->assertTrue($original->equals($restored));
        $this->assertSame($original->toString(), $restored->toString());
    }

    public function testUlidIdentifierIsSelfEqual(): void
    {
        $id = TestUlidIdentifier::generate();
        $this->assertTrue($id->equals($id));
    }

    public function testUlidIdentifierRoundTrip(): void
    {
        $original = TestUlidIdentifier::generate();
        $restored = TestUlidIdentifier::fromString($original->toString());

        $this->assertTrue($original->equals($restored));
        $this->assertSame($original->toString(), $restored->toString());
    }

    public function testStringIdentifierEquality(): void
    {
        $idA = TestStringIdentifier::from('slug-post-1');
        $idB = TestStringIdentifier::from('slug-post-1');
        $idC = TestStringIdentifier::from('slug-post-2');

        $this->assertTrue($idA->equals($idB));
        $this->assertFalse($idA->equals($idC));
    }

    public function testIntegerIdentifierEquality(): void
    {
        $idA = TestIntIdentifier::from(42);
        $idB = TestIntIdentifier::from(42);
        $idC = TestIntIdentifier::from(99);

        $this->assertTrue($idA->equals($idB));
        $this->assertFalse($idA->equals($idC));
    }

    public function testAllIdentifiersImplementIdentifierContract(): void
    {
        $identifiers = [
            TestUuidIdentifier::generate(),
            TestUlidIdentifier::generate(),
            TestStringIdentifier::from('test'),
            TestIntIdentifier::from(1),
        ];

        foreach ($identifiers as $identifier) {
            $this->assertInstanceOf(IdentifierContract::class, $identifier);
            $this->assertInstanceOf(\JsonSerializable::class, $identifier);
            $this->assertTrue(method_exists($identifier, 'toString'));
            $this->assertTrue(method_exists($identifier, 'equals'));
            $this->assertTrue(method_exists($identifier, 'fromString'));
        }
    }

    public function testIdentifierJsonSerializationReturnsString(): void
    {
        $uuid = TestUuidIdentifier::generate();
        $json = json_encode($uuid);

        $this->assertIsString($json);
        $this->assertSame('"' . $uuid->toString() . '"', $json);
    }

    public function testIntegerIdentifierJsonSerializationReturnsInteger(): void
    {
        $intId = TestIntIdentifier::from(42);
        $json = json_encode($intId);

        $this->assertSame('42', $json);
    }

    // ──────────────────────────────────────────────────────
    // 4. Domain Exception Hierarchy
    // ──────────────────────────────────────────────────────

    /**
     * @return array<string, array{class: class-string<DomainException>, code: string, httpStatus: int}>
     */
    public static function exceptionProvider(): array
    {
        return [
            'InvalidStateDomainException' => [
                'class' => InvalidStateDomainException::class,
                'code' => 'INVALID_STATE',
                'httpStatus' => 422,
            ],
            'InvalidArgumentDomainException' => [
                'class' => InvalidArgumentDomainException::class,
                'code' => 'INVALID_ARGUMENT',
                'httpStatus' => 422,
            ],
            'NotFoundDomainException' => [
                'class' => NotFoundDomainException::class,
                'code' => 'NOT_FOUND',
                'httpStatus' => 404,
            ],
            'AggregateNotFoundException' => [
                'class' => AggregateNotFoundException::class,
                'code' => 'AGGREGATE_NOT_FOUND',
                'httpStatus' => 404,
            ],
            'ConflictDomainException' => [
                'class' => ConflictDomainException::class,
                'code' => 'CONFLICT',
                'httpStatus' => 409,
            ],
            'OptimisticLockException' => [
                'class' => OptimisticLockException::class,
                'code' => 'OPTIMISTIC_LOCK',
                'httpStatus' => 409,
            ],
            'InvalidAggregateRootException' => [
                'class' => InvalidAggregateRootException::class,
                'code' => 'INVALID_AGGREGATE_ROOT',
                'httpStatus' => 500,
            ],
            'InvalidStateException' => [
                'class' => InvalidStateException::class,
                'code' => 'INVALID_STATE_SYSTEM',
                'httpStatus' => 500,
            ],
        ];
    }

    /**
     * @dataProvider exceptionProvider
     */
    public function testExceptionExtendsDomainException(string $class): void
    {
        $exception = new $class('test message');
        $this->assertInstanceOf(DomainException::class, $exception);
    }

    /**
     * @dataProvider exceptionProvider
     */
    public function testExceptionReturnsUniqueErrorCode(string $class, string $code): void
    {
        $exception = new $class('test message');
        $this->assertSame($code, $exception->errorCode());
    }

    /**
     * @dataProvider exceptionProvider
     */
    public function testExceptionReturnsCorrectHttpStatus(string $class, string $code, int $httpStatus): void
    {
        $exception = new $class('test message');
        $this->assertSame($httpStatus, $exception->httpStatus());
    }

    /**
     * @dataProvider exceptionProvider
     */
    public function testExceptionImplementsJsonSerializable(string $class): void
    {
        $exception = new $class('test message');
        $this->assertInstanceOf(\JsonSerializable::class, $exception);

        $serialized = $exception->jsonSerialize();
        $this->assertIsArray($serialized);
        $this->assertArrayHasKey('title', $serialized);
        $this->assertArrayHasKey('detail', $serialized);
        $this->assertArrayHasKey('code', $serialized);
    }

    /**
     * @dataProvider exceptionProvider
     */
    public function testExceptionToErrorArrayMatchesJsonSerialize(string $class): void
    {
        $exception = new $class('test message');
        $this->assertSame(
            $exception->toErrorArray(),
            $exception->jsonSerialize(),
        );
    }

    public function testAllExceptionCodesAreUnique(): void
    {
        $codes = [];

        foreach (self::exceptionProvider() as $name => $config) {
            $codes[$name] = $config['code'];
        }

        $uniqueCodes = array_unique($codes);
        $this->assertSameSize($codes, $uniqueCodes, 'All exception error codes must be unique.');
    }

    public function testExceptionCustomCodeOverridesDefault(): void
    {
        $exception = InvalidStateDomainException::because('test', code: 'CUSTOM_CODE');
        $this->assertSame('CUSTOM_CODE', $exception->errorCode());
    }

    public function testOptimisticLockExceptionForFactory(): void
    {
        $exception = OptimisticLockException::for(
            aggregateId: 'order-123',
            expectedVersion: 5,
            actualVersion: 3,
        );

        $this->assertSame('OPTIMISTIC_LOCK', $exception->errorCode());
        $this->assertSame(409, $exception->httpStatus());
        $this->assertStringContainsString('order-123', $exception->getMessage());
        $this->assertStringContainsString('5', $exception->getMessage());
        $this->assertStringContainsString('3', $exception->getMessage());
    }

    public function testNotFoundDomainExceptionForAggregateFactory(): void
    {
        $exception = NotFoundDomainException::forAggregate('Order', 'uuid-123');

        $this->assertSame('NOT_FOUND', $exception->errorCode());
        $this->assertSame(404, $exception->httpStatus());
        $this->assertStringContainsString('Order', $exception->getMessage());
        $this->assertStringContainsString('uuid-123', $exception->getMessage());
    }

    public function testAggregateNotFoundExceptionForFactory(): void
    {
        $exception = AggregateNotFoundException::for('App\Domain\Order', 'uuid-456');

        $this->assertSame('AGGREGATE_NOT_FOUND', $exception->errorCode());
        $this->assertSame(404, $exception->httpStatus());
        $this->assertStringContainsString('App\Domain\Order', $exception->getMessage());
        $this->assertStringContainsString('uuid-456', $exception->getMessage());
    }

    // ──────────────────────────────────────────────────────
    // 5. Snapshot Readonly Immutability
    // ──────────────────────────────────────────────────────

    public function testSnapshotIsReadonlyClass(): void
    {
        $reflection = new \ReflectionClass(Snapshot::class);

        $this->assertTrue($reflection->isReadOnly());
        $this->assertTrue($reflection->isFinal());
    }

    public function testSnapshotPropertiesAreReadonly(): void
    {
        $reflection = new \ReflectionClass(Snapshot::class);
        $properties = $reflection->getProperties();

        foreach ($properties as $property) {
            $this->assertTrue(
                $property->isReadOnly(),
                sprintf('Snapshot::$%s must be readonly', $property->getName()),
            );
        }
    }

    public function testSnapshotRoundTripViaArray(): void
    {
        $original = Snapshot::create('Order', 'uuid-123', 5, ['status' => 'paid']);
        $restored = Snapshot::fromArray($original->toArray());

        $this->assertTrue($original->equals($restored));
        $this->assertSame($original->aggregateType, $restored->aggregateType);
        $this->assertSame($original->aggregateId, $restored->aggregateId);
        $this->assertSame($original->version, $restored->version);
        $this->assertSame($original->state, $restored->state);
    }

    public function testSnapshotRoundTripViaJson(): void
    {
        $original = Snapshot::create('Order', 'uuid-123', 5, ['status' => 'paid']);
        $restored = Snapshot::fromJson($original->toJson());

        $this->assertTrue($original->equals($restored));
    }

    public function testSnapshotJsonSerializeMatchesToArray(): void
    {
        $snapshot = Snapshot::create('Order', 'uuid-123', 5, ['status' => 'paid']);

        $this->assertSame($snapshot->toArray(), $snapshot->jsonSerialize());
    }

    public function testSnapshotEqualsIsStructural(): void
    {
        $snapshotA = Snapshot::create('Order', 'uuid-123', 5, ['status' => 'paid']);
        $snapshotB = Snapshot::create('Order', 'uuid-123', 5, ['status' => 'paid']);
        $snapshotC = Snapshot::create('Order', 'uuid-123', 6, ['status' => 'paid']);
        $snapshotD = Snapshot::create('Invoice', 'uuid-123', 5, ['status' => 'paid']);

        $this->assertTrue($snapshotA->equals($snapshotB));
        $this->assertFalse($snapshotA->equals($snapshotC));
        $this->assertFalse($snapshotA->equals($snapshotD));
    }

    public function testSnapshotFromArrayThrowsOnInvalidData(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Snapshot::fromArray(['aggregate_type' => 'Order']);  // Missing required fields
    }

    public function testSnapshotFromJsonThrowsOnInvalidJson(): void
    {
        $this->expectException(\JsonException::class);

        Snapshot::fromJson('not valid json {{{');
    }

    // ──────────────────────────────────────────────────────
    // 6. InMemorySnapshotStore Contract Compliance
    // ──────────────────────────────────────────────────────

    public function testInMemorySnapshotStoreImplementsSnapshotStore(): void
    {
        $store = new InMemorySnapshotStore;
        $this->assertInstanceOf(SnapshotStore::class, $store);
    }

    public function testInMemorySnapshotStoreSaveAndLoad(): void
    {
        $store = new InMemorySnapshotStore;
        $snapshot = Snapshot::create('Order', 'uuid-123', 5, ['status' => 'paid']);

        $store->save($snapshot);
        $loaded = $store->load('Order', 'uuid-123');

        $this->assertNotNull($loaded);
        $this->assertTrue($snapshot->equals($loaded));
    }

    public function testInMemorySnapshotStoreReturnsNullForMissing(): void
    {
        $store = new InMemorySnapshotStore;
        $this->assertNull($store->load('Order', 'nonexistent'));
    }

    public function testInMemorySnapshotStoreHasCheck(): void
    {
        $store = new InMemorySnapshotStore;
        $snapshot = Snapshot::create('Order', 'uuid-123', 5, []);

        $this->assertFalse($store->has('Order', 'uuid-123'));

        $store->save($snapshot);

        $this->assertTrue($store->has('Order', 'uuid-123'));
    }

    public function testInMemorySnapshotStoreDelete(): void
    {
        $store = new InMemorySnapshotStore;
        $snapshot = Snapshot::create('Order', 'uuid-123', 5, []);
        $store->save($snapshot);

        $store->delete('Order', 'uuid-123');

        $this->assertNull($store->load('Order', 'uuid-123'));
        $this->assertFalse($store->has('Order', 'uuid-123'));
    }

    public function testInMemorySnapshotStoreCount(): void
    {
        $store = new InMemorySnapshotStore;

        $store->save(Snapshot::create('Order', 'id-1', 1, []));
        $store->save(Snapshot::create('Order', 'id-2', 1, []));
        $store->save(Snapshot::create('Invoice', 'id-3', 1, []));

        $this->assertSame(3, $store->count());
        $this->assertSame(2, $store->count('Order'));
        $this->assertSame(1, $store->count('Invoice'));
    }

    public function testInMemorySnapshotStoreStats(): void
    {
        $store = new InMemorySnapshotStore;

        $store->save(Snapshot::create('Order', 'id-1', 1, []));
        $store->save(Snapshot::create('Order', 'id-2', 1, []));
        $store->save(Snapshot::create('Invoice', 'id-3', 1, []));

        $stats = $store->stats();

        $this->assertArrayHasKey('total', $stats);
        $this->assertArrayHasKey('by_type', $stats);
        $this->assertSame(3, $stats['total']);
        $this->assertSame(2, $stats['by_type']['Order']);
        $this->assertSame(1, $stats['by_type']['Invoice']);
    }

    public function testInMemorySnapshotStorePurge(): void
    {
        $store = new InMemorySnapshotStore;

        $store->save(Snapshot::create('Order', 'id-1', 1, []));
        $store->save(Snapshot::create('Invoice', 'id-2', 1, []));

        $removed = $store->purge('Order');
        $this->assertSame(1, $removed);
        $this->assertSame(1, $store->count());
        $this->assertNull($store->load('Order', 'id-1'));
        $this->assertNotNull($store->load('Invoice', 'id-2'));

        $removed = $store->purge();
        $this->assertSame(1, $removed);
        $this->assertSame(0, $store->count());
    }

    public function testInMemorySnapshotStoreDeleteOlderThan(): void
    {
        $store = new InMemorySnapshotStore;

        $store->save(Snapshot::create('Order', 'id-1', 3, []));
        $store->save(Snapshot::create('Order', 'id-1', 7, []));

        $store->deleteOlderThan('Order', 'id-1', 5);

        $loaded = $store->load('Order', 'id-1');
        $this->assertNotNull($loaded);
        $this->assertSame(7, $loaded->version);
    }

    // ──────────────────────────────────────────────────────
    // 7. AggregateRootId Identity Contract
    // ──────────────────────────────────────────────────────

    public function testAggregateRootIdGeneratesUniqueIds(): void
    {
        $idA = AggregateRootId::generate();
        $idB = AggregateRootId::generate();

        $this->assertNotSame($idA->toString(), $idB->toString());
    }

    public function testAggregateRootIdRoundTrip(): void
    {
        $original = AggregateRootId::generate();
        $restored = AggregateRootId::fromString($original->toString());

        $this->assertTrue($original->equals($restored));
    }

    public function testAggregateRootIdJsonSerializable(): void
    {
        $id = AggregateRootId::generate();
        $json = json_encode($id);

        $this->assertIsString($json);
        $this->assertSame('"' . $id->toString() . '"', $json);
    }

    // ──────────────────────────────────────────────────────
    // 8. toJson/fromJson Contract on Entities
    // ──────────────────────────────────────────────────────

    public function testEntityToJsonAndJsonSerializeAreConsistent(): void
    {
        $entity = new TestConcreteEntity('json-test-id', 'JSON Test');

        $this->assertSame(
            $entity->toJson(),
            json_encode($entity->toArray(), JSON_UNESCAPED_UNICODE),
        );
    }

    public function testEntityFromJsonRoundTrip(): void
    {
        $original = new TestConcreteEntity('json-roundtrip-id', 'Round Trip');
        $json = $original->toJson();
        $restored = TestConcreteEntity::fromJson($json);

        $this->assertSame($original->id(), $restored->id());
        $this->assertSame($original->toArray()['name'], $restored->toArray()['name']);
    }

    public function testAggregateRootToJsonRoundTrip(): void
    {
        $original = new TestConcreteAggregate(AggregateRootId::generate(), 'JSON Aggregate');
        $original->incrementVersion();
        $json = $original->toJson();
        $restored = TestConcreteAggregate::fromJson($json);

        $this->assertSame($original->id(), $restored->id());
        $this->assertSame($original->version(), $restored->version());
    }

    public function testIdentifierFromJsonRoundTrip(): void
    {
        $original = TestUuidIdentifier::generate();
        $json = json_encode($original);
        $restored = TestUuidIdentifier::fromJson($json);

        $this->assertTrue($original->equals($restored));
    }
}

// ──────────────────────────────────────────────────────
// Test Fixtures
// ──────────────────────────────────────────────────────

if (! class_exists(\TestStringIdentifier::class)) {
    final readonly class TestStringIdentifier extends \ZeroBoiler\Domain\Identifiers\StringIdentifier {}
}

if (! class_exists(\TestIntIdentifier::class)) {
    final readonly class TestIntIdentifier extends \ZeroBoiler\Domain\Identifiers\IntegerIdentifier {}
}
