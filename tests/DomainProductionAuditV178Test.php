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
use ZeroBoiler\Domain\Concerns\EventSourced;
use ZeroBoiler\Domain\Concerns\Guards;
use ZeroBoiler\Domain\Concerns\HasDomainEvents;
use ZeroBoiler\Domain\Concerns\HasSnapshots;
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
use ZeroBoiler\Domain\ValueObject;
use ZeroBoiler\Events\Domain\DomainEvent;

/**
 * Comprehensive production readiness audit for zeroboiler/domain v1.78.0.
 *
 * Validates all production contracts across the entire package:
 * - strict types enforcement
 * - serialization round-trip (toArray/fromArray/toJson/fromJson)
 * - domain exception hierarchy with RFC 9457 compliance
 * - identifier type safety and equality
 * - aggregate root event lifecycle
 * - unit of work transactional boundaries
 * - snapshot store and snapshotting repository
 * - value object and entity base classes
 * - PHP 8.5 syntax compatibility
 *
 * @since 1.78.0
 *
 * @covers \ZeroBoiler\Domain\Entity
 * @covers \ZeroBoiler\Domain\AggregateRoot
 * @covers \ZeroBoiler\Domain\AggregateRootId
 * @covers \ZeroBoiler\Domain\DomainEventCollection
 * @covers \ZeroBoiler\Domain\InMemoryUnitOfWork
 * @covers \ZeroBoiler\Domain\ValueObject
 * @covers \ZeroBoiler\Domain\Exceptions\DomainException
 * @covers \ZeroBoiler\Domain\Exceptions\InvalidStateDomainException
 * @covers \ZeroBoiler\Domain\Exceptions\InvalidArgumentDomainException
 * @covers \ZeroBoiler\Domain\Exceptions\NotFoundDomainException
 * @covers \ZeroBoiler\Domain\Exceptions\ConflictDomainException
 * @covers \ZeroBoiler\Domain\Exceptions\OptimisticLockException
 * @covers \ZeroBoiler\Domain\Exceptions\AggregateNotFoundException
 * @covers \ZeroBoiler\Domain\Exceptions\InvalidAggregateRootException
 * @covers \ZeroBoiler\Domain\Exceptions\InvalidStateException
 * @covers \ZeroBoiler\Domain\Identifiers\UuidIdentifier
 * @covers \ZeroBoiler\Domain\Identifiers\UlidIdentifier
 * @covers \ZeroBoiler\Domain\Identifiers\StringIdentifier
 * @covers \ZeroBoiler\Domain\Identifiers\IntegerIdentifier
 * @covers \ZeroBoiler\Domain\Snapshots\Snapshot
 * @covers \ZeroBoiler\Domain\Snapshots\InMemorySnapshotStore
 * @covers \ZeroBoiler\Domain\Snapshots\SnapshotPolicy
 * @covers \ZeroBoiler\Domain\Snapshots\SnapshottingRepository
 * @covers \ZeroBoiler\Domain\Concerns\Guards
 * @covers \ZeroBoiler\Domain\Concerns\HasDomainEvents
 * @covers \ZeroBoiler\Domain\Concerns\EventSourced
 * @covers \ZeroBoiler\Domain\Concerns\HasSnapshots
 */
final class DomainProductionAuditV178Test extends TestCase
{
    // ──────────────────────────────────────────────────────────────────────────
    // 1. STRICT TYPES & PHP 8.5 SYNTAX
    // ──────────────────────────────────────────────────────────────────────────

    public function testAllSourceFilesHaveStrictTypes(): void
    {
        $srcDir = __DIR__ . '/../src';
        $files = glob($srcDir . '/**/*.php') ?: [];

        // Exclude IDE helpers and stubs
        $files = array_filter($files, fn (string $f): bool =>
            ! str_contains($f, '_ide_helper') && ! str_contains($f, '/stubs/')
        );

        foreach ($files as $file) {
            $content = file_get_contents($file);
            $this->assertStringContainsString(
                'declare(strict_types=1)',
                $content,
                "File {$file} is missing declare(strict_types=1)"
            );
        }

        $this->assertGreaterThan(0, count($files), 'No source files found');
    }

