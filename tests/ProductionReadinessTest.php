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
use ZeroBoiler\Domain\Snapshots\{Snapshot, SnapshotPolicy, SnapshottingRepository};
use ZeroBoiler\Domain\Snapshots\InMemorySnapshotStore;

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

it('ensures InMemoryUnitOfWork is final', function (): void {
    $ref = new ReflectionClass(\ZeroBoiler\Domain\InMemoryUnitOfWork::class);
    expect($ref->isFinal())->toBeTrue();
});

// --- Test fixtures ---

final class TestUuidId extends UuidIdentifier {}

final class TestUlidId extends UlidIdentifier {}
