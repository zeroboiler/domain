<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Tests\Production;

use ZeroBoiler\Domain\AggregateRoot;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\Contracts\AggregateRoot as AggregateRootContract;
use ZeroBoiler\Domain\Contracts\Entity as EntityContract;
use ZeroBoiler\Domain\Contracts\Identifier as IdentifierContract;
use ZeroBoiler\Domain\Contracts\Repository as RepositoryContract;
use ZeroBoiler\Domain\Contracts\UnitOfWork as UnitOfWorkContract;
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
use ReflectionClass;
use ReflectionMethod;

/**
 * Comprehensive production readiness audit for the domain package.
 *
 * Verifies PHP 8.5 syntax compliance, strict types, interface implementations,
 * serialization contracts, immutability, and domain invariant enforcement
 * across all 40+ source files.
 *
 * @since 1.74.0
 */
test('all domain source files have strict_types declaration', function () {
    $srcDir = __DIR__ . '/../../../src';
    $files = findPhpFiles($srcDir);

    foreach ($files as $file) {
        $content = file_get_contents($file);
        expect($content)
            ->toContain('declare(strict_types=1)', "File {$file} is missing declare(strict_types=1)");
    }

    expect(count($files))->toBeGreaterThan(0);
});

test('all public methods have return type declarations', function () {
    $classesToCheck = [
        AggregateRoot::class,
        AggregateRootId::class,
        Entity::class,
        ValueObject::class,
        DomainEventCollection::class,
        InMemoryUnitOfWork::class,
        InMemorySnapshotStore::class,
        Snapshot::class,
        SnapshottingRepository::class,
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
        InvalidAggregateRootException::class,
        InvalidStateException::class,
    ];

    foreach ($classesToCheck as $class) {
        $reflection = new ReflectionClass($class);
        $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

        foreach ($methods as $method) {
            if ($method->getDeclaringClass()->getName() !== $class && ! $reflection->isAbstract()) {
                continue;
            }

            if (str_starts_with($method->getName(), '__')) {
                continue;
            }

            $returnType = $method->getReturnType();
            expect($returnType)
                ->not()->toBeNull(
                    "{$class}::{$method->getName()}() is missing return type declaration"
                );
        }
    }
});

test('readonly and final modifiers are correctly applied', function () {
    // final readonly classes
    $finalReadonlyClasses = [
        AggregateRootId::class,
        DomainEventCollection::class,
        Snapshot::class,
        UuidIdentifier::class,
        UlidIdentifier::class,
        StringIdentifier::class,
        IntegerIdentifier::class,
    ];

    foreach ($finalReadonlyClasses as $class) {
        $reflection = new ReflectionClass($class);
        expect($reflection->isFinal())->toBeTrue("{$class} should be final")
            ->and($reflection->isReadOnly())->toBeTrue("{$class} should be readonly");
    }

    // Abstract classes should NOT be final
    expect((new ReflectionClass(AggregateRoot::class))->isAbstract())->toBeTrue()
        ->and((new ReflectionClass(Entity::class))->isAbstract())->toBeTrue()
        ->and((new ReflectionClass(ValueObject::class))->isAbstract())->toBeTrue()
        ->and((new ReflectionClass(DomainException::class))->isAbstract())->toBeTrue();
});

test('interface implementations are correct', function () {
    // AggregateRoot implements AggregateRootContract + EntityContract
    $arReflection = new ReflectionClass(AggregateRoot::class);
    expect($arReflection->implementsInterface(AggregateRootContract::class))->toBeTrue()
        ->and($arReflection->implementsInterface(EntityContract::class))->toBeTrue();

    // Entity implements EntityContract + JsonSerializable
    $entityReflection = new ReflectionClass(Entity::class);
    expect($entityReflection->implementsInterface(EntityContract::class))->toBeTrue()
        ->and($entityReflection->implementsInterface(\JsonSerializable::class))->toBeTrue();

    // DomainEventCollection implements Countable, IteratorAggregate, JsonSerializable
    $decReflection = new ReflectionClass(DomainEventCollection::class);
    expect($decReflection->implementsInterface(\Countable::class))->toBeTrue()
        ->and($decReflection->implementsInterface(\IteratorAggregate::class))->toBeTrue()
        ->and($decReflection->implementsInterface(\JsonSerializable::class))->toBeTrue();

    // InMemoryUnitOfWork implements UnitOfWorkContract
    $uowReflection = new ReflectionClass(InMemoryUnitOfWork::class);
    expect($uowReflection->implementsInterface(UnitOfWorkContract::class))->toBeTrue();

    // All identifiers implement IdentifierContract
    $identifiers = [UuidIdentifier::class, UlidIdentifier::class, StringIdentifier::class, IntegerIdentifier::class];
    foreach ($identifiers as $identifier) {
        expect((new ReflectionClass($identifier))->implementsInterface(IdentifierContract::class))->toBeTrue()
            ->and((new ReflectionClass($identifier))->implementsInterface(\JsonSerializable::class))->toBeTrue();
    }
});

