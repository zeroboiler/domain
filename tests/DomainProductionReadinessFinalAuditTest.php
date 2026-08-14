<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

/**
 * Production readiness final audit for the domain package.
 *
 * Validates PHP 8.5 syntax patterns, strict types enforcement, immutability
 * guarantees, domain invariant protection, and complete round-trip
 * serialization coverage across all core domain primitives.
 *
 * This is the authoritative "release gate" test — all assertions must pass
 * before the package is considered production-ready.
 *
 * @internal Quality gate test for CI/CD release pipeline.
 *
 * @since 1.56.0
 */

use PHPUnit\Framework\TestCase;
use ZeroBoiler\Domain\AggregateRoot;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\DomainEventCollection;
use ZeroBoiler\Domain\Entity;
use ZeroBoiler\Domain\Exceptions\ConflictDomainException;
use ZeroBoiler\Domain\Exceptions\InvalidArgumentDomainException;
use ZeroBoiler\Domain\Exceptions\InvalidStateDomainException;
use ZeroBoiler\Domain\Exceptions\NotFoundDomainException;
use ZeroBoiler\Domain\Exceptions\OptimisticLockException;
use ZeroBoiler\Domain\Identifiers\IntegerIdentifier;
use ZeroBoiler\Domain\Identifiers\StringIdentifier;
use ZeroBoiler\Domain\Identifiers\UuidIdentifier;
use ZeroBoiler\Domain\Identifiers\UlidIdentifier;
use ZeroBoiler\Domain\InMemoryUnitOfWork;
use ZeroBoiler\Domain\Snapshots\Snapshot;
use ZeroBoiler\Domain\ValueObject;
use ZeroBoiler\Domain\Tests\Fixtures\TestAggregate;
use ZeroBoiler\Domain\Tests\Fixtures\TestEntity;
use ZeroBoiler\Domain\Tests\Fixtures\TestValueObject;
use ZeroBoiler\Events\Domain\DomainEvent;

/**
 * @covers \ZeroBoiler\Domain\AggregateRoot
 * @covers \ZeroBoiler\Domain\AggregateRootId
 * @covers \ZeroBoiler\Domain\DomainEventCollection
 * @covers \ZeroBoiler\Domain\Entity
 * @covers \ZeroBoiler\Domain\Identifiers\IntegerIdentifier
 * @covers \ZeroBoiler\Domain\Identifiers\StringIdentifier
 * @covers \ZeroBoiler\Domain\Identifiers\UuidIdentifier
 * @covers \ZeroBoiler\Domain\Identifiers\UlidIdentifier
 * @covers \ZeroBoiler\Domain\InMemoryUnitOfWork
 * @covers \ZeroBoiler\Domain\Snapshots\Snapshot
 * @covers \ZeroBoiler\Domain\ValueObject
 * @covers \ZeroBoiler\Domain\Exceptions\InvalidStateDomainException
 * @covers \ZeroBoiler\Domain\Exceptions\InvalidArgumentDomainException
 * @covers \ZeroBoiler\Domain\Exceptions\NotFoundDomainException
 * @covers \ZeroBoiler\Domain\Exceptions\ConflictDomainException
 * @covers \ZeroBoiler\Domain\Exceptions\OptimisticLockException
 * @covers \ZeroBoiler\Domain\Concerns\HasDomainEvents
 * @covers \ZeroBoiler\Domain\Concerns\EventSourced
 */
final class DomainProductionReadinessFinalAuditTest extends TestCase
{
    // ── AggregateRootId: Immutability & Round-Trip ─────────────────────────

    public function testAggregateRootIdIsFinalReadonly(): void
    {
        $reflection = new ReflectionClass(AggregateRootId::class);
        self::assertTrue($reflection->isFinal());
        self::assertTrue($reflection->isReadOnly());
    }

    public function testAggregateRootIdImplementsStringableAndJsonSerializable(): void
    {
        $id = AggregateRootId::generate();
        self::assertInstanceOf(\Stringable::class, $id);
        self::assertInstanceOf(\JsonSerializable::class, $id);
    }

