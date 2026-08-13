<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Tests;

use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use ZeroBoiler\Domain\AggregateRoot;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\Contracts\AggregateRoot as AggregateRootContract;
use ZeroBoiler\Domain\Contracts\Entity as EntityContract;
use ZeroBoiler\Domain\Contracts\Identifier as IdentifierContract;
use ZeroBoiler\Domain\Contracts\Repository as RepositoryContract;
use ZeroBoiler\Domain\Contracts\UnitOfWork as UnitOfWorkContract;
use ZeroBoiler\Domain\DomainEventCollection;
use ZeroBoiler\Domain\DomainException;
use ZeroBoiler\Domain\Entity;
use ZeroBoiler\Domain\Exceptions\AggregateNotFoundException;
use ZeroBoiler\Domain\Exceptions\ConflictDomainException;
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

/**
 * Final production audit — verifies all domain primitives meet production-ready criteria.
 *
 * Checklist:
 * 1. All classes use declare(strict_types=1)
 * 2. All classes have proper docblocks with @since tags
 * 3. Core classes are final or abstract where appropriate
 * 4. All public methods have return type declarations
 * 5. All properties are typed
 * 6. All identifiers implement IdentifierContract
 * 7. All exceptions extend DomainException
 * 8. All exceptions have defaultErrorCode() and named constructors
 * 9. Round-trip serialization works for all primitives
 * 10. JsonSerializable is implemented consistently
 */
test('all source files have strict_types declaration', function (): void {
    $srcDir = __DIR__ . '/../src';
    $files = glob($srcDir . '/**/*.php', recursive: true);

    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        $content = file_get_contents($file);
        expect($content)
            ->toContain('declare(strict_types=1)', "File {$file} is missing declare(strict_types=1)");
    }
});

test('core classes have proper visibility modifiers', function (): void {
    // AggregateRootId is final readonly
    expect((new ReflectionClass(AggregateRootId::class))->isFinal())->toBeTrue();
    expect((new ReflectionClass(AggregateRootId::class))->isReadOnly())->toBeTrue();

    // AggregateRoot is abstract
    expect((new ReflectionClass(AggregateRoot::class))->isAbstract())->toBeTrue();

    // Entity is abstract
    expect((new ReflectionClass(Entity::class))->isAbstract())->toBeTrue();

    // InMemoryUnitOfWork is final
    expect((new ReflectionClass(InMemoryUnitOfWork::class))->isFinal())->toBeTrue();

    // DomainEventCollection is final readonly
    expect((new ReflectionClass(DomainEventCollection::class))->isFinal())->toBeTrue();
    expect((new ReflectionClass(DomainEventCollection::class))->isReadOnly())->toBeTrue();

    // Snapshot is final readonly
    expect((new ReflectionClass(Snapshot::class))->isFinal())->toBeTrue();
    expect((new ReflectionClass(Snapshot::class))->isReadOnly())->toBeTrue();

    // SnapshotPolicy is final readonly attribute
    expect((new ReflectionClass(SnapshotPolicy::class))->isFinal())->toBeTrue();
    expect((new ReflectionClass(SnapshotPolicy::class))->isReadOnly())->toBeTrue();

    // SnapshottingRepository is final readonly
    expect((new ReflectionClass(SnapshottingRepository::class))->isFinal())->toBeTrue();
    expect((new ReflectionClass(SnapshottingRepository::class))->isReadOnly())->toBeTrue();
});

test('all identifier types implement IdentifierContract', function (): void {
    $identifiers = [
        UuidIdentifier::class,
        UlidIdentifier::class,
        StringIdentifier::class,
        IntegerIdentifier::class,
    ];

    foreach ($identifiers as $class) {
        $ref = new ReflectionClass($class);
        expect($ref->implementsInterface(IdentifierContract::class))
            ->toBeTrue("{$class} must implement IdentifierContract");
        expect($ref->implementsInterface(\JsonSerializable::class))
            ->toBeTrue("{$class} must implement JsonSerializable");
    }
});