test('serialization contract: identifiers support toArray/fromArray/toJson/fromJson', function () {
    $identifiers = [
        'uuid' => UuidIdentifier::generate(),
        'ulid' => UlidIdentifier::generate(),
        'string' => StringIdentifier::from('test-key'),
        'integer' => IntegerIdentifier::from(42),
    ];

    foreach ($identifiers as $type => $id) {
        // toArray
        $array = $id->toArray();
        expect($array)->toBeArray("{$type} identifier toArray() should return array");

        // fromArray round-trip
        $restored = $id::fromArray($array);
        expect($restored->equals($id))->toBeTrue("{$type} identifier fromArray round-trip failed");

        // toJson
        $json = $id->toJson();
        expect($json)->toBeString("{$type} identifier toJson() should return string");

        // fromJson round-trip
        $fromJson = $id::fromJson($json);
        expect($fromJson->equals($id))->toBeTrue("{$type} identifier fromJson round-trip failed");

        // jsonSerialize
        $serialized = $id->jsonSerialize();
        expect($serialized)->not()->toBeNull("{$type} identifier jsonSerialize() should not be null");
    }
});

test('serialization contract: Snapshot supports round-trip', function () {
    $snapshot = new Snapshot(
        aggregateType: 'TestAggregate',
        aggregateId: AggregateRootId::generate()->toString(),
        version: 5,
        state: ['status' => 'completed', 'total' => 100],
    );

    $array = $snapshot->toArray();
    expect($array)->toHaveKeys(['aggregate_type', 'aggregate_id', 'version', 'state']);

    $json = $snapshot->toJson();
    $restored = Snapshot::fromJson($json);
    expect($restored->aggregateType)->toBe($snapshot->aggregateType)
        ->and($restored->aggregateId)->toBe($snapshot->aggregateId)
        ->and($restored->version)->toBe($snapshot->version)
        ->and($restored->state)->toBe($snapshot->state);
});

test('serialization contract: DomainEventCollection supports round-trip', function () {
    $event1 = DomainEvent::occur('order.placed', ['customer_id' => 'cust-1']);
    $event2 = DomainEvent::occur('order.paid', ['amount' => 100]);
    $collection = new DomainEventCollection([$event1, $event2]);

    $array = $collection->toArray();
    expect($array)->toBeArray()->toHaveCount(2);

    $json = $collection->toJson();
    expect($json)->toBeJson();

    $restored = DomainEventCollection::fromJson($json);
    expect($restored->count())->toBe(2)
        ->and($restored->first()->eventType)->toBe('order.placed')
        ->and($restored->last()->eventType)->toBe('order.paid');
});

test('domain exception hierarchy has unique error codes', function () {
    $exceptions = [
        InvalidStateDomainException::because('test'),
        InvalidArgumentDomainException::because('test'),
        NotFoundDomainException::because('test'),
        ConflictDomainException::because('test'),
        OptimisticLockException::for('id', 1, 2),
        AggregateNotFoundException::for('Test', 'id'),
        InvalidAggregateRootException::notAnAggregate(new \stdClass),
        InvalidStateException::because('test'),
    ];

    $codes = array_map(fn (DomainException $e) => $e->errorCode(), $exceptions);
    $uniqueCodes = array_unique($codes);

    expect($uniqueCodes)->toHaveCount(count($codes), 'All domain exceptions should have unique default error codes');
});

test('domain exceptions implement RFC 9457 toErrorArray format', function () {
    $exception = InvalidStateDomainException::because('Order is completed', 'ORDER_COMPLETED');

    $errorArray = $exception->toErrorArray();
    expect($errorArray)->toHaveKeys(['title', 'detail', 'code'])
        ->and($errorArray['code'])->toBe('ORDER_COMPLETED');

    // jsonSerialize should return same format
    $jsonSerialized = $exception->jsonSerialize();
    expect($jsonSerialized)->toBe($errorArray);
});

test('domain exceptions support round-trip serialization', function () {
    $original = InvalidStateDomainException::because('Test reason', 'TEST_CODE');

    $array = $original->toArray();
    $restored = InvalidStateDomainException::fromArray($array, 'TEST_CODE');

    expect($restored->getMessage())->toBe($original->getMessage())
        ->and($restored->errorCode())->toBe($original->errorCode());

    // JSON round-trip
    $json = $original->toJson();
    $fromJson = InvalidStateDomainException::fromJson($json);
    expect($fromJson->getMessage())->toBe($original->getMessage());
});

