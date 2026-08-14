<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

/**
 * Production-ready integration test for the Domain package.
 *
 * Tests the full domain lifecycle: AggregateRoot creation, event sourcing,
 * Unit of Work transactional boundaries, identifier types, snapshot
 * serialization, and domain exception hierarchy.
 *
 * @covers \ZeroBoiler\Domain\AggregateRoot
 * @covers \ZeroBoiler\Domain\AggregateRootId
 * @covers \ZeroBoiler\Domain\Entity
 * @covers \ZeroBoiler\Domain\InMemoryUnitOfWork
 * @covers \ZeroBoiler\Domain\DomainEventCollection
 * @covers \ZeroBoiler\Domain\Identifiers\UuidIdentifier
 * @covers \ZeroBoiler\Domain\Identifiers\UlidIdentifier
 * @covers \ZeroBoiler\Domain\Identifiers\StringIdentifier
 * @covers \ZeroBoiler\Domain\Identifiers\IntegerIdentifier
 * @covers \ZeroBoiler\Domain\Snapshots\Snapshot
 * @covers \ZeroBoiler\Domain\Snapshots\InMemorySnapshotStore
 * @covers \ZeroBoiler\Domain\ValueObject
 * @covers \ZeroBoiler\Domain\Exceptions\DomainException
 */
