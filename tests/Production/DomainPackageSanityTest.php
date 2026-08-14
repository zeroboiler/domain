<?php

declare(strict_types=1);

/**
 * Production sanity tests for the ZeroBoiler Domain package.
 *
 * Validates strict types, return types, immutability, domain invariants,
 * serialization contracts, and cross-class consistency across the entire
 * domain package — without requiring a PHP runtime.
 *
 * These tests are designed as PHP source files for CI execution with Pest.
 * They serve as living documentation of the package's contracts and guarantees.
 *
 * @covers \ZeroBoiler\Domain\AggregateRoot
 * @covers \ZeroBoiler\Domain\AggregateRootId
 * @covers \ZeroBoiler\Domain\Entity
 * @covers \ZeroBoiler\Domain\ValueObject
 * @covers \ZeroBoiler\Domain\DomainEventCollection
 * @covers \ZeroBoiler\Domain\InMemoryUnitOfWork
 * @covers \ZeroBoiler\Domain\Contracts\*
 * @covers \ZeroBoiler\Domain\Identifiers\*
 * @covers \ZeroBoiler\Domain\Exceptions\*
 * @covers \ZeroBoiler\Domain\Snapshots\*
 * @covers \ZeroBoiler\Domain\Concerns\*
 */

use ZeroBoiler\Domain\AggregateRoot;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\Contracts\Identifier as IdentifierContract;
use ZeroBoiler\Domain\Contracts\Repository as RepositoryContract;
use ZeroBoiler\Domain\Contracts\UnitOfWork as UnitOfWorkContract;
use ZeroBoiler\Domain\Identifiers\IntegerIdentifier;
use ZeroBoiler\Domain\Identifiers\StringIdentifier;
use ZeroBoiler\Domain\Identifiers\UuidIdentifier;
use ZeroBoiler\Domain\Identifiers\UlidIdentifier;
use ZeroBoiler\Domain\Snapshots\{InMemorySnapshotStore, Snapshot, SnapshotPolicy};

// ---------------------------------------------------------------------------
//  AggregateRootId — UUID v4 identity
// ---------------------------------------------------------------------------

describe('AggregateRootId — production contract', function (): void {
    it('generates a valid UUID v4', function (): void {
        $id = AggregateRootId::generate();
        expect($id->toString())->toMatch(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
        );
    });

    it('is a final readonly class', function (): void {
        $reflection = new ReflectionClass(AggregateRootId::class);
        expect($reflection->isFinal())->toBeTrue();
        expect($reflection->isReadOnly())->toBeTrue();
    });

    it('round-trips through toArray/fromArray', function (): void {
        $id = AggregateRootId::generate();
        $array = $id->toArray();
        expect($array)->toHaveKey('uuid');

        $restored = AggregateRootId::fromArray($array);
        expect($id->equals($restored))->toBeTrue();
    });

    it('round-trips through JSON encode/fromJson', function (): void {
        $id = AggregateRootId::generate();
        $json = json_encode($id->toArray());
        expect($json)->toBeString()->toBeJson();

        $restored = AggregateRootId::fromJson($json);
        expect($id->equals($restored))->toBeTrue();
    });

    it('accepts both uuid and id keys in fromArray', function (): void {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $fromUuid = AggregateRootId::fromArray(['uuid' => $uuid]);
        $fromId = AggregateRootId::fromArray(['id' => $uuid]);

        expect($fromUuid->equals($fromId))->toBeTrue();
    });

    it('validates UUID strings', function (): void {
        expect(AggregateRootId::isValid('550e8400-e29b-41d4-a716-446655440000'))->toBeTrue();
        expect(AggregateRootId::isValid('not-a-uuid'))->toBeFalse();
        expect(AggregateRootId::isValid(''))->toBeFalse();
    });

    it('serializes to JSON as string (JsonSerializable)', function (): void {
        $id = AggregateRootId::fromString('550e8400-e29b-41d4-a716-446655440000');
        $encoded = json_encode($id);
        expect($encoded)->toBe('"550e8400-e29b-41d4-a716-446655440000"');
    });

    it('implements Stringable', function (): void {
        $id = AggregateRootId::generate();
        expect((string) $id)->toBe($id->toString());
    });

    it('round-trips through PHP serialize/unserialize', function (): void {
        $id = AggregateRootId::generate();
        $serialized = serialize($id);
        $restored = unserialize($serialized);

        expect($restored)->toBeInstanceOf(AggregateRootId::class);
        expect($id->equals($restored))->toBeTrue();
    });

    it('throws on invalid UUID in fromString', function (): void {
        AggregateRootId::fromString('invalid-uuid');
    })->throws(\Ramsey\Uuid\Exception\InvalidUuidStringException::class);

    it('throws on missing key in fromArray', function (): void {
        AggregateRootId::fromArray(['foo' => 'bar']);
    })->throws(\InvalidArgumentException::class);

    it('throws on invalid type in fromArray', function (): void {
        AggregateRootId::fromArray(['uuid' => 12345]);
    })->throws(\InvalidArgumentException::class);
});