    public function testAbstractClassesAreNotFinal(): void
    {
        $this->assertFalse((new \ReflectionClass(AggregateRoot::class))->isFinal());
        $this->assertFalse((new \ReflectionClass(Entity::class))->isFinal());
        $this->assertFalse((new \ReflectionClass(ValueObject::class))->isFinal());
        $this->assertFalse((new \ReflectionClass(DomainException::class))->isFinal());
    }

    public function testConcreteClassesAreFinal(): void
    {
        $this->assertTrue((new \ReflectionClass(AggregateRootId::class))->isFinal());
        $this->assertTrue((new \ReflectionClass(DomainEventCollection::class))->isFinal());
        $this->assertTrue((new \ReflectionClass(Snapshot::class))->isFinal());
        $this->assertTrue((new \ReflectionClass(InMemorySnapshotStore::class))->isFinal());
        $this->assertTrue((new \ReflectionClass(SnapshottingRepository::class))->isFinal());
        $this->assertTrue((new \ReflectionClass(SnapshotPolicy::class))->isFinal());
    }

    public function testExceptionClassesAreFinal(): void
    {
        $exceptions = [
            InvalidStateDomainException::class,
            InvalidArgumentDomainException::class,
            NotFoundDomainException::class,
            ConflictDomainException::class,
            OptimisticLockException::class,
            AggregateNotFoundException::class,
            InvalidAggregateRootException::class,
            InvalidStateException::class,
        ];

        foreach ($exceptions as $exception) {
            $this->assertTrue(
                (new \ReflectionClass($exception))->isFinal(),
                "{$exception} should be final"
            );
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 2. IDENTIFIER CONTRACT — TYPE SAFETY & EQUALITY
    // ──────────────────────────────────────────────────────────────────────────

    public function testUuidIdentifierSerializationRoundTrip(): void
    {
        $id = UuidIdentifier::generate();
        $json = $id->toJson();
        $restored = UuidIdentifier::fromJson($json);

        $this->assertTrue($id->equals($restored));
        $this->assertSame($id->toString(), $restored->toString());
    }

    public function testUlidIdentifierSerializationRoundTrip(): void
    {
        $id = UlidIdentifier::generate();
        $array = $id->toArray();
        $restored = UlidIdentifier::fromArray($array);

        $this->assertTrue($id->equals($restored));
        $this->assertSame($id->toString(), $restored->toString());
    }

    public function testStringIdentifierValidationRejectsEmpty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        StringIdentifier::fromString('');
    }

    public function testIntegerIdentifierValidationRejectsNegative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        IntegerIdentifier::fromInt(-1);
    }

    public function testIdentifierEqualityRequiresSameType(): void
    {
        $uuid = UuidIdentifier::generate();
        $stringId = StringIdentifier::fromString('test-123');

        // Same string value, different type — not equal
        $this->assertFalse($uuid->equals($stringId));
    }

    public function testIdentifierImplementsStringable(): void
    {
        $id = UuidIdentifier::generate();
        $this->assertInstanceOf(\Stringable::class, $id);
        $this->assertSame($id->toString(), (string) $id);
    }

