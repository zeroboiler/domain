<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace Tests\Domain;

use PHPUnit\Framework\TestCase;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\DomainEventCollection;
use ZeroBoiler\Domain\Identifiers\IntegerIdentifier;
use ZeroBoiler\Domain\Identifiers\StringIdentifier;
use ZeroBoiler\Domain\Identifiers\UlidIdentifier;
use ZeroBoiler\Domain\Identifiers\UuidIdentifier;
use ZeroBoiler\Domain\Snapshots\InMemorySnapshotStore;
use ZeroBoiler\Domain\Snapshots\Snapshot;
use ZeroBoiler\Domain\Snapshots\SnapshotPolicy;

/**
 * Production readiness audit — identifier round-trip and domain invariant verification.
 *
 * @covers \ZeroBoiler\Domain\AggregateRootId
 * @covers \ZeroBoiler\Domain\Identifiers\UuidIdentifier
 * @covers \ZeroBoiler\Domain\Identifiers\UlidIdentifier
 * @covers \ZeroBoiler\Domain\Identifiers\StringIdentifier
 * @covers \ZeroBoiler\Domain\Identifiers\IntegerIdentifier
 * @covers \ZeroBoiler\Domain\DomainEventCollection
 * @covers \ZeroBoiler\Domain\Snapshots\Snapshot
 * @covers \ZeroBoiler\Domain\Snapshots\InMemorySnapshotStore
 */
final class DomainProductionSerdeAuditTest extends TestCase
{
    // ---------------------------------------------------------------
    // AggregateRootId round-trip
    // ---------------------------------------------------------------

    public function testAggregateRootIdRoundTrip(): void
    {
        $original = AggregateRootId::generate();
        $restored = AggregateRootId::fromArray($original->toArray());

        self::assertTrue($original->equals($restored));
        self::assertSame($original->toString(), $restored->toString());
    }

    public function testAggregateRootIdFromArrayAcceptsIdKey(): void
    {
        $original = AggregateRootId::generate();
        $restored = AggregateRootId::fromArray(['id' => $original->toString()]);

        self::assertTrue($original->equals($restored));
    }

    public function testAggregateRootIdFromArrayRejectsMissingKeys(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        AggregateRootId::fromArray(['foo' => 'bar']);
    }

    public function testAggregateRootIdJsonSerializable(): void
    {
        $id = AggregateRootId::generate();

        self::assertSame($id->toString(), json_encode($id));
    }

    public function testAggregateRootIdToStringAndMagicToStringAreConsistent(): void
    {
        $id = AggregateRootId::generate();

        self::assertSame($id->toString(), (string) $id);
    }

    // ---------------------------------------------------------------
    // UuidIdentifier round-trip
    // ---------------------------------------------------------------

    public function testUuidIdentifierRoundTrip(): void
    {
        $original = UuidOrderId::generate();
        $restored = UuidOrderId::fromArray($original->toArray());

        self::assertTrue($original->equals($restored));
        self::assertSame($original->toString(), $restored->toString());
    }

    public function testUuidIdentifierFromArrayAcceptsIdKey(): void
    {
        $original = UuidOrderId::generate();
        $restored = UuidOrderId::fromArray(['id' => $original->toString()]);

        self::assertTrue($original->equals($restored));
    }

    public function testUuidIdentifierCrossTypeNotEqual(): void
    {
        $id1 = UuidOrderId::generate();
        $id2 = UuidProductId::generate();

        self::assertFalse($id1->equals($id2));
    }

    // ---------------------------------------------------------------
    // UlidIdentifier round-trip
    // ---------------------------------------------------------------

    public function testUlidIdentifierRoundTrip(): void
    {
        $original = UlidProductId::generate();
        $restored = UlidProductId::fromArray($original->toArray());

        self::assertTrue($original->equals($restored));
        self::assertSame($original->toString(), $restored->toString());
    }

    public function testUlidIdentifierIsValid(): void
    {
        $id = UlidProductId::generate();
        self::assertTrue(UlidProductId::isValid($id->toString()));
        self::assertFalse(UlidProductId::isValid('not-a-ulid'));
    }

    // ---------------------------------------------------------------
    // StringIdentifier round-trip
    // ---------------------------------------------------------------