// ---------------------------------------------------------------------------
//  Entity — base entity with identity equality
// ---------------------------------------------------------------------------

describe('Entity — production contract', function (): void {
    it('supports string IDs', function (): void {
        $entity = new TestConcreteEntity('abc-123');
        expect($entity->id())->toBe('abc-123');
    });

    it('supports integer IDs', function (): void {
        $entity = new TestConcreteEntity(42);
        expect($entity->id())->toBe('42');
    });

    it('supports Stringable IDs', function (): void {
        $id = AggregateRootId::generate();
        $entity = new TestConcreteEntity($id);
        expect($entity->id())->toBe($id->toString());
    });

    it('compares identity equality correctly', function (): void {
        $a = new TestConcreteEntity('same-id');
        $b = new TestConcreteEntity('same-id');
        $c = new TestConcreteEntity('other-id');

        expect($a->equals($b))->toBeTrue();
        expect($a->equals($c))->toBeFalse();
    });

    it('never equals a different class even with same ID', function (): void {
        $a = new TestConcreteEntity('id-1');
        $b = new class ('id-1') extends TestConcreteEntity {};

        // Different concrete class → not equal
        expect($a->equals($b))->toBeFalse();
    });

    it('includes id and type in toArray', function (): void {
        $entity = new TestConcreteEntity('id-42', 'TestEntity');
        $array = $entity->toArray();

        expect($array)->toHaveKey('id');
        expect($array)->toHaveKey('type');
        expect($array['id'])->toBe('id-42');
        expect($array['type'])->toBe('TestConcreteEntity');
    });

    it('round-trips through fromArray/toArray', function (): void {
        $original = new TestConcreteEntity('id-99', 'Jane');
        $array = $original->toArray();

        $restored = TestConcreteEntity::fromArray($array);
        expect($restored->id())->toBe('id-99');
        expect($restored->name)->toBe('Jane');
    });

    it('round-trips through fromJson/toJson', function (): void {
        $original = new TestConcreteEntity('id-55', 'Test');
        $json = $original->toJson();

        $restored = TestConcreteEntity::fromJson($json);
        expect($restored->id())->toBe('id-55');
    });

    it('implements JsonSerializable', function (): void {
        $entity = new TestConcreteEntity('id-1');
        $json = json_encode($entity);

        expect($json)->toBeJson();
        $data = json_decode($json, true);
        expect($data['id'])->toBe('id-1');
    });

    it('has readonly id property', function (): void {
        $reflection = new ReflectionClass(TestConcreteEntity::class);
        $idProp = $reflection->getProperty('id');
        expect($idProp->isReadOnly())->toBeTrue();
        expect($idProp->isPublic())->toBeTrue();
    });
});

// ---------------------------------------------------------------------------
//  AggregateRoot — aggregate with events and versioning
// ---------------------------------------------------------------------------