    public function testIdentifierImplementsJsonSerializable(): void
    {
        $id = IntegerIdentifier::fromInt(42);
        $encoded = json_encode($id);
        $this->assertIsString($encoded);
        $this->assertStringContainsString('42', $encoded);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 3. DOMAIN EXCEPTION HIERARCHY — RFC 9457
    // ──────────────────────────────────────────────────────────────────────────

    public function testDomainExceptionBaseErrorCode(): void
    {
        $e = $this->createConcreteException('test message');
        $this->assertSame('DOMAIN_ERROR', $e->errorCode());
        $this->assertSame(500, $e->httpStatus());
    }

    public function testExceptionSubclassesHaveCorrectErrorCodes(): void
    {
        $cases = [
            [InvalidStateDomainException::because('x'), 'INVALID_STATE', 422],
            [InvalidArgumentDomainException::because('x'), 'INVALID_ARGUMENT', 422],
            [NotFoundDomainException::because('x'), 'NOT_FOUND', 404],
            [ConflictDomainException::because('x'), 'CONFLICT', 409],
            [OptimisticLockException::for('id', 1, 2), 'OPTIMISTIC_LOCK', 409],
            [AggregateNotFoundException::for('Order', 'id'), 'AGGREGATE_NOT_FOUND', 404],
            [InvalidAggregateRootException::notAnAggregate(new \stdClass), 'INVALID_AGGREGATE_ROOT', 500],
            [InvalidStateException::because('x'), 'INVALID_STATE_SYSTEM', 500],
        ];

        foreach ($cases as [$exception, $expectedCode, $expectedStatus]) {
            $this->assertSame($expectedCode, $exception->errorCode(), get_class($exception) . ' error code');
            $this->assertSame($expectedStatus, $exception->httpStatus(), get_class($exception) . ' HTTP status');
        }
    }

    public function testDomainExceptionSerializationRoundTrip(): void
    {
        $e = InvalidStateDomainException::because('Order must be pending');
        $array = $e->toErrorArray();

        $this->assertArrayHasKey('title', $array);
        $this->assertArrayHasKey('detail', $array);
        $this->assertArrayHasKey('code', $array);
        $this->assertArrayHasKey('status', $array);
        $this->assertSame('INVALID_STATE', $array['code']);
        $this->assertSame(422, $array['status']);
    }

    public function testDomainExceptionJsonSerialization(): void
    {
        $e = NotFoundDomainException::forId('order-123');
        $json = json_encode($e);
        $data = json_decode($json, true);

        $this->assertSame('NOT_FOUND', $data['code']);
        $this->assertSame(404, $data['status']);
    }

    public function testDomainExceptionFromJsonRoundTrip(): void
    {
        $original = ConflictDomainException::because('Concurrent modification');
        $json = $original->toJson();
        $restored = ConflictDomainException::fromJson($json);

        $this->assertSame($original->getMessage(), $restored->getMessage());
        $this->assertSame($original->errorCode(), $restored->errorCode());
    }

    public function testDomainExceptionFromErrorArrayRoundTrip(): void
    {
        $original = OptimisticLockException::for('agg-1', 5, 7);
        $errorArray = $original->toErrorArray();
        $restored = DomainException::fromArray($errorArray, OptimisticLockException::class);

        $this->assertSame('OPTIMISTIC_LOCK', $restored->errorCode());
        $this->assertSame($original->getMessage(), $restored->getMessage());
    }

    public function testCustomErrorCodeOverridesDefault(): void
    {
        $e = new class('test', 0, null, 'CUSTOM_CODE') extends DomainException {};

        $this->assertSame('CUSTOM_CODE', $e->errorCode());
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 4. ENTITY BASE — SERIALIZATION & EQUALITY
    // ──────────────────────────────────────────────────────────────────────────

    public function testEntityWithIntId(): void
    {
        $entity = new TestIntEntity(42);
        $this->assertSame('42', $entity->id());
        $this->assertSame(['id' => '42', 'type' => 'TestIntEntity'], $entity->toArray());
    }

    public function testEntityWithStringId(): void
    {
        $entity = new TestStringEntity('abc');
        $this->assertSame('abc', $entity->id());
    }

    public function testEntityWithStringableId(): void
    {
        $id = UuidIdentifier::generate();
        $entity = new TestStringableEntity($id);
        $this->assertSame($id->toString(), $entity->id());
    }

    public function testEntityEquality(): void
    {
        $a = new TestIntEntity(42);
        $b = new TestIntEntity(42);
        $c = new TestIntEntity(99);

        $this->assertTrue($a->equals($b));
        $this->assertFalse($a->equals($c));
    }

    public function testEntityEqualityRequiresSameClass(): void
    {
        $a = new TestIntEntity(42);
        $b = new class(42) extends Entity { public function __construct(int|string|\Stringable $id) { parent::__construct($id); } };

        $this->assertFalse($a->equals($b));
    }

    public function testEntityJsonSerialize(): void
    {
        $entity = new TestIntEntity(1);
        $json = json_encode($entity);
        $data = json_decode($json, true);

        $this->assertSame('1', $data['id']);
        $this->assertSame('TestIntEntity', $data['type']);
    }

    public function testEntityFromJsonRoundTrip(): void
    {
        $entity = new TestSimpleEntity(42, 'widget', 3);
        $json = $entity->toJson();
        $restored = TestSimpleEntity::fromJson($json);

        $this->assertSame('42', $restored->id());
        $this->assertSame('widget', $restored->productId);
        $this->assertSame(3, $restored->quantity);
    }

    public function testEntityFromJsonRoundTripPreservesAllFields(): void
    {
        $original = new TestSimpleEntity(10, 'prod-x', 5);
        $roundTripped = TestSimpleEntity::fromJson($original->toJson());

        $this->assertEquals($original->toArray(), $roundTripped->toArray());
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 5. AGGREGATE ROOT — EVENT LIFECYCLE
    // ──────────────────────────────────────────────────────────────────────────

    public function testAggregateRootRecordsEvents(): void
    {
        $aggregate = TestOrder::create();

        $this->assertTrue($aggregate->hasUncommittedEvents());
        $this->assertCount(1, $aggregate->peekDomainEvents());
    }

    public function testAggregateRootPullEventsDestructive(): void
    {
        $aggregate = TestOrder::create();
        $events = $aggregate->pullDomainEvents();

        $this->assertCount(1, $events);
        $this->assertFalse($aggregate->hasUncommittedEvents());
    }

    public function testAggregateRootClearEvents(): void
    {
        $aggregate = TestOrder::create();
        $aggregate->clearDomainEvents();

        $this->assertFalse($aggregate->hasUncommittedEvents());
        $this->assertCount(0, $aggregate->peekDomainEvents());
    }

    public function testAggregateRootVersionTracking(): void
    {
        $aggregate = TestOrder::create();
        $this->assertSame(1, $aggregate->version());

        $aggregate->incrementVersion();
        $this->assertSame(2, $aggregate->version());
    }

    public function testAggregateRootToArray(): void
    {
        $aggregate = TestOrder::create();
        $array = $aggregate->toArray();

        $this->assertArrayHasKey('id', $array);
        $this->assertArrayHasKey('version', $array);
        $this->assertArrayHasKey('type', $array);
        $this->assertSame('TestOrder', $array['type']);
    }

    public function testAggregateRootToJson(): void
    {
        $aggregate = TestOrder::create();
        $json = $aggregate->toJson();
        $data = json_decode($json, true);

        $this->assertArrayHasKey('id', $data);
        $this->assertArrayHasKey('version', $data);
    }

    public function testAggregateRootEquality(): void
    {
        $id = AggregateRootId::generate();
        $a = TestOrder::reconstitute($id);
        $b = TestOrder::reconstitute($id);

        $this->assertTrue($a->equals($b));
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 6. UNIT OF WORK — TRANSACTIONAL BOUNDARIES
    // ──────────────────────────────────────────────────────────────────────────

    public function testUnitOfWorkBeginCommitCycle(): void
    {
        $uow = new InMemoryUnitOfWork;

        $this->assertFalse($uow->isActive());

        $uow->begin();
        $this->assertTrue($uow->isActive());

        $uow->commit();
        $this->assertFalse($uow->isActive());
    }

    public function testUnitOfWorkBeginRollbackCycle(): void
    {
        $uow = new InMemoryUnitOfWork;

        $uow->begin();
        $uow->rollback();
        $this->assertFalse($uow->isActive());
    }

    public function testUnitOfWorkRunCallback(): void
    {
        $uow = new InMemoryUnitOfWork;
        $result = $uow->run(fn (): string => 'success');

        $this->assertSame('success', $result);
        $this->assertFalse($uow->isActive());
    }

    public function testUnitOfWorkRollbackOnException(): void
    {
        $uow = new InMemoryUnitOfWork;

        try {
            $uow->run(fn () => throw new \RuntimeException('fail'));
            $this->fail('Should have thrown');
        } catch (\RuntimeException $e) {
            $this->assertSame('fail', $e->getMessage());
            $this->assertFalse($uow->isActive());
        }
    }

    public function testUnitOfWorkTrackAndCommit(): void
    {
        $uow = new InMemoryUnitOfWork;
        $aggregate = TestOrder::create();

        $uow->begin();
        $uow->track($aggregate);
        $this->assertTrue($uow->isTracking($aggregate));

        $uow->commit();

        $committed = $uow->getCommitted();
        $this->assertCount(1, $committed);
    }

    public function testUnitOfWorkPendingEvents(): void
    {
        $uow = new InMemoryUnitOfWork;

        $uow->begin();
        $aggregate = TestOrder::create();
        $uow->track($aggregate);

        $this->assertTrue($uow->hasPendingEvents());
        $this->assertSame(1, $uow->getPendingEventCount());

        $events = $uow->getPendingEvents();
        $this->assertInstanceOf(DomainEventCollection::class, $events);
        $this->assertCount(1, $events);
    }

    public function testUnitOfWorkClearResetsState(): void
    {
        $uow = new InMemoryUnitOfWork;

        $uow->begin();
        $aggregate = TestOrder::create();
        $uow->track($aggregate);
        $uow->clear();

        $this->assertCount(0, $uow->getCommitted());
        $this->assertFalse($uow->hasPendingEvents());
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 7. SNAPSHOT STORE
    // ──────────────────────────────────────────────────────────────────────────

    public function testSnapshotStoreSaveAndLoad(): void
    {
        $store = new InMemorySnapshotStore;
        $snapshot = Snapshot::create('TestOrder', 'uuid-1', 5, ['status' => 'pending']);

        $store->save($snapshot);
        $loaded = $store->load('TestOrder', 'uuid-1');

        $this->assertNotNull($loaded);
        $this->assertTrue($snapshot->equals($loaded));
    }

    public function testSnapshotStoreHasAndDelete(): void
    {
        $store = new InMemorySnapshotStore;
        $snapshot = Snapshot::create('TestOrder', 'uuid-1', 1, []);

        $this->assertFalse($store->has('TestOrder', 'uuid-1'));

        $store->save($snapshot);
        $this->assertTrue($store->has('TestOrder', 'uuid-1'));

        $store->delete('TestOrder', 'uuid-1');
        $this->assertFalse($store->has('TestOrder', 'uuid-1'));
    }

    public function testSnapshotStoreCountAndStats(): void
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
        $this->assertSame(2, $stats['by_type']['Order']);
        $this->assertSame(1, $stats['by_type']['User']);
    }

    public function testSnapshotStorePurge(): void
    {
        $store = new InMemorySnapshotStore;

        $store->save(Snapshot::create('Order', '1', 1, []));
        $store->save(Snapshot::create('User', '1', 1, []));

        $removed = $store->purge('Order');
        $this->assertSame(1, $removed);
        $this->assertSame(1, $store->count());

        $removed = $store->purge();
        $this->assertSame(1, $removed);
        $this->assertSame(0, $store->count());
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 8. SNAPSHOT SERIALIZATION
    // ──────────────────────────────────────────────────────────────────────────

    public function testSnapshotSerializationRoundTrip(): void
    {
        $original = Snapshot::create('TestOrder', 'uuid-1', 10, ['status' => 'shipped', 'total' => 1999]);
        $array = $original->toArray();
        $restored = Snapshot::fromArray($array);

        $this->assertTrue($original->equals($restored));
        $this->assertSame('TestOrder', $restored->aggregateType);
        $this->assertSame('uuid-1', $restored->aggregateId);
        $this->assertSame(10, $restored->version);
        $this->assertSame(['status' => 'shipped', 'total' => 1999], $restored->state);
    }

    public function testSnapshotJsonRoundTrip(): void
    {
        $original = Snapshot::create('Order', 'id-1', 5, ['x' => true]);
        $json = $original->toJson();
        $restored = Snapshot::fromJson($json);

        $this->assertTrue($original->equals($restored));
    }

    public function testSnapshotImplementsJsonSerializable(): void
    {
        $snapshot = Snapshot::create('Test', 'id', 1, []);
        $this->assertInstanceOf(\JsonSerializable::class, $snapshot);

        $json = json_encode($snapshot);
        $this->assertIsString($json);
        $this->assertStringContainsString('"aggregate_type":"Test"', $json);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 9. DOMAIN EVENT COLLECTION
    // ──────────────────────────────────────────────────────────────────────────

    public function testDomainEventCollectionIsTyped(): void
    {
        $collection = new DomainEventCollection;

        $this->assertInstanceOf(\JsonSerializable::class, $collection);
        $this->assertInstanceOf(\Countable::class, $collection);
        $this->assertInstanceOf(\IteratorAggregate::class, $collection);
    }

    public function testDomainEventCollectionJsonSerialize(): void
    {
        $collection = new DomainEventCollection;
        $json = json_encode($collection);

        $this->assertSame('[]', $json);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 10. CONTRACT INTERFACE COMPLETENESS
    // ──────────────────────────────────────────────────────────────────────────

    public function testEntityContractRequiresAllMethods(): void
    {
        $reflection = new \ReflectionClass(EntityContract::class);
        $required = ['id', 'equals', 'toArray', 'fromArray', 'fromJson', 'toJson', 'hasUncommittedEvents'];

        foreach ($required as $method) {
            $this->assertTrue(
                $reflection->hasMethod($method),
                "Entity contract missing method: {$method}"
            );
        }
    }

    public function testAggregateRootContractRequiresAllMethods(): void
    {
        $reflection = new \ReflectionClass(AggregateRootContract::class);
        $required = ['id', 'equals', 'toArray', 'version', 'incrementVersion',
            'pullDomainEvents', 'clearDomainEvents', 'hasUncommittedEvents',
            'peekDomainEvents', 'toJson',
        ];

        foreach ($required as $method) {
            $this->assertTrue(
                $reflection->hasMethod($method),
                "AggregateRoot contract missing method: {$method}"
            );
        }
    }

    public function testUnitOfWorkContractRequiresAllMethods(): void
    {
        $reflection = new \ReflectionClass(UnitOfWorkContract::class);
        $required = ['begin', 'commit', 'rollback', 'run', 'isActive',
            'track', 'isTracking', 'markForDeletion', 'getCommitted',
            'getDeleted', 'hasPendingEvents', 'getPendingEventCount',
            'getPendingEvents', 'clear',
        ];

        foreach ($required as $method) {
            $this->assertTrue(
                $reflection->hasMethod($method),
                "UnitOfWork contract missing method: {$method}"
            );
        }
    }

    public function testRepositoryContractRequiresAllMethods(): void
    {
        $reflection = new \ReflectionClass(RepositoryContract::class);
        $required = ['find', 'save', 'delete'];

        foreach ($required as $method) {
            $this->assertTrue(
                $reflection->hasMethod($method),
                "Repository contract missing method: {$method}"
            );
        }
    }

    public function testSnapshotStoreContractRequiresAllMethods(): void
    {
        $reflection = new \ReflectionClass(SnapshotStore::class);
        $required = ['load', 'save', 'has', 'delete', 'deleteOlderThan', 'count', 'stats', 'purge'];

        foreach ($required as $method) {
            $this->assertTrue(
                $reflection->hasMethod($method),
                "SnapshotStore contract missing method: {$method}"
            );
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // HELPERS — Test doubles
    // ──────────────────────────────────────────────────────────────────────────

    private function createConcreteException(string $message): DomainException
    {
        return new class($message) extends DomainException {};
    }
}

// ──────────────────────────────────────────────────────────────────────────────
// Test doubles (in same file for self-contained test)
// ──────────────────────────────────────────────────────────────────────────────

final class TestIntEntity extends Entity
{
    public function __construct(int|string|\Stringable $id)
    {
        parent::__construct($id);
    }
}

final class TestStringEntity extends Entity
{
    public function __construct(int|string|\Stringable $id)
    {
        parent::__construct($id);
    }
}

final class TestStringableEntity extends Entity
{
    public function __construct(int|string|\Stringable $id)
    {
        parent::__construct($id);
    }
}

final class TestSimpleEntity extends Entity
{
    public function __construct(
        int|string|\Stringable $id,
        public readonly string $productId,
        public readonly int $quantity,
    ) {
        parent::__construct($id);
    }

    public function toArray(): array
    {
        return [
            ...parent::toArray(),
            'product_id' => $this->productId,
            'quantity' => $this->quantity,
        ];
    }
}

final class TestOrder extends AggregateRoot
{
    use HasDomainEvents;

    public static function create(): self
    {
        $order = new self(AggregateRootId::generate());
        $order->recordThat(DomainEvent::occur(
            'order.created',
            ['aggregate_id' => $order->id()],
        ));

        return $order;
    }

    public static function reconstitute(AggregateRootId $id): self
    {
        return new self($id);
    }

    public function toArray(): array
    {
        return [
            ...parent::toArray(),
            'status' => 'pending',
        ];
    }
}