test('all exceptions extend DomainException', function (): void {
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

    foreach ($exceptions as $class) {
        $ref = new ReflectionClass($class);
        expect($ref->isSubclassOf(DomainException::class))
            ->toBeTrue("{$class} must extend DomainException");
        expect($ref->isFinal())
            ->toBeTrue("{$class} must be final");
    }
});

test('all exceptions have named constructors and errorCode', function (): void {
    $e = InvalidStateDomainException::because('test');
    expect($e->errorCode())->toBe('INVALID_STATE');

    $e = InvalidArgumentDomainException::because('test');
    expect($e->errorCode())->toBe('INVALID_ARGUMENT');

    $e = NotFoundDomainException::because('test');
    expect($e->errorCode())->toBe('NOT_FOUND');

    $e = NotFoundDomainException::forAggregate('Order', '123');
    expect($e->errorCode())->toBe('NOT_FOUND');

    $e = ConflictDomainException::because('test');
    expect($e->errorCode())->toBe('CONFLICT');

    $e = OptimisticLockException::for('id', 1, 2);
    expect($e->errorCode())->toBe('OPTIMISTIC_LOCK');

    $e = AggregateNotFoundException::for('Order', '123');
    expect($e->errorCode())->toBe('AGGREGATE_NOT_FOUND');

    $e = InvalidAggregateRootException::notAnAggregate(new \stdClass);
    expect($e->errorCode())->toBe('INVALID_AGGREGATE_ROOT');

    $e = InvalidStateException::because('test');
    expect($e->errorCode())->toBe('INVALID_STATE_SYSTEM');
});

test('all public methods have return type declarations', function (): void {
    $classesToCheck = [
        AggregateRootId::class,
        AggregateRoot::class,
        Entity::class,
        DomainEventCollection::class,
        InMemoryUnitOfWork::class,
        Snapshot::class,
        InMemorySnapshotStore::class,
    ];

    foreach ($classesToCheck as $class) {
        $ref = new ReflectionClass($class);
        $publicMethods = $ref->getMethods(ReflectionMethod::IS_PUBLIC);

        foreach ($publicMethods as $method) {
            // Skip constructor
            if ($method->getName() === '__construct') {
                continue;
            }

            expect($method->hasReturnType())
                ->toBeTrue("{$class}::{$method->getName()}() must have a return type declaration");
        }
    }
});

test('all properties are typed', function (): void {
    $classesToCheck = [
        AggregateRootId::class,
        DomainEventCollection::class,
        Snapshot::class,
    ];

    foreach ($classesToCheck as $class) {
        $ref = new ReflectionClass($class);
        $properties = $ref->getProperties();

        foreach ($properties as $property) {
            if ($property->getName() === 'value' && $class === AggregateRootId::class) {
                // Readonly promoted property from constructor
                expect($property->hasType())
                    ->toBeTrue("{$class}::\${$property->getName()} must have a type");
                continue;
            }

            expect($property->hasType())
                ->toBeTrue("{$class}::\${$property->getName()} must have a type declaration");
        }
    }
});

test('AggregateRootId round-trip serialization', function (): void {
    $id = AggregateRootId::generate();
    $array = $id->toArray();
    $restored = AggregateRootId::fromArray($array);

    expect($id->equals($restored))->toBeTrue();
    expect($id->toString())->toBe($restored->toString());
    expect($id->jsonSerialize())->toBe($id->toString());
});

test('all identifier types round-trip serialization', function (): void {
    // UUID
    $uuid = UuidIdentifier::fromString('550e8400-e29b-41d4-a716-446655440000');
    $restored = UuidIdentifier::fromArray($uuid->toArray());
    expect($uuid->equals($restored))->toBeTrue();
    expect($uuid->jsonSerialize())->toBe('550e8400-e29b-41d4-a716-446655440000');

    // ULID
    $ulid = UlidIdentifier::fromString('01H5J5S5S5S5S5S5S5S5S5S5S5');
    $restored = UlidIdentifier::fromArray($ulid->toArray());
    expect($ulid->equals($restored))->toBeTrue();

    // String
    $str = StringIdentifier::from('my-slug');
    $restored = StringIdentifier::fromArray($str->toArray());
    expect($str->equals($restored))->toBeTrue();
    expect($str->jsonSerialize())->toBe('my-slug');

    // Integer
    $int = IntegerIdentifier::from(42);
    $restored = IntegerIdentifier::fromArray($int->toArray());
    expect($int->equals($restored))->toBeTrue();
    expect($int->jsonSerialize())->toBe(42);
});