describe('AggregateRoot — production contract', function (): void {
    it('creates with generated ID', function (): void {
        $aggregate = new TestConcreteAggregate(AggregateRootId::generate());
        expect($aggregate->id())->toBeString()->toBeNotEmpty();
        expect($aggregate->version())->toBe(0);
    });

    it('exposes typed aggregateId', function (): void {
        $id = AggregateRootId::generate();
        $aggregate = new TestConcreteAggregate($id);
        expect($aggregate->aggregateId())->toBeInstanceOf(AggregateRootId::class);
        expect($aggregate->aggregateId()->equals($id))->toBeTrue();
    });

    it('increments version on apply', function (): void {
        $aggregate = new TestConcreteAggregate(AggregateRootId::generate());
        $aggregate->testRecordThat(new TestDomainEvent('test.increment'));

        expect($aggregate->version())->toBe(1);
    });

    it('pulls domain events destructively', function (): void {
        $aggregate = new TestConcreteAggregate(AggregateRootId::generate());
        $aggregate->testRecordThat(new TestDomainEvent('test.a'));
        $aggregate->testRecordThat(new TestDomainEvent('test.b'));

        $events = $aggregate->pullDomainEvents();
        expect($events->count())->toBe(2);

        // After pull, events are cleared
        expect($aggregate->pullDomainEvents()->count())->toBe(0);
    });

    it('peeks domain events non-destructively', function (): void {
        $aggregate = new TestConcreteAggregate(AggregateRootId::generate());
        $aggregate->testRecordThat(new TestDomainEvent('test.peek'));

        $peeked = $aggregate->peekDomainEvents();
        expect($peeked->count())->toBe(1);

        // Events still available for pull
        $pulled = $aggregate->pullDomainEvents();
        expect($pulled->count())->toBe(1);
    });

    it('clears domain events', function (): void {
        $aggregate = new TestConcreteAggregate(AggregateRootId::generate());
        $aggregate->testRecordThat(new TestDomainEvent('test.clear'));
        $aggregate->clearDomainEvents();

        expect($aggregate->pullDomainEvents()->count())->toBe(0);
    });

    it('includes id, version, and type in toArray', function (): void {
        $aggregate = new TestConcreteAggregate(AggregateRootId::generate(), 'MyOrder');
        $array = $aggregate->toArray();

        expect($array)->toHaveKey('id');
        expect($array)->toHaveKey('version');
        expect($array)->toHaveKey('type');
        expect($array['version'])->toBe(0);
        expect($array['type'])->toBe('TestConcreteAggregate');
    });

    it('compares equality by class and ID', function (): void {
        $id = AggregateRootId::generate();
        $a = new TestConcreteAggregate($id);
        $b = new TestConcreteAggregate($id);
        $c = new TestConcreteAggregate(AggregateRootId::generate());

        expect($a->equals($b))->toBeTrue();
        expect($a->equals($c))->toBeFalse();
    });
});

// ---------------------------------------------------------------------------
//  Identifiers — UuidIdentifier, UlidIdentifier, StringIdentifier, IntegerIdentifier
// ---------------------------------------------------------------------------

describe('UuidIdentifier — production contract', function (): void {
    it('is abstract readonly', function (): void {
        $reflection = new ReflectionClass(UuidIdentifier::class);
        expect($reflection->isAbstract())->toBeTrue();
        expect($reflection->isReadOnly())->toBeTrue();
    });

    it('subclasses maintain type-safe equality', function (): void {
        $id1 = new class ('550e8400-e29b-41d4-a716-446655440000') extends UuidIdentifier {};
        $id2 = new class ('550e8400-e29b-41d4-a716-446655440000') extends UuidIdentifier {};
        // Different anonymous classes → not equal even with same UUID
        expect($id1->equals($id2))->toBeFalse();
    });

    it('same class instances with same UUID are equal', function (): void {
        $reflection = new ReflectionClass(TestUuidIdentifier::class);
        $a = TestUuidIdentifier::fromString('550e8400-e29b-41d4-a716-446655440000');
        $b = TestUuidIdentifier::fromString('550e8400-e29b-41d4-a716-446655440000');

        expect($a->equals($b))->toBeTrue();
    });

    it('round-trips through toArray/fromArray', function (): void {
        $id = TestUuidIdentifier::generate();
        $restored = TestUuidIdentifier::fromArray($id->toArray());
        expect($id->equals($restored))->toBeTrue();
    });

    it('round-trips through toJson/fromJson', function (): void {
        $id = TestUuidIdentifier::generate();
        $restored = TestUuidIdentifier::fromJson($id->toJson());
        expect($id->equals($restored))->toBeTrue();
    });
});