    public function testStringIdentifierRoundTrip(): void
    {
        $original = StringIdentifier::from('my-blog-post');
        $restored = StringIdentifier::fromArray($original->toArray());

        self::assertTrue($original->equals($restored));
        self::assertSame('my-blog-post', $restored->toString());
    }

    public function testStringIdentifierRejectsEmptyString(): void
    {
        $this->expectException(\ValueError::class);

        StringIdentifier::from('');
    }

    public function testStringIdentifierFromArrayAcceptsIdKey(): void
    {
        $restored = StringIdentifier::fromArray(['id' => 'my-slug']);
        self::assertSame('my-slug', $restored->toString());
    }

    // ---------------------------------------------------------------
    // IntegerIdentifier round-trip
    // ---------------------------------------------------------------

    public function testIntegerIdentifierRoundTrip(): void
    {
        $original = IntegerIdentifier::from(42);
        $restored = IntegerIdentifier::fromArray($original->toArray());

        self::assertTrue($original->equals($restored));
        self::assertSame(42, $restored->toInt());
    }

    public function testIntegerIdentifierFromArrayAcceptsIdKeyAsInt(): void
    {
        $restored = IntegerIdentifier::fromArray(['id' => 99]);
        self::assertSame(99, $restored->toInt());
    }

    public function testIntegerIdentifierFromArrayAcceptsIdKeyAsString(): void
    {
        $restored = IntegerIdentifier::fromArray(['id' => '42']);
        self::assertSame(42, $restored->toInt());
    }

    public function testIntegerIdentifierJsonSerializeReturnsInt(): void
    {
        $id = IntegerIdentifier::from(42);
        self::assertSame(42, json_encode($id));
    }

    // ---------------------------------------------------------------
    // Snapshot round-trip
    // ---------------------------------------------------------------

    public function testSnapshotRoundTrip(): void
    {
        $original = Snapshot::create(
            aggregateType: 'App\\Domain\\Order',
            aggregateId: '550e8400-e29b-41d4-a716-446655440000',
            version: 50,
            state: ['status' => 'shipped', 'total' => 99.99],
        );

        $restored = Snapshot::fromArray($original->toArray());

        self::assertTrue($original->equals($restored));
        self::assertSame($original->aggregateType, $restored->aggregateType);
        self::assertSame($original->aggregateId, $restored->aggregateId);
        self::assertSame($original->version, $restored->version);
        self::assertSame($original->state, $restored->state);
    }

    public function testSnapshotJsonSerializable(): void
    {
        $snapshot = Snapshot::create('Order', 'id-1', 10, ['key' => 'value']);
        $json = json_encode($snapshot);

        self::assertIsString($json);
        $data = json_decode((string) $json, true);
        self::assertSame('Order', $data['aggregate_type']);
        self::assertSame('id-1', $data['aggregate_id']);
        self::assertSame(10, $data['version']);
    }

    public function testSnapshotFromArrayRejectsInvalidData(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Snapshot::fromArray(['foo' => 'bar']);
    }

    // ---------------------------------------------------------------
    // InMemorySnapshotStore
    // ---------------------------------------------------------------

    public function testInMemorySnapshotStoreCrud(): void
    {
        $store = new InMemorySnapshotStore();
        $snapshot = Snapshot::create('Order', 'id-1', 10, ['status' => 'pending']);

        self::assertFalse($store->has('Order', 'id-1'));
        self::assertSame(0, $store->count());

        $store->save($snapshot);

        self::assertTrue($store->has('Order', 'id-1'));
        self::assertSame(1, $store->count());

        $loaded = $store->load('Order', 'id-1');
        self::assertNotNull($loaded);
        self::assertSame(10, $loaded->version);

        $store->delete('Order', 'id-1');
        self::assertFalse($store->has('Order', 'id-1'));
        self::assertNull($store->load('Order', 'id-1'));
    }

    public function testInMemorySnapshotStoreStats(): void
    {
        $store = new InMemorySnapshotStore();
        $store->save(Snapshot::create('Order', 'id-1', 1, []));
        $store->save(Snapshot::create('Order', 'id-2', 2, []));
        $store->save(Snapshot::create('User', 'id-1', 5, []));

        $stats = $store->stats();
        self::assertSame(3, $stats['total']);
        self::assertSame(2, $stats['by_type']['Order']);
        self::assertSame(1, $stats['by_type']['User']);
    }