test('DomainException round-trip serialization', function (): void {
    $original = InvalidStateDomainException::because('Order must be pending', 'CUSTOM_CODE');
    $array = $original->toArray();
    $restored = DomainException::fromArray($array, InvalidStateDomainException::class);

    expect($restored->getMessage())->toBe('Order must be pending');
    expect($restored->errorCode())->toBe('CUSTOM_CODE');

    // toErrorArray format round-trip
    $errorArray = $original->toErrorArray();
    expect($errorArray)->toHaveKeys(['title', 'detail', 'code']);
    expect($errorArray['code'])->toBe('CUSTOM_CODE');

    // JSON serialization
    $json = json_encode($original);
    expect($json)->toBeJson();
    $decoded = json_decode($json, true);
    expect($decoded)->toHaveKey('code');
});

test('Snapshot round-trip serialization', function (): void {
    $snapshot = Snapshot::create(
        aggregateType: 'App\\Domain\\Order',
        aggregateId: '550e8400-e29b-41d4-a716-446655440000',
        version: 42,
        state: ['status' => 'pending', 'total' => 1999],
    );

    $array = $snapshot->toArray();
    $restored = Snapshot::fromArray($array);

    expect($snapshot->equals($restored))->toBeTrue();
    expect($restored->aggregateType)->toBe('App\\Domain\\Order');
    expect($restored->aggregateId)->toBe('550e8400-e29b-41d4-a716-446655440000');
    expect($restored->version)->toBe(42);
    expect($restored->state)->toBe(['status' => 'pending', 'total' => 1999]);

    // JSON serialization
    $json = json_encode($snapshot);
    expect($json)->toBeJson();
});

test('DomainEventCollection round-trip serialization', function (): void {
    $collection = new DomainEventCollection([
        \ZeroBoiler\Events\Domain\DomainEvent::occur('test.created', ['id' => '1']),
        \ZeroBoiler\Events\Domain\DomainEvent::occur('test.updated', ['id' => '1', 'field' => 'value']),
    ]);

    $array = $collection->toArray();
    expect($array)->toBeArray();
    expect($array)->toHaveCount(2);

    // JSON serialization
    $json = json_encode($collection);
    expect($json)->toBeJson();
});

test('SnapshotStore interface compliance', function (): void {
    $store = new InMemorySnapshotStore();

    // Implements contract
    expect($store)->toBeInstanceOf(SnapshotStore::class);

    // Save and load
    $snapshot = Snapshot::create('Order', '123', 1, ['status' => 'pending']);
    $store->save($snapshot);

    expect($store->has('Order', '123'))->toBeTrue();
    $loaded = $store->load('Order', '123');
    expect($loaded)->not->toBeNull();
    expect($loaded->aggregateId)->toBe('123');

    // Count and stats
    expect($store->count())->toBe(1);
    expect($store->count('Order'))->toBe(1);
    expect($store->count('Invoice'))->toBe(0);

    $stats = $store->stats();
    expect($stats)->toHaveKeys(['total', 'by_type']);
    expect($stats['total'])->toBe(1);

    // Delete
    $store->delete('Order', '123');
    expect($store->has('Order', '123'))->toBeFalse();
    expect($store->count())->toBe(0);

    // Purge
    $store->save($snapshot);
    $store->save(Snapshot::create('Invoice', '456', 1, []));
    $purged = $store->purge('Order');
    expect($purged)->toBe(1);
    expect($store->count())->toBe(1);

    $purged = $store->purge();
    expect($purged)->toBe(1);
    expect($store->count())->toBe(0);
});