    public function testAggregateRootIdRoundTripSerialization(): void
    {
        $original = AggregateRootId::generate();
        $restored = AggregateRootId::fromArray($original->toArray());

        self::assertTrue($original->equals($restored));
        self::assertSame($original->toString(), $restored->toString());
        self::assertSame($original->jsonSerialize(), $restored->jsonSerialize());
    }

    public function testAggregateRootIdFromArrayOfIdKey(): void
    {
        $original = AggregateRootId::generate();
        $restored = AggregateRootId::fromArray(['id' => $original->toString()]);
        self::assertTrue($original->equals($restored));
    }

    public function testAggregateRootIdFromArrayOfInvalidKeyThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AggregateRootId::fromArray(['foo' => 123]);
    }

    public function testAggregateRootIdToStringMatchesJsonSerialize(): void
    {
        $id = AggregateRootId::generate();
        self::assertSame($id->toString(), $id->jsonSerialize());
        self::assertSame($id->toString(), (string) $id);
    }

    public function testAggregateRootIdEqualityIsReflexiveSymmetricTransitive(): void
    {
        $a = AggregateRootId::fromString('550e8400-e29b-41d4-a716-446655440000');
        $b = AggregateRootId::fromString('550e8400-e29b-41d4-a716-446655440000');
        $c = AggregateRootId::fromString('550e8400-e29b-41d4-a716-446655440000');
        $d = AggregateRootId::fromString('660e8400-e29b-41d4-a716-446655440001');

        // Reflexive
        self::assertTrue($a->equals($a));
        // Symmetric
        self::assertTrue($a->equals($b));
        self::assertTrue($b->equals($a));
        // Transitive
        self::assertTrue($a->equals($b));
        self::assertTrue($b->equals($c));
        self::assertTrue($a->equals($c));
        // Inequality
        self::assertFalse($a->equals($d));
    }

    // ── UuidIdentifier: Abstract Readonly & Inheritance ────────────────────

    public function testUuidIdentifierIsAbstractReadonly(): void
    {
        $reflection = new ReflectionClass(UuidIdentifier::class);
        self::assertTrue($reflection->isAbstract());
        self::assertTrue($reflection->isReadOnly());
    }

    public function testUuidIdentifierConcreteSubclassRoundTrip(): void
    {
        $original = TestUuidIdentifier::generate();
        $restored = TestUuidIdentifier::fromArray($original->toArray());

        self::assertTrue($original->equals($restored));
        self::assertSame($original->toString(), $restored->toString());
    }

    public function testUuidIdentifierDifferentSubclassesNotEqual(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $a = TestUuidIdentifier::fromString($uuid);
        $b = TestUuidIdentifierAlt::fromString($uuid);

        // Same concrete class → equal
        self::assertTrue($a->equals(TestUuidIdentifier::fromString($uuid)));
        // Different concrete class → NOT equal (even same UUID value)
        self::assertFalse($a->equals($b));
        self::assertFalse($b->equals($a));
    }

    public function testUuidIdentifierJsonSerializable(): void
    {
        $id = TestUuidIdentifier::generate();
        self::assertSame($id->toString(), $id->jsonSerialize());
    }

    // ── UlidIdentifier: Abstract Readonly & Monotonic ──────────────────────

    public function testUlidIdentifierIsAbstractReadonly(): void
    {
        $reflection = new ReflectionClass(UlidIdentifier::class);
        self::assertTrue($reflection->isAbstract());
        self::assertTrue($reflection->isReadOnly());
    }

    public function testUlidIdentifierConcreteSubclassRoundTrip(): void
    {
        $original = TestUlidIdentifier::generate();
        $restored = TestUlidIdentifier::fromArray($original->toArray());

        self::assertTrue($original->equals($restored));
        self::assertSame($original->toString(), $restored->toString());
    }

    public function testUlidIdentifierValidationRejectsInvalid(): void
    {
        self::assertFalse(TestUlidIdentifier::isValid('not-a-ulid'));
        self::assertFalse(TestUlidIdentifier::isValid(''));
    }

    // ── StringIdentifier: Final Readonly & Non-Empty ──────────────────────

    public function testStringIdentifierIsFinalReadonly(): void
    {
        $reflection = new ReflectionClass(StringIdentifier::class);
        self::assertTrue($reflection->isFinal());
        self::assertTrue($reflection->isReadOnly());
    }

    public function testStringIdentifierRejectsEmptyString(): void
    {
        $this->expectException(\ValueError::class);
        StringIdentifier::from('');
    }

    public function testStringIdentifierRoundTrip(): void
    {
        $original = StringIdentifier::from('my-slug');
        $restored = StringIdentifier::fromArray($original->toArray());

        self::assertTrue($original->equals($restored));
        self::assertSame('my-slug', $original->toString());
    }

    public function testStringIdentifierEqualityRequiresSameClass(): void
    {
        // StringIdentifier is final, so only same-class comparison
        $a = StringIdentifier::from('test');
        $b = StringIdentifier::from('test');
        $c = StringIdentifier::from('other');

        self::assertTrue($a->equals($b));
        self::assertFalse($a->equals($c));
    }

    // ── IntegerIdentifier: Final Readonly ──────────────────────────────────

    public function testIntegerIdentifierIsFinalReadonly(): void
    {
        $reflection = new ReflectionClass(IntegerIdentifier::class);
        self::assertTrue($reflection->isFinal());
        self::assertTrue($reflection->isReadOnly());
    }

    public function testIntegerIdentifierRoundTrip(): void
    {
        $original = IntegerIdentifier::from(42);
        $restored = IntegerIdentifier::fromArray($original->toArray());

        self::assertTrue($original->equals($restored));
        self::assertSame(42, $original->toInt());
        self::assertSame('42', $original->toString());
        self::assertSame(42, $original->jsonSerialize());
    }

    public function testIntegerIdentifierFromStringParsing(): void
    {
        $id = IntegerIdentifier::fromString('42');
        self::assertSame(42, $id->toInt());
    }

    public function testIntegerIdentifierRejectsNonNumericString(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        IntegerIdentifier::fromArray(['foo' => 'abc']);
    }

    public function testIntegerIdentifierValidatesNegativeNumbers(): void
    {
        self::assertTrue(IntegerIdentifier::isValid('-5'));
        self::assertTrue(IntegerIdentifier::isValid('0'));
        self::assertFalse(IntegerIdentifier::isValid('abc'));
    }

    // ── AggregateRoot: Abstract, Versioning, Events ───────────────────────

    public function testAggregateRootIsAbstract(): void
    {
        $reflection = new ReflectionClass(AggregateRoot::class);
        self::assertTrue($reflection->isAbstract());
    }

    public function testAggregateRootConstructorIsProtected(): void
    {
        $constructor = new ReflectionMethod(AggregateRoot::class, '__construct');
        self::assertTrue($constructor->isProtected());
    }

    public function testAggregateRootRecordsEventsAndIncrementsVersion(): void
    {
        $id = AggregateRootId::generate();
        $aggregate = TestAggregate::create($id);

        self::assertSame(1, $aggregate->version());
        self::assertTrue($aggregate->hasUncommittedEvents());

        $aggregate->rename('New Name');

        self::assertSame(2, $aggregate->version());
        self::assertSame('New Name', $aggregate->name);
        self::assertTrue($aggregate->nameChanged);
    }

    public function testAggregateRootPullDomainEventsReturnsTypedCollection(): void
    {
        $id = AggregateRootId::generate();
        $aggregate = TestAggregate::create($id);
        $aggregate->rename('X');

        $events = $aggregate->pullDomainEvents();

        self::assertInstanceOf(DomainEventCollection::class, $events);
        self::assertSame(2, $events->count());
        self::assertFalse($aggregate->hasUncommittedEvents());
    }

    public function testAggregateRootPeekDomainEventsDoesNotConsume(): void
    {
        $id = AggregateRootId::generate();
        $aggregate = TestAggregate::create($id);

        $peeked = $aggregate->peekDomainEvents();
        self::assertInstanceOf(DomainEventCollection::class, $peeked);
        self::assertSame(1, $peeked->count());
        self::assertTrue($aggregate->hasUncommittedEvents());
    }

    public function testAggregateRootClearDomainEventsRemovesAll(): void
    {
        $id = AggregateRootId::generate();
        $aggregate = TestAggregate::create($id);

        $aggregate->clearDomainEvents();
        self::assertFalse($aggregate->hasUncommittedEvents());
    }

    public function testAggregateRootIdentityEqualityUsesAggregateRootId(): void
    {
        $id = AggregateRootId::generate();
        $a = TestAggregate::create($id);

        // Different aggregate with same ID should be equal
        $b = TestAggregate::create($id);
        self::assertTrue($a->equals($b));

        // Different ID → not equal
        $c = TestAggregate::create(AggregateRootId::generate());
        self::assertFalse($a->equals($c));
    }

    public function testAggregateRootToArrayReturnsIdVersionType(): void
    {
        $id = AggregateRootId::generate();
        $aggregate = TestAggregate::create($id);

        $array = $aggregate->toArray();
        self::assertArrayHasKey('id', $array);
        self::assertArrayHasKey('version', $array);
        self::assertArrayHasKey('type', $array);
        self::assertSame($id->toString(), $array['id']);
        self::assertSame(1, $array['version']);
        self::assertSame('TestAggregate', $array['type']);
    }

    public function testAggregateRootJsonSerializeDelegatesToArray(): void
    {
        $id = AggregateRootId::generate();
        $aggregate = TestAggregate::create($id);
        self::assertSame($aggregate->toArray(), $aggregate->jsonSerialize());
    }

    // ── Entity: Abstract, Flexible IDs, fromArray ──────────────────────────

    public function testEntityIsAbstract(): void
    {
        $reflection = new ReflectionClass(Entity::class);
        self::assertTrue($reflection->isAbstract());
    }

    public function testEntityWithIntId(): void
    {
        $entity = new TestEntity(42);
        self::assertSame('42', $entity->id());
    }

    public function testEntityWithStringId(): void
    {
        $entity = new TestEntity('my-id');
        self::assertSame('my-id', $entity->id());
    }

    public function testEntityWithStringableId(): void
    {
        $id = AggregateRootId::generate();
        $entity = new TestEntity($id);
        self::assertSame($id->toString(), $entity->id());
    }

    public function testEntityFromArrayRoundTrip(): void
    {
        // TestEntity has no extra constructor params — just id
        $entity = TestEntity::fromArray(['id' => '99']);
        self::assertSame('99', $entity->id());
    }

    public function testEntityEqualityRequiresSameClass(): void
    {
        $a = new TestEntity('1');
        $b = new TestEntity('1');
        $c = new TestEntity('2');

        self::assertTrue($a->equals($b));
        self::assertFalse($a->equals($c));
    }

    public function testEntityToArrayReturnsIdAndType(): void
    {
        $entity = new TestEntity('42');
        $array = $entity->toArray();

        self::assertArrayHasKey('id', $array);
        self::assertArrayHasKey('type', $array);
        self::assertSame('42', $array['id']);
    }

    // ── DomainEventCollection: Type-Safe, Immutable ──────────────────────

    public function testDomainEventCollectionIsFinalReadonly(): void
    {
        $reflection = new ReflectionClass(DomainEventCollection::class);
        self::assertTrue($reflection->isFinal());
        self::assertTrue($reflection->isReadOnly());
    }

    public function testDomainEventCollectionRejectsNonSequentialArray(): void
    {
        $event = DomainEvent::occur('test', ['key' => 'value']);
        $this->expectException(\InvalidArgumentException::class);
        new DomainEventCollection(['non_sequential' => $event]);
    }

    public function testDomainEventCollectionRejectsNonDomainEventItems(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DomainEventCollection(['not an event']);
    }

    public function testDomainEventCollectionFunctionalOperations(): void
    {
        $e1 = DomainEvent::occur('order.created', ['id' => '1']);
        $e2 = DomainEvent::occur('order.paid', ['id' => '1']);
        $e3 = DomainEvent::occur('order.shipped', ['id' => '1']);

        $collection = new DomainEventCollection([$e1, $e2, $e3]);

        // count, isEmpty
        self::assertSame(3, $collection->count());
        self::assertFalse($collection->isEmpty());

        // get, first, last
        self::assertSame($e1, $collection->get(0));
        self::assertSame($e1, $collection->first());
        self::assertSame($e3, $collection->last());
        self::assertNull($collection->get(99));

        // all
        $all = $collection->all();
        self::assertCount(3, $all);

        // map
        $types = $collection->map(fn (DomainEvent $e): string => $e->eventType);
        self::assertSame(['order.created', 'order.paid', 'order.shipped'], $types);

        // filter
        $paid = $collection->filter(fn (DomainEvent $e): bool => $e->eventType === 'order.paid');
        self::assertSame(1, $paid->count());

        // merge
        $e4 = DomainEvent::occur('order.delivered', ['id' => '1']);
        $merged = $collection->merge([$e4]);
        self::assertSame(4, $merged->count());
    }

    public function testDomainEventCollectionRoundTripSerialization(): void
    {
        $e1 = DomainEvent::occur('test.event', ['key' => 'value']);
        $e2 = DomainEvent::occur('test.other', ['num' => 42]);

        $original = new DomainEventCollection([$e1, $e2]);
        $serialized = $original->toArray();
        $restored = DomainEventCollection::fromArray($serialized);

        self::assertSame($original->count(), $restored->count());
        self::assertSame($original->first()->eventType, $restored->first()->eventType);
    }

    public function testDomainEventCollectionFromArrayRejectsNonSequential(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DomainEventCollection::fromArray(['non_sequential' => DomainEvent::occur('test', [])]);
    }

    public function testDomainEventCollectionJsonSerializable(): void
    {
        $event = DomainEvent::occur('test', ['data' => true]);
        $collection = new DomainEventCollection([$event]);

        $json = json_encode($collection);
        self::assertIsString($json);
        $decoded = json_decode($json, true);
        self::assertIsArray($decoded);
        self::assertCount(1, $decoded);
    }

    public function testDomainEventCollectionEmpty(): void
    {
        $collection = new DomainEventCollection;
        self::assertTrue($collection->isEmpty());
        self::assertSame(0, $collection->count());
        self::assertNull($collection->first());
        self::assertNull($collection->last());
        self::assertSame([], $collection->all());
    }

    // ── ValueObject: Domain Equality ───────────────────────────────────────

    public function testValueObjectIsAbstract(): void
    {
        $reflection = new ReflectionClass(ValueObject::class);
        self::assertTrue($reflection->isAbstract());
    }

    public function testValueObjectEqualityBasedOnToArray(): void
    {
        $a = TestValueObject::from('hello');
        $b = TestValueObject::from('hello');
        $c = TestValueObject::from('world');

        self::assertTrue($a->equals($b));
        self::assertFalse($a->equals($c));
    }

    public function testValueObjectEqualityReturnsFalseForNull(): void
    {
        $vo = TestValueObject::from('test');
        self::assertFalse($vo->equals(null));
    }

    public function testValueObjectEqualityReturnsFalseForDifferentType(): void
    {
        $vo = TestValueObject::from('test');
        self::assertFalse($vo->equals(new \stdClass));
    }

    // ── DomainException Hierarchy: Codes & Serialization ──────────────────

    public function testInvalidStateExceptionErrorCode(): void
    {
        $e = InvalidStateDomainException::because('Order must be pending.');
        self::assertSame('INVALID_STATE', $e->errorCode());
    }

    public function testInvalidArgumentExceptionErrorCode(): void
    {
        $e = InvalidArgumentDomainException::because('Qty must be > 0.');
        self::assertSame('INVALID_ARGUMENT', $e->errorCode());
    }

    public function testNotFoundExceptionErrorCode(): void
    {
        $e = NotFoundDomainException::forAggregate('Order', '123');
        self::assertSame('NOT_FOUND', $e->errorCode());
    }

    public function testConflictExceptionErrorCode(): void
    {
        $e = ConflictDomainException::because('Concurrent modification.');
        self::assertSame('CONFLICT', $e->errorCode());
    }

    public function testDomainExceptionToErrorArrayRfc9457(): void
    {
        $e = InvalidStateDomainException::because('Invalid transition.');
        $array = $e->toErrorArray();

        self::assertArrayHasKey('title', $array);
        self::assertArrayHasKey('detail', $array);
        self::assertArrayHasKey('code', $array);
        self::assertSame('InvalidStateDomainException', $array['title']);
        self::assertSame('Invalid transition.', $array['detail']);
        self::assertSame('INVALID_STATE', $array['code']);
    }

    public function testDomainExceptionJsonSerializeRfc9457(): void
    {
        $e = NotFoundDomainException::because('Not found.');
        $json = $e->jsonSerialize();

        self::assertSame($e->toErrorArray(), $json);
    }

    public function testDomainExceptionCustomErrorCodeOverrides(): void
    {
        $e = InvalidStateDomainException::because('Custom.', 'CUSTOM_CODE');
        self::assertSame('CUSTOM_CODE', $e->errorCode());
    }

    public function testDomainExceptionRoundTripSerialization(): void
    {
        $original = InvalidStateDomainException::because('Test message.');
        $restored = InvalidStateDomainException::fromArray($original->toArray());

        self::assertSame($original->getMessage(), $restored->getMessage());
        self::assertSame($original->errorCode(), $restored->errorCode());
    }

    public function testDomainExceptionToArrayContainsErrorCodeAndMessage(): void
    {
        $e = InvalidArgumentDomainException::because('Bad arg.');
        $array = $e->toArray();

        self::assertArrayHasKey('error_code', $array);
        self::assertArrayHasKey('message', $array);
        self::assertArrayHasKey('file', $array);
        self::assertArrayHasKey('line', $array);
        self::assertSame('INVALID_ARGUMENT', $array['error_code']);
    }

    public function testOptimisticLockExceptionForFactory(): void
    {
        $e = OptimisticLockException::for('order-123', expected: 5, actual: 3);
        self::assertStringContainsString('order-123', $e->getMessage());
        self::assertStringContainsString('5', $e->getMessage());
        self::assertStringContainsString('3', $e->getMessage());
        self::assertSame('OPTIMISTIC_LOCK', $e->errorCode());
    }

    // ── Snapshot: Immutable, Round-Trip ───────────────────────────────────

    public function testSnapshotIsFinalReadonly(): void
    {
        $reflection = new ReflectionClass(Snapshot::class);
        self::assertTrue($reflection->isFinal());
        self::assertTrue($reflection->isReadOnly());
    }

    public function testSnapshotRoundTrip(): void
    {
        $original = Snapshot::create('App\\Order', 'id-123', 5, ['status' => 'paid']);
        $restored = Snapshot::fromArray($original->toArray());

        self::assertTrue($original->equals($restored));
        self::assertSame('App\\Order', $restored->aggregateType);
        self::assertSame('id-123', $restored->aggregateId);
        self::assertSame(5, $restored->version);
        self::assertSame(['status' => 'paid'], $restored->state);
    }

    public function testSnapshotJsonSerializable(): void
    {
        $snapshot = Snapshot::create('App\\Order', 'id-123', 1, ['x' => 1]);
        $json = json_encode($snapshot);
        self::assertIsString($json);
        $decoded = json_decode($json, true);
        self::assertSame('App\\Order', $decoded['aggregate_type']);
    }

    public function testSnapshotFromArrayRejectsInvalidData(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Snapshot::fromArray(['invalid' => 'data']);
    }

    public function testSnapshotEqualityConsidersAllFields(): void
    {
        $a = Snapshot::create('App\\Order', 'id-1', 1, ['s' => 'a']);
        $b = Snapshot::create('App\\Order', 'id-1', 1, ['s' => 'a']);
        $c = Snapshot::create('App\\Order', 'id-1', 1, ['s' => 'b']); // Different state

        self::assertTrue($a->equals($b));
        self::assertFalse($a->equals($c));
    }

    // ── InMemoryUnitOfWork: Transactional Guarantees ──────────────────────

    public function testUnitOfWorkRunAutoCommitAndRollback(): void
    {
        $uow = new InMemoryUnitOfWork;

        // Successful commit
        $result = $uow->run(function (): string {
            return 'success';
        });
        self::assertSame('success', $result);
        self::assertFalse($uow->isActive());

        // Failed — rollback
        $exception = null;
        try {
            $uow->run(function (): void {
                throw new \RuntimeException('fail');
            });
        } catch (\RuntimeException $e) {
            $exception = $e;
        }
        self::assertNotNull($exception);
        self::assertSame('fail', $exception->getMessage());
        self::assertFalse($uow->isActive());
    }

    public function testUnitOfWorkTrackAndCommitCollectsEvents(): void
    {
        $uow = new InMemoryUnitOfWork;
        $id = AggregateRootId::generate();
        $aggregate = TestAggregate::create($id);

        $uow->begin();
        $uow->track($aggregate);
        self::assertTrue($uow->hasPendingEvents());
        self::assertSame(1, $uow->getPendingEventCount());

        $uow->commit();
        self::assertFalse($uow->isActive());
    }

    public function testUnitOfWorkCommitReturnsCommittedAggregates(): void
    {
        $uow = new InMemoryUnitOfWork;
        $id = AggregateRootId::generate();
        $aggregate = TestAggregate::create($id);

        $uow->begin();
        $uow->track($aggregate);
        $uow->commit();

        $committed = $uow->getCommitted();
        self::assertArrayHasKey($aggregate->id(), $committed);
    }

    public function testUnitOfWorkRollbackDiscardsEvents(): void
    {
        $uow = new InMemoryUnitOfWork;
        $id = AggregateRootId::generate();
        $aggregate = TestAggregate::create($id);

        $uow->begin();
        $uow->track($aggregate);
        $uow->rollback();

        self::assertFalse($uow->hasPendingEvents());
        self::assertSame([], $uow->getCommitted());
    }

    public function testUnitOfWorkNestedRunWithSavepoints(): void
    {
        $uow = new InMemoryUnitOfWork;
        $log = [];

        $result = $uow->run(function () use ($uow, &$log): string {
            $log[] = 'outer-start';
            $inner = $uow->run(function () use (&$log): string {
                $log[] = 'inner';
                return 'inner-result';
            });
            $log[] = 'outer-end';

            return $inner;
        });

        self::assertSame('inner-result', $result);
        self::assertSame(['outer-start', 'inner', 'outer-end'], $log);
    }

    public function testUnitOfWorkMarkForDeletionAndCommit(): void
    {
        $uow = new InMemoryUnitOfWork;
        $id = AggregateRootId::generate();
        $aggregate = TestAggregate::create($id);

        $uow->begin();
        $uow->track($aggregate);
        $uow->markForDeletion($aggregate);
        $uow->commit();

        $deleted = $uow->getDeleted();
        self::assertArrayHasKey($aggregate->id(), $deleted);
        $committed = $uow->getCommitted();
        self::assertArrayNotHasKey($aggregate->id(), $committed);
    }

    public function testUnitOfWorkClearResetsAllState(): void
    {
        $uow = new InMemoryUnitOfWork;
        $id = AggregateRootId::generate();
        $aggregate = TestAggregate::create($id);

        $uow->begin();
        $uow->track($aggregate);
        $uow->commit();
        $uow->clear();

        self::assertFalse($uow->hasPendingEvents());
        self::assertSame([], $uow->getCommitted());
        self::assertSame([], $uow->getDeleted());
    }

    public function testUnitOfWorkGetPendingEventsReturnsCollection(): void
    {
        $uow = new InMemoryUnitOfWork;
        $id = AggregateRootId::generate();
        $aggregate = TestAggregate::create($id);

        $uow->begin();
        $uow->track($aggregate);

        $pending = $uow->getPendingEvents();
        self::assertInstanceOf(DomainEventCollection::class, $pending);
        self::assertSame(1, $pending->count());
    }

    public function testUnitOfWorkTrackRequiresActiveScope(): void
    {
        $uow = new InMemoryUnitOfWork;
        $id = AggregateRootId::generate();
        $aggregate = TestAggregate::create($id);

        $this->expectException(\RuntimeException::class);
        $uow->track($aggregate);
    }

    // ── PHP 8.5 Syntax Validation ──────────────────────────────────────────

    public function testAllCoreClassesUseDeclareStrictTypes(): void
    {
        $classes = [
            AggregateRoot::class,
            AggregateRootId::class,
            DomainEventCollection::class,
            Entity::class,
            ValueObject::class,
            InMemoryUnitOfWork::class,
            Snapshot::class,
            UuidIdentifier::class,
            UlidIdentifier::class,
            StringIdentifier::class,
            IntegerIdentifier::class,
        ];

        foreach ($classes as $class) {
            $file = (new ReflectionClass($class))->getFileName();
            $contents = file_get_contents($file);
            self::assertStringContainsString(
                'declare(strict_types=1)',
                $contents,
                "{$class} must have declare(strict_types=1)"
            );
        }
    }

    public function testAllCoreClassesHaveReturnTypesOnPublicMethods(): void
    {
        $classes = [
            AggregateRoot::class,
            AggregateRootId::class,
            Entity::class,
            StringIdentifier::class,
            IntegerIdentifier::class,
        ];

        foreach ($classes as $class) {
            $reflection = new ReflectionClass($class);
            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getDeclaringClass()->getName() !== $class) {
                    continue; // Skip inherited methods
                }
                $returnType = $method->getReturnType();
                self::assertNotNull(
                    $returnType,
                    "{$class}::{$method->getName()}() must have a return type declaration"
                );
            }
        }
    }

    // ── Interface Contract Compliance ──────────────────────────────────────

    public function testEntityImplementsEntityContract(): void
    {
        self::assertInstanceOf(
            \ZeroBoiler\Domain\Contracts\Entity::class,
            new TestEntity('1')
        );
    }

    public function testAggregateRootImplementsAggregateRootContract(): void
    {
        $id = AggregateRootId::generate();
        $aggregate = TestAggregate::create($id);

        self::assertInstanceOf(
            \ZeroBoiler\Domain\Contracts\AggregateRoot::class,
            $aggregate
        );
        self::assertInstanceOf(
            \ZeroBoiler\Domain\Contracts\Entity::class,
            $aggregate
        );
    }

    public function testAggregateRootIdImplementsStringable(): void
    {
        self::assertInstanceOf(\Stringable::class, AggregateRootId::generate());
    }

    public function testIdentifiersImplementIdentifierContract(): void
    {
        $id = TestUuidIdentifier::generate();
        self::assertInstanceOf(\ZeroBoiler\Domain\Contracts\Identifier::class, $id);

        $ulid = TestUlidIdentifier::generate();
        self::assertInstanceOf(\ZeroBoiler\Domain\Contracts\Identifier::class, $ulid);

        $str = StringIdentifier::from('test');
        self::assertInstanceOf(\ZeroBoiler\Domain\Contracts\Identifier::class, $str);

        $int = IntegerIdentifier::from(42);
        self::assertInstanceOf(\ZeroBoiler\Domain\Contracts\Identifier::class, $int);
    }
}