describe('UlidIdentifier — production contract', function (): void {
    it('generates valid ULIDs', function (): void {
        $id = TestUlidIdentifier::generate();
        expect(TestUlidIdentifier::isValid($id->toString()))->toBeTrue();
    });

    it('is abstract readonly', function (): void {
        $reflection = new ReflectionClass(UlidIdentifier::class);
        expect($reflection->isAbstract())->toBeTrue();
        expect($reflection->isReadOnly())->toBeTrue();
    });

    it('round-trips through toArray/fromArray', function (): void {
        $id = TestUlidIdentifier::generate();
        $restored = TestUlidIdentifier::fromArray($id->toArray());
        expect($id->equals($restored))->toBeTrue();
    });

    it('round-trips through toJson/fromJson', function (): void {
        $id = TestUlidIdentifier::generate();
        $restored = TestUlidIdentifier::fromJson($id->toJson());
        expect($id->equals($restored))->toBeTrue();
    });

    it('validates ULID strings', function (): void {
        $id = TestUlidIdentifier::generate();
        expect(TestUlidIdentifier::isValid($id->toString()))->toBeTrue();
        expect(TestUlidIdentifier::isValid('not-a-ulid'))->toBeFalse();
    });

    it('exposes Symfony Ulid object', function (): void {
        $id = TestUlidIdentifier::generate();
        $ulid = $id->toUlid();
        expect($ulid)->toBeInstanceOf(\Symfony\Component\Uid\Ulid::class);
    });
});

describe('StringIdentifier — production contract', function (): void {
    it('is final readonly', function (): void {
        $reflection = new ReflectionClass(StringIdentifier::class);
        expect($reflection->isFinal())->toBeTrue();
        expect($reflection->isReadOnly())->toBeTrue();
    });

    it('rejects empty strings', function (): void {
        StringIdentifier::from('');
    })->throws(\ValueError::class);

    it('creates from non-empty string', function (): void {
        $id = StringIdentifier::from('my-slug');
        expect($id->toString())->toBe('my-slug');
    });

    it('validates correctly', function (): void {
        expect(StringIdentifier::isValid('hello'))->toBeTrue();
        expect(StringIdentifier::isValid(''))->toBeFalse();
    });

    it('round-trips through toArray/fromArray', function (): void {
        $id = StringIdentifier::from('test-slug');
        $restored = StringIdentifier::fromArray($id->toArray());
        expect($id->equals($restored))->toBeTrue();
    });

    it('round-trips through toJson/fromJson', function (): void {
        $id = StringIdentifier::from('another-slug');
        $restored = StringIdentifier::fromJson($id->toJson());
        expect($id->equals($restored))->toBeTrue();
    });

    it('serializes to JSON as string', function (): void {
        $id = StringIdentifier::from('slug');
        expect(json_encode($id))->toBe('"slug"');
    });
});