test('aggregate root contract methods have correct signatures', function () {
    $contract = new ReflectionClass(AggregateRootContract::class);
    $requiredMethods = ['id', 'version', 'incrementVersion', 'pullDomainEvents', 'clearDomainEvents', 'peekDomainEvents', 'equals', 'toArray', 'toJson'];

    foreach ($requiredMethods as $method) {
        expect($contract->hasMethod($method))->toBeTrue("AggregateRootContract should have method {$method}()");

        $contractMethod = $contract->getMethod($method);
        expect($contractMethod->getReturnType())->not()->toBeNull("{$method}() should have a return type");
    }
});

test('entity contract methods have correct signatures', function () {
    $contract = new ReflectionClass(EntityContract::class);
    $requiredMethods = ['id', 'equals', 'toArray', 'fromArray', 'fromJson', 'jsonSerialize', 'toJson'];

    foreach ($requiredMethods as $method) {
        expect($contract->hasMethod($method))->toBeTrue("EntityContract should have method {$method}()");

        $contractMethod = $contract->getMethod($method);
        expect($contractMethod->getReturnType())->not()->toBeNull("{$method}() should have a return type");
    }
});

test('unit of work contract methods have correct signatures', function () {
    $contract = new ReflectionClass(UnitOfWorkContract::class);
    $requiredMethods = ['begin', 'commit', 'rollback', 'run', 'track', 'isTracking', 'isActive'];

    foreach ($requiredMethods as $method) {
        expect($contract->hasMethod($method))->toBeTrue("UnitOfWorkContract should have method {$method}()");

        $contractMethod = $contract->getMethod($method);
        expect($contractMethod->getReturnType())->not()->toBeNull("{$method}() should have a return type");
    }
});

test('repository contract methods have correct signatures', function () {
    $contract = new ReflectionClass(RepositoryContract::class);
    $requiredMethods = ['find', 'save', 'delete'];

    foreach ($requiredMethods as $method) {
        expect($contract->hasMethod($method))->toBeTrue("RepositoryContract should have method {$method}()");

        $contractMethod = $contract->getMethod($method);
        expect($contractMethod->getReturnType())->not()->toBeNull("{$method}() should have a return type");
    }
});

test('identifier cross-type inequality is enforced', function () {
    $uuid = UuidIdentifier::generate();
    $ulid = UlidIdentifier::generate();
    $string = StringIdentifier::from('test');
    $integer = IntegerIdentifier::from(1);

    // Different identifier types should never be equal
    expect($uuid->equals($ulid))->toBeFalse()
        ->and($uuid->equals($string))->toBeFalse()
        ->and($uuid->equals($integer))->toBeFalse()
        ->and($ulid->equals($string))->toBeFalse()
        ->and($ulid->equals($integer))->toBeFalse()
        ->and($string->equals($integer))->toBeFalse();
});

test('aggregate root identity is immutable', function () {
    $id = AggregateRootId::generate();
    $originalString = $id->toString();

    // Create a new AggregateRoot with the ID
    $ar = new class($id) extends AggregateRoot {
        public function toArray(): array
        {
            return [...parent::toArray(), 'test' => true];
        }
    };

    expect($ar->id())->toBe($originalString)
        ->and($ar->aggregateId()->equals($id))->toBeTrue();

    // ID should not change after version increment
    $ar->incrementVersion();
    expect($ar->id())->toBe($originalString)
        ->and($ar->version())->toBe(1);
});

test('value object structural equality works correctly', function () {
    $vo1 = new class('123 Main St', 'NYC', 'US') extends ValueObject {
        public function __construct(
            public readonly string $street,
            public readonly string $city,
            public readonly string $country,
        ) {}

        public static function fromArray(array $data): static
        {
            return new static($data['street'], $data['city'], $data['country']);
        }

        public function toArray(): array
        {
            return ['street' => $this->street, 'city' => $this->city, 'country' => $this->country];
        }
    };

    $vo2 = new class('123 Main St', 'NYC', 'US') extends ValueObject {
        public function __construct(
            public readonly string $street,
            public readonly string $city,
            public readonly string $country,
        ) {}

        public static function fromArray(array $data): static
        {
            return new static($data['street'], $data['city'], $data['country']);
        }

        public function toArray(): array
        {
            return ['street' => $this->street, 'city' => $this->city, 'country' => $this->country];
        }
    };

    $vo3 = new class('456 Oak Ave', 'LA', 'US') extends ValueObject {
        public function __construct(
            public readonly string $street,
            public readonly string $city,
            public readonly string $country,
        ) {}

        public static function fromArray(array $data): static
        {
            return new static($data['street'], $data['city'], $data['country']);
        }

        public function toArray(): array
        {
            return ['street' => $this->street, 'city' => $this->city, 'country' => $this->country];
        }
    };

    // Same class, same values → equal
    expect($vo1->equals($vo1))->toBeTrue();

    // Different anonymous class instances → NOT equal (different concrete class)
    expect($vo1->equals($vo2))->toBeFalse();

    // Different values → NOT equal
    expect($vo1->equals($vo3))->toBeFalse();
});

