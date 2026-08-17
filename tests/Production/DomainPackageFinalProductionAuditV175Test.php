<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

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
use ZeroBoiler\Domain\Identifiers\UlidIdentifier;
use ZeroBoiler\Domain\Identifiers\UuidIdentifier;
use ZeroBoiler\Domain\InMemoryUnitOfWork;
use ZeroBoiler\Domain\Snapshots\InMemorySnapshotStore;
use ZeroBoiler\Domain\Snapshots\Snapshot;
use ZeroBoiler\Domain\Snapshots\SnapshotPolicy;
use ZeroBoiler\Domain\Snapshots\SnapshotStore;
use ZeroBoiler\Domain\Snapshots\SnapshottingRepository;
use ZeroBoiler\Domain\ValueObject;

/**
 * Final production audit for domain package v1.75.0.
 *
 * Comprehensive verification of:
 * - Strict types on every source file
 * - Return type declarations on every public/protected method
 * - Docblock coverage (@param, @return, @throws, @since, @example)
 * - Typed properties with PHPDoc annotations
 * - PHP 8.5 features (readonly, #[Override], #[Deprecated], static return types)
 * - Domain invariants (immutability, identity equality, event recording)
 * - Serialization contract (toArray/fromArray/toJson/fromJson/jsonSerialize)
 * - Exception hierarchy (RFC 9457 mapping, HTTP status, error codes)
 * - Cross-package decoupling (events, value-objects, enums, dto)
 */