describe('IntegerIdentifier — production contract', function (): void {
    it('is final readonly', function (): void {
        $reflection = new ReflectionClass(IntegerIdentifier::class);
        expect($reflection->isFinal())->toBeTrue();
        expect($reflection->isReadOnly())->toBeTrue();
    });

    it('creates from int', function (): void {
        $id = IntegerIdentifier::from(42);
        expect($id->toInt())->toBe(42);
        expect($id->toString())->toBe('42');
    });

    it('creates from string', function (): void {
        $id = IntegerIdentifier::fromString('42');
        expect($id->toInt())->toBe(42);
    });

    it('validates correctly', function (): void {
        expect(IntegerIdentifier::isValid('42'))->toBeTrue();
        expect(IntegerIdentifier::isValid('-5'))->toBeTrue();
        expect(IntegerIdentifier::isValid('abc'))->toBeFalse();
    });

    it('round-trips through toArray/fromArray', function (): void {
        $id = IntegerIdentifier::from(99);
        $restored = IntegerIdentifier::fromArray($id->toArray());
        expect($id->equals($restored))->toBeTrue();
    });

    it('round-trips through toJson/fromJson', function (): void {
        $id = IntegerIdentifier::from(123);
        $restored = IntegerIdentifier::fromJson($id->toJson());
        expect($id->equals($restored))->toBeTrue();
    });

    it('serializes to JSON as integer', function (): void {
        $id = IntegerIdentifier::from(42);
        expect(json_encode($id))->toBe('42');
    });

    it('accepts int or string id key in fromArray', function (): void {
        $fromInt = IntegerIdentifier::fromArray(['integer' => 42]);
        $fromStr = IntegerIdentifier::fromArray(['id' => '42']);
        expect($fromInt->equals($fromStr))->toBeTrue();
    });
});

// ---------------------------------------------------------------------------
//  Domain Exceptions — hierarchy, error codes, RFC 9457
// ---------------------------------------------------------------------------

describe('Domain exceptions — production contract', function (): void {
    it('InvalidStateDomainException has correct code and status', function (): void {
        $ex = InvalidStateDomainException::because('Must be pending');
        expect($ex->errorCode())->toBe('INVALID_STATE');
        expect($ex->httpStatus())->toBe(422);
    });

    it('InvalidArgumentDomainException has correct code and status', function (): void {
        $ex = InvalidArgumentDomainException::because('Qty must be positive');
        expect($ex->errorCode())->toBe('INVALID_ARGUMENT');
        expect($ex->httpStatus())->toBe(422);
    });

    it('NotFoundDomainException has correct code and status', function (): void {
        $ex = NotFoundDomainException::forAggregate('Order', 'uuid-123');
        expect($ex->errorCode())->toBe('NOT_FOUND');
        expect($ex->httpStatus())->toBe(404);
    });

    it('NotFoundDomainException::forId produces standard message', function (): void {
        $ex = NotFoundDomainException::forId('order-456');
        expect($ex->getMessage())->toContain('order-456');
        expect($ex->errorCode())->toBe('NOT_FOUND');
    });

    it('ConflictDomainException has correct code and status', function (): void {
        $ex = ConflictDomainException::because('Concurrent modification');
        expect($ex->errorCode())->toBe('CONFLICT');
        expect($ex->httpStatus())->toBe(409);
    });

    it('OptimisticLockException has correct code and status', function (): void {
        $ex = \ZeroBoiler\Domain\Exceptions\OptimisticLockException::for(
            'id-1',
            expectedVersion: 10,
            actualVersion: 5,
        );
        expect($ex->errorCode())->toBe('OPTIMISTIC_LOCK');
        expect($ex->httpStatus())->toBe(409);
        expect($ex->getMessage())->toContain('expected version 10');
        expect($ex->getMessage())->toContain('current version 5');
    });

    it('AggregateNotFoundException has correct code and status', function (): void {
        $ex = \ZeroBoiler\Domain\Exceptions\AggregateNotFoundException::for(
            'App\\Domain\\Order',
            'uuid-123',
        );
        expect($ex->errorCode())->toBe('AGGREGATE_NOT_FOUND');
        expect($ex->httpStatus())->toBe(404);
    });

    it('InvalidAggregateRootException has correct code and status', function (): void {
        $obj = new \stdClass();
        $ex = \ZeroBoiler\Domain\Exceptions\InvalidAggregateRootException::notAnAggregate($obj);
        expect($ex->errorCode())->toBe('INVALID_AGGREGATE_ROOT');
        expect($ex->httpStatus())->toBe(500);
    });

    it('InvalidStateException has correct code and status', function (): void {
        $ex = \ZeroBoiler\Domain\Exceptions\InvalidStateException::because('Bad config');
        expect($ex->errorCode())->toBe('INVALID_STATE_SYSTEM');
        expect($ex->httpStatus())->toBe(500);
    });

    it('all exceptions produce RFC 9457 toErrorArray', function (): void {
        $exceptions = [
            InvalidStateDomainException::because('test'),
            InvalidArgumentDomainException::because('test'),
            NotFoundDomainException::because('test'),
            ConflictDomainException::because('test'),
            \ZeroBoiler\Domain\Exceptions\OptimisticLockException::for('id', 1, 0),
            \ZeroBoiler\Domain\Exceptions\AggregateNotFoundException::for('X', 'id'),
            \ZeroBoiler\Domain\Exceptions\InvalidAggregateRootException::notAnAggregate(new \stdClass()),
            \ZeroBoiler\Domain\Exceptions\InvalidStateException::because('test'),
        ];

        foreach ($exceptions as $ex) {
            $error = $ex->toErrorArray();
            expect($error)->toHaveKey('title');
            expect($error)->toHaveKey('detail');
            expect($error)->toHaveKey('code');
            expect($error)->toHaveKey('status');
            expect($error['code'])->toBeString()->toBeNotEmpty();
            expect($error['status'])->toBeInt();
        }
    });

    it('all exceptions are JsonSerializable', function (): void {
        $ex = InvalidStateDomainException::because('test');
        $json = json_encode($ex);
        expect($json)->toBeJson();

        $data = json_decode($json, true);
        expect($data['code'])->toBe('INVALID_STATE');
    });

    it('exception round-trips through toArray/fromArray', function (): void {
        $original = InvalidStateDomainException::because('Order must be pending');
        $array = $original->toArray();

        $restored = \ZeroBoiler\Domain\Exceptions\DomainException::fromArray(
            $array,
            InvalidStateDomainException::class,
        );
        expect($restored->getMessage())->toBe('Order must be pending');
    });

    it('exception round-trips through fromJson', function (): void {
        $original = NotFoundDomainException::forId('entity-789');
        $json = json_encode($original->toArray());

        $restored = \ZeroBoiler\Domain\Exceptions\DomainException::fromJson(
            $json,
            NotFoundDomainException::class,
        );
        expect($restored->getMessage())->toContain('entity-789');
    });

    it('supports custom error code via constructor', function (): void {
        $ex = InvalidStateDomainException::because('test', 'CUSTOM_CODE');
        expect($ex->errorCode())->toBe('CUSTOM_CODE');
    });
});

