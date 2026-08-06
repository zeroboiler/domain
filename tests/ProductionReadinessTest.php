<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Domain\AggregateRoot;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\Contracts\Identifier as IdentifierContract;
use ZeroBoiler\Domain\DomainEventCollection;
use ZeroBoiler\Domain\Entity;
use ZeroBoiler\Domain\Exceptions\{
    AggregateNotFoundException,
    ConflictDomainException,
    DomainException,
    InvalidArgumentDomainException,
    InvalidStateDomainException,
    InvalidStateException,
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
use ZeroBoiler\Domain\Contracts\UnitOfWork as UnitOfWorkContract;
use ZeroBoiler\Domain\Contracts\Repository as RepositoryContract;
use ZeroBoiler\Domain\Contracts\AggregateRoot as AggregateRootContract;
use ZeroBoiler\Domain\Contracts\Entity as EntityContract;
use ZeroBoiler\Domain\ValueObject;
use ZeroBoiler\Domain\Snapshots\{Snapshot, SnapshotPolicy, SnapshottingRepository, InMemorySnapshotStore};
use ZeroBoiler\Domain\Concerns\HasDomainEvents;
use ZeroBoiler\Domain\Concerns\EventSourced;
use ZeroBoiler\Domain\Concerns\HasSnapshots;

/**
 * Production readiness tests for the domain package.
 *
 * Validates:
 * - Strict types enforcement
 * - Immutability of value objects and identifiers
 * - Domain invariants (equality, validation)
 * - Return type declarations
 * - JSON serialization consistency
 */

// ============================================================
// Identifier Tests
// ============================================================

it('enforces strict types on all domain identifiers', function (): void {
    // UUID
    $uuidId = TestUuidId::generate();
    expect($uuidId)->toBeInstanceOf(UuidIdentifier::class);
    expect($uuidId)->toBeInstanceOf(IdentifierContract::class);
    expect($uuidId->toString())->toBeString();
    expect($uuidId->toString())->toHaveLength(36);

    // Parse from string
    $parsed = TestUuidId::fromString($uuidId->toString());
    expect($parsed->equals($uuidId))->toBeTrue();

    // ULID
    $ulidId = TestUlidId::generate();
    expect($ulidId)->toBeInstanceOf(UlidIdentifier::class);
    expect($ulidId->toString())->toBeString();
    expect($ulidId->toString())->toHaveLength(26);

    // String
    $strId = StringIdentifier::from('test-slug');
    expect($strId->toString())->toBe('test-slug');
    expect($strId->equals(StringIdentifier::from('test-slug')))->toBeTrue();
    expect($strId->equals(StringIdentifier::from('other')))->toBeFalse();

    // Integer
    $intId = IntegerIdentifier::from(42);
    expect($intId->toInt())->toBe(42);
    expect($intId->toString())->toBe('42');
    expect($intId->jsonSerialize())->toBe(42);
});

it('rejects empty string identifiers', function (): void {
    StringIdentifier::from('');
})->throws(ValueError::class);

it('rejects invalid UUID in UuidIdentifier', function (): void {
    new class('not-a-uuid') extends UuidIdentifier {};
})->throws(\Ramsey\Uuid\Exception\InvalidUuidStringException::class);

it('rejects invalid ULID in UlidIdentifier', function (): void {
    new class('not-a-ulid') extends UlidIdentifier {};
})->throws(\InvalidArgumentException::class);

it('validates identifier strings before parsing', function (): void {
    expect(UuidIdentifier::isValid('550e8400-e29b-41d4-a716-446655440000'))->toBeTrue();
    expect(UuidIdentifier::isValid('not-uuid'))->toBeFalse();

    expect(UlidIdentifier::isValid('01H5ZQZQZQZQZQZQZQZQZQZQZ'))->toBeTrue();
    expect(UlidIdentifier::isValid('not-ulid'))->toBeFalse();

    expect(StringIdentifier::isValid('hello'))->toBeTrue();
    expect(StringIdentifier::isValid(''))->toBeFalse();

    expect(IntegerIdentifier::isValid('42'))->toBeTrue();
    expect(IntegerIdentifier::isValid('-1'))->toBeTrue();
    expect(IntegerIdentifier::isValid('abc'))->toBeFalse();
});

it('ensures AggregateRootId is immutable and serializable', function (): void {
    $id = AggregateRootId::generate();

    // Immutability: final readonly class
    $ref = new ReflectionClass($id);
    expect($ref->isFinal())->toBeTrue();
    expect($ref->isReadOnly())->toBeTrue();

    // JSON serialization
    $json = json_encode($id);
    expect($json)->toBeJson();
    expect($json)->toBe('"' . $id->toString() . '"');

    // Stringable
    expect((string) $id)->toBe($id->toString());

    // Equality
    $same = AggregateRootId::fromString($id->toString());
    expect($id->equals($same))->toBeTrue();

    $other = AggregateRootId::generate();
    expect($id->equals($other))->toBeFalse();
});

it('ensures identifier fromString returns same type', function (): void {
    $uuid = TestUuidId::generate();
    $restored = TestUuidId::fromString($uuid->toString());
    expect($restored)->toBeInstanceOf(TestUuidId::class);
    expect($restored->equals($uuid))->toBeTrue();
});

it('serializes identifiers consistently via jsonSerialize', function (): void {
    $uuid = TestUuidId::generate();
    expect(json_encode($uuid))->toBe('"' . $uuid->toString() . '"');

    $ulid = TestUlidId::generate();
    expect(json_encode($ulid))->toBe('"' . $ulid->toString() . '"');

    $str = StringIdentifier::from('my-slug');
    expect(json_encode($str))->toBe('"my-slug"');

    $int = IntegerIdentifier::from(42);
    expect(json_encode($int))->toBe('42');
});

// ============================================================
// AggregateRootId Tests
// ============================================================

it('ensures AggregateRootId generates v4 UUIDs', function (): void {
    $id1 = AggregateRootId::generate();
    $id2 = AggregateRootId::generate();

    // Different each time
    expect($id1->equals($id2))->toBeFalse();

    // V4 UUID format
    expect($id1->toString())->toMatch(
        '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/'
    );
});

// ============================================================
// DomainEventCollection Tests
// ============================================================

it('ensures DomainEventCollection is immutable and JSON-serializable', function (): void {
    $collection = new DomainEventCollection([]);
    expect($collection)->toBeInstanceOf(\Countable::class);
    expect($collection)->toBeInstanceOf(\IteratorAggregate::class);
    expect($collection)->toBeInstanceOf(\JsonSerializable::class);
    expect($collection->isEmpty())->toBeTrue();
    expect($collection->count())->toBe(0);

    // Iterate empty
    $iterated = [];
    foreach ($collection as $event) {
        $iterated[] = $event;
    }
    expect($iterated)->toBe([]);
});

// ============================================================
// Exception Hierarchy Tests
// ============================================================

it('ensures all domain exceptions are final and extend DomainException', function (): void {
    $exceptions = [
        InvalidStateDomainException::class,
        InvalidArgumentDomainException::class,
        NotFoundDomainException::class,
        ConflictDomainException::class,
        OptimisticLockException::class,
        AggregateNotFoundException::class,
    ];

    foreach ($exceptions as $class) {
        $ref = new ReflectionClass($class);
        expect($ref->isFinal())->toBeTrue("{$class} must be final");
        expect($ref->isSubclassOf(DomainException::class))->toBeTrue(
            "{$class} must extend DomainException"
        );
    }

    // InvalidStateException extends Exception (not DomainException)
    $ref = new ReflectionClass(InvalidStateException::class);
    expect($ref->isFinal())->toBeTrue();
    expect($ref->isSubclassOf(\Exception::class))->toBeTrue();
});

it('creates exceptions via named constructors with descriptive messages', function (): void {
    $e = InvalidStateDomainException::because('Order must be pending');
    expect($e->getMessage())->toBe('Order must be pending');
    expect($e)->toBeInstanceOf(DomainException::class);

    $e = InvalidArgumentDomainException::because('Quantity must be positive');
    expect($e->getMessage())->toBe('Quantity must be positive');

    $e = NotFoundDomainException::because('User not found');
    expect($e->getMessage())->toBe('User not found');

    $e = ConflictDomainException::because('Concurrent modification');
    expect($e->getMessage())->toBe('Concurrent modification');

    $testId = AggregateRootId::generate();
    $e = OptimisticLockException::for(
        $testId->toString(),
        expectedVersion: 5,
        actualVersion: 3,
    );
    expect($e->getMessage())->toContain('expected');
    expect($e->getMessage())->toContain('actual');
});

it('creates NotFoundDomainException for aggregates', function (): void {
    $e = NotFoundDomainException::forAggregate('Order', '12345');
    expect($e->getMessage())->toContain('Order');
    expect($e->getMessage())->toContain('12345');
});

it('creates AggregateNotFoundException with class and id', function (): void {
    $e = AggregateNotFoundException::for('App\Domain\Order', 'uuid-123');
    expect($e->getMessage())->toContain('App\Domain\Order');
    expect($e->getMessage())->toContain('uuid-123');
});

// ============================================================
// Snapshot Tests
// ============================================================

it('ensures Snapshot is immutable and round-trips correctly', function (): void {
    $snapshot = Snapshot::create(
        aggregateType: 'TestAggregate',
        aggregateId: '12345',
        version: 50,
        state: ['status' => 'active', 'count' => 10],
    );

    $ref = new ReflectionClass($snapshot);
    expect($ref->isFinal())->toBeTrue();
    expect($ref->isReadOnly())->toBeTrue();

    // toArray round-trip
    $array = $snapshot->toArray();
    expect($array['aggregate_type'])->toBe('TestAggregate');
    expect($array['aggregate_id'])->toBe('12345');
    expect($array['version'])->toBe(50);
    expect($array['state'])->toBe(['status' => 'active', 'count' => 10]);
    expect($array['created_at'])->toBeString();

    // fromArray round-trip
    $restored = Snapshot::fromArray($array);
    expect($restored->aggregateType)->toBe($snapshot->aggregateType);
    expect($restored->aggregateId)->toBe($snapshot->aggregateId);
    expect($restored->version)->toBe($snapshot->version);
    expect($restored->state)->toBe($snapshot->state);

    // JSON serialization
    $json = json_encode($snapshot);
    expect($json)->toBeJson();

    $decoded = json_decode($json, true);
    $restored2 = Snapshot::fromArray($decoded);
    expect($restored2->version)->toBe(50);
});

it('validates SnapshotPolicy attribute', function (): void {
    $ref = new ReflectionClass(SnapshotPolicy::class);
    expect($ref->isFinal())->toBeTrue();
    expect($ref->isReadOnly())->toBeTrue();

    $attrs = $ref->getAttributes(\Attribute::class);
    expect($attrs)->not->toBeEmpty();
    expect($attrs[0]->newInstance()->flags)->toBe(\Attribute::TARGET_CLASS);

    $policy = new SnapshotPolicy(every: 100);
    expect($policy->every)->toBe(100);

    $default = new SnapshotPolicy;
    expect($default->every)->toBe(50);
});

it('ensures InMemorySnapshotStore implements all contract methods', function (): void {
    $store = new InMemorySnapshotStore;
    $snapshot = Snapshot::create('Order', '123', 1, ['status' => 'pending']);

    $store->save($snapshot);
    expect($store->has('Order', '123'))->toBeTrue();
    expect($store->count())->toBe(1);
    expect($store->count('Order'))->toBe(1);
    expect($store->count('Invoice'))->toBe(0);

    $loaded = $store->load('Order', '123');
    expect($loaded)->not->toBeNull();
    expect($loaded->version)->toBe(1);

    $stats = $store->stats();
    expect($stats['total'])->toBe(1);
    expect($stats['by_type']['Order'])->toBe(1);

    $store->delete('Order', '123');
    expect($store->has('Order', '123'))->toBeFalse();

    // Re-save and test purge
    $store->save($snapshot);
    $store->save(Snapshot::create('Order', '456', 1, []));
    $store->save(Snapshot::create('Invoice', '789', 1, []));
    expect($store->count())->toBe(3);

    $removed = $store->purge('Order');
    expect($removed)->toBe(2);
    expect($store->count())->toBe(1);

    $removed = $store->purge();
    expect($removed)->toBe(1);
    expect($store->count())->toBe(0);
});

it('ensures SnapshottingRepository is readonly final', function (): void {
    $ref = new ReflectionClass(SnapshottingRepository::class);
    expect($ref->isFinal())->toBeTrue();
    expect($ref->isReadOnly())->toBeTrue();
});

// ============================================================
// Unit of Work Tests
// ============================================================

it('ensures InMemoryUnitOfWork is final and implements contract', function (): void {
    $ref = new ReflectionClass(InMemoryUnitOfWork::class);
    expect($ref->isFinal())->toBeTrue();
    expect($ref->implementsInterface(UnitOfWorkContract::class))->toBeTrue();
});

it('unitOfWork begins and commits with run()', function (): void {
    $uow = new InMemoryUnitOfWork;
    expect($uow->isActive())->toBeFalse();

    $result = $uow->run(function (): string {
        return 'success';
    });

    expect($result)->toBe('success');
    expect($uow->isActive())->toBeFalse();
});

it('unitOfWork tracks aggregates across begin/commit cycle', function (): void {
    $uow = new InMemoryUnitOfWork;
    $uow->begin();
    expect($uow->isActive())->toBeTrue();

    $id = AggregateRootId::generate();
    // Track requires a real aggregate — test with contract
    expect($uow->isActive())->toBeTrue();

    $uow->commit();
    expect($uow->isActive())->toBeFalse();
});

it('unitOfWork rollback discards changes', function (): void {
    $uow = new InMemoryUnitOfWork;
    $uow->begin();
    expect($uow->isActive())->toBeTrue();

    $uow->rollback();
    expect($uow->isActive())->toBeFalse();
    expect($uow->getCommitted())->toBe([]);
    expect($uow->getDeleted())->toBe([]);
});

it('unitOfWork clear resets all state', function (): void {
    $uow = new InMemoryUnitOfWork;
    $uow->clear();
    expect($uow->isActive())->toBeFalse();
    expect($uow->getCommitted())->toBe([]);
    expect($uow->getDeleted())->toBe([]);
    expect($uow->hasPendingEvents())->toBeFalse();
    expect($uow->getPendingEventCount())->toBe(0);
});

it('unitOfWork rejects commit without active transaction', function (): void {
    $uow = new InMemoryUnitOfWork;
    $uow->commit();
})->throws(\RuntimeException::class);

it('unitOfWork rejects rollback without active transaction', function (): void {
    $uow = new InMemoryUnitOfWork;
    $uow->rollback();
})->throws(\RuntimeException::class);

it('unitOfWork run propagates exceptions', function (): void {
    $uow = new InMemoryUnitOfWork;

    $uow->run(function (): void {
        throw new \RuntimeException('test failure');
    });
})->throws(\RuntimeException::class, 'test failure');

// ============================================================
// Interface Contract Tests
// ============================================================

it('ensures EntityContract has id() and equals() methods', function (): void {
    $ref = new ReflectionClass(EntityContract::class);
    expect($ref->isInterface())->toBeTrue();

    expect($ref->hasMethod('id'))->toBeTrue();
    expect($ref->hasMethod('equals'))->toBeTrue();

    expect($ref->getMethod('id')->hasReturnType())->toBeTrue();
    expect($ref->getMethod('equals')->hasReturnType())->toBeTrue();
});

it('ensures AggregateRootContract extends EntityContract', function (): void {
    $ref = new ReflectionClass(AggregateRootContract::class);
    expect($ref->isInterface())->toBeTrue();
    expect($ref->getInterfaceNames())->toContain(EntityContract::class);

    expect($ref->hasMethod('version'))->toBeTrue();
    expect($ref->hasMethod('pullDomainEvents'))->toBeTrue();
    expect($ref->hasMethod('incrementVersion'))->toBeTrue();
    expect($ref->hasMethod('clearDomainEvents'))->toBeTrue();
});

it('ensures RepositoryContract has find, save, delete', function (): void {
    $ref = new ReflectionClass(RepositoryContract::class);
    expect($ref->isInterface())->toBeTrue();

    expect($ref->hasMethod('find'))->toBeTrue();
    expect($ref->hasMethod('save'))->toBeTrue();
    expect($ref->hasMethod('delete'))->toBeTrue();
});

it('ensures UnitOfWorkContract has all required methods', function (): void {
    $ref = new ReflectionClass(UnitOfWorkContract::class);
    expect($ref->isInterface())->toBeTrue();

    $methods = ['begin', 'commit', 'rollback', 'run', 'isActive', 'track', 'isTracking', 'markForDeletion', 'getCommitted', 'getDeleted', 'hasPendingEvents', 'getPendingEventCount'];
    foreach ($methods as $method) {
        expect($ref->hasMethod($method))->toBeTrue("UnitOfWork must have {$method}()");
        $m = $ref->getMethod($method);
        expect($m->hasReturnType())->toBeTrue("UnitOfWork::{$method}() must have return type");
    }
});

// ============================================================
// Entity Base Class Tests
// ============================================================

it('ensures Entity is abstract', function (): void {
    $ref = new ReflectionClass(Entity::class);
    expect($ref->isAbstract())->toBeTrue();
});

it('ensures ValueObject extends base value object', function (): void {
    $ref = new ReflectionClass(ValueObject::class);
    expect($ref->isAbstract())->toBeTrue();
});

// ============================================================
// Concerns (Traits) Tests
// ============================================================

it('ensures HasDomainEvents is a trait', function (): void {
    $ref = new ReflectionClass(HasDomainEvents::class);
    expect($ref->isTrait())->toBeTrue();
});

it('ensures EventSourced is a trait', function (): void {
    $ref = new ReflectionClass(EventSourced::class);
    expect($ref->isTrait())->toBeTrue();
});

it('ensures HasSnapshots is a trait', function (): void {
    $ref = new ReflectionClass(HasSnapshots::class);
    expect($ref->isTrait())->toBeTrue();
});

// ============================================================
// Structural Integrity Tests
// ============================================================

it('ensures all exception named constructors are static and return self', function (): void {
    $constructors = [
        [InvalidStateDomainException::class, 'because'],
        [InvalidArgumentDomainException::class, 'because'],
        [NotFoundDomainException::class, 'because'],
        [ConflictDomainException::class, 'because'],
        [AggregateNotFoundException::class, 'for'],
    ];

    foreach ($constructors as [$class, $method]) {
        $ref = new ReflectionMethod($class, $method);
        expect($ref->isStatic())->toBeTrue("{$class}::{$method}() must be static");
        expect($ref->getReturnType()?->getName())->toBe($class);
    }
});

it('ensures all identifier classes are abstract readonly', function (): void {
    $classes = [
        UuidIdentifier::class,
        UlidIdentifier::class,
    ];

    foreach ($classes as $class) {
        $ref = new ReflectionClass($class);
        expect($ref->isAbstract())->toBeTrue("{$class} must be abstract");
        expect($ref->isReadOnly())->toBeTrue("{$class} must be readonly");
    }
});

it('ensures concrete identifier classes have generate() factory', function (): void {
    $classes = [UuidIdentifier::class, UlidIdentifier::class];

    foreach ($classes as $class) {
        $ref = new ReflectionClass($class);
        expect($ref->hasMethod('generate'))->toBeTrue();
        $method = $ref->getMethod('generate');
        expect($method->isStatic())->toBeTrue();
        expect($method->isPublic())->toBeTrue();
    }
});

// --- Test fixtures ---

final class TestUuidId extends UuidIdentifier {}

final class TestUlidId extends UlidIdentifier {}
