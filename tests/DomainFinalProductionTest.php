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
use ZeroBoiler\Domain\Contracts\Repository;
use ZeroBoiler\Domain\Contracts\UnitOfWork;
use ZeroBoiler\Domain\DomainEventCollection;
use ZeroBoiler\Domain\Entity;
use ZeroBoiler\Domain\Exceptions\AggregateNotFoundException;
use ZeroBoiler\Domain\Exceptions\ConflictDomainException;
use ZeroBoiler\Domain\Exceptions\InvalidAggregateRootException;
use ZeroBoiler\Domain\Exceptions\InvalidArgumentDomainException;
use ZeroBoiler\Domain\Exceptions\InvalidStateDomainException;
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

describe('Domain Final Production Test Suite', function (): void {
    // =========================================================================
    // AggregateRootId — Immutable UUID v4 Identity
    // =========================================================================
    describe('AggregateRootId', function (): void {
        test('generate() creates valid UUID v4', function (): void {
            $id = AggregateRootId::generate();
            expect($id->toString())->toBeString();
            expect(strlen($id->toString()))->toBe(36);
            expect($id->value)->toBeInstanceOf(\Ramsey\Uuid\UuidInterface::class);
        });

        test('fromString() parses existing UUID', function (): void {
            $id = AggregateRootId::generate();
            $parsed = AggregateRootId::fromString($id->toString());
            expect($parsed->toString())->toBe($id->toString());
            expect($parsed->equals($id))->toBeTrue();
        });

        test('equals() returns false for different IDs', function (): void {
            $id1 = AggregateRootId::generate();
            $id2 = AggregateRootId::generate();
            expect($id1->equals($id2))->toBeFalse();
        });

        test('JSON serialization returns string', function (): void {
            $id = AggregateRootId::generate();
            $encoded = json_encode($id);
            expect($encoded)->toBeJson();
            expect(json_decode($encoded, true))->toBe($id->toString());
        });

        test('__toString() returns same as toString()', function (): void {
            $id = AggregateRootId::generate();
            expect((string) $id)->toBe($id->toString());
        });

        test('is readonly and final', function (): void {
            $ref = new \ReflectionClass(AggregateRootId::class);
            expect($ref->isReadOnly())->toBeTrue();
            expect($ref->isFinal())->toBeTrue();
        });
    });

    // =========================================================================
    // UuidIdentifier — Abstract readonly UUID v4
    // =========================================================================
    describe('UuidIdentifier', function (): void {
        test('generate() and fromString() work with subclass', function (): void {
            $id = TestUuidId::generate();
            expect($id->value)->toBeString();
            expect($id->isValid($id->value))->toBeTrue();

            $parsed = TestUuidId::fromString($id->value);
            expect($parsed->equals($id))->toBeTrue();
        });

        test('equals() is type-safe across subclasses', function (): void {
            $a = TestUuidId::generate();
            $b = TestUuidId::fromString($a->value);
            $c = TestUuidId::generate();
            expect($a->equals($b))->toBeTrue();
            expect($a->equals($c))->toBeFalse();
        });

        test('invalid UUID throws on construction', function (): void {
            expect(fn (): mixed => TestUuidId::fromString('not-a-uuid'))
                ->toThrow(\Ramsey\Uuid\Exception\InvalidUuidStringException::class);
        });

        test('isValid() returns false for non-UUID strings', function (): void {
            expect(TestUuidId::isValid('not-a-uuid'))->toBeFalse();
            expect(TestUuidId::isValid('550e8400-e29b-41d4-a716-446655440000'))->toBeTrue();
        });

        test('toUuid() returns Ramsey instance', function (): void {
            $id = TestUuidId::generate();
            expect($id->toUuid())->toBeInstanceOf(\Ramsey\Uuid\UuidInterface::class);
        });

        test('JSON serialization returns string value', function (): void {
            $id = TestUuidId::generate();
            expect(json_encode($id))->toBe('"' . $id->value . '"');
        });
    });

    // =========================================================================
    // UlidIdentifier — Abstract readonly ULID
    // =========================================================================
    describe('UlidIdentifier', function (): void {
        test('generate() creates monotonic ULID', function (): void {
            $id = TestUlidId::generate();
            expect($id->value)->toBeString();
            expect(strlen($id->value))->toBe(26);
            expect(TestUlidId::isValid($id->value))->toBeTrue();
        });

        test('fromString() parses existing ULID', function (): void {
            $id = TestUlidId::generate();
            $parsed = TestUlidId::fromString($id->value);
            expect($parsed->equals($id))->toBeTrue();
        });

        test('toUlid() returns Symfony ULID object', function (): void {
            $id = TestUlidId::generate();
            expect($id->toUlid())->toBeInstanceOf(\Symfony\Component\Uid\Ulid::class);
        });

        test('invalid ULID throws on construction', function (): void {
            expect(fn (): mixed => TestUlidId::fromString('not-a-ulid!!!'))
                ->toThrow(\InvalidArgumentException::class);
        });

        test('JSON serialization returns string value', function (): void {
            $id = TestUlidId::generate();
            expect(json_encode($id))->toBe('"' . $id->value . '"');
        });
    });

    // =========================================================================
    // StringIdentifier — Readonly non-empty string
    // =========================================================================
    describe('StringIdentifier', function (): void {
        test('from() creates identifier from non-empty string', function (): void {
            $id = TestStringId::from('my-slug');
            expect($id->value)->toBe('my-slug');
            expect($id->toString())->toBe('my-slug');
        });

        test('from() throws on empty string', function (): void {
            expect(fn (): mixed => TestStringId::from(''))
                ->toThrow(\ValueError::class);
        });

        test('isValid() returns false for empty string', function (): void {
            expect(TestStringId::isValid(''))->toBeFalse();
            expect(TestStringId::isValid('hello'))->toBeTrue();
        });

        test('equals() is type-safe', function (): void {
            $a = TestStringId::from('slug-a');
            $b = TestStringId::from('slug-a');
            $c = TestStringId::from('slug-b');
            expect($a->equals($b))->toBeTrue();
            expect($a->equals($c))->toBeFalse();
        });

        test('JSON serialization returns string value', function (): void {
            $id = TestStringId::from('test');
            expect(json_encode($id))->toBe('"test"');
        });
    });

    // =========================================================================
    // IntegerIdentifier — Final readonly integer
    // =========================================================================
    describe('IntegerIdentifier', function (): void {
        test('from() creates identifier from integer', function (): void {
            $id = IntegerIdentifier::from(42);
            expect($id->value)->toBe(42);
            expect($id->toInt())->toBe(42);
            expect($id->toString())->toBe('42');
        });

        test('fromString() parses integer strings', function (): void {
            $id = IntegerIdentifier::fromString('42');
            expect($id->toInt())->toBe(42);
        });

        test('isValid() checks string is integer', function (): void {
            expect(IntegerIdentifier::isValid('42'))->toBeTrue();
            expect(IntegerIdentifier::isValid('-5'))->toBeTrue();
            expect(IntegerIdentifier::isValid('abc'))->toBeFalse();
        });

        test('equals() checks value equality', function (): void {
            $a = IntegerIdentifier::from(1);
            $b = IntegerIdentifier::from(1);
            $c = IntegerIdentifier::from(2);
            expect($a->equals($b))->toBeTrue();
            expect($a->equals($c))->toBeFalse();
        });

        test('JSON serialization returns integer value', function (): void {
            $id = IntegerIdentifier::from(42);
            expect(json_encode($id))->toBe('42');
        });

        test('is final and readonly', function (): void {
            $ref = new \ReflectionClass(IntegerIdentifier::class);
            expect($ref->isFinal())->toBeTrue();
            expect($ref->isReadOnly())->toBeTrue();
        });
    });

    // =========================================================================
    // Entity — Base domain entity with flexible ID types
    // =========================================================================
    describe('Entity', function (): void {
        test('accepts string ID', function (): void {
            $entity = new TestEntity('entity-1');
            expect($entity->id())->toBe('entity-1');
        });

        test('accepts integer ID', function (): void {
            $entity = new TestEntity(42);
            expect($entity->id())->toBe('42');
        });

        test('accepts Stringable ID (AggregateRootId)', function (): void {
            $id = AggregateRootId::generate();
            $entity = new TestEntity($id);
            expect($entity->id())->toBe($id->toString());
        });

        test('equals() checks class type and identity', function (): void {
            $a = new TestEntity('id-1');
            $b = new TestEntity('id-1');
            $c = new TestEntity('id-2');
            expect($a->equals($b))->toBeTrue();
            expect($a->equals($c))->toBeFalse();
        });

        test('has domain events capability', function (): void {
            $entity = new TestEntity('id-1');
            expect($entity->hasUncommittedEvents())->toBeFalse();
        });
    });

    // =========================================================================
    // AggregateRoot — Typed identity, events, versioning
    // =========================================================================
    describe('AggregateRoot', function (): void {
        test('version starts at 0', function (): void {
            $order = TestAggregate::create(AggregateRootId::generate());
            expect($order->version())->toBe(0);
        });

        test('id() returns string UUID', function (): void {
            $id = AggregateRootId::generate();
            $order = TestAggregate::create($id);
            expect($order->id())->toBe($id->toString());
        });

        test('aggregateId() returns typed AggregateRootId', function (): void {
            $id = AggregateRootId::generate();
            $order = TestAggregate::create($id);
            expect($order->aggregateId())->toBe($id);
        });

        test('apply() increments version', function (): void {
            $order = TestAggregate::create(AggregateRootId::generate());
            $initialVersion = $order->version();
            $order->mutate('item_added', ['item' => 'widget']);
            expect($order->version())->toBe($initialVersion + 1);
        });

        test('pullDomainEvents() is destructive', function (): void {
            $id = AggregateRootId::generate();
            $order = TestAggregate::create($id);
            $events1 = $order->pullDomainEvents();
            expect($events1->count())->toBe(1);
            $events2 = $order->pullDomainEvents();
            expect($events2->isEmpty())->toBeTrue();
        });

        test('clearDomainEvents() removes all events', function (): void {
            $id = AggregateRootId::generate();
            $order = TestAggregate::create($id);
            $order->clearDomainEvents();
            expect($order->pullDomainEvents()->isEmpty())->toBeTrue();
        });

        test('setVersion() and incrementVersion() work', function (): void {
            $id = AggregateRootId::generate();
            $order = TestAggregate::create($id);
            $order->setVersion(10);
            expect($order->version())->toBe(10);
            $order->incrementVersion();
            expect($order->version())->toBe(11);
        });

        test('toArray() includes id, version, type', function (): void {
            $id = AggregateRootId::generate();
            $order = TestAggregate::create($id);
            $arr = $order->toArray();
            expect($arr)->toHaveKey('id');
            expect($arr)->toHaveKey('version');
            expect($arr)->toHaveKey('type');
            expect($arr['type'])->toBe('TestAggregate');
        });

        test('equals() checks class type and aggregate ID', function (): void {
            $id1 = AggregateRootId::generate();
            $id2 = AggregateRootId::generate();
            $a = TestAggregate::create($id1);
            $b = TestAggregate::create($id1);
            $c = TestAggregate::create($id2);
            expect($a->equals($b))->toBeTrue();
            expect($a->equals($c))->toBeFalse();
        });
    });

    // =========================================================================
    // DomainEventCollection — Type-safe immutable event collection
    // =========================================================================
    describe('DomainEventCollection', function (): void {
        test('constructor validates sequential list of DomainEvent', function (): void {
            $event = DomainEvent::occur('test.event', ['key' => 'value']);
            $collection = new DomainEventCollection([$event]);
            expect($collection->count())->toBe(1);
            expect($collection->isEmpty())->toBeFalse();
        });

        test('constructor rejects non-list array', function (): void {
            $event = DomainEvent::occur('test.event', []);
            expect(fn (): mixed => new DomainEventCollection(['key' => $event]))
                ->toThrow(\InvalidArgumentException::class);
        });

        test('constructor rejects non-DomainEvent items', function (): void {
            expect(fn (): mixed => new DomainEventCollection(['not-an-event']))
                ->toThrow(\InvalidArgumentException::class);
        });

        test('all() returns plain array', function (): void {
            $e1 = DomainEvent::occur('test.a', []);
            $e2 = DomainEvent::occur('test.b', []);
            $collection = new DomainEventCollection([$e1, $e2]);
            expect($collection->all())->toBe([$e1, $e2]);
        });

        test('filter() returns new collection', function (): void {
            $e1 = DomainEvent::occur('test.a', []);
            $e2 = DomainEvent::occur('test.b', []);
            $collection = new DomainEventCollection([$e1, $e2]);
            $filtered = $collection->filter(
                fn (DomainEvent $e): bool => str_starts_with($e->eventType, 'test.a'),
            );
            expect($filtered->count())->toBe(1);
        });

        test('map() returns plain array of results', function (): void {
            $e1 = DomainEvent::occur('test.a', []);
            $e2 = DomainEvent::occur('test.b', []);
            $collection = new DomainEventCollection([$e1, $e2]);
            $types = $collection->map(fn (DomainEvent $e): string => $e->eventType);
            expect($types)->toBe(['test.a', 'test.b']);
        });

        test('first() and last() return correct events', function (): void {
            $e1 = DomainEvent::occur('test.a', []);
            $e2 = DomainEvent::occur('test.b', []);
            $collection = new DomainEventCollection([$e1, $e2]);
            expect($collection->first()->eventType)->toBe('test.a');
            expect($collection->last()->eventType)->toBe('test.b');
        });

        test('merge() combines two collections', function (): void {
            $e1 = DomainEvent::occur('test.a', []);
            $e2 = DomainEvent::occur('test.b', []);
            $c1 = new DomainEventCollection([$e1]);
            $c2 = new DomainEventCollection([$e2]);
            $merged = $c1->merge($c2);
            expect($merged->count())->toBe(2);
        });

        test('get() returns event at index or null', function (): void {
            $e1 = DomainEvent::occur('test.a', []);
            $collection = new DomainEventCollection([$e1]);
            expect($collection->get(0)->eventType)->toBe('test.a');
            expect($collection->get(99))->toBeNull();
        });

        test('is readonly and final', function (): void {
            $ref = new \ReflectionClass(DomainEventCollection::class);
            expect($ref->isReadOnly())->toBeTrue();
            expect($ref->isFinal())->toBeTrue();
        });

        test('jsonSerialize() returns array of event arrays', function (): void {
            $e1 = DomainEvent::occur('test.a', ['x' => 1]);
            $collection = new DomainEventCollection([$e1]);
            $json = json_encode($collection);
            expect($json)->toBeJson();
        });

        test('implements Countable, IteratorAggregate, JsonSerializable', function (): void {
            $ref = new \ReflectionClass(DomainEventCollection::class);
            expect($ref->implementsInterface(\Countable::class))->toBeTrue();
            expect($ref->implementsInterface(\IteratorAggregate::class))->toBeTrue();
            expect($ref->implementsInterface(\JsonSerializable::class))->toBeTrue();
        });
    });

    // =========================================================================
    // Snapshot — Immutable aggregate state snapshot
    // =========================================================================
    describe('Snapshot', function (): void {
        test('create() and toArray() round-trip', function (): void {
            $snapshot = Snapshot::create('Order', 'id-1', 5, ['status' => 'paid']);
            $arr = $snapshot->toArray();
            expect($arr['aggregate_type'])->toBe('Order');
            expect($arr['aggregate_id'])->toBe('id-1');
            expect($arr['version'])->toBe(5);
            expect($arr['state'])->toBe(['status' => 'paid']);
            expect($arr)->toHaveKey('created_at');
        });

        test('fromArray() restores from array', function (): void {
            $original = Snapshot::create('Order', 'id-1', 5, ['status' => 'paid']);
            $restored = Snapshot::fromArray($original->toArray());
            expect($restored->aggregateType)->toBe('Order');
            expect($restored->aggregateId)->toBe('id-1');
            expect($restored->version)->toBe(5);
            expect($restored->state)->toBe(['status' => 'paid']);
        });

        test('fromArray() validates types', function (): void {
            expect(fn (): mixed => Snapshot::fromArray([]))
                ->toThrow(\InvalidArgumentException::class);
        });

        test('equals() checks all fields', function (): void {
            $a = Snapshot::create('Order', 'id-1', 5, ['s' => 'a']);
            $b = Snapshot::create('Order', 'id-1', 5, ['s' => 'a']);
            $c = Snapshot::create('Order', 'id-1', 6, ['s' => 'a']);
            expect($a->equals($b))->toBeTrue();
            expect($a->equals($c))->toBeFalse();
        });

        test('jsonSerialize() delegates to toArray()', function (): void {
            $snapshot = Snapshot::create('Order', 'id-1', 1, []);
            $json = json_encode($snapshot);
            expect($json)->toBeJson();
            $decoded = json_decode($json, true);
            expect($decoded['aggregate_type'])->toBe('Order');
        });

        test('is final and readonly', function (): void {
            $ref = new \ReflectionClass(Snapshot::class);
            expect($ref->isFinal())->toBeTrue();
            expect($ref->isReadOnly())->toBeTrue();
        });
    });

    // =========================================================================
    // InMemorySnapshotStore — In-memory snapshot persistence
    // =========================================================================
    describe('InMemorySnapshotStore', function (): void {
        test('save() and load() round-trip', function (): void {
            $store = new InMemorySnapshotStore;
            $snapshot = Snapshot::create('Order', 'id-1', 5, ['s' => 'a']);
            $store->save($snapshot);
            $loaded = $store->load('Order', 'id-1');
            expect($loaded)->not->toBeNull();
            expect($loaded->equals($snapshot))->toBeTrue();
        });

        test('has() returns correct boolean', function (): void {
            $store = new InMemorySnapshotStore;
            $snapshot = Snapshot::create('Order', 'id-1', 1, []);
            $store->save($snapshot);
            expect($store->has('Order', 'id-1'))->toBeTrue();
            expect($store->has('Order', 'id-99'))->toBeFalse();
        });

        test('delete() removes snapshot', function (): void {
            $store = new InMemorySnapshotStore;
            $snapshot = Snapshot::create('Order', 'id-1', 1, []);
            $store->save($snapshot);
            $store->delete('Order', 'id-1');
            expect($store->has('Order', 'id-1'))->toBeFalse();
        });

        test('deleteOlderThan() removes old snapshots', function (): void {
            $store = new InMemorySnapshotStore;
            $store->save(Snapshot::create('Order', 'id-1', 5, []));
            $store->save(Snapshot::create('Order', 'id-1', 10, []));
            $store->deleteOlderThan('Order', 'id-1', 8);
            $loaded = $store->load('Order', 'id-1');
            expect($loaded->version)->toBe(10);
        });

        test('count() counts total and per-type', function (): void {
            $store = new InMemorySnapshotStore;
            $store->save(Snapshot::create('Order', 'id-1', 1, []));
            $store->save(Snapshot::create('Order', 'id-2', 1, []));
            $store->save(Snapshot::create('User', 'id-1', 1, []));
            expect($store->count())->toBe(3);
            expect($store->count('Order'))->toBe(2);
            expect($store->count('User'))->toBe(1);
        });

        test('stats() returns total and by_type', function (): void {
            $store = new InMemorySnapshotStore;
            $store->save(Snapshot::create('Order', 'id-1', 1, []));
            $store->save(Snapshot::create('User', 'id-1', 1, []));
            $stats = $store->stats();
            expect($stats['total'])->toBe(2);
            expect($stats['by_type']['Order'])->toBe(1);
            expect($stats['by_type']['User'])->toBe(1);
        });

        test('purge() removes by type or all', function (): void {
            $store = new InMemorySnapshotStore;
            $store->save(Snapshot::create('Order', 'id-1', 1, []));
            $store->save(Snapshot::create('User', 'id-1', 1, []));
            $removed = $store->purge('Order');
            expect($removed)->toBe(1);
            expect($store->count())->toBe(1);
            $store->purge();
            expect($store->count())->toBe(0);
        });

        test('implements SnapshotStore interface', function (): void {
            $ref = new \ReflectionClass(InMemorySnapshotStore::class);
            expect($ref->implementsInterface(SnapshotStore::class))->toBeTrue();
        });
    });

    // =========================================================================
    // InMemoryUnitOfWork — Transactional event queuing
    // =========================================================================
    describe('InMemoryUnitOfWork', function (): void {
        test('begin() activates and rollback() deactivates', function (): void {
            $uow = new InMemoryUnitOfWork;
            expect($uow->isActive())->toBeFalse();
            $uow->begin();
            expect($uow->isActive())->toBeTrue();
            $uow->rollback();
            expect($uow->isActive())->toBeFalse();
        });

        test('run() auto-commits on success', function (): void {
            $uow = new InMemoryUnitOfWork;
            $result = $uow->run(fn (): string => 'success');
            expect($result)->toBe('success');
            expect($uow->isActive())->toBeFalse();
        });

        test('run() auto-rollbacks on exception', function (): void {
            $uow = new InMemoryUnitOfWork;
            expect(fn (): mixed => $uow->run(fn (): mixed => throw new \RuntimeException('fail')))
                ->toThrow(\RuntimeException::class);
            expect($uow->isActive())->toBeFalse();
        });

        test('nested run() uses savepoints', function (): void {
            $uow = new InMemoryUnitOfWork;
            $count = 0;
            $uow->run(function () use (&$count, $uow): void {
                $count++;
                $uow->run(function () use (&$count): void {
                    $count++;
                });
            });
            expect($count)->toBe(2);
            expect($uow->isActive())->toBeFalse();
        });

        test('track() requires active UoW', function (): void {
            $uow = new InMemoryUnitOfWork;
            $id = AggregateRootId::generate();
            $aggregate = TestAggregate::create($id);
            expect(fn (): mixed => $uow->track($aggregate))
                ->toThrow(\RuntimeException::class);
        });

        test('isTracking() works', function (): void {
            $uow = new InMemoryUnitOfWork;
            $id = AggregateRootId::generate();
            $aggregate = TestAggregate::create($id);
            $uow->begin();
            expect($uow->isTracking($aggregate))->toBeFalse();
            $uow->track($aggregate);
            expect($uow->isTracking($aggregate))->toBeTrue();
            $uow->rollback();
        });

        test('queueEvent() requires active UoW', function (): void {
            $uow = new InMemoryUnitOfWork;
            expect(fn (): mixed => $uow->queueEvent(DomainEvent::occur('test', [])))
                ->toThrow(\RuntimeException::class);
        });

        test('clear() resets all state', function (): void {
            $uow = new InMemoryUnitOfWork;
            $uow->begin();
            $id = AggregateRootId::generate();
            $uow->track(TestAggregate::create($id));
            $uow->commit();
            $uow->clear();
            expect($uow->getCommitted())->toBe([]);
            expect($uow->getDeleted())->toBe([]);
            expect($uow->hasPendingEvents())->toBeFalse();
            expect($uow->isActive())->toBeFalse();
        });

        test('implements UnitOfWork contract', function (): void {
            $ref = new \ReflectionClass(InMemoryUnitOfWork::class);
            expect($ref->implementsInterface(UnitOfWork::class))->toBeTrue();
        });

        test('is final class', function (): void {
            $ref = new \ReflectionClass(InMemoryUnitOfWork::class);
            expect($ref->isFinal())->toBeTrue();
        });
    });

    // =========================================================================
    // Domain Exceptions — Error code hierarchy
    // =========================================================================
    describe('Domain Exceptions', function (): void {
        test('InvalidStateDomainException default code', function () {
            $e = InvalidStateDomainException::because('test');
            expect($e->errorCode())->toBe('INVALID_STATE');
            expect($e->getMessage())->toBe('test');
        });

        test('InvalidStateDomainException custom code', function () {
            $e = InvalidStateDomainException::because('test', 'ORDER_NOT_PENDING');
            expect($e->errorCode())->toBe('ORDER_NOT_PENDING');
        });

        test('InvalidArgumentDomainException default code', function () {
            $e = InvalidArgumentDomainException::because('test');
            expect($e->errorCode())->toBe('INVALID_ARGUMENT');
        });

        test('NotFoundDomainException default code', function () {
            $e = NotFoundDomainException::because('test');
            expect($e->errorCode())->toBe('NOT_FOUND');
        });

        test('NotFoundDomainException forAggregate() factory', function () {
            $e = NotFoundDomainException::forAggregate('Order', 'id-1');
            expect($e->errorCode())->toBe('NOT_FOUND');
            expect($e->getMessage())->toContain('Order');
            expect($e->getMessage())->toContain('id-1');
        });

        test('AggregateNotFoundException default code', function () {
            $e = AggregateNotFoundException::for('Order', 'id-1');
            expect($e->errorCode())->toBe('AGGREGATE_NOT_FOUND');
        });

        test('ConflictDomainException default code', function () {
            $e = ConflictDomainException::because('test');
            expect($e->errorCode())->toBe('CONFLICT');
        });

        test('OptimisticLockException typed factory', function () {
            $e = OptimisticLockException::for('id-1', 5, 3);
            expect($e->errorCode())->toBe('OPTIMISTIC_LOCK');
            expect($e->getMessage())->toContain('id-1');
            expect($e->getMessage())->toContain('5');
            expect($e->getMessage())->toContain('3');
        });

        test('InvalidAggregateRootException default code', function () {
            $obj = new \stdClass;
            $e = InvalidAggregateRootException::notAnAggregate($obj);
            expect($e->errorCode())->toBe('INVALID_AGGREGATE_ROOT');
        });

        test('all exceptions are JsonSerializable', function () {
            $exceptions = [
                InvalidStateDomainException::because('test'),
                InvalidArgumentDomainException::because('test'),
                NotFoundDomainException::because('test'),
                AggregateNotFoundException::for('T', 'id'),
                ConflictDomainException::because('test'),
                OptimisticLockException::for('id', 1, 2),
                InvalidAggregateRootException::notAnAggregate(new \stdClass),
            ];
            foreach ($exceptions as $e) {
                $json = json_encode($e);
                expect($json)->toBeJson();
                $decoded = json_decode($json, true);
                expect($decoded)->toHaveKey('title');
                expect($decoded)->toHaveKey('detail');
                expect($decoded)->toHaveKey('code');
            }
        });

        test('toErrorArray() returns RFC 9457 compatible structure', function () {
            $e = InvalidStateDomainException::because('Order must be pending');
            $arr = $e->toErrorArray();
            expect($arr)->toHaveKey('title');
            expect($arr)->toHaveKey('detail');
            expect($arr)->toHaveKey('code');
        });

        test('all concrete exceptions are final', function () {
            $classes = [
                InvalidStateDomainException::class,
                InvalidArgumentDomainException::class,
                NotFoundDomainException::class,
                AggregateNotFoundException::class,
                ConflictDomainException::class,
                OptimisticLockException::class,
                InvalidAggregateRootException::class,
            ];
            foreach ($classes as $class) {
                expect((new \ReflectionClass($class))->isFinal())->toBeTrue();
            }
        });
    });

    // =========================================================================
    // Contracts — Interface compliance
    // =========================================================================
    describe('Contracts', function (): void {
        test('AggregateRootId is Stringable and JsonSerializable', function () {
            $ref = new \ReflectionClass(AggregateRootId::class);
            expect($ref->implementsInterface(\Stringable::class))->toBeTrue();
            expect($ref->implementsInterface(\JsonSerializable::class))->toBeTrue();
        });

        test('Identifier implementations implement IdentifierContract', function () {
            $implementations = [TestUuidId::class, TestUlidId::class, StringIdentifier::class, IntegerIdentifier::class];
            foreach ($implementations as $class) {
                expect((new \ReflectionClass($class))->implementsInterface(IdentifierContract::class))->toBeTrue();
            }
        });

        test('AggregateRoot extends Entity and implements AggregateRootContract', function () {
            $ref = new \ReflectionClass(TestAggregate::class);
            expect($ref->isSubclassOf(Entity::class))->toBeTrue();
            expect($ref->implementsInterface(AggregateRootContract::class))->toBeTrue();
        });
    });
});

// =========================================================================
// Test Fixtures
// =========================================================================

final class TestUuidId extends UuidIdentifier {}

final class TestUlidId extends UlidIdentifier {}

final class TestStringId extends StringIdentifier {}

final class TestEntity extends Entity
{
    public string $name = 'test';
}

/**
 * @extends AggregateRoot<AggregateRootId>
 */
final class TestAggregate extends AggregateRoot
{
    public string $status = 'pending';

    public function __construct(AggregateRootId $id)
    {
        parent::__construct($id);
    }

    public static function create(AggregateRootId $id): self
    {
        $aggregate = new self($id);
        $aggregate->apply(DomainEvent::occur('test.created', [
            'id' => $id->toString(),
        ]));

        return $aggregate;
    }

    public function mutate(string $type, array $payload = []): void
    {
        $this->apply(DomainEvent::occur($type, $payload));
    }

    protected function applyTestCreated(DomainEvent $event): void
    {
        $this->status = 'created';
    }
}