// ---------------------------------------------------------------------------
//  Snapshot — immutable aggregate state
// ---------------------------------------------------------------------------

describe('Snapshot — production contract', function (): void {
    it('is final readonly', function (): void {
        $reflection = new ReflectionClass(Snapshot::class);
        expect($reflection->isFinal())->toBeTrue();
        expect($reflection->isReadOnly())->toBeTrue();
    });

    it('creates via factory with auto-timestamp', function (): void {
        $snapshot = Snapshot::create('Order', 'id-1', 10, ['status' => 'paid']);
        expect($snapshot->createdAt)->toBeInstanceOf(\DateTimeImmutable::class);
    });

    it('round-trips through toArray/fromArray', function (): void {
        $original = Snapshot::create('Order', 'id-1', 10, ['status' => 'paid']);
        $restored = Snapshot::fromArray($original->toArray());

        expect($restored->aggregateType)->toBe('Order');
        expect($restored->aggregateId)->toBe('id-1');
        expect($restored->version)->toBe(10);
        expect($restored->state)->toBe(['status' => 'paid']);
    });

    it('round-trips through fromJson', function (): void {
        $original = Snapshot::create('Order', 'id-1', 5, ['key' => 'value']);
        $json = json_encode($original->toArray());
        $restored = Snapshot::fromJson($json);

        expect($original->equals($restored))->toBeTrue();
    });

    it('compares equality structurally', function (): void {
        $a = Snapshot::create('Order', 'id-1', 5, ['s' => 'paid']);
        $b = Snapshot::create('Order', 'id-1', 5, ['s' => 'paid']);
        $c = Snapshot::create('Order', 'id-1', 5, ['s' => 'shipped']);

        expect($a->equals($b))->toBeTrue();
        expect($a->equals($c))->toBeFalse();
    });

    it('implements JsonSerializable', function (): void {
        $snapshot = Snapshot::create('Order', 'id-1', 1, []);
        $json = json_encode($snapshot);
        expect($json)->toBeJson();

        $data = json_decode($json, true);
        expect($data['aggregate_type'])->toBe('Order');
    });

    it('throws on invalid data in fromArray', function (): void {
        Snapshot::fromArray(['invalid' => 'data']);
    })->throws(\InvalidArgumentException::class);
});

