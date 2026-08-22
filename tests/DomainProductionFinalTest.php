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
use ZeroBoiler\Domain\Contracts\Repository;
use ZeroBoiler\Domain\Contracts\UnitOfWork as UnitOfWorkContract;
use ZeroBoiler\Domain\DomainEventCollection;
use ZeroBoiler\Domain\Entity;
use ZeroBoiler\Domain\Identifiers\IntegerIdentifier;
use ZeroBoiler\Domain\Identifiers\StringIdentifier;
use ZeroBoiler\Domain\Identifiers\UuidIdentifier;
use ZeroBoiler\Domain\Identifiers\UlidIdentifier;
use ZeroBoiler\Domain\InMemoryUnitOfWork;
use ZeroBoiler\Domain\Snapshots\InMemorySnapshotStore;
use ZeroBoiler\Domain\Snapshots\Snapshot;
use ZeroBoiler\Domain\Snapshots\SnapshotPolicy;
use ZeroBoiler\Domain\Snapshots\SnapshottingRepository;
use ZeroBoiler\Domain\ValueObject;
use ZeroBoiler\Events\Domain\DomainEvent;
use JsonSerializable;

// ===========================================================================
//  Identifier Production Hardening
// ===========================================================================

describe('UuidIdentifier production hardening', function (): void {
    it('rejects invalid UUID in constructor', function (): void {
        expect(fn () => new class('not-a-uuid') extends UuidIdentifier {})
            ->toThrow(\Ramsey\Uuid\Exception\InvalidUuidStringException::class);
    });

    it('generates unique IDs across multiple calls', function (): void {
        $id1 = TestUuidId::generate();
        $id2 = TestUuidId::generate();

        expect($id1->equals($id2))->toBeFalse();
        expect($id1->toString())->not->toBe($id2->toString());
    });

    it('round-trips through JSON serialization', function (): void {
        $id = TestUuidId::generate();
        $json = json_encode($id);
        $restored = TestUuidId::fromString($json);

        expect($restored->equals($id))->toBeTrue();
    });

    it('provides toUuid() metadata access', function (): void {
        $id = TestUuidId::generate();
        $uuid = $id->toUuid();

        expect($uuid->getVersion())->toBe(4);
    });

    it('stringifies via __toString', function (): void {
        $id = TestUuidId::generate();
        expect((string) $id)->toBe($id->toString());
    });
});

describe('UlidIdentifier production hardening', function (): void {
    it('rejects invalid ULID in constructor', function (): void {
        expect(fn () => new class('not-a-ulid') extends UlidIdentifier {})
            ->toThrow(\InvalidArgumentException::class);
    });

    it('generates monotonic ULIDs', function (): void {
        $id1 = TestUlidId::generate();
        $id2 = TestUlidId::generate();

        // Monotonic ULIDs are lexicographically sortable
        expect($id2->toString() > $id1->toString())->toBeTrue();
    });

    it('validates ULID strings without throwing', function (): void {
        $id = TestUlidId::generate();
        expect(TestUlidId::isValid($id->toString()))->toBeTrue();
        expect(TestUlidId::isValid('invalid'))->toBeFalse();
    });

    it('round-trips through JSON serialization', function (): void {
        $id = TestUlidId::generate();
        $json = json_encode($id);
        $restored = TestUlidId::fromString($json);

        expect($restored->equals($id))->toBeTrue();
    });

    it('provides toUlid() Symfony object', function (): void {
        $id = TestUlidId::generate();
        $ulid = $id->toUlid();

        expect($ulid->isMonotonic())->toBeTrue();
    });
});

describe('StringIdentifier production hardening', function (): void {
    it('rejects empty string', function (): void {
        expect(fn () => StringIdentifier::from(''))
            ->toThrow(\ValueError::class);
    });

    it('accepts any non-empty string', function (): void {
        $id = StringIdentifier::from('my-slug-123');

        expect($id->toString())->toBe('my-slug-123');
        expect((string) $id)->toBe('my-slug-123');
    });

    it('implements JsonSerializable correctly', function (): void {
        $id = StringIdentifier::from('test-id');
        expect($id->jsonSerialize())->toBe('test-id');
    });

    it('validates strings without throwing', function (): void {
        expect(StringIdentifier::isValid('hello'))->toBeTrue();
        expect(StringIdentifier::isValid(''))->toBeFalse();
    });
});