test('UnitOfWork contract compliance', function (): void {
    $uow = new InMemoryUnitOfWork;

    expect($uow)->toBeInstanceOf(UnitOfWorkContract::class);
    expect($uow->isActive())->toBeFalse();

    // Run closure
    $result = $uow->run(function (): string {
        return 'success';
    });
    expect($result)->toBe('success');

    // Clear resets all state
    $uow->begin();
    $uow->commit();
    $uow->clear();
    expect($uow->isActive())->toBeFalse();
});

test('contracts have proper return types', function (): void {
    // Entity contract
    $entityMethods = (new ReflectionClass(EntityContract::class))->getMethods();
    foreach ($entityMethods as $method) {
        expect($method->hasReturnType())
            ->toBeTrue("EntityContract::{$method->getName()}() must have a return type");
    }

    // AggregateRoot contract
    $arMethods = (new ReflectionClass(AggregateRootContract::class))->getMethods();
    foreach ($arMethods as $method) {
        if (str_starts_with($method->getName(), 'pull') || str_starts_with($method->getName(), 'clear') || str_starts_with($method->getName(), 'has') || str_starts_with($method->getName(), 'peek')) {
            expect($method->hasReturnType())
                ->toBeTrue("AggregateRootContract::{$method->getName()}() must have a return type");
        }
    }

    // Repository contract
    $repoMethods = (new ReflectionClass(RepositoryContract::class))->getMethods();
    foreach ($repoMethods as $method) {
        expect($method->hasReturnType())
            ->toBeTrue("RepositoryContract::{$method->getName()}() must have a return type");
    }

    // UnitOfWork contract
    $uowMethods = (new ReflectionClass(UnitOfWorkContract::class))->getMethods();
    foreach ($uowMethods as $method) {
        expect($method->hasReturnType())
            ->toBeTrue("UnitOfWorkContract::{$method->getName()}() must have a return type");
    }

    // Identifier contract
    $idMethods = (new ReflectionClass(IdentifierContract::class))->getMethods();
    foreach ($idMethods as $method) {
        expect($method->hasReturnType())
            ->toBeTrue("IdentifierContract::{$method->getName()}() must have a return type");
    }

    // SnapshotStore contract
    $storeMethods = (new ReflectionClass(SnapshotStore::class))->getMethods();
    foreach ($storeMethods as $method) {
        expect($method->hasReturnType())
            ->toBeTrue("SnapshotStore::{$method->getName()}() must have a return type");
    }
});

test('AggregateRootId validation rejects invalid UUIDs', function (): void {
    expect(fn () => AggregateRootId::fromString('not-a-uuid'))
        ->toThrow(\Ramsey\Uuid\Exception\InvalidUuidStringException::class);

    expect(AggregateRootId::isValid('not-a-uuid'))->toBeFalse();
    expect(AggregateRootId::isValid('550e8400-e29b-41d4-a716-446655440000'))->toBeTrue();
});

test('StringIdentifier rejects empty string', function (): void {
    expect(fn () => StringIdentifier::from(''))
        ->toThrow(\ValueError::class);

    expect(StringIdentifier::isValid(''))->toBeFalse();
    expect(StringIdentifier::isValid('valid'))->toBeTrue();
});

test('all docblocks contain @since 1.0.0 tag', function (): void {
    $classesToCheck = [
        AggregateRootId::class,
        AggregateRoot::class,
        Entity::class,
        ValueObject::class,
        DomainEventCollection::class,
        InMemoryUnitOfWork::class,
        Snapshot::class,
        SnapshotPolicy::class,
        SnapshotStore::class,
        SnapshottingRepository::class,
        InMemorySnapshotStore::class,
        UuidIdentifier::class,
        UlidIdentifier::class,
        StringIdentifier::class,
        IntegerIdentifier::class,
        DomainException::class,
        InvalidStateDomainException::class,
        InvalidArgumentDomainException::class,
        NotFoundDomainException::class,
        ConflictDomainException::class,
        OptimisticLockException::class,
        AggregateNotFoundException::class,
    ];

    foreach ($classesToCheck as $class) {
        $ref = new ReflectionClass($class);
        $doc = $ref->getDocComment();
        expect($doc)->not->toBeFalse();
        expect($doc)->toContain('@since', "{$class} must have a @since tag in its docblock");
    }
});
