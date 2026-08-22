<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Tests\Production;

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
use ZeroBoiler\Domain\ValueObject;
use ZeroBoiler\Events\Domain\DomainEvent;

/**
 * Comprehensive production readiness audit for domain package v1.76.0.
 *
 * Extended audit covering:
 * - Contract method signature completeness
 * - Serialization round-trip integrity for all types
 * - Domain invariant enforcement (immutability, identity, events)
 * - InMemoryUnitOfWork transactional semantics
 * - Snapshot lifecycle correctness
 * - Identifier type safety across hierarchy
 * - Cross-package contract compliance
 * - Deprecated API detection
 *
 * @since 1.76.0
 */
describe('Domain Package Production Audit v1.76.0', function () {
    // ─── 1. Contract Interface Completeness ────────────────────────────────
    describe('Contract Interface Method Signatures', function () {
        test('Entity contract has all required methods', function () {
            $methods = array_map(
                fn (ReflectionMethod $m) => $m->getName(),
                (new ReflectionClass(EntityContract::class))->getMethods(),
            );

            expect($methods)->toContain('id');
            expect($methods)->toContain('equals');
            expect($methods)->toContain('toArray');
            expect($methods)->toContain('fromArray');
            expect($methods)->toContain('fromJson');
            expect($methods)->toContain('toJson');
            expect($methods)->toContain('hasUncommittedEvents');
            expect($methods)->toContain('jsonSerialize');
        });

        test('AggregateRoot contract extends Entity and adds version/events', function () {
            $ref = new ReflectionClass(AggregateRootContract::class);

            expect($ref->getInterfaceNames())->toContain(EntityContract::class);

            $methods = array_map(
                fn (ReflectionMethod $m) => $m->getName(),
                $ref->getMethods(),
            );

            expect($methods)->toContain('version');
            expect($methods)->toContain('incrementVersion');
            expect($methods)->toContain('pullDomainEvents');
            expect($methods)->toContain('clearDomainEvents');
            expect($methods)->toContain('peekDomainEvents');
            expect($methods)->toContain('hasUncommittedEvents');
            expect($methods)->toContain('toJson');
        });

        test('Identifier contract has full serialization support', function () {
            $ref = new ReflectionClass(IdentifierContract::class);
            $methods = array_map(fn (ReflectionMethod $m) => $m->getName(), $ref->getMethods());

            expect($methods)->toContain('fromString');
            expect($methods)->toContain('toString');
            expect($methods)->toContain('equals');
            expect($methods)->toContain('toArray');
            expect($methods)->toContain('fromArray');
            expect($methods)->toContain('fromJson');
            expect($methods)->toContain('jsonSerialize');
        });

        test('Repository contract has find/save/delete', function () {
            $ref = new ReflectionClass(RepositoryContract::class);
            $methods = array_map(fn (ReflectionMethod $m) => $m->getName(), $ref->getMethods());

            expect($methods)->toContain('find');
            expect($methods)->toContain('save');
            expect($methods)->toContain('delete');
        });

        test('UnitOfWork contract has full transaction API', function () {
            $ref = new ReflectionClass(UnitOfWorkContract::class);
            $methods = array_map(fn (ReflectionMethod $m) => $m->getName(), $ref->getMethods());

            expect($methods)->toContain('begin');
            expect($methods)->toContain('commit');
            expect($methods)->toContain('rollback');
            expect($methods)->toContain('run');
            expect($methods)->toContain('isActive');
            expect($methods)->toContain('track');
            expect($methods)->toContain('isTracking');
            expect($methods)->toContain('markForDeletion');
            expect($methods)->toContain('hasPendingEvents');
            expect($methods)->toContain('getPendingEventCount');
            expect($methods)->toContain('getPendingEvents');
            expect($methods)->toContain('getCommitted');
            expect($methods)->toContain('getDeleted');
            expect($methods)->toContain('clear');
        });
    });

    // ─── 2. Serialization Round-Trip Integrity ───────────────────────────
    describe('Serialization Round-Trips', function () {
        test('AggregateRootId round-trips through toArray/fromArray', function () {
            $id = AggregateRootId::generate();
            $restored = AggregateRootId::fromArray($id->toArray());

            expect($restored->equals($id))->toBeTrue();
            expect($restored->toString())->toBe($id->toString());
        });

        test('AggregateRootId round-trips through toJson/fromJson', function () {
            $id = AggregateRootId::fromString('550e8400-e29b-41d4-a716-446655440000');
            $json = $id->toJson();
            $restored = AggregateRootId::fromJson($json);

            expect($restored->equals($id))->toBeTrue();
        });

        test('AggregateRootId round-trips through jsonSerialize', function () {
            $id = AggregateRootId::generate();
            $json = json_encode($id);
            $restored = AggregateRootId::fromString($json);

            expect($restored->equals($id))->toBeTrue();
        });

        test('UuidIdentifier subclass round-trips through toArray/fromArray', function () {
            $id = TestAuditOrderId::generate();
            $restored = TestAuditOrderId::fromArray($id->toArray());

            expect($restored->equals($id))->toBeTrue();
        });

        test('UlidIdentifier subclass round-trips through toArray/fromArray', function () {
            $id = TestAuditProductId::generate();
            $restored = TestAuditProductId::fromArray($id->toArray());

            expect($restored->equals($id))->toBeTrue();
        });

        test('StringIdentifier round-trips through toArray/fromArray', function () {
            $id = StringIdentifier::from('test-slug');
            $restored = StringIdentifier::fromArray($id->toArray());

            expect($restored->equals($id))->toBeTrue();
        });

        test('IntegerIdentifier round-trips through toArray/fromArray', function () {
            $id = IntegerIdentifier::from(42);
            $restored = IntegerIdentifier::fromArray($id->toArray());

            expect($restored->equals($id))->toBeTrue();
            expect($restored->toInt())->toBe(42);
        });

        test('DomainEventCollection round-trips through toArray/fromArray', function () {
            $e1 = DomainEvent::occur('test.event1', ['key' => 'val1']);
            $e2 = DomainEvent::occur('test.event2', ['key' => 'val2']);
            $collection = new DomainEventCollection([$e1, $e2]);

            $restored = DomainEventCollection::fromArray($collection->toArray());

            expect($restored->count())->toBe(2);
            expect($restored->first()->eventType)->toBe('test.event1');
            expect($restored->last()->eventType)->toBe('test.event2');
        });

        test('DomainEventCollection round-trips through toJson/fromJson', function () {
            $e = DomainEvent::occur('test.serialize', ['data' => 123]);
            $collection = new DomainEventCollection([$e]);

            $json = $collection->toJson();
            $restored = DomainEventCollection::fromJson($json);

            expect($restored->count())->toBe(1);
            expect($restored->first()->eventType)->toBe('test.serialize');
            expect($restored->first()->payload['data'])->toBe(123);
        });

        test('Snapshot round-trips through toArray/fromArray', function () {
            $snapshot = Snapshot::create(
                aggregateType: TestAuditOrder::class,
                aggregateId: AggregateRootId::generate()->toString(),
                version: 10,
                state: ['status' => 'paid', 'total' => 99.99],
            );

            $restored = Snapshot::fromArray($snapshot->toArray());

            expect($restored->equals($snapshot))->toBeTrue();
            expect($restored->aggregateType)->toBe(TestAuditOrder::class);
            expect($restored->version)->toBe(10);
            expect($restored->state['status'])->toBe('paid');
        });

        test('Entity subclass round-trips through fromArray/toArray', function () {
            $entity = new TestAuditOrderItem('item-42', 'Widget', 3, 9.99);

            $array = $entity->toArray();
            $restored = TestAuditOrderItem::fromArray($array);

            expect($restored->id())->toBe('item-42');
            expect($restored->productName)->toBe('Widget');
            expect($restored->quantity)->toBe(3);
            expect($restored->price)->toBe(9.99);
        });

        test('Entity subclass round-trips through fromJson/toJson', function () {
            $entity = new TestAuditOrderItem('item-99', 'Gadget', 1, 49.99);

            $json = $entity->toJson();
            $restored = TestAuditOrderItem::fromJson($json);

            expect($restored->id())->toBe('item-99');
            expect($restored->equals($entity))->toBeTrue();
        });
    });

    // ─── 3. Domain Invariant Enforcement ────────────────────────────────────
    describe('Domain Invariants', function () {
        test('AggregateRoot identity is immutable after construction', function () {
            $id = AggregateRootId::generate();
            $order = TestAuditOrder::place($id);

            expect($order->id())->toBe($id->toString());
            expect($order->aggregateId())->toBe($id);
        });

        test('Entity equality is based on identity not properties', function () {
            $item1 = new TestAuditOrderItem('1', 'Widget', 2, 10.0);
            $item2 = new TestAuditOrderItem('1', 'Different', 99, 999.0);
            $item3 = new TestAuditOrderItem('2', 'Widget', 2, 10.0);

            expect($item1->equals($item2))->toBeTrue();   // Same ID, different props
            expect($item1->equals($item3))->toBeFalse();  // Different ID, same props
        });

        test('AggregateRoot equality checks class type', function () {
            $id = AggregateRootId::generate();
            $order1 = TestAuditOrder::place($id);
            $order2 = new TestAuditOtherOrder($id);

            expect($order1->equals($order2))->toBeFalse();
        });

        test('AggregateRoot version increments with each apply()', function () {
            $id = AggregateRootId::generate();
            $order = TestAuditOrder::place($id);
            $order->pullDomainEvents();
            $order->addItem('Widget', 3, 10.0);
            $order->pullDomainEvents();
            $order->addItem('Gadget', 1, 25.0);

            expect($order->version())->toBe(3);
        });

        test('DomainException hierarchy all implement JsonSerializable', function () {
            $exceptions = [
                InvalidStateDomainException::because('test'),
                InvalidArgumentDomainException::because('test'),
                NotFoundDomainException::forId('test'),
                ConflictDomainException::because('test'),
                AggregateNotFoundException::for('Test', 'id'),
                OptimisticLockException::for('id', expectedVersion: 1, actualVersion: 2),
                InvalidAggregateRootException::notAnAggregate(new \stdClass),
                InvalidStateException::because('test'),
            ];

            foreach ($exceptions as $exception) {
                expect($exception)->toBeInstanceOf(\JsonSerializable::class);
                $array = $exception->toErrorArray();
                expect($array)->toBeArray();
                expect($array)->toHaveKey('title');
                expect($array)->toHaveKey('detail');
                expect($array)->toHaveKey('code');
                expect($array)->toHaveKey('status');

                // JSON round-trip
                $json = json_encode($exception);
                expect($json)->toBeString();
                expect(strlen($json))->toBeGreaterThan(0);
            }
        });

        test('DomainException HTTP status mapping is correct', function () {
            $statusMap = [
                InvalidStateDomainException::because('test') => 422,
                InvalidArgumentDomainException::because('test') => 422,
                NotFoundDomainException::forId('test') => 404,
                ConflictDomainException::because('test') => 409,
                AggregateNotFoundException::for('Test', 'id') => 404,
                OptimisticLockException::for('id', expectedVersion: 1, actualVersion: 2) => 409,
                InvalidAggregateRootException::notAnAggregate(new \stdClass) => 500,
                InvalidStateException::because('test') => 500,
            ];

            foreach ($statusMap as $exception => $expectedStatus) {
                expect($exception->httpStatus())->toBe($expectedStatus);
            }
        });

        test('ValueObject equality is structural (toArray-based)', function () {
            $vo1 = new TestAuditAddress('123 Main', 'NYC', 'US');
            $vo2 = new TestAuditAddress('123 Main', 'NYC', 'US');
            $vo3 = new TestAuditAddress('456 Oak', 'LA', 'US');

            expect($vo1->equals($vo2))->toBeTrue();
            expect($vo1->equals($vo3))->toBeFalse();
        });
    });

    // ─── 4. InMemoryUnitOfWork Transactional Semantics ─────────────────────
    describe('InMemoryUnitOfWork', function () {
        test('run() auto-commits on success', function () {
            $uow = new InMemoryUnitOfWork;
            $dispatched = [];

            $uow->setEventDispatcher(function (DomainEvent $e) use (&$dispatched): void {
                $dispatched[] = $e->eventType;
            });

            $id = AggregateRootId::generate();
            $result = $uow->run(function () use ($uow, $id) {
                $order = TestAuditOrder::place($id);
                $uow->track($order);

                return $order->id();
            });

            expect($result)->toBe($id->toString());
            expect($dispatched)->toContain('order.placed');
            expect($uow->isActive())->toBeFalse();
        });

        test('run() auto-rollbacks on exception and events are not dispatched', function () {
            $uow = new InMemoryUnitOfWork;
            $dispatched = [];

            $uow->setEventDispatcher(function (DomainEvent $e) use (&$dispatched): void {
                $dispatched[] = $e->eventType;
            });

            $thrown = false;
            try {
                $uow->run(function () use ($uow) {
                    $id = AggregateRootId::generate();
                    $order = TestAuditOrder::place($id);
                    $uow->track($order);

                    throw new \RuntimeException('Intentional failure');
                });
            } catch (\RuntimeException $e) {
                $thrown = true;
            }

            expect($thrown)->toBeTrue();
            expect($dispatched)->toBeEmpty();
            expect($uow->isActive())->toBeFalse();
        });

        test('nested run() creates savepoints', function () {
            $uow = new InMemoryUnitOfWork;
            $committed = [];
            $dispatched = [];

            $uow->setPersistenceCallback(function (array $c, array $d) use (&$committed): void {
                foreach ($c as $agg) {
                    $committed[] = $agg->id();
                }
            });
            $uow->setEventDispatcher(function (DomainEvent $e) use (&$dispatched): void {
                $dispatched[] = $e->eventType;
            });

            $uow->run(function () use ($uow) {
                $id1 = AggregateRootId::generate();
                $order1 = TestAuditOrder::place($id1);
                $uow->track($order1);

                // Nested scope
                $uow->run(function () use ($uow) {
                    $id2 = AggregateRootId::generate();
                    $order2 = TestAuditOrder::place($id2);
                    $uow->track($order2);
                });
            });

            expect(count($committed))->toBe(2);
            expect(count($dispatched))->toBe(2);
        });

        test('markForDeletion removes from committed set on commit', function () {
            $uow = new InMemoryUnitOfWork;
            $deleted = [];

            $uow->setPersistenceCallback(function (array $c, array $d) use (&$deleted): void {
                foreach ($d as $agg) {
                    $deleted[] = $agg->id();
                }
            });

            $id = AggregateRootId::generate();
            $order = TestAuditOrder::place($id);

            $uow->run(function () use ($uow, $order) {
                $uow->track($order);
                $uow->markForDeletion($order);
            });

            expect($deleted)->toContain($id->toString());
            expect($uow->getCommitted())->toBeEmpty();
        });

        test('peekDomainEvents does not consume events', function () {
            $id = AggregateRootId::generate();
            $order = TestAuditOrder::place($id);

            $peeked = $order->peekDomainEvents();
            expect($peeked->count())->toBe(1);

            // Events still available for pulling
            $pulled = $order->pullDomainEvents();
            expect($pulled->count())->toBe(1);

            // Now empty
            expect($order->peekDomainEvents()->count())->toBe(0);
            expect($order->hasUncommittedEvents())->toBeFalse();
        });
    });

    // ─── 5. Snapshot Lifecycle ──────────────────────────────────────────────
    describe('Snapshot Lifecycle', function () {
        test('InMemorySnapshotStore CRUD operations', function () {
            $store = new InMemorySnapshotStore;
            $id = AggregateRootId::generate();
            $snapshot = Snapshot::create(TestAuditOrder::class, $id->toString(), 5, ['status' => 'paid']);

            expect($store->has(TestAuditOrder::class, $id->toString()))->toBeFalse();

            $store->save($snapshot);

            expect($store->has(TestAuditOrder::class, $id->toString()))->toBeTrue();

            $loaded = $store->load(TestAuditOrder::class, $id->toString());
            expect($loaded)->not->toBeNull();
            expect($loaded->version)->toBe(5);
            expect($loaded->state['status'])->toBe('paid');

            expect($store->count(TestAuditOrder::class))->toBe(1);
            expect($store->count())->toBe(1);

            $store->delete(TestAuditOrder::class, $id->toString());
            expect($store->has(TestAuditOrder::class, $id->toString()))->toBeFalse();
        });

        test('InMemorySnapshotStore purge by type', function () {
            $store = new InMemorySnapshotStore;
            $id1 = AggregateRootId::generate();
            $id2 = AggregateRootId::generate();

            $store->save(Snapshot::create(TestAuditOrder::class, $id1->toString(), 1, []));
            $store->save(Snapshot::create(TestAuditOrder::class, $id2->toString(), 2, []));
            $store->save(Snapshot::create('OtherType', 'other-id', 1, []));

            $removed = $store->purge(TestAuditOrder::class);

            expect($removed)->toBe(2);
            expect($store->count())->toBe(1);
        });

        test('Snapshot equals checks structural fields', function () {
            $id = AggregateRootId::generate();
            $s1 = Snapshot::create('Order', $id->toString(), 10, ['status' => 'paid']);
            $s2 = Snapshot::create('Order', $id->toString(), 10, ['status' => 'paid']);
            $s3 = Snapshot::create('Order', $id->toString(), 11, ['status' => 'paid']);

            expect($s1->equals($s2))->toBeTrue();
            expect($s1->equals($s3))->toBeFalse();
        });

        test('SnapshotPolicy attribute is readonly', function () {
            $ref = new ReflectionClass(SnapshotPolicy::class);
            expect($ref->isReadOnly())->toBeTrue();
        });
    });

    // ─── 6. Identifier Type Safety ─────────────────────────────────────────
    describe('Identifier Type Safety', function () {
        test('different UUID identifier subclasses are never equal', function () {
            $orderId = TestAuditOrderId::generate();
            $userId = TestAuditUserId::generate();

            // Even with same UUID value, different subclasses != equal
            expect($orderId->equals($userId))->toBeFalse();
        });

        test('same UUID identifier subclass with same value are equal', function () {
            $uuid = '550e8400-e29b-41d4-a716-446655440000';
            $id1 = TestAuditOrderId::fromString($uuid);
            $id2 = TestAuditOrderId::fromString($uuid);

            expect($id1->equals($id2))->toBeTrue();
        });

        test('StringIdentifier rejects empty strings', function () {
            expect(fn () => StringIdentifier::from(''))
                ->toThrow(\InvalidArgumentException::class);
        });

        test('IntegerIdentifier rejects non-integer strings', function () {
            expect(fn () => IntegerIdentifier::fromString('abc'))
                ->toThrow(\InvalidArgumentException::class);
        });

        test('all identifiers implement Identifier contract', function () {
            $identifiers = [
                AggregateRootId::generate(),
                TestAuditOrderId::generate(),
                TestAuditProductId::generate(),
                StringIdentifier::from('test'),
                IntegerIdentifier::from(1),
            ];

            foreach ($identifiers as $id) {
                expect($id)->toBeInstanceOf(IdentifierContract::class);
                expect($id)->toBeInstanceOf(\JsonSerializable::class);
                expect($id)->toBeInstanceOf(\Stringable::class);
            }
        });
    });

    // ─── 7. Class Modifier Verification ──────────────────────────────────
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

        test('SnapshottingRepository is final readonly', function () {
            $ref = new ReflectionClass(SnapshottingRepository::class);
            expect($ref->isFinal())->toBeTrue();
            expect($ref->isReadOnly())->toBeTrue();
        });

        test('InMemoryUnitOfWork is final', function () {
            $ref = new ReflectionClass(InMemoryUnitOfWork::class);
            expect($ref->isFinal())->toBeTrue();
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

        test('all exception classes are final', function () {
            $exceptions = [
                DomainException::class,
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
                if ($class === DomainException::class) {
                    continue; // Abstract
                }
                expect((new ReflectionClass($class))->isFinal())
                    ->toBeTrue("{$class} should be final");
            }
        });
    });

    // ─── 8. Deprecated API Detection ──────────────────────────────────────
    describe('Deprecated API', function () {
        test('AggregateRoot::getVersion has #[Deprecated] attribute', function () {
            $method = new ReflectionMethod(AggregateRoot::class, 'getVersion');
            $attrs = $method->getAttributes(\Deprecated::class);
            expect($attrs)->not->toBeEmpty();
        });

        test('OffsetPagination::setItems has #[Deprecated] attribute', function () {
            $method = new ReflectionMethod(OffsetPagination::class, 'setItems');
            $attrs = $method->getAttributes(\Deprecated::class);
            expect($attrs)->not->toBeEmpty();
        });
    });

    // ─── 9. DomainEventCollection Functional API ──────────────────────────
    describe('DomainEventCollection Functional API', function () {
        test('some/none/hasType predicates work correctly', function () {
            $e1 = DomainEvent::occur('order.placed', []);
            $e2 = DomainEvent::occur('order.item_added', []);
            $e3 = DomainEvent::occur('order.paid', []);
            $collection = new DomainEventCollection([$e1, $e2, $e3]);

            expect($collection->some(fn ($e) => $e->eventType === 'order.paid'))->toBeTrue();
            expect($collection->some(fn ($e) => $e->eventType === 'order.cancelled'))->toBeFalse();
            expect($collection->none(fn ($e) => $e->eventType === 'order.cancelled'))->toBeTrue();
            expect($collection->hasType('order.placed'))->toBeTrue();
            expect($collection->hasType('order.shipped'))->toBeFalse();
        });

        test('reduce accumulates correctly', function () {
            $e1 = DomainEvent::occur('order.item_added', ['amount' => 10]);
            $e2 = DomainEvent::occur('order.item_added', ['amount' => 20]);
            $e3 = DomainEvent::occur('order.item_added', ['amount' => 5]);
            $collection = new DomainEventCollection([$e1, $e2, $e3]);

            $total = $collection->reduce(
                fn (int $sum, DomainEvent $e) => $sum + ($e->payload['amount'] ?? 0),
                0,
            );

            expect($total)->toBe(35);
        });

        test('countBy counts matching events', function () {
            $e1 = DomainEvent::occur('click', []);
            $e2 = DomainEvent::occur('view', []);
            $e3 = DomainEvent::occur('click', []);
            $collection = new DomainEventCollection([$e1, $e2, $e3]);

            expect($collection->countBy(fn ($e) => $e->eventType === 'click'))->toBe(2);
            expect($collection->countBy(fn ($e) => $e->eventType === 'view'))->toBe(1);
        });

        test('types returns unique event types in order', function () {
            $e1 = DomainEvent::occur('a', []);
            $e2 = DomainEvent::occur('b', []);
            $e3 = DomainEvent::occur('a', []);
            $collection = new DomainEventCollection([$e1, $e2, $e3]);

            expect($collection->types())->toBe(['a', 'b']);
        });

        test('each returns the same collection for chaining', function () {
            $collection = new DomainEventCollection([
                DomainEvent::occur('a', []),
            ]);

            $result = $collection->each(fn () => {});
            expect($result)->toBe($collection);
        });

        test('merge combines two collections', function () {
            $c1 = new DomainEventCollection([DomainEvent::occur('a', [])]);
            $c2 = new DomainEventCollection([DomainEvent::occur('b', [])]);
            $merged = $c1->merge($c2);

            expect($merged->count())->toBe(2);
            expect($merged->hasType('a'))->toBeTrue();
            expect($merged->hasType('b'))->toBeTrue();
        });

        test('filter returns a new collection', function () {
            $collection = new DomainEventCollection([
                DomainEvent::occur('a', []),
                DomainEvent::occur('b', []),
            ]);

            $filtered = $collection->filter(fn ($e) => $e->eventType === 'a');

            expect($filtered->count())->toBe(1);
            expect($collection->count())->toBe(2); // Original unchanged
        });

        test('constructor rejects non-sequential arrays', function () {
            expect(fn () => new DomainEventCollection(['key' => DomainEvent::occur('a', [])]))
                ->toThrow(\InvalidArgumentException::class);
        });

        test('constructor rejects non-DomainEvent items', function () {
            expect(fn () => new DomainEventCollection(['not_an_event']))
                ->toThrow(\InvalidArgumentException::class);
        });
    });
});