    public function testInMemorySnapshotStorePurgeByType(): void
    {
        $store = new InMemorySnapshotStore();
        $store->save(Snapshot::create('Order', 'id-1', 1, []));
        $store->save(Snapshot::create('User', 'id-1', 1, []));

        $removed = $store->purge('Order');
        self::assertSame(1, $removed);
        self::assertSame(1, $store->count());
        self::assertTrue($store->has('User', 'id-1'));
    }

    // ---------------------------------------------------------------
    // SnapshotPolicy attribute
    // ---------------------------------------------------------------

    public function testSnapshotPolicyAttributeDefaults(): void
    {
        $policy = new SnapshotPolicy();
        self::assertSame(50, $policy->every);
    }

    public function testSnapshotPolicyAttributeCustom(): void
    {
        $policy = new SnapshotPolicy(every: 100);
        self::assertSame(100, $policy->every);
    }

    public function testSnapshotPolicyAttributeDisabled(): void
    {
        $policy = new SnapshotPolicy(every: 0);
        self::assertSame(0, $policy->every);
    }

    // ---------------------------------------------------------------
    // Strict types verification — all domain classes
    // ---------------------------------------------------------------

    public function testAllDomainClassesUseStrictTypes(): void
    {
        $classes = [
            \ZeroBoiler\Domain\Entity::class,
            \ZeroBoiler\Domain\AggregateRoot::class,
            \ZeroBoiler\Domain\AggregateRootId::class,
            \ZeroBoiler\Domain\ValueObject::class,
            \ZeroBoiler\Domain\DomainEventCollection::class,
            \ZeroBoiler\Domain\InMemoryUnitOfWork::class,
            \ZeroBoiler\Domain\Exceptions\DomainException::class,
            \ZeroBoiler\Domain\Exceptions\InvalidStateDomainException::class,
            \ZeroBoiler\Domain\Exceptions\InvalidArgumentDomainException::class,
            \ZeroBoiler\Domain\Exceptions\NotFoundDomainException::class,
            \ZeroBoiler\Domain\Exceptions\ConflictDomainException::class,
            \ZeroBoiler\Domain\Exceptions\AggregateNotFoundException::class,
            \ZeroBoiler\Domain\Exceptions\OptimisticLockException::class,
            \ZeroBoiler\Domain\Exceptions\InvalidStateException::class,
            \ZeroBoiler\Domain\Exceptions\InvalidAggregateRootException::class,
            \ZeroBoiler\Domain\Identifiers\UuidIdentifier::class,
            \ZeroBoiler\Domain\Identifiers\UlidIdentifier::class,
            \ZeroBoiler\Domain\Identifiers\StringIdentifier::class,
            \ZeroBoiler\Domain\Identifiers\IntegerIdentifier::class,
            \ZeroBoiler\Domain\Identifiers\Identifier::class,
            \ZeroBoiler\Domain\Snapshots\Snapshot::class,
            \ZeroBoiler\Domain\Snapshots\SnapshotPolicy::class,
            \ZeroBoiler\Domain\Snapshots\InMemorySnapshotStore::class,
            \ZeroBoiler\Domain\Snapshots\SnapshotStore::class,
            \ZeroBoiler\Domain\Snapshots\SnapshottingRepository::class,
            \ZeroBoiler\Domain\Contracts\Entity::class,
            \ZeroBoiler\Domain\Contracts\AggregateRoot::class,
            \ZeroBoiler\Domain\Contracts\Identifier::class,
            \ZeroBoiler\Domain\Contracts\Repository::class,
            \ZeroBoiler\Domain\Contracts\UnitOfWork::class,
        ];

        foreach ($classes as $class) {
            $ref = new \ReflectionClass($class);
            $file = (string) $ref->getFileName();
            $contents = (string) file_get_contents($file);
            self::assertStringContainsString(
                'declare(strict_types=1)',
                $contents,
                "Class {$class} does not declare strict_types=1",
            );
        }
    }
}

// Concrete identifier subclasses for testing equality across types.
// These extend abstract readonly parent classes, so must also be readonly.

final readonly class UuidOrderId extends \ZeroBoiler\Domain\Identifiers\UuidIdentifier {}
final readonly class UuidProductId extends \ZeroBoiler\Domain\Identifiers\UuidIdentifier {}
final readonly class UlidProductId extends \ZeroBoiler\Domain\Identifiers\UlidIdentifier {}