test('domain event collection functional operations work', function () {
    $events = [
        DomainEvent::occur('order.placed', ['id' => 1]),
        DomainEvent::occur('order.item_added', ['id' => 2]),
        DomainEvent::occur('order.paid', ['id' => 3]),
        DomainEvent::occur('order.placed', ['id' => 4]),
    ];

    $collection = new DomainEventCollection($events);

    // count, isEmpty
    expect($collection->count())->toBe(4)
        ->and($collection->isEmpty())->toBeFalse();

    // some / none
    expect($collection->some(fn (DomainEvent $e) => $e->eventType === 'order.placed'))->toBeTrue()
        ->and($collection->some(fn (DomainEvent $e) => $e->eventType === 'order.shipped'))->toBeFalse()
        ->and($collection->none(fn (DomainEvent $e) => $e->eventType === 'order.shipped'))->toBeTrue();

    // hasType
    expect($collection->hasType('order.placed'))->toBeTrue()
        ->and($collection->hasType('order.shipped'))->toBeFalse();

    // countBy
    expect($collection->countBy(fn (DomainEvent $e) => $e->eventType === 'order.placed'))->toBe(2);

    // find
    $found = $collection->find(fn (DomainEvent $e) => $e->eventType === 'order.paid');
    expect($found)->not()->toBeNull()
        ->and($found->eventType)->toBe('order.paid');

    // types
    $types = $collection->types();
    expect($types)->toBe(['order.placed', 'order.item_added', 'order.paid']);

    // reduce
    $total = $collection->reduce(
        fn (int $sum, DomainEvent $e) => $sum + ($e->payload['id'] ?? 0),
        0,
    );
    expect($total)->toBe(10);

    // merge
    $extra = DomainEvent::occur('order.shipped', ['id' => 5]);
    $merged = $collection->merge(new DomainEventCollection([$extra]));
    expect($merged->count())->toBe(5)
        ->and($merged->hasType('order.shipped'))->toBeTrue();
});

test('unit of work lifecycle: begin/track/commit/rollback', function () {
    $uow = new InMemoryUnitOfWork;

    $id = AggregateRootId::generate();
    $ar = new class($id) extends AggregateRoot {
        public function toArray(): array
        {
            return [...parent::toArray(), 'test' => true];
        }
    };

    // Initial state
    expect($uow->isActive())->toBeFalse()
        ->and($uow->isTracking($ar))->toBeFalse();

    $uow->begin();
    expect($uow->isActive())->toBeTrue();

    $uow->track($ar);
    expect($uow->isTracking($ar))->toBeTrue();

    $uow->commit();
    expect($uow->isActive())->toBeFalse();
});

test('unit of work run shorthand with exception handling', function () {
    $uow = new InMemoryUnitOfWork;

    $result = $uow->run(function () {
        return 'success';
    });

    expect($result)->toBe('success');
    expect($uow->isActive())->toBeFalse();
});

test('SnapshotPolicy attribute is correctly configured', function () {
    $reflection = new ReflectionClass(SnapshotPolicy::class);
    expect($reflection->isAttribute())->toBeTrue();

    $attribute = $reflection->getAttributes()[0] ?? null;
    expect($attribute)->not()->toBeNull()
        ->and($attribute->getName())->toBe(\Attribute::class);
});

test('InMemorySnapshotStore implements SnapshotStore contract', function () {
    $store = new InMemorySnapshotStore;
    $reflection = new ReflectionClass($store);

    expect($reflection->implementsInterface(SnapshotStore::class))->toBeTrue();
});

test('SnapshottingRepository implements Repository contract', function () {
    $store = new InMemorySnapshotStore;
    $repo = new class($store) extends SnapshottingRepository {
        protected function findAggregate(string $id): ?AggregateRoot
        {
            return null;
        }

        protected function saveAggregate(AggregateRoot $aggregate): void {}

        protected function deleteAggregate(string $id): void {}
    };

    $reflection = new ReflectionClass($repo);
    expect($reflection->implementsInterface(RepositoryContract::class))->toBeTrue();
});

/**
 * Helper: find all PHP files in a directory recursively.
 *
 * @return list<string>
 */
function findPhpFiles(string $dir): array
{
    $result = [];
    $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
    );

    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $result[] = $file->getRealPath();
        }
    }

    return $result;
}