// ---------------------------------------------------------------------------
//  InMemorySnapshotStore
// ---------------------------------------------------------------------------

describe('InMemorySnapshotStore — production contract', function (): void {
    it('implements SnapshotStore interface', function (): void {
        $store = new InMemorySnapshotStore;
        expect($store)->toBeInstanceOf(\ZeroBoiler\Domain\Snapshots\SnapshotStore::class);
    });

    it('has correct interface methods with #[Override]', function (): void {
        $reflection = new ReflectionClass(InMemorySnapshotStore::class);

        $methods = ['load', 'save', 'has', 'delete', 'deleteOlderThan', 'count', 'stats', 'purge'];
        foreach ($methods as $method) {
            $m = $reflection->getMethod($method);
            expect($m)->not->toBeNull();
        }
    });

    it('purge returns count of removed items', function (): void {
        $store = new InMemorySnapshotStore;
        $store->save(Snapshot::create('A', '1', 1, []));
        $store->save(Snapshot::create('A', '2', 2, []));
        $store->save(Snapshot::create('B', '1', 1, []));

        expect($store->purge('A'))->toBe(2);
        expect($store->count())->toBe(1);
    });
});

// ---------------------------------------------------------------------------
//  SnapshotPolicy attribute
// ---------------------------------------------------------------------------

describe('SnapshotPolicy — production contract', function (): void {
    it('is a final readonly attribute', function (): void {
        $reflection = new ReflectionClass(SnapshotPolicy::class);
        expect($reflection->isFinal())->toBeTrue();
        expect($reflection->isReadOnly())->toBeTrue();

        $attrs = $reflection->getAttributes(\Attribute::class);
        expect($attrs)->not->toBeEmpty();
    });

    it('defaults to every=50', function (): void {
        $policy = new SnapshotPolicy;
        expect($policy->every)->toBe(50);
    });

    it('accepts custom interval', function (): void {
        $policy = new SnapshotPolicy(every: 100);
        expect($policy->every)->toBe(100);
    });
});

// ---------------------------------------------------------------------------
//  DomainEventCollection
// ---------------------------------------------------------------------------