describe('IntegerIdentifier production hardening', function (): void {
    it('stores and retrieves integer values', function (): void {
        $id = IntegerIdentifier::from(42);

        expect($id->toInt())->toBe(42);
        expect($id->toString())->toBe('42');
    });

    it('serializes as integer (not string) in JSON', function (): void {
        $id = IntegerIdentifier::from(42);
        $json = json_encode(['id' => $id]);

        expect($json)->toBe('{"id":42}');
    });

    it('handles zero', function (): void {
        $id = IntegerIdentifier::from(0);
        expect($id->toInt())->toBe(0);
    });

    it('handles negative integers', function (): void {
        $id = IntegerIdentifier::from(-5);
        expect($id->toInt())->toBe(-5);
        expect($id->toString())->toBe('-5');
    });

    it('validates string representations', function (): void {
        expect(IntegerIdentifier::isValid('42'))->toBeTrue();
        expect(IntegerIdentifier::isValid('-5'))->toBeTrue();
        expect(IntegerIdentifier::isValid('abc'))->toBeFalse();
    });
});

// ===========================================================================
//  AggregateRootId Production Hardening
// ===========================================================================

describe('AggregateRootId production hardening', function (): void {
    it('is final and readonly', function (): void {
        $reflection = new \ReflectionClass(AggregateRootId::class);

        expect($reflection->isFinal())->toBeTrue();
        expect($reflection->isReadOnly())->toBeTrue();
    });

    it('implements Stringable and JsonSerializable', function (): void {
        $id = AggregateRootId::generate();

        expect($id)->toBeInstanceOf(\Stringable::class);
        expect($id)->toBeInstanceOf(JsonSerializable::class);
    });

    it('jsonSerialize returns same as toString', function (): void {
        $id = AggregateRootId::generate();

        expect($id->jsonSerialize())->toBe($id->toString());
    });
});

// ===========================================================================
//  DomainEventCollection Production Hardening
// ===========================================================================

describe('DomainEventCollection production hardening', function (): void {
    it('rejects non-sequential arrays', function (): void {
        $event = DomainEvent::occur('test.event', []);
        $nonList = [1 => $event, 5 => $event];

        expect(fn () => new DomainEventCollection($nonList))
            ->toThrow(\InvalidArgumentException::class);
    });

    it('rejects mixed-type items', function (): void {
        $event = DomainEvent::occur('test.event', []);

        expect(fn () => new DomainEventCollection([$event, 'not-an-event']))
            ->toThrow(\InvalidArgumentException::class);
    });

    it('filter returns empty collection for no matches', function (): void {
        $event = DomainEvent::occur('order.created', []);
        $collection = new DomainEventCollection([$event]);
        $filtered = $collection->filter(fn (DomainEvent $e): bool => $e->eventType === 'order.shipped');

        expect($filtered->isEmpty())->toBeTrue();
    });

    it('merge produces combined collection', function (): void {
        $e1 = DomainEvent::occur('order.created', ['id' => '1']);
        $e2 = DomainEvent::occur('order.item_added', ['id' => '2']);
        $collection = new DomainEventCollection([$e1]);

        $merged = $collection->merge(new DomainEventCollection([$e2]));

        expect($merged->count())->toBe(2);
    });

    it('toArray returns same as jsonSerialize', function (): void {
        $event = DomainEvent::occur('order.created', ['id' => '1']);
        $collection = new DomainEventCollection([$event]);

        expect($collection->toArray())->toBe($collection->jsonSerialize());
    });

    it('map returns transformed result', function (): void {
        $e1 = DomainEvent::occur('order.created', ['id' => '1']);
        $e2 = DomainEvent::occur('order.item_added', ['id' => '2']);
        $collection = new DomainEventCollection([$e1, $e2]);

        $types = $collection->map(fn (DomainEvent $e): string => $e->eventType);

        expect($types)->toBe(['order.created', 'order.item_added']);
    });

    it('last returns last event', function (): void {
        $e1 = DomainEvent::occur('order.created', []);
        $e2 = DomainEvent::occur('order.shipped', []);
        $collection = new DomainEventCollection([$e1, $e2]);

        expect($collection->last()->eventType)->toBe('order.shipped');
    });

    it('last returns null for empty collection', function (): void {
        $collection = new DomainEventCollection;

        expect($collection->last())->toBeNull();
    });

    it('get returns event at index', function (): void {
        $e1 = DomainEvent::occur('order.created', []);
        $e2 = DomainEvent::occur('order.shipped', []);
        $collection = new DomainEventCollection([$e1, $e2]);

        expect($collection->get(1)->eventType)->toBe('order.shipped');
    });

    it('get returns null for out-of-bounds', function (): void {
        $e1 = DomainEvent::occur('order.created', []);
        $collection = new DomainEventCollection([$e1]);

        expect($collection->get(5))->toBeNull();
    });
});