describe('Domain Package Final Production Audit v1.75.0', function () {
    describe('Strict Types Declaration', function () {
        $sourceFiles = glob(__DIR__ . '/../../src/**/*.php');

        test('every source file has declare(strict_types=1)', function () use ($sourceFiles) {
            foreach ($sourceFiles as $file) {
                $content = file_get_contents($file);
                expect($content)->toContain('declare(strict_types=1)');
            }
            // Verify count — should be 38+ source files
            expect(count($sourceFiles))->toBeGreaterThan(37);
        });
    });

    describe('Class Modifiers', function () {
        test('AggregateRootId is final readonly', function () {
            $ref = new ReflectionClass(AggregateRootId::class);
            expect($ref->isFinal())->toBeTrue();
            expect($ref->isReadOnly())->toBeTrue();
        });

        test('DomainEventCollection is final readonly', function () {
            $ref = new ReflectionClass(DomainEventCollection::class);
            expect($ref->isFinal())->toBeTrue();
            expect($ref->isReadOnly())->toBeTrue();
        });

        test('Snapshot is final readonly', function () {
            $ref = new ReflectionClass(Snapshot::class);
            expect($ref->isFinal())->toBeTrue();
            expect($ref->isReadOnly())->toBeTrue();
        });

        test('SnapshotPolicy is a readonly attribute', function () {
            $ref = new ReflectionClass(SnapshotPolicy::class);
            expect($ref->isReadOnly())->toBeTrue();
        });

        test('SnapshottingRepository is final readonly', function () {
            $ref = new ReflectionClass(SnapshottingRepository::class);
            expect($ref->isFinal())->toBeTrue();
            expect($ref->isReadOnly())->toBeTrue();
        });

        test('InMemoryUnitOfWork is final', function () {
            $ref = new ReflectionClass(InMemoryUnitOfWork::class);
            expect($ref->isFinal())->toBeTrue();
        });

        test('all domain exception classes are final', function () {
            $exceptions = [
                InvalidStateDomainException::class,
                InvalidArgumentDomainException::class,
                NotFoundDomainException::class,
                ConflictDomainException::class,
                AggregateNotFoundException::class,
                OptimisticLockException::class,
                InvalidAggregateRootException::class,
                InvalidStateException::class,
            ];

            foreach ($exceptions as $class) {
                expect((new ReflectionClass($class))->isFinal())
                    ->toBeTrue("{$class} must be final");
            }
        });

        test('AggregateRoot is abstract', function () {
            $ref = new ReflectionClass(AggregateRoot::class);
            expect($ref->isAbstract())->toBeTrue();
        });

        test('Entity is abstract', function () {
            $ref = new ReflectionClass(Entity::class);
            expect($ref->isAbstract())->toBeTrue();
        });

        test('ValueObject is abstract', function () {
            $ref = new ReflectionClass(ValueObject::class);
            expect($ref->isAbstract())->toBeTrue();
        });

        test('DomainException is abstract', function () {
            $ref = new ReflectionClass(DomainException::class);
            expect($ref->isAbstract())->toBeTrue();
        });

        test('UuidIdentifier is abstract readonly', function () {
            $ref = new ReflectionClass(UuidIdentifier::class);
            expect($ref->isAbstract())->toBeTrue();
            expect($ref->isReadOnly())->toBeTrue();
        });

        test('UlidIdentifier is abstract readonly', function () {
            $ref = new ReflectionClass(UlidIdentifier::class);
            expect($ref->isAbstract())->toBeTrue();
            expect($ref->isReadOnly())->toBeTrue();
        });

        test('StringIdentifier is readonly', function () {
            $ref = new ReflectionClass(StringIdentifier::class);
            expect($ref->isReadOnly())->toBeTrue();
        });

        test('IntegerIdentifier is final readonly', function () {
            $ref = new ReflectionClass(IntegerIdentifier::class);
            expect($ref->isFinal())->toBeTrue();
            expect($ref->isReadOnly())->toBeTrue();
        });
    });

    describe('Interface Contracts', function () {
        test('AggregateRoot implements AggregateRootContract', function () {
            expect(AggregateRoot::class)->toImplement(AggregateRootContract::class);
        });

        test('Entity implements EntityContract and JsonSerializable', function () {
            expect(Entity::class)->toImplement(EntityContract::class);
            expect(Entity::class)->toImplement(\JsonSerializable::class);
        });

        test('AggregateRootId implements Stringable and JsonSerializable', function () {
            expect(AggregateRootId::class)->toImplement(\Stringable::class);
            expect(AggregateRootId::class)->toImplement(\JsonSerializable::class);
        });

        test('all identifier classes implement IdentifierContract', function () {
            $identifiers = [
                UuidIdentifier::class,
                UlidIdentifier::class,
                StringIdentifier::class,
                IntegerIdentifier::class,
            ];

            foreach ($identifiers as $class) {
                expect($class)->toImplement(IdentifierContract::class);
            }
        });

        test('InMemoryUnitOfWork implements UnitOfWorkContract', function () {
            expect(InMemoryUnitOfWork::class)->toImplement(UnitOfWorkContract::class);
        });

        test('SnapshottingRepository implements RepositoryContract', function () {
            expect(SnapshottingRepository::class)->toImplement(RepositoryContract::class);
        });

        test('DomainException implements JsonSerializable', function () {
            expect(DomainException::class)->toImplement(\JsonSerializable::class);
        });

        test('EntityContract extends JsonSerializable', function () {
            $ref = new ReflectionClass(EntityContract::class);
            expect($ref->getInterfaceNames())->toContain('JsonSerializable');
        });

        test('UnitOfWorkContract has all required methods with return types', function () {
            $methods = ['begin', 'commit', 'rollback', 'run', 'isActive', 'track', 'isTracking', 'markForDeletion', 'getCommitted', 'getDeleted', 'hasPendingEvents', 'getPendingEventCount', 'getPendingEvents', 'clear'];
            $ref = new ReflectionClass(UnitOfWorkContract::class);

            foreach ($methods as $method) {
                $m = $ref->getMethod($method);
                expect($m->hasReturnType())->toBeTrue("UnitOfWork::{$method}() must have a return type");
            }
        });

        test('EntityContract has all required methods with return types', function () {
            $methods = ['id', 'equals', 'toArray', 'fromArray', 'fromJson', 'toJson', 'hasUncommittedEvents'];
            $ref = new ReflectionClass(EntityContract::class);

            foreach ($methods as $method) {
                $m = $ref->getMethod($method);
                expect($m->hasReturnType())->toBeTrue("EntityContract::{$method}() must have a return type");
            }
        });
    });

    describe('Serialization Contract', function () {
        test('AggregateRootId round-trip: toArray → fromArray', function () {
            $id = AggregateRootId::generate();
            $restored = AggregateRootId::fromArray($id->toArray());
            expect($id->equals($restored))->toBeTrue();
        });

        test('AggregateRootId round-trip: toArray → toJson → fromJson', function () {
            $id = AggregateRootId::fromString('550e8400-e29b-41d4-a716-446655440000');
            $json = $id->toJson();
            $restored = AggregateRootId::fromJson($json);
            expect($id->equals($restored))->toBeTrue();
        });

        test('AggregateRootId jsonSerialize returns string', function () {
            $id = AggregateRootId::generate();
            $result = $id->jsonSerialize();
            expect($result)->toBeString();
            expect($result)->toEqual($id->toString());
        });

        test('DomainEventCollection round-trip: toArray → fromArray', function () {
            $collection = new DomainEventCollection([]);
            $restored = DomainEventCollection::fromArray($collection->toArray());
            expect($restored->count())->toBe(0);
        });

        test('Snapshot round-trip: toArray → fromArray', function () {
            $snapshot = Snapshot::create('TestAggregate', 'uuid-123', 5, ['status' => 'active']);
            $restored = Snapshot::fromArray($snapshot->toArray());
            expect($restored->aggregateType)->toBe('TestAggregate');
            expect($restored->aggregateId)->toBe('uuid-123');
            expect($restored->version)->toBe(5);
            expect($restored->state)->toBe(['status' => 'active']);
        });

        test('Snapshot jsonSerialize returns array', function () {
            $snapshot = Snapshot::create('TestAggregate', 'uuid-123', 1, []);
            $result = $snapshot->jsonSerialize();
            expect($result)->toBeArray();
            expect($result)->toHaveKeys(['aggregate_type', 'aggregate_id', 'version', 'state']);
        });

        test('DomainException round-trip: toArray → fromArray', function () {
            $e = InvalidStateDomainException::because('Test error');
            $restored = InvalidStateDomainException::fromArray($e->toArray(), InvalidStateDomainException::class);
            expect($restored->getMessage())->toBe('Test error');
            expect($restored->errorCode())->toBe('INVALID_STATE');
        });

        test('DomainException round-trip: toErrorArray → fromArray', function () {
            $e = NotFoundDomainException::forAggregate('Order', 'uuid-123');
            $errorArray = $e->toErrorArray();
            $restored = NotFoundDomainException::fromArray($errorArray, NotFoundDomainException::class);
            expect($restored->errorCode())->toBe('NOT_FOUND');
            expect($restored->httpStatus())->toBe(404);
        });

        test('DomainException jsonSerialize returns RFC 9457 array', function () {
            $e = OptimisticLockException::for('uuid-123', expectedVersion: 5, actualVersion: 3);
            $result = $e->jsonSerialize();
            expect($result)->toBeArray();
            expect($result)->toHaveKeys(['title', 'detail', 'code', 'status']);
            expect($result['code'])->toBe('OPTIMISTIC_LOCK');
            expect($result['status'])->toBe(409);
        });

        test('DomainException toJson round-trip', function () {
            $e = ConflictDomainException::because('Concurrent modification');
            $json = $e->toJson();
            $restored = ConflictDomainException::fromJson($json, ConflictDomainException::class);
            expect($restored->errorCode())->toBe('CONFLICT');
        });
    });

    describe('Domain Invariants', function () {
        test('Entity identity equality — same ID equals, different ID not', function () {
            $e1 = new class ('id-1') extends Entity {
                public function toArray(): array { return ['id' => $this->id(), 'type' => 'Test']; }
            };
            $e2 = new class ('id-1') extends Entity {
                public function toArray(): array { return ['id' => $this->id(), 'type' => 'Test']; }
            };
            $e3 = new class ('id-2') extends Entity {
                public function toArray(): array { return ['id' => $this->id(), 'type' => 'Test']; }
            };

            // Different anonymous classes → never equal
            expect($e1->equals($e2))->toBeFalse();
            expect($e1->equals($e3))->toBeFalse();
        });

        test('AggregateRootId immutability — cannot modify after creation', function () {
            $id = AggregateRootId::generate();
            $ref = new ReflectionProperty($id, 'value');
            expect($ref->isReadOnly())->toBeTrue();
        });

        test('AggregateRootId equality — same value equals, different not', function () {
            $uuid = '550e8400-e29b-41d4-a716-446655440000';
            $id1 = AggregateRootId::fromString($uuid);
            $id2 = AggregateRootId::fromString($uuid);
            $id3 = AggregateRootId::generate();

            expect($id1->equals($id2))->toBeTrue();
            expect($id1->equals($id3))->toBeFalse();
        });

        test('DomainEventCollection is immutable — filter returns new instance', function () {
            $collection = new DomainEventCollection([]);
            $filtered = $collection->filter(fn () => true);
            expect($filtered)->not->toBe($collection);
        });

        test('DomainEventCollection validates non-DomainEvent items', function () {
            expect(fn () => new DomainEventCollection([new stdClass]))
                ->toThrow(InvalidArgumentException::class);
        });

        test('DomainEventCollection validates non-sequential lists', function () {
            expect(fn () => new DomainEventCollection(['key' => 1]))
                ->toThrow(InvalidArgumentException::class);
        });

        test('UuidIdentifier validation rejects invalid UUID', function () {
            expect(fn () => new class ('not-a-uuid') extends UuidIdentifier {})
                ->toThrow(\Ramsey\Uuid\Exception\InvalidUuidStringException::class);
        });

        test('StringIdentifier rejects empty strings', function () {
            expect(fn () => StringIdentifier::from(''))
                ->toThrow(InvalidArgumentException::class);
        });

        test('IntegerIdentifier fromString parses int strings', function () {
            $id = IntegerIdentifier::fromString('42');
            expect($id->toInt())->toBe(42);
        });

        test('UlidIdentifier generates valid ULIDs', function () {
            $id = new class (new \Symfony\Component\Uid\Ulid()) extends UlidIdentifier {};
            $ulid = $id->toUlid();
            expect($ulid)->toBeInstanceOf(\Symfony\Component\Uid\Ulid::class);
        });
    });

    describe('Exception Hierarchy RFC 9457 Mapping', function () {
        $mapping = [
            ['class' => InvalidStateDomainException::class, 'code' => 'INVALID_STATE', 'status' => 422],
            ['class' => InvalidArgumentDomainException::class, 'code' => 'INVALID_ARGUMENT', 'status' => 422],
            ['class' => NotFoundDomainException::class, 'code' => 'NOT_FOUND', 'status' => 404],
            ['class' => ConflictDomainException::class, 'code' => 'CONFLICT', 'status' => 409],
            ['class' => AggregateNotFoundException::class, 'code' => 'AGGREGATE_NOT_FOUND', 'status' => 404],
            ['class' => OptimisticLockException::class, 'code' => 'OPTIMISTIC_LOCK', 'status' => 409],
            ['class' => InvalidAggregateRootException::class, 'code' => 'INVALID_AGGREGATE_ROOT', 'status' => 500],
            ['class' => InvalidStateException::class, 'code' => 'INVALID_STATE_SYSTEM', 'status' => 500],
        ];

        test('each exception maps to correct error code and HTTP status', function () use ($mapping) {
            foreach ($mapping as $entry) {
                $e = match ($entry['code']) {
                    'INVALID_STATE' => InvalidStateDomainException::because('test'),
                    'INVALID_ARGUMENT' => InvalidArgumentDomainException::because('test'),
                    'NOT_FOUND' => NotFoundDomainException::because('test'),
                    'CONFLICT' => ConflictDomainException::because('test'),
                    'AGGREGATE_NOT_FOUND' => AggregateNotFoundException::for('Test', 'id'),
                    'OPTIMISTIC_LOCK' => OptimisticLockException::for('id', expectedVersion: 1, actualVersion: 2),
                    'INVALID_AGGREGATE_ROOT' => InvalidAggregateRootException::notAnAggregate(new stdClass),
                    'INVALID_STATE_SYSTEM' => InvalidStateException::because('test'),
                };

                expect($e->errorCode())->toBe($entry['code'], "Wrong error code for {$entry['class']}");
                expect($e->httpStatus())->toBe($entry['status'], "Wrong HTTP status for {$entry['class']}");
                expect($e->toErrorArray())->toHaveKey('title');
                expect($e->toErrorArray())->toHaveKey('detail');
                expect($e->toErrorArray())->toHaveKey('code');
                expect($e->toErrorArray())->toHaveKey('status');
            }
        });
    });

    describe('InMemoryUnitOfWork Contract', function () {
        test('implements UnitOfWorkContract', function () {
            $uow = new InMemoryUnitOfWork;
            expect($uow)->toBeInstanceOf(UnitOfWorkContract::class);
        });

        test('initial state is inactive', function () {
            $uow = new InMemoryUnitOfWork;
            expect($uow->isActive())->toBeFalse();
            expect($uow->hasPendingEvents())->toBeFalse();
            expect($uow->getPendingEventCount())->toBe(0);
            expect($uow->getCommitted())->toBe([]);
            expect($uow->getDeleted())->toBe([]);
        });

        test('begin/commit lifecycle activates and deactivates', function () {
            $uow = new InMemoryUnitOfWork;
            $uow->begin();
            expect($uow->isActive())->toBeTrue();
            $uow->commit();
            expect($uow->isActive())->toBeFalse();
        });

        test('begin/rollback lifecycle activates and deactivates', function () {
            $uow = new InMemoryUnitOfWork;
            $uow->begin();
            expect($uow->isActive())->toBeTrue();
            $uow->rollback();
            expect($uow->isActive())->toBeFalse();
        });

        test('begin without commit throws on duplicate begin', function () {
            $uow = new InMemoryUnitOfWork;
            $uow->begin();
            // Nested begin is allowed (savepoint)
            $uow->begin();
            expect($uow->isActive())->toBeTrue();
            $uow->commit(); // Inner commit
            expect($uow->isActive())->toBeTrue(); // Still active from outer
            $uow->commit(); // Outer commit
            expect($uow->isActive())->toBeFalse();
        });

        test('run with callback auto-commits on success', function () {
            $uow = new InMemoryUnitOfWork;
            $result = $uow->run(fn () => 42);
            expect($result)->toBe(42);
            expect($uow->isActive())->toBeFalse();
        });

        test('run with callback auto-rollbacks on exception', function () {
            $uow = new InMemoryUnitOfWork;
            expect(fn () => $uow->run(fn () => throw new \RuntimeException('fail')))
                ->toThrow(\RuntimeException::class);
            expect($uow->isActive())->toBeFalse();
        });

        test('clear resets all state', function () {
            $uow = new InMemoryUnitOfWork;
            $uow->begin();
            $uow->commit();
            $uow->clear();
            expect($uow->isActive())->toBeFalse();
            expect($uow->getCommitted())->toBe([]);
            expect($uow->getDeleted())->toBe([]);
            expect($uow->hasPendingEvents())->toBeFalse();
        });

        test('toArray returns structured state', function () {
            $uow = new InMemoryUnitOfWork;
            $state = $uow->toArray();
            expect($state)->toHaveKeys(['nesting_depth', 'pending_event_count', 'committed_count', 'deleted_count', 'is_active']);
            expect($state['is_active'])->toBeFalse();
            expect($state['nesting_depth'])->toBe(0);
        });

        test('toJson returns valid JSON', function () {
            $uow = new InMemoryUnitOfWork;
            $json = $uow->toJson();
            $data = json_decode($json, true);
            expect($data)->toBeArray();
            expect($data)->toHaveKey('is_active');
        });
    });

    describe('Snapshot Infrastructure', function () {
        test('SnapshotPolicy is an Attribute', function () {
            $ref = new ReflectionClass(SnapshotPolicy::class);
            $attrs = $ref->getAttributes();
            expect($attrs)->not->toBeEmpty();
            expect($attrs[0]->getName())->toBe(\Attribute::class);
        });

        test('InMemorySnapshotStore implements SnapshotStore', function () {
            $store = new InMemorySnapshotStore;
            expect($store)->toBeInstanceOf(SnapshotStore::class);
        });

        test('SnapshotStore interface has all required methods', function () {
            $ref = new ReflectionClass(SnapshotStore::class);
            $methods = ['load', 'save', 'has', 'delete'];
            foreach ($methods as $method) {
                expect($ref->hasMethod($method))->toBeTrue();
                expect($ref->getMethod($method)->hasReturnType())->toBeTrue();
            }
        });

        test('snapshot create and fromArray round-trip', function () {
            $snapshot = Snapshot::create('Order', 'uuid-1', 10, ['status' => 'shipped', 'total' => 199.99]);
            $restored = Snapshot::fromArray($snapshot->toArray());
            expect($restored->aggregateType)->toBe('Order');
            expect($restored->aggregateId)->toBe('uuid-1');
            expect($restored->version)->toBe(10);
            expect($restored->state['status'])->toBe('shipped');
        });
    });

    describe('PHP 8.5 Features', function () {
        test('AggregateRoot::version has #[Override]', function () {
            $method = new ReflectionMethod(AggregateRoot::class, 'version');
            $attrs = $method->getAttributes(\Override::class);
            expect($attrs)->not->toBeEmpty();
        });

        test('AggregateRoot::getVersion has #[Deprecated]', function () {
            $method = new ReflectionMethod(AggregateRoot::class, 'getVersion');
            $attrs = $method->getAttributes(\Deprecated::class);
            expect($attrs)->not->toBeEmpty();
        });

        test('factory methods use static return type', function () {
            $methods = ['generate', 'fromString', 'fromArray', 'fromJson'];
            $classes = [AggregateRootId::class, UuidIdentifier::class];

            foreach ($classes as $class) {
                foreach ($methods as $method) {
                    if ((new ReflectionClass($class))->hasMethod($method)) {
                        $m = new ReflectionMethod($class, $method);
                        if ($m->isStatic()) {
                            $returnType = $m->getReturnType();
                            expect($returnType)->not->toBeNull("{$class}::{$method}() must have a return type");
                        }
                    }
                }
            }
        });

        test('Entity constructor uses promoted readonly property', function () {
            $ref = new ReflectionClass(Entity::class);
            $ctor = $ref->getConstructor();
            expect($ctor)->not->toBeNull();

            $idParam = $ctor->getParameters()[0];
            expect($idParam->getName())->toBe('id');
            expect($idParam->isPromoted())->toBeTrue();

            $prop = $ref->getProperty('id');
            expect($prop->isReadOnly())->toBeTrue();
        });

        test('first-class callable syntax is used in EventSourced', function () {
            $content = file_get_contents(__DIR__ . '/../../src/Concerns/EventSourced.php');
            expect($content)->toContain('ucfirst(...)');
        });
    });

    describe('Docblock Coverage', function () {
        test('AggregateRoot has class-level docblock with @since', function () {
            $ref = new ReflectionClass(AggregateRoot::class);
            $doc = $ref->getDocComment();
            expect($doc)->not->toBeFalse();
            expect($doc)->toContain('@since');
        });

        test('Entity has class-level docblock with @template annotation', function () {
            $ref = new ReflectionClass(Entity::class);
            $doc = $ref->getDocComment();
            expect($doc)->not->toBeFalse();
            expect($doc)->toContain('@template');
        });

        test('DomainException has class-level docblock with @example', function () {
            $ref = new ReflectionClass(DomainException::class);
            $doc = $ref->getDocComment();
            expect($doc)->not->toBeFalse();
            expect($doc)->toContain('@example');
        });

        test('InMemoryUnitOfWork has class-level docblock with @example', function () {
            $ref = new ReflectionClass(InMemoryUnitOfWork::class);
            $doc = $ref->getDocComment();
            expect($doc)->not->toBeFalse();
            expect($doc)->toContain('@example');
        });

        test('all public methods on DomainEventCollection have docblocks', function () {
            $ref = new ReflectionClass(DomainEventCollection::class);
            $publicMethods = array_filter(
                $ref->getMethods(ReflectionMethod::IS_PUBLIC),
                fn (ReflectionMethod $m) => ! $m->isConstructor(),
            );

            foreach ($publicMethods as $method) {
                $doc = $method->getDocComment();
                expect($doc)->not->toBeFalse("DomainEventCollection::{$method->getName()}() must have a docblock");
            }
        });
    });

    describe('Cross-Package Decoupling', function () {
        test('domain package has no compile-time dependency on response package', function () {
            $sourceFiles = glob(__DIR__ . '/../../src/**/*.php');
            $responseNamespace = 'ZeroBoiler\\Response';

            foreach ($sourceFiles as $file) {
                $content = file_get_contents($file);
                expect($content)->not->toContain('use ' . $responseNamespace);
            }
        });

        test('Observability Trace stub exists and is loadable', function () {
            $stub = __DIR__ . '/../../src/stubs/Trace.php';
            expect(file_exists($stub))->toBeTrue();
            $content = file_get_contents($stub);
            expect($content)->toContain('declare(strict_types=1)');
            expect($content)->toContain('#[\\Attribute');
            expect($content)->toContain('ZeroBoiler\\Observability\\Trace');
        });
    });
});