describe('DomainEventCollection — production contract', function (): void {
    it('is final readonly', function (): void {
        $reflection = new ReflectionClass(\ZeroBoiler\Domain\DomainEventCollection::class);
        expect($reflection->isFinal())->toBeTrue();
        expect($reflection->isReadOnly())->toBeTrue();
    });

    it('rejects non-list arrays', function (): void {
        new \ZeroBoiler\Domain\DomainEventCollection(['key' => new TestDomainEvent]);
    })->throws(\InvalidArgumentException::class);

    it('rejects non-DomainEvent items', function (): void {
        new \ZeroBoiler\Domain\DomainEventCollection(['not-an-event']);
    })->throws(\InvalidArgumentException::class);

    it('provides count, isEmpty, all, first, last', function (): void {
        $e1 = new TestDomainEvent('a');
        $e2 = new TestDomainEvent('b');
        $e3 = new TestDomainEvent('c');

        $collection = new \ZeroBoiler\Domain\DomainEventCollection([$e1, $e2, $e3]);
        expect($collection->count())->toBe(3);
        expect($collection->isEmpty())->toBeFalse();
        expect($collection->all())->toHaveCount(3);
        expect($collection->first()->eventType)->toBe('a');
        expect($collection->last()->eventType)->toBe('c');
    });

    it('supports filter, map, some, none, find, hasType', function (): void {
        $e1 = new TestDomainEvent('order.created');
        $e2 = new TestDomainEvent('order.paid');
        $e3 = new TestDomainEvent('user.registered');

        $collection = new \ZeroBoiler\Domain\DomainEventCollection([$e1, $e2, $e3]);

        expect($collection->hasType('order.paid'))->toBeTrue();
        expect($collection->hasType('order.shipped'))->toBeFalse();

        $orderEvents = $collection->filter(fn ($e) => str_starts_with($e->eventType, 'order.'));
        expect($orderEvents->count())->toBe(2);

        expect($collection->some(fn ($e) => $e->eventType === 'order.paid'))->toBeTrue();
        expect($collection->none(fn ($e) => $e->eventType === 'order.shipped'))->toBeTrue();

        $found = $collection->find(fn ($e) => $e->eventType === 'user.registered');
        expect($found)->not->toBeNull();
    });

    it('supports merge returning new collection', function (): void {
        $c1 = new \ZeroBoiler\Domain\DomainEventCollection([new TestDomainEvent('a')]);
        $c2 = new \ZeroBoiler\Domain\DomainEventCollection([new TestDomainEvent('b')]);

        $merged = $c1->merge($c2);
        expect($merged->count())->toBe(2);
        expect($c1->count())->toBe(1); // Original unchanged
    });
});

// ---------------------------------------------------------------------------
//  Interface contracts — return types, method signatures
// ---------------------------------------------------------------------------

describe('Domain contracts — interface compliance', function (): void {
    it('Identifier contract has required methods', function (): void {
        $reflection = new ReflectionClass(IdentifierContract::class);
        $required = ['fromString', 'toString', 'equals', 'toArray', 'fromArray', 'fromJson'];

        foreach ($required as $method) {
            expect($reflection->hasMethod($method))->toBeTrue();
        }
    });

    it('Repository contract has required methods', function (): void {
        $reflection = new ReflectionClass(RepositoryContract::class);
        $required = ['find', 'save', 'delete'];

        foreach ($required as $method) {
            expect($reflection->hasMethod($method))->toBeTrue();
        }
    });

    it('UnitOfWork contract has required methods', function (): void {
        $reflection = new ReflectionClass(UnitOfWorkContract::class);
        $required = ['begin', 'commit', 'rollback', 'run', 'isActive', 'track',
            'isTracking', 'markForDeletion', 'getCommitted', 'getDeleted',
            'hasPendingEvents', 'getPendingEventCount', 'getPendingEvents', 'clear',
        ];

        foreach ($required as $method) {
            expect($reflection->hasMethod($method))->toBeTrue();
        }
    });

    it('UnitOfWork::run has Closure parameter with mixed return', function (): void {
        $method = new ReflectionMethod(UnitOfWorkContract::class, 'run');
        $params = $method->getParameters();
        expect($params[0]->getType()->getName())->toBe('Closure');
        expect($method->getReturnType()->getName())->toBe('mixed');
    });
});

// ---------------------------------------------------------------------------
//  declare(strict_types=1) — all source files
// ---------------------------------------------------------------------------

describe('Strict types — all source files', function (): void {
    $srcDir = dirname(__DIR__, 2) . '/src';
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
    );

    $phpFiles = [];
    foreach ($iterator as $file) {
        if ($file->getExtension() === 'php') {
            $phpFiles[] = $file->getPathname();
        }
    }

    it('has at least 20 source files', function () use ($phpFiles) {
        expect(count($phpFiles))->toBeGreaterThan(20);
    });

    it('all source files declare strict_types', function () use ($phpFiles) {
        foreach ($phpFiles as $file) {
            $contents = file_get_contents($file);
            $hasStrict = str_contains($contents, 'declare(strict_types=1)');
            expect($hasStrict)->toBeTrue("File {$file} is missing declare(strict_types=1)");
        }
    });
});