// ===========================================================================
//  Snapshot Production Hardening
// ===========================================================================

describe('Snapshot production hardening', function (): void {
    it('is final and readonly', function (): void {
        $reflection = new \ReflectionClass(Snapshot::class);

        expect($reflection->isFinal())->toBeTrue();
        expect($reflection->isReadOnly())->toBeTrue();
    });

    it('round-trips through toArray/fromArray', function (): void {
        $original = Snapshot::create('Order', '550e8400-...', 50, ['status' => 'paid']);
        $restored = Snapshot::fromArray($original->toArray());

        expect($restored->aggregateType)->toBe($original->aggregateType);
        expect($restored->aggregateId)->toBe($original->aggregateId);
        expect($restored->version)->toBe($original->version);
        expect($restored->state)->toBe($original->state);
    });

    it('rejects invalid data in fromArray', function (): void {
        expect(fn () => Snapshot::fromArray(['invalid' => 'data']))
            ->toThrow(\InvalidArgumentException::class);
    });

    it('serializes createdAt as ISO string', function (): void {
        $snapshot = Snapshot::create('Order', 'id', 1, []);
        $array = $snapshot->toArray();

        expect($array['created_at'])->toBeString();
        expect(\DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $array['created_at']))
            ->toBeInstanceOf(\DateTimeImmutable::class);
    });

    it('compares equality correctly', function (): void {
        $s1 = Snapshot::create('Order', 'id', 1, ['x' => 1]);
        $s2 = Snapshot::create('Order', 'id', 1, ['x' => 1]);
        $s3 = Snapshot::create('Order', 'id', 1, ['x' => 2]);

        expect($s1->equals($s2))->toBeTrue();
        expect($s1->equals($s3))->toBeFalse();
    });

    it('jsonSerializes to toArray', function (): void {
        $snapshot = Snapshot::create('Order', 'id', 1, ['x' => 1]);

        expect($snapshot->jsonSerialize())->toBe($snapshot->toArray());
    });
});

// ===========================================================================
//  InMemorySnapshotStore Production Hardening
// ===========================================================================

describe('InMemorySnapshotStore production hardening', function (): void {
    it('deleteOlderThan removes only older snapshots', function (): void {
        $store = new InMemorySnapshotStore;
        $old = Snapshot::create('Order', 'id1', 5, []);
        $new = Snapshot::create('Order', 'id1', 20, []);

        $store->save($old);
        $store->save($new);

        // Overwrite with the new version (latest wins)
        $store->save($new);
        $store->deleteOlderThan('Order', 'id1', 10);

        expect($store->has('Order', 'id1'))->toBeTrue();
    });

    it('purge removes all snapshots', function (): void {
        $store = new InMemorySnapshotStore;
        $store->save(Snapshot::create('Order', 'id1', 1, []));
        $store->save(Snapshot::create('Order', 'id2', 1, []));

        $removed = $store->purge();
        expect($removed)->toBe(2);
        expect($store->count())->toBe(0);
    });

    it('purge by type removes only matching type', function (): void {
        $store = new InMemorySnapshotStore;
        $store->save(Snapshot::create('Order', 'id1', 1, []));
        $store->save(Snapshot::create('Product', 'id2', 1, []));

        $removed = $store->purge('Order');
        expect($removed)->toBe(1);
        expect($store->count())->toBe(1);
        expect($store->count('Order'))->toBe(0);
    });

    it('stats returns correct by_type counts', function (): void {
        $store = new InMemorySnapshotStore;
        $store->save(Snapshot::create('Order', 'id1', 1, []));
        $store->save(Snapshot::create('Order', 'id2', 1, []));
        $store->save(Snapshot::create('Product', 'id3', 1, []));

        $stats = $store->stats();

        expect($stats['total'])->toBe(3);
        expect($stats['by_type']['Order'])->toBe(2);
        expect($stats['by_type']['Product'])->toBe(1);
    });
});

// ===========================================================================
//  DomainException Hierarchy Production Hardening
// ===========================================================================