describe('Domain Package Production Integration', function (): void {
    describe('AggregateRootId lifecycle', function (): void {
        it('generates valid UUID v4 and round-trips through serialization', function (): void {
            $id = \ZeroBoiler\Domain\AggregateRootId::generate();

            // Generate produces valid UUID
            expect(\ZeroBoiler\Domain\AggregateRootId::isValid($id->toString()))->toBeTrue();

            // String conversion
            expect($id->toString())->toBeString();
            expect($id->__toString())->toBe($id->toString());

            // Equality
            $same = \ZeroBoiler\Domain\AggregateRootId::fromString($id->toString());
            expect($id->equals($same))->toBeTrue();

            // Array round-trip
            $array = $id->toArray();
            expect($array)->toHaveKey('uuid');
            $restored = \ZeroBoiler\Domain\AggregateRootId::fromArray($array);
            expect($id->equals($restored))->toBeTrue();

            // JSON round-trip
            $json = json_encode($id);
            $fromJson = \ZeroBoiler\Domain\AggregateRootId::fromJson($json);
            expect($id->equals($fromJson))->toBeTrue();

            // Supports both 'uuid' and 'id' keys in fromArray
            $fromId = \ZeroBoiler\Domain\AggregateRootId::fromArray(['id' => $id->toString()]);
            expect($id->equals($fromId))->toBeTrue();
        });

        it('rejects invalid UUID strings in fromString', function (): void {
            \ZeroBoiler\Domain\AggregateRootId::fromString('not-a-uuid');
        })->throws(\Ramsey\Uuid\Exception\InvalidUuidStringException::class);

        it('validates without throwing', function (): void {
            expect(\ZeroBoiler\Domain\AggregateRootId::isValid('not-valid'))->toBeFalse();
            expect(\ZeroBoiler\Domain\AggregateRootId::isValid('550e8400-e29b-41d4-a716-446655440000'))->toBeTrue();
        });

        it('fromArray throws on missing/invalid key', function (): void {
            \ZeroBoiler\Domain\AggregateRootId::fromArray(['wrong' => 'key']);
        })->throws(\InvalidArgumentException::class);
    });

    describe('AggregateRoot creation and events', function (): void {
        it('creates aggregate with initial event and correct version', function (): void {
            $id = \ZeroBoiler\Domain\AggregateRootId::generate();
            $aggregate = \ZeroBoiler\Domain\Tests\Fixtures\TestAggregate::create($id);

            expect($aggregate->id())->toBe($id->toString());
            expect($aggregate->version())->toBe(1);
            expect($aggregate->hasUncommittedEvents())->toBeTrue();

            // Pull events (destructive)
            $events = $aggregate->pullDomainEvents();
            expect($events->count())->toBe(1);
            expect($events->first()->eventType)->toBe('TestAggregateCreated');
            expect($aggregate->hasUncommittedEvents())->toBeFalse();

            // toArray includes id, version, type
            $array = $aggregate->toArray();
            expect($array)->toHaveKeys(['id', 'version', 'type']);
            expect($array['type'])->toBe('TestAggregate');
        });

        it('applies domain events and increments version', function (): void {
            $id = \ZeroBoiler\Domain\AggregateRootId::generate();
            $aggregate = \ZeroBoiler\Domain\Tests\Fixtures\TestAggregate::create($id);
            $aggregate->pullDomainEvents(); // Clear initial events

            $aggregate->rename('New Name');

            expect($aggregate->version())->toBe(2);
            expect($aggregate->name)->toBe('New Name');
            expect($aggregate->nameChanged)->toBeTrue();

            // Peek events without consuming
            $peeked = $aggregate->peekDomainEvents();
            expect($peeked->count())->toBe(1);
            expect($peeked->first()->eventType)->toBe('TestAggregateRenamed');
            expect($aggregate->hasUncommittedEvents())->toBeTrue();

            // Events still available after peek
            $pulled = $aggregate->pullDomainEvents();
            expect($pulled->count())->toBe(1);
            expect($aggregate->hasUncommittedEvents())->toBeFalse();
        });

        it('supports identity equality', function (): void {
            $id = \ZeroBoiler\Domain\AggregateRootId::generate();
            $a = new class ($id) extends \ZeroBoiler\Domain\AggregateRoot {};
            $b = new class ($id) extends \ZeroBoiler\Domain\AggregateRoot {};

            // Different anonymous classes — not equal
            // Use TestAggregate for same-class equality
            $c = \ZeroBoiler\Domain\Tests\Fixtures\TestAggregate::create($id);
            $d = \ZeroBoiler\Domain\Tests\Fixtures\TestAggregate::create($id);

            // Same ID, same class → equal
            expect($c->id())->toBe($d->id());
            expect($c->equals($d))->toBeTrue();
        });

        it('serializes to JSON via JsonSerializable', function (): void {
            $id = \ZeroBoiler\Domain\AggregateRootId::generate();
            $aggregate = \ZeroBoiler\Domain\Tests\Fixtures\TestAggregate::create($id);

            $json = json_encode($aggregate);
            expect($json)->toBeJson();
            $data = json_decode($json, true);
            expect($data['id'])->toBe($id->toString());
            expect($data['version'])->toBe(1);
        });
    });

    describe('UnitOfWork transactional boundaries', function (): void {
        it('run() auto-commits on success and dispatches events', function (): void {
            $uow = new \ZeroBoiler\Domain\InMemoryUnitOfWork;

            $dispatched = [];
            $uow->setEventDispatcher(function (object $event) use (&$dispatched): void {
                $dispatched[] = $event;
            });

            $id = \ZeroBoiler\Domain\AggregateRootId::generate();
            $result = $uow->run(function () use ($id): \ZeroBoiler\Domain\Tests\Fixtures\TestAggregate {
                $aggregate = \ZeroBoiler\Domain\Tests\Fixtures\TestAggregate::create($id);
                $aggregate->rename('Test');

                return $aggregate;
            });

            expect($result)->toBeInstanceOf(\ZeroBoiler\Domain\Tests\Fixtures\TestAggregate::class);
            expect($result->name)->toBe('Test');
            expect(count($dispatched))->toBe(2); // created + renamed
            expect($uow->isActive())->toBeFalse();
        });

        it('run() auto-rolls back on exception', function (): void {
            $uow = new \ZeroBoiler\Domain\InMemoryUnitOfWork;

            $dispatched = [];
            $uow->setEventDispatcher(function (object $event) use (&$dispatched): void {
                $dispatched[] = $event;
            });

            try {
                $uow->run(function () {
                    $id = \ZeroBoiler\Domain\AggregateRootId::generate();
                    $aggregate = \ZeroBoiler\Domain\Tests\Fixtures\TestAggregate::create($id);
                    $aggregate->rename('Test');

                    throw new \RuntimeException('Intentional failure');
                });
            } catch (\RuntimeException) {
                // Expected
            }

            expect($dispatched)->toBeEmpty();
            expect($uow->isActive())->toBeFalse();
            expect($uow->hasPendingEvents())->toBeFalse();
        });

        it('supports nested run() via savepoints', function (): void {
            $uow = new \ZeroBoiler\Domain\InMemoryUnitOfWork;

            $allDispatched = [];
            $uow->setEventDispatcher(function (object $event) use (&$allDispatched): void {
                $allDispatched[] = $event;
            });

            $ids = [];
            for ($i = 0; $i < 3; $i++) {
                $ids[] = \ZeroBoiler\Domain\AggregateRootId::generate();
            }

            $uow->run(function () use ($uow, $ids): void {
                // Outer scope: create first aggregate
                $a = \ZeroBoiler\Domain\Tests\Fixtures\TestAggregate::create($ids[0]);
                $uow->track($a);

                // Inner scope: create second aggregate
                $uow->run(function () use ($uow, $ids): void {
                    $b = \ZeroBoiler\Domain\Tests\Fixtures\TestAggregate::create($ids[1]);
                    $uow->track($b);
                });
            });

            // All events dispatched after outermost commit
            expect(count($allDispatched))->toBe(2);
            expect($uow->isActive())->toBeFalse();
        });

        it('tracks and marks for deletion', function () {
            $uow = new \ZeroBoiler\Domain\InMemoryUnitOfWork;

            $persisted = [];
            $deleted = [];
            $uow->setPersistenceCallback(function (array $committed, array $deletedAggs) use (&$persisted, &$deleted): void {
                $persisted = $committed;
                $deleted = $deletedAggs;
            });

            $id1 = \ZeroBoiler\Domain\AggregateRootId::generate();
            $id2 = \ZeroBoiler\Domain\AggregateRootId::generate();

            $uow->run(function () use ($uow, $id1, $id2): void {
                $a = \ZeroBoiler\Domain\Tests\Fixtures\TestAggregate::create($id1);
                $b = \ZeroBoiler\Domain\Tests\Fixtures\TestAggregate::create($id2);
                $uow->track($a);
                $uow->markForDeletion($b);
            });

            expect(count($persisted))->toBe(2); // Both tracked
            expect(count($deleted))->toBe(1); // One deleted
        });

        it('supports manual begin/commit/rollback', function () {
            $uow = new \ZeroBoiler\Domain\InMemoryUnitOfWork;

            $dispatched = [];
            $uow->setEventDispatcher(function (object $event) use (&$dispatched): void {
                $dispatched[] = $event;
            });

            $id = \ZeroBoiler\Domain\AggregateRootId::generate();

            $uow->begin();
            $aggregate = \ZeroBoiler\Domain\Tests\Fixtures\TestAggregate::create($id);
            $uow->track($aggregate);
            expect($uow->isActive())->toBeTrue();
            expect($uow->isTracking($aggregate))->toBeTrue();

            $uow->commit();
            expect($uow->isActive())->toBeFalse();
            expect(count($dispatched))->toBe(1);
        });
    });

    describe('DomainEventCollection', function (): void {
        it('supports functional operations (each, reduce, some, none, find, types)', function () {
            $events = [
                \ZeroBoiler\Events\Domain\DomainEvent::occur('order.placed', ['id' => '1']),
                \ZeroBoiler\Events\Domain\DomainEvent::occur('order.paid', ['amount' => 100]),
                \ZeroBoiler\Events\Domain\DomainEvent::occur('order.shipped', ['tracking' => 'X']),
            ];

            $collection = new \ZeroBoiler\Domain\DomainEventCollection($events);

            // each (fluent)
            $seen = [];
            $result = $collection->each(function (\ZeroBoiler\Events\Domain\DomainEvent $event, int $index) use (&$seen): void {
                $seen[] = $event->eventType;
            });
            expect($result)->toBe($collection); // Fluent return
            expect($seen)->toBe(['order.placed', 'order.paid', 'order.shipped']);

            // reduce
            $total = $collection->reduce(
                fn (float $sum, \ZeroBoiler\Events\Domain\DomainEvent $event): float => $sum + ($event->payload['amount'] ?? 0),
                0.0,
            );
            expect($total)->toBe(100.0);

            // some / none
            expect($collection->some(fn (\ZeroBoiler\Events\Domain\DomainEvent $e): bool => $e->eventType === 'order.paid'))->toBeTrue();
            expect($collection->none(fn (\ZeroBoiler\Events\Domain\DomainEvent $e): bool => $e->eventType === 'order.refunded'))->toBeTrue();

            // find
            $paid = $collection->find(fn (\ZeroBoiler\Events\Domain\DomainEvent $e): bool => $e->eventType === 'order.paid');
            expect($paid)->not->toBeNull();
            expect($paid->eventType)->toBe('order.paid');

            // hasType
            expect($collection->hasType('order.placed'))->toBeTrue();
            expect($collection->hasType('order.cancelled'))->toBeFalse();

            // countBy
            expect($collection->countBy(fn (\ZeroBoiler\Events\Domain\DomainEvent $e): bool => str_starts_with($e->eventType, 'order.')))->toBe(3);

            // types
            expect($collection->types())->toBe(['order.placed', 'order.paid', 'order.shipped']);

            // filter + merge
            $paid = $collection->filter(fn (\ZeroBoiler\Events\Domain\DomainEvent $e): bool => $e->eventType === 'order.paid');
            expect($paid->count())->toBe(1);

            $extra = \ZeroBoiler\Events\Domain\DomainEvent::occur('order.refunded', []);
            $merged = $collection->merge($paid)->merge([$extra]);
            expect($merged->count())->toBe(4);

            // JSON serialization
            $json = json_encode($collection);
            expect($json)->toBeJson();
            $decoded = json_decode($json, true);
            expect(count($decoded))->toBe(3);
        });

        it('round-trips through toArray/fromArray', function () {
            $events = [
                \ZeroBoiler\Events\Domain\DomainEvent::occur('test.event', ['key' => 'value']),
            ];
            $original = new \ZeroBoiler\Domain\DomainEventCollection($events);
            $array = $original->toArray();
            $restored = \ZeroBoiler\Domain\DomainEventCollection::fromArray($array);

            expect($restored->count())->toBe(1);
            expect($restored->first()->eventType)->toBe('test.event');
        });

        it('fromArray rejects non-sequential arrays', function () {
            \ZeroBoiler\Domain\DomainEventCollection::fromArray(['key' => 'value']);
        })->throws(\InvalidArgumentException::class);
    });

    describe('Identifier types', function () {
        it('UuidIdentifier generates, validates, and round-trips', function () {
            $id = \ZeroBoiler\Domain\Tests\Fixtures\TestUuidIdentifier::generate();

            expect(\ZeroBoiler\Domain\Identifiers\UuidIdentifier::isValid($id->toString()))->toBeTrue();
            expect($id->equals(\ZeroBoiler\Domain\Tests\Fixtures\TestUuidIdentifier::fromString($id->toString())))->toBeTrue();

            // toArray/fromArray round-trip
            $array = $id->toArray();
            $restored = \ZeroBoiler\Domain\Tests\Fixtures\TestUuidIdentifier::fromArray($array);
            expect($id->equals($restored))->toBeTrue();

            // JSON round-trip
            $json = json_encode($id);
            $fromId = \ZeroBoiler\Domain\Tests\Fixtures\TestUuidIdentifier::fromJson($json);
            expect($id->equals($fromId))->toBeTrue();
        });

        it('UlidIdentifier generates monotonic and round-trips', function () {
            $id = \ZeroBoiler\Domain\Tests\Fixtures\TestUlidIdentifier::generate();

            expect(\ZeroBoiler\Domain\Identifiers\UlidIdentifier::isValid($id->toString()))->toBeTrue();
            expect($id->toUlid())->toBeInstanceOf(\Symfony\Component\Uid\Ulid::class);

            // Array round-trip
            $restored = \ZeroBoiler\Domain\Tests\Fixtures\TestUlidIdentifier::fromArray($id->toArray());
            expect($id->equals($restored))->toBeTrue();

            // JSON round-trip
            $fromId = \ZeroBoiler\Domain\Tests\Fixtures\TestUlidIdentifier::fromJson(json_encode($id));
            expect($id->equals($fromId))->toBeTrue();
        });

        it('StringIdentifier validates non-empty and round-trips', function () {
            $id = \ZeroBoiler\Domain\Identifiers\StringIdentifier::from('my-slug');

            expect($id->toString())->toBe('my-slug');
            expect(\ZeroBoiler\Domain\Identifiers\StringIdentifier::isValid('valid'))->toBeTrue();
            expect(\ZeroBoiler\Domain\Identifiers\StringIdentifier::isValid(''))->toBeFalse();

            // Empty throws
            expect(fn () => \ZeroBoiler\Domain\Identifiers\StringIdentifier::from(''))->toThrow(\ValueError::class);

            // Round-trip
            $restored = \ZeroBoiler\Domain\Identifiers\StringIdentifier::fromArray($id->toArray());
            expect($id->equals($restored))->toBeTrue();
        });

        it('IntegerIdentifier validates and round-trips', function () {
            $id = \ZeroBoiler\Domain\Identifiers\IntegerIdentifier::from(42);

            expect($id->toInt())->toBe(42);
            expect($id->toString())->toBe('42');
            expect($id->jsonSerialize())->toBe(42);

            // fromString
            $parsed = \ZeroBoiler\Domain\Identifiers\IntegerIdentifier::fromString('99');
            expect($parsed->toInt())->toBe(99);

            // Round-trip
            $restored = \ZeroBoiler\Domain\Identifiers\IntegerIdentifier::fromArray($id->toArray());
            expect($id->equals($restored))->toBeTrue();

            // fromArray with 'id' key
            $fromId = \ZeroBoiler\Domain\Identifiers\IntegerIdentifier::fromArray(['id' => 42]);
            expect($fromId->toInt())->toBe(42);
        });

        it('different identifier types are never equal', function () {
            $uuid = \ZeroBoiler\Domain\Tests\Fixtures\TestUuidIdentifier::generate();
            $ulid = \ZeroBoiler\Domain\Tests\Fixtures\TestUlidIdentifier::generate();
            $str = \ZeroBoiler\Domain\Identifiers\StringIdentifier::from('test');
            $int = \ZeroBoiler\Domain\Identifiers\IntegerIdentifier::from(1);

            // Different types → not equal (even with same value conceptually)
            expect($uuid->equals($ulid))->toBeFalse();
            expect($str->equals($int))->toBeFalse();
        });
    });

    describe('Snapshot serialization', function () {
        it('creates, serializes, and restores snapshots', function () {
            $snapshot = \ZeroBoiler\Domain\Snapshots\Snapshot::create(
                'Order',
                'uuid-123',
                50,
                ['status' => 'paid', 'total' => 100.0],
            );

            // toArray round-trip
            $array = $snapshot->toArray();
            expect($array)->toHaveKeys(['aggregate_type', 'aggregate_id', 'version', 'state', 'created_at']);
            $restored = \ZeroBoiler\Domain\Snapshots\Snapshot::fromArray($array);
            expect($snapshot->equals($restored))->toBeTrue();
            expect($restored->version)->toBe(50);
            expect($restored->state['status'])->toBe('paid');

            // JSON round-trip
            $json = json_encode($snapshot);
            $fromJson = \ZeroBoiler\Domain\Snapshots\Snapshot::fromJson($json);
            expect($snapshot->equals($fromJson))->toBeTrue();

            // JsonSerializable
            $encoded = json_encode($snapshot);
            expect($encoded)->toBeJson();
        });

        it('InMemorySnapshotStore CRUD operations', function () {
            $store = new \ZeroBoiler\Domain\Snapshots\InMemorySnapshotStore();

            $s1 = \ZeroBoiler\Domain\Snapshots\Snapshot::create('Order', 'id-1', 10, []);
            $s2 = \ZeroBoiler\Domain\Snapshots\Snapshot::create('Product', 'id-2', 5, []);

            $store->save($s1);
            $store->save($s2);

            expect($store->has('Order', 'id-1'))->toBeTrue();
            expect($store->has('Product', 'id-1'))->toBeFalse();
            expect($store->count())->toBe(2);
            expect($store->count('Order'))->toBe(1);

            $loaded = $store->load('Order', 'id-1');
            expect($loaded)->not->toBeNull();
            expect($loaded->equals($s1))->toBeTrue();

            // Stats
            $stats = $store->stats();
            expect($stats['total'])->toBe(2);
            expect($stats['by_type']['Order'])->toBe(1);

            // Delete
            $store->delete('Order', 'id-1');
            expect($store->has('Order', 'id-1'))->toBeFalse();
            expect($store->count())->toBe(1);

            // Purge by type
            $removed = $store->purge('Product');
            expect($removed)->toBe(1);
            expect($store->count())->toBe(0);
        });
    });

    describe('Domain exception hierarchy', function () {
        it('all exceptions have correct error codes and serialization', function () {
            $exceptions = [
                'InvalidState' => \ZeroBoiler\Domain\Exceptions\InvalidStateDomainException::because('State violation'),
                'InvalidArgument' => \ZeroBoiler\Domain\Exceptions\InvalidArgumentDomainException::because('Arg violation'),
                'NotFound' => \ZeroBoiler\Domain\Exceptions\NotFoundDomainException::because('Not found'),
                'Conflict' => \ZeroBoiler\Domain\Exceptions\ConflictDomainException::because('Conflict'),
                'AggregateNotFound' => \ZeroBoiler\Domain\Exceptions\AggregateNotFoundException::for('Order', 'id-1'),
                'InvalidAggregate' => \ZeroBoiler\Domain\Exceptions\InvalidAggregateRootException::notAnAggregate(new \stdClass),
            ];

            foreach ($exceptions as $expectedSuffix => $exception) {
                $code = $exception->errorCode();
                expect($code)->toBeString()->toContain($expectedSuffix);

                // toErrorArray
                $errorArray = $exception->toErrorArray();
                expect($errorArray)->toHaveKeys(['title', 'detail', 'code']);
                expect($errorArray['code'])->toBe($code);

                // JSON serialization
                $json = json_encode($exception);
                expect($json)->toBeJson();
                $decoded = json_decode($json, true);
                expect($decoded['code'])->toBe($code);
            }
        });

        it('OptimisticLockException has structured message', function () {
            $e = \ZeroBoiler\Domain\Exceptions\OptimisticLockException::for(
                'id-1',
                expectedVersion: 5,
                actualVersion: 3,
            );

            expect($e->getMessage())->toContain('5');
            expect($e->getMessage())->toContain('3');
            expect($e->errorCode())->toBe('OPTIMISTIC_LOCK');
        });

        it('supports custom error codes', function () {
            $e = \ZeroBoiler\Domain\Exceptions\InvalidStateDomainException::because(
                'Custom violation',
                code: 'CUSTOM_CODE',
            );

            expect($e->errorCode())->toBe('CUSTOM_CODE');
        });

        it('round-trips through toArray/fromArray', function () {
            $original = \ZeroBoiler\Domain\Exceptions\InvalidStateDomainException::because('Test message');
            $array = $original->toArray();
            $restored = \ZeroBoiler\Domain\Exceptions\DomainException::fromArray(
                $array,
                \ZeroBoiler\Domain\Exceptions\InvalidStateDomainException::class,
            );

            expect($restored->getMessage())->toBe('Test message');
            expect($restored->errorCode())->toBe('INVALID_STATE');
        });

        it('round-trips through JSON', function () {
            $original = \ZeroBoiler\Domain\Exceptions\InvalidArgumentDomainException::because('Arg error');
            $json = json_encode($original);
            $restored = \ZeroBoiler\Domain\Exceptions\DomainException::fromJson(
                $json,
                \ZeroBoiler\Domain\Exceptions\InvalidArgumentDomainException::class,
            );

            expect($restored->getMessage())->toBe('Arg error');
            expect($restored->errorCode())->toBe('INVALID_ARGUMENT');
        });
    });

    describe('Entity base class', function () {
        it('supports string IDs and equality', function () {
            $e1 = new \ZeroBoiler\Domain\Tests\Fixtures\TestEntity('abc');
            $e2 = new \ZeroBoiler\Domain\Tests\Fixtures\TestEntity('abc');
            $e3 = new \ZeroBoiler\Domain\Tests\Fixtures\TestEntity('xyz');

            expect($e1->id())->toBe('abc');
            expect($e1->equals($e2))->toBeTrue();
            expect($e1->equals($e3))->toBeFalse();
        });

        it('supports integer IDs', function () {
            $e = new \ZeroBoiler\Domain\Tests\Fixtures\TestEntity(42);
            expect($e->id())->toBe('42');
        });

        it('toArray includes id and type', function () {
            $e = new \ZeroBoiler\Domain\Tests\Fixtures\TestEntity('test-id');
            $array = $e->toArray();

            expect($array['id'])->toBe('test-id');
            expect($array['type'])->toBe('TestEntity');
        });

        it('supports JSON serialization', function () {
            $e = new \ZeroBoiler\Domain\Tests\Fixtures\TestEntity('json-test');
            $json = json_encode($e);
            $data = json_decode($json, true);

            expect($data['id'])->toBe('json-test');
            expect($data['type'])->toBe('TestEntity');
        });
    });
});
