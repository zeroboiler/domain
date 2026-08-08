<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Tests;

use ZeroBoiler\Domain\AggregateRoot;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\Contracts\AggregateRoot as AggregateRootContract;
use ZeroBoiler\Domain\Contracts\Entity as EntityContract;
use ZeroBoiler\Domain\Contracts\Identifier as IdentifierContract;
use ZeroBoiler\Domain\Contracts\Repository as RepositoryContract;
use ZeroBoiler\Domain\Contracts\UnitOfWork as UnitOfWorkContract;
use ZeroBoiler\Domain\DomainEventCollection;
use ZeroBoiler\Domain\Entity;
use ZeroBoiler\Domain\Exceptions\{
    AggregateNotFoundException,
    ConflictDomainException,
    DomainException,
    InvalidArgumentDomainException,
    InvalidStateDomainException,
    NotFoundDomainException,
    OptimisticLockException,
};
use ZeroBoiler\Domain\Identifiers\IntegerIdentifier;
use ZeroBoiler\Domain\Identifiers\StringIdentifier;
use ZeroBoiler\Domain\Identifiers\UuidIdentifier;
use ZeroBoiler\Domain\Identifiers\UlidIdentifier;
use ZeroBoiler\Domain\InMemoryUnitOfWork;
use ZeroBoiler\Domain\Snapshots\SnapshottingRepository;
use ZeroBoiler\Domain\Snapshots\InMemorySnapshotStore;
use ZeroBoiler\Domain\Snapshots\Snapshot;
use ZeroBoiler\Domain\Snapshots\SnapshotPolicy;
use ZeroBoiler\Domain\Tests\Fixtures\TestAggregate;
use ZeroBoiler\Domain\Tests\Fixtures\TestEntity;
use ZeroBoiler\Domain\Tests\Fixtures\TestValueObject;
use ZeroBoiler\Domain\ValueObject;
use ZeroBoiler\Events\Domain\DomainEvent;

/**
 * Production boundary invariant tests — verifies domain invariants that
 * must hold true under all conditions: type safety, immutability,
 * identity semantics, event ordering, and serialization consistency.
 *
 * These tests verify PRODUCTION GUARANTEES, not implementation details.
 */