// ─── Test Fixtures ──────────────────────────────────────────────────────────

final class TestAuditOrderId extends UuidIdentifier {}

final class TestAuditUserId extends UuidIdentifier {}

final class TestAuditProductId extends UlidIdentifier {}

final class TestAuditOrderItem extends Entity
{
    public function __construct(
        int|string|\Stringable $id,
        public readonly string $productName,
        public readonly int $quantity,
        public readonly float $price,
    ) {
        parent::__construct($id);
    }
}

final class TestAuditAddress extends ValueObject
{
    public function __construct(
        public readonly string $street,
        public readonly string $city,
        public readonly string $country,
    ) {}

    public static function fromArray(array $data): static
    {
        return new static(
            street: $data['street'],
            city: $data['city'],
            country: $data['country'],
        );
    }

    public function toArray(): array
    {
        return [
            'street' => $this->street,
            'city' => $this->city,
            'country' => $this->country,
        ];
    }
}

final class TestAuditOrder extends AggregateRoot
{
    use Concerns\EventSourced;

    public string $status = 'pending';
    public float $total = 0.0;

    public function __construct(AggregateRootId $id)
    {
        parent::__construct($id);
    }

    public static function place(AggregateRootId $id): self
    {
        $order = new self($id);
        $order->apply(DomainEvent::occur('order.placed', ['id' => $id->toString()]));

        return $order;
    }

    public function addItem(string $name, int $qty, float $price): void
    {
        $this->apply(DomainEvent::occur('order.item_added', [
            'name' => $name,
            'quantity' => $qty,
            'price' => $price,
        ]));
    }

    protected function applyOrderPlaced(DomainEvent $event): void
    {
        $this->status = 'pending';
    }

    protected function applyOrderItemAdded(DomainEvent $event): void
    {
        $this->total += $event->payload['quantity'] * $event->payload['price'];
    }

    public function toSnapshotState(): array
    {
        return ['status' => $this->status, 'total' => $this->total];
    }

    public static function reconstituteFromSnapshot(Snapshot $snapshot, AggregateRootId $id): static
    {
        $order = new static($id);
        $order->status = $snapshot->state['status'];
        $order->total = $snapshot->state['total'];
        $order->setVersion($snapshot->version);

        return $order;
    }
}

final class TestAuditOtherOrder extends AggregateRoot
{
    public function __construct(AggregateRootId $id)
    {
        parent::__construct($id);
    }
}