describe('DomainException hierarchy production hardening', function (): void {
    it('all exceptions extend DomainException and implement JsonSerializable', function (): void {
        $exceptions = [
            \ZeroBoiler\Domain\Exceptions\ConflictDomainException::because('test'),
            \ZeroBoiler\Domain\Exceptions\InvalidStateDomainException::because('test'),
            \ZeroBoiler\Domain\Exceptions\InvalidArgumentDomainException::because('test'),
            \ZeroBoiler\Domain\Exceptions\NotFoundDomainException::because('test'),
            \ZeroBoiler\Domain\Exceptions\AggregateNotFoundException::for('Order', 'id'),
            \ZeroBoiler\Domain\Exceptions\OptimisticLockException::for('id', 1, 2),
            \ZeroBoiler\Domain\Exceptions\InvalidAggregateRootException::notAnAggregate(new \stdClass),
        ];

        foreach ($exceptions as $exception) {
            expect($exception)->toBeInstanceOf(\ZeroBoiler\Domain\Exceptions\DomainException::class);
            expect($exception)->toBeInstanceOf(JsonSerializable::class);
            expect($exception->errorCode())->toBeString();
            expect($exception->toErrorArray())->toBeArray();
            expect($exception->jsonSerialize())->toBeArray();
        }
    });

    it('custom domain code overrides default', function (): void {
        $e = \ZeroBoiler\Domain\Exceptions\NotFoundDomainException::because('test', 'CUSTOM_CODE');
        expect($e->errorCode())->toBe('CUSTOM_CODE');
    });

    it('toErrorArray has required keys', function (): void {
        $e = \ZeroBoiler\Domain\Exceptions\InvalidStateDomainException::because('test');
        $array = $e->toErrorArray();

        expect($array)->toHaveKeys(['title', 'detail', 'code']);
    });

    it('toErrorArray title is class basename', function (): void {
        $e = \ZeroBoiler\Domain\Exceptions\InvalidStateDomainException::because('test');
        $array = $e->toErrorArray();

        expect($array['title'])->toBe('InvalidStateDomainException');
    });

    it('toArray has debug information', function (): void {
        $e = \ZeroBoiler\Domain\Exceptions\ConflictDomainException::because('test');
        $array = $e->toArray();

        expect($array)->toHaveKeys(['error_code', 'message', 'file', 'line']);
        expect($array['error_code'])->toBe('CONFLICT');
    });
});

// ===========================================================================
//  InMemoryUnitOfWork Production Hardening
// ===========================================================================

describe('InMemoryUnitOfWork production hardening', function (): void {
    it('supports nested run() calls via savepoints', function (): void {
        $uow = new InMemoryUnitOfWork;

        $result = $uow->run(function () use ($uow): mixed {
            $inner = $uow->run(function (): string {
                return 'inner';
            });

            return $inner;
        });

        expect($result)->toBe('inner');
    });

    it('rolls back nested scope independently', function (): void {
        $uow = new InMemoryUnitOfWork;
        $counter = 0;

        try {
            $uow->run(function () use ($uow, &$counter): void {
                $counter++;
                try {
                    $uow->run(function (): void {
                        throw new \RuntimeException('inner fail');
                    });
                } catch (\RuntimeException $e) {
                    // Inner scope rolled back, outer continues
                    $counter++;
                }
            });
        } catch (\RuntimeException $e) {
            $counter++;
        }

        // Outer should succeed — only inner rolled back
        expect($counter)->toBe(2);
    });

    it('dispatches events via callback on commit', function (): void {
        $uow = new InMemoryUnitOfWork;
        $dispatched = [];

        $uow->setEventDispatcher(function (DomainEvent $event) use (&$dispatched): void {
            $dispatched[] = $event->eventType;
        });

        $uow->run(function () use ($uow): void {
            $uow->queueEvent(DomainEvent::occur('test.created', []));
        });

        expect($dispatched)->toBe(['test.created']);
    });

    it('clear discards all pending events', function (): void {
        $uow = new InMemoryUnitOfWork;
        $dispatched = [];

        $uow->setEventDispatcher(function (DomainEvent $event) use (&$dispatched): void {
            $dispatched[] = $event->eventType;
        });

        $uow->begin();
        $uow->queueEvent(DomainEvent::occur('test.created', []));
        $uow->clear();

        expect($uow->hasPendingEvents())->toBeFalse();
    });

    it('persistence callback fires before event dispatch', function (): void {
        $uow = new InMemoryUnitOfWork;
        $order = [];

        $uow->setPersistenceCallback(function (array $committed, array $deleted) use (&$order): void {
            $order[] = 'persist';
        });

        $uow->setEventDispatcher(function (DomainEvent $event) use (&$order): void {
            $order[] = 'dispatch';
        });

        $uow->begin();
        $uow->queueEvent(DomainEvent::occur('test.created', []));
        $uow->commit();

        // Persistence must happen before dispatch
        expect($order)->toBe(['persist', 'dispatch']);
    });
});

// ===========================================================================
//  Helper classes for tests
// ===========================================================================

final class TestUuidId extends UuidIdentifier {}
final class TestUlidId extends UlidIdentifier {}