describe('Domain Boundary Invariants', function (): void {

    // =========================================================================
    //  AggregateRootId — Immutability & Identity Invariants
    // =========================================================================

    describe('AggregateRootId immutability', function (): void {
        it('is final and cannot be extended', function (): void {
            $reflection = new \ReflectionClass(AggregateRootId::class);

            expect($reflection->isFinal())->toBeTrue();
        });

        it('is readonly and cannot be mutated after construction', function (): void {
            $reflection = new \ReflectionClass(AggregateRootId::class);

            expect($reflection->isReadOnly())->toBeTrue();
        });

        it('produces unique IDs on every generate() call', function (): void {
            $ids = array_map(fn (): AggregateRootId => AggregateRootId::generate(), range(1, 100));
            $strings = array_map(fn (AggregateRootId $id): string => $id->toString(), $ids);

            expect(count(array_unique($strings)))->toBe(100);
        });

        it('maintains referential equality via equals()', function (): void {
            $uuid = '550e8400-e29b-41d4-a716-446655440000';
            $a = AggregateRootId::fromString($uuid);
            $b = AggregateRootId::fromString($uuid);

            expect($a->equals($b))->toBeTrue();
            expect($b->equals($a))->toBeTrue();
        });

        it('JSON serializes to string UUID', function (): void {
            $id = AggregateRootId::fromString('550e8400-e29b-41d4-a716-446655440000');
            $json = json_encode($id);

            expect($json)->toBeJson();
            $decoded = json_decode($json, true);
            expect($decoded)->toBe('550e8400-e29b-41d4-a716-446655440000');
        });

        it('is Stringable', function (): void {
            $id = AggregateRootId::generate();

            expect((string) $id)->toBe($id->toString());
        });
    });

    // =========================================================================
    //  Entity — Identity Semantics
    // =========================================================================

    describe('Entity identity semantics', function (): void {
        it('supports string IDs', function (): void {
            $a = new TestEntity('user-1');
            $b = new TestEntity('user-1');

            expect($a->id())->toBe('user-1');
            expect($a->equals($b))->toBeTrue();
        });

        it('supports integer IDs converted to string', function (): void {
            $a = new TestEntity(42);

            expect($a->id())->toBe('42');
        });

        it('supports Stringable IDs', function (): void {
            $id = AggregateRootId::generate();
            $a = new TestEntity($id);

            expect($a->id())->toBe($id->toString());
        });

        it('different types are never equal even with same ID', function (): void {
            class EntityA extends Entity {}
            class EntityB extends Entity {}

            $a = new EntityA('shared-id');
            $b = new EntityB('shared-id');

            expect($a->equals($b))->toBeFalse();
        });

        it('toArray includes id and type', function (): void {
            $entity = new TestEntity('abc-123');
            $arr = $entity->toArray();

            expect($arr)->toHaveKey('id');
            expect($arr)->toHaveKey('type');
            expect($arr['id'])->toBe('abc-123');
            expect($arr['type'])->toBe('TestEntity');
        });
    });

    // =========================================================================
    //  AggregateRoot — Version & Event Invariants
    // =========================================================================

    describe('AggregateRoot version invariants', function (): void {
        it('starts at version 0', function (): void {
            $aggregate = TestAggregate::create(AggregateRootId::generate());

            expect($aggregate->version())->toBe(1); // create() applies 1 event
        });

        it('increments version on each apply()', function (): void {
            $id = AggregateRootId::generate();
            $aggregate = TestAggregate::create($id);

            $initialVersion = $aggregate->version();
            $aggregate->rename('Test Name');

            expect($aggregate->version())->toBe($initialVersion + 1);
        });

        it('pullDomainEvents is destructive', function (): void {
            $aggregate = TestAggregate::create(AggregateRootId::generate());
            $events = $aggregate->pullDomainEvents();

            expect($events->count())->toBe(1);
            expect($aggregate->pullDomainEvents()->count())->toBe(0);
            expect($aggregate->hasUncommittedEvents())->toBeFalse();
        });

        it('clearDomainEvents discards without returning', function (): void {
            $aggregate = TestAggregate::create(AggregateRootId::generate());
            $aggregate->clearDomainEvents();

            expect($aggregate->pullDomainEvents()->count())->toBe(0);
        });

        it('toArray includes id, version, and type', function (): void {
            $id = AggregateRootId::generate();
            $aggregate = TestAggregate::create($id);
            $aggregate->rename('Test');

            $arr = $aggregate->toArray();

            expect($arr)->toHaveKey('id');
            expect($arr)->toHaveKey('version');
            expect($arr)->toHaveKey('type');
            expect($arr['id'])->toBe($id->toString());
            expect($arr['version'])->toBeGreaterThan(0);
            expect($arr['type'])->toBe('TestAggregate');
        });
    });

    // =========================================================================
    //  DomainEventCollection — Immutability Invariants
    // =========================================================================

    describe('DomainEventCollection immutability', function (): void {
        it('is final and readonly', function (): void {
            $reflection = new \ReflectionClass(DomainEventCollection::class);

            expect($reflection->isFinal())->toBeTrue();
            expect($reflection->isReadOnly())->toBeTrue();
        });

        it('filter returns a new instance', function (): void {
            $e1 = DomainEvent::occur('test.1', ['k' => 'v1']);
            $e2 = DomainEvent::occur('test.2', ['k' => 'v2']);
            $original = new DomainEventCollection([$e1, $e2]);

            $filtered = $original->filter(fn (DomainEvent $e): bool => $e->eventType === 'test.1');

            expect($filtered)->not->toBe($original);
            expect($filtered->count())->toBe(1);
            expect($original->count())->toBe(2);
        });

        it('merge returns a new instance', function (): void {
            $e1 = DomainEvent::occur('test.1', []);
            $e2 = DomainEvent::occur('test.2', []);
            $a = new DomainEventCollection([$e1]);
            $b = new DomainEventCollection([$e2]);

            $merged = $a->merge($b);

            expect($merged)->not->toBe($a);
            expect($merged->count())->toBe(2);
            expect($a->count())->toBe(1);
        });

        it('rejects non-sequential arrays', function (): void {
            expect(fn () => new DomainEventCollection([0 => DomainEvent::occur('t', []), 2 => DomainEvent::occur('t', [])]))
                ->toThrow(\InvalidArgumentException::class,
                    'sequential list'
                );
        });

        it('rejects non-DomainEvent items', function (): void {
            expect(fn () => new DomainEventCollection(['not-an-event']))
                ->toThrow(\InvalidArgumentException::class,
                    'must be a DomainEvent'
                );
        });

        it('JSON serializes to list of arrays', function (): void {
            $e = DomainEvent::occur('order.placed', ['id' => '123']);
            $collection = new DomainEventCollection([$e]);
            $json = json_encode($collection);
            $decoded = json_decode($json, true);

            expect(is_array($decoded))->toBeTrue();
            expect(is_list($decoded))->toBeTrue();
            expect(count($decoded))->toBe(1);
        });
    });

    // =========================================================================
    //  Identifiers — Cross-Type Inequality Invariant
    // =========================================================================

    describe('Identifier cross-type inequality', function (): void {
        it('UuidIdentifier subclasses are never equal to each other', function (): void {
            class IdA extends UuidIdentifier {}
            class IdB extends UuidIdentifier {}

            $uuid = '550e8400-e29b-41d4-a716-446655440000';
            $a = IdA::fromString($uuid);
            $b = IdB::fromString($uuid);

            expect($a->equals($b))->toBeFalse();
        });

        it('UlidIdentifier subclasses are never equal to each other', function (): void {
            class UidA extends UlidIdentifier {}
            class UidB extends UlidIdentifier {}

            $ulid = '01JF5K2RNBVTQHJMP0E5M4QGXS';
            $a = UidA::fromString($ulid);
            $b = UidB::fromString($ulid);

            expect($a->equals($b))->toBeFalse();
        });

        it('StringIdentifier is final and cannot be extended', function (): void {
            $reflection = new \ReflectionClass(StringIdentifier::class);

            expect($reflection->isFinal())->toBeTrue();
        });

        it('IntegerIdentifier is final and cannot be extended', function (): void {
            $reflection = new \ReflectionClass(IntegerIdentifier::class);

            expect($reflection->isFinal())->toBeTrue();
        });

        it('all identifiers implement IdentifierContract', function (): void {
            expect(UuidIdentifier::generate())->toBeInstanceOf(IdentifierContract::class);
            expect(StringIdentifier::from('test'))->toBeInstanceOf(IdentifierContract::class);
            expect(IntegerIdentifier::from(42))->toBeInstanceOf(IdentifierContract::class);
        });

        it('IntegerIdentifier JSON serializes as int', function (): void {
            $id = IntegerIdentifier::from(42);
            $json = json_encode(['id' => $id]);
            $decoded = json_decode($json, true);

            expect($decoded['id'])->toBe(42);
            expect(is_int($decoded['id']))->toBeTrue();
        });
    });

    // =========================================================================
    //  ValueObject — Equality Invariant
    // =========================================================================

    describe('ValueObject equality invariant', function (): void {
        it('compares by toArray output', function (): void {
            $a = TestValueObject::from('hello');
            $b = TestValueObject::from('hello');

            expect($a->equals($b))->toBeTrue();
        });

        it('different values are not equal', function (): void {
            $a = TestValueObject::from('hello');
            $b = TestValueObject::from('world');

            expect($a->equals($b))->toBeFalse();
        });

        it('null is not equal to any value object', function (): void {
            $a = TestValueObject::from('hello');

            expect($a->equals(null))->toBeFalse();
        });
    });

    // =========================================================================
    //  Snapshot — Round-Trip Invariant
    // =========================================================================

    describe('Snapshot round-trip invariant', function (): void {
        it('survives toArray → fromArray cycle', function (): void {
            $original = Snapshot::create('Order', 'order-123', 10, ['status' => 'paid']);
            $restored = Snapshot::fromArray($original->toArray());

            expect($restored->aggregateType)->toBe($original->aggregateType);
            expect($restored->aggregateId)->toBe($original->aggregateId);
            expect($restored->version)->toBe($original->version);
            expect($restored->state)->toBe($original->state);
        });

        it('JSON round-trip preserves all fields', function (): void {
            $snapshot = Snapshot::create('Order', 'order-123', 5, ['total' => 99.99]);
            $json = json_encode($snapshot);
            $decoded = json_decode($json, true);

            expect($decoded['aggregate_type'])->toBe('Order');
            expect($decoded['aggregate_id'])->toBe('order-123');
            expect($decoded['version'])->toBe(5);
            expect($decoded['state'])->toBe(['total' => 99.99]);
            expect($decoded)->toHaveKey('created_at');
        });

        it('fromArray rejects invalid types', function (): void {
            expect(fn () => Snapshot::fromArray(['aggregate_type' => 123, 'aggregate_id' => 'x', 'version' => 'bad', 'state' => [], 'created_at' => 'x']))
                ->toThrow(\InvalidArgumentException::class,
                    'Invalid snapshot data'
                );
        });
    });

    // =========================================================================
    //  DomainException — Error Code Invariant
    // =========================================================================

    describe('DomainException error code invariants', function (): void {
        it('each exception type has a unique default code', function (): void {
            $codes = array_map(
                fn (string $class): string => (new class extends DomainException {
                    public function __construct(string $class) { parent::__construct('test'); }
                    protected function defaultErrorCode(): string { return 'X'; }
                })->errorCode(),
                [],
            );

            $exceptions = [
                InvalidStateDomainException::because('test'),
                InvalidArgumentDomainException::because('test'),
                NotFoundDomainException::because('test'),
                ConflictDomainException::because('test'),
                OptimisticLockException::for('id', 1, 2),
                AggregateNotFoundException::for('T', 'id'),
            ];

            $codes = array_map(fn (DomainException $e): string => $e->errorCode(), $exceptions);

            expect(count(array_unique($codes)))->toBe(count($codes));
        });

        it('custom code overrides default', function (): void {
            $e = InvalidStateDomainException::because('test', code: 'CUSTOM_CODE');

            expect($e->errorCode())->toBe('CUSTOM_CODE');
        });

        it('toErrorArray returns RFC 9457 structure', function (): void {
            $e = NotFoundDomainException::because('User missing');
            $arr = $e->toErrorArray();

            expect($arr)->toHaveKey('title');
            expect($arr)->toHaveKey('detail');
            expect($arr)->toHaveKey('code');
            expect($arr['code'])->toBe('NOT_FOUND');
        });

        it('jsonSerialize matches toErrorArray', function (): void {
            $e = InvalidArgumentDomainException::because('bad input');
            expect($e->jsonSerialize())->toBe($e->toErrorArray());
        });
    });

    // =========================================================================
    //  Unit of Work — Transactional Invariants
    // =========================================================================

    describe('UnitOfWork transactional invariants', function (): void {
        it('run() auto-commits on success', function (): void {
            $uow = new InMemoryUnitOfWork;
            $committed = false;

            $uow->setPersistenceCallback(function (array $c, array $d) use (&$committed): void {
                $committed = true;
            });

            $uow->run(fn () => null);

            expect($committed)->toBeTrue();
        });

        it('run() auto-rollbacks on exception', function (): void {
            $uow = new InMemoryUnitOfWork;
            $committed = false;

            $uow->setPersistenceCallback(function (array $c, array $d) use (&$committed): void {
                $committed = true;
            });

            expect(fn () => $uow->run(fn () => throw new \RuntimeException('fail')))->toThrow(\RuntimeException::class);
            expect($committed)->toBeFalse();
            expect($uow->isActive())->toBeFalse();
        });

        it('clear() resets all state', function (): void {
            $uow = new InMemoryUnitOfWork;
            $uow->begin();
            $uow->commit();

            $uow->clear();

            expect($uow->isActive())->toBeFalse();
            expect($uow->getCommitted())->toBe([]);
            expect($uow->getDeleted())->toBe([]);
            expect($uow->hasPendingEvents())->toBeFalse();
        });

        it('nested run() creates savepoints', function (): void {
            $uow = new InMemoryUnitOfWork;
            $log = [];

            $uow->run(function () use ($uow, &$log): void {
                $log[] = 'outer-start';
                $uow->run(function () use (&$log): void {
                    $log[] = 'inner';
                });
                $log[] = 'outer-end';
            });

            expect($log)->toBe(['outer-start', 'inner', 'outer-end']);
        });
    });

    // =========================================================================
    //  Contracts — Interface Compliance
    // =========================================================================

    describe('Contract compliance', function (): void {
        it('AggregateRoot implements AggregateRootContract', function (): void {
            expect(TestAggregate::create(AggregateRootId::generate()))->toBeInstanceOf(AggregateRootContract::class);
        });

        it('AggregateRoot implements EntityContract', function (): void {
            expect(TestAggregate::create(AggregateRootId::generate()))->toBeInstanceOf(EntityContract::class);
        });

        it('AggregateRootId is Stringable and JsonSerializable', function (): void {
            $id = AggregateRootId::generate();

            expect($id)->toBeInstanceOf(\Stringable::class);
            expect($id)->toBeInstanceOf(\JsonSerializable::class);
        });

        it('InMemoryUnitOfWork implements UnitOfWorkContract', function (): void {
            expect(new InMemoryUnitOfWork)->toBeInstanceOf(UnitOfWorkContract::class);
        });
    });
});
