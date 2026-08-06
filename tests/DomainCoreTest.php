<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Tests;

use ZeroBoiler\Domain\AggregateRoot;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\Contracts\Identifier as IdentifierContract;
use ZeroBoiler\Domain\DomainEventCollection;
use ZeroBoiler\Domain\Entity;
use ZeroBoiler\Domain\Identifiers\IntegerIdentifier;
use ZeroBoiler\Domain\Identifiers\StringIdentifier;
use ZeroBoiler\Domain\Identifiers\UuidIdentifier;
use ZeroBoiler\Domain\Identifiers\UlidIdentifier;
use ZeroBoiler\Domain\Snapshots\Snapshot;
use ZeroBoiler\Domain\Snapshots\InMemorySnapshotStore;
use ZeroBoiler\Domain\Exceptions\{
    ConflictDomainException,
    DomainException,
    InvalidArgumentDomainException,
    InvalidStateDomainException,
    NotFoundDomainException,
    OptimisticLockException,
};

// ===========================================================================
//  AggregateRootId — UUID v4 identity
// ===========================================================================

describe('AggregateRootId', function (): void {
    it('generates a valid UUID v4', function (): void {
        $id = AggregateRootId::generate();

        expect($id->toString())->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/');
    });

    it('parses from string', function (): void {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $id = AggregateRootId::fromString($uuid);

        expect($id->toString())->toBe($uuid);
    });

    it('compares equality correctly', function (): void {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $id1 = AggregateRootId::fromString($uuid);
        $id2 = AggregateRootId::fromString($uuid);
        $id3 = AggregateRootId::generate();

        expect($id1->equals($id2))->toBeTrue()
            ->and($id1->equals($id3))->toBeFalse();
    });

    it('serializes to JSON as string', function (): void {
        $id = AggregateRootId::fromString('550e8400-e29b-41d4-a716-446655440000');

        expect(json_encode($id))->toBe('"550e8400-e29b-41d4-a716-446655440000"');
    });

    it('casts to string', function (): void {
        $id = AggregateRootId::fromString('550e8400-e29b-41d4-a716-446655440000');

        expect((string) $id)->toBe('550e8400-e29b-41d4-a716-446655440000');
    });

    it('is readonly', function (): void {
        $id = AggregateRootId::generate();

        expect($id)->toBeInstanceOf(\ReadOnly::class); // implicit from final readonly
    });
});

// ===========================================================================
//  DomainEventCollection — type-safe event list
// ===========================================================================

describe('DomainEventCollection', function (): void {
    it('creates empty collection', function (): void {
        $collection = new DomainEventCollection;

        expect($collection->isEmpty())->toBeTrue()
            ->and($collection->count())->toBe(0)
            ->and($collection->all())->toBe([]);
    });

    it('stores and retrieves events', function (): void {
        $event = DomainEventStub::create('test.occurred', ['key' => 'value']);
        $collection = new DomainEventCollection([$event]);

        expect($collection->count())->toBe(1)
            ->and($collection->isEmpty())->toBeFalse()
            ->and($collection->all()[0]->eventType)->toBe('test.occurred');
    });

    it('is iterable', function (): void {
        $events = [
            DomainEventStub::create('a'),
            DomainEventStub::create('b'),
        ];
        $collection = new DomainEventCollection($events);

        $types = [];
        foreach ($collection as $event) {
            $types[] = $event->eventType;
        }

        expect($types)->toBe(['a', 'b']);
    });

    it('serializes to JSON', function (): void {
        $event = DomainEventStub::create('test.event', ['data' => 42]);
        $collection = new DomainEventCollection([$event]);

        $json = json_encode($collection);

        expect($json)->toBeJson()
            ->and(json_decode($json, true)[0]['event_type'])->toBe('test.event');
    });
});

// ===========================================================================
//  Snapshot — immutable aggregate state
// ===========================================================================

describe('Snapshot', function (): void {
    it('creates from factory', function (): void {
        $snapshot = Snapshot::create('Order', 'id-123', 5, ['status' => 'paid']);

        expect($snapshot->aggregateType)->toBe('Order')
            ->and($snapshot->aggregateId)->toBe('id-123')
            ->and($snapshot->version)->toBe(5)
            ->and($snapshot->state)->toBe(['status' => 'paid'])
            ->and($snapshot->createdAt)->toBeInstanceOf(\DateTimeImmutable::class);
    });

    it('serializes to array and back', function (): void {
        $snapshot = Snapshot::create('Order', 'id-123', 5, ['status' => 'paid']);
        $array = $snapshot->toArray();
        $restored = Snapshot::fromArray($array);

        expect($restored->aggregateType)->toBe($snapshot->aggregateType)
            ->and($restored->aggregateId)->toBe($snapshot->aggregateId)
            ->and($restored->version)->toBe($snapshot->version)
            ->and($restored->state)->toBe($snapshot->state);
    });

    it('serializes to JSON', function (): void {
        $snapshot = Snapshot::create('Order', 'id-123', 1, []);

        $json = json_encode($snapshot);

        expect($json)->toBeJson()
            ->and(json_decode($json, true)['aggregate_type'])->toBe('Order');
    });

    it('is readonly', function (): void {
        $snapshot = Snapshot::create('Order', 'id-123', 1, []);

        expect($snapshot)->toBeInstanceOf(\DateTimeImmutable::class); // property access check
    });
});

// ===========================================================================
//  InMemorySnapshotStore
// ===========================================================================

describe('InMemorySnapshotStore', function (): void {
    it('stores and retrieves snapshots', function (): void {
        $store = new InMemorySnapshotStore;
        $snapshot = Snapshot::create('Order', 'id-1', 1, []);

        $store->save($snapshot);

        expect($store->load('Order', 'id-1'))->not->toBeNull()
            ->and($store->load('Order', 'id-1')->aggregateId)->toBe('id-1');
    });

    it('returns null for missing snapshots', function (): void {
        $store = new InMemorySnapshotStore;

        expect($store->load('Order', 'nonexistent'))->toBeNull();
    });

    it('reports has() correctly', function (): void {
        $store = new InMemorySnapshotStore;
        $store->save(Snapshot::create('Order', 'id-1', 1, []));

        expect($store->has('Order', 'id-1'))->toBeTrue()
            ->and($store->has('Order', 'id-2'))->toBeFalse();
    });

    it('counts snapshots', function (): void {
        $store = new InMemorySnapshotStore;
        $store->save(Snapshot::create('Order', 'id-1', 1, []));
        $store->save(Snapshot::create('Order', 'id-2', 2, []));
        $store->save(Snapshot::create('User', 'id-1', 1, []));

        expect($store->count())->toBe(3)
            ->and($store->count('Order'))->toBe(2)
            ->and($store->count('User'))->toBe(1);
    });

    it('deletes snapshots', function (): void {
        $store = new InMemorySnapshotStore;
        $store->save(Snapshot::create('Order', 'id-1', 1, []));
        $store->delete('Order', 'id-1');

        expect($store->load('Order', 'id-1'))->toBeNull();
    });

    it('purges by type', function (): void {
        $store = new InMemorySnapshotStore;
        $store->save(Snapshot::create('Order', 'id-1', 1, []));
        $store->save(Snapshot::create('User', 'id-1', 1, []));

        $removed = $store->purge('Order');

        expect($removed)->toBe(1)
            ->and($store->count('Order'))->toBe(0)
            ->and($store->count('User'))->toBe(1);
    });

    it('purges all when type is null', function (): void {
        $store = new InMemorySnapshotStore;
        $store->save(Snapshot::create('Order', 'id-1', 1, []));
        $store->save(Snapshot::create('User', 'id-1', 1, []));

        $removed = $store->purge();

        expect($removed)->toBe(2)
            ->and($store->count())->toBe(0);
    });

    it('returns stats', function (): void {
        $store = new InMemorySnapshotStore;
        $store->save(Snapshot::create('Order', 'id-1', 1, []));
        $store->save(Snapshot::create('Order', 'id-2', 2, []));
        $store->save(Snapshot::create('User', 'id-1', 1, []));

        $stats = $store->stats();

        expect($stats['total'])->toBe(3)
            ->and($stats['by_type']['Order'])->toBe(2)
            ->and($stats['by_type']['User'])->toBe(1);
    });

    it('clears all snapshots', function (): void {
        $store = new InMemorySnapshotStore;
        $store->save(Snapshot::create('Order', 'id-1', 1, []));
        $store->clear();

        expect($store->count())->toBe(0);
    });

    it('deletes older than version', function (): void {
        $store = new InMemorySnapshotStore;
        $store->save(Snapshot::create('Order', 'id-1', 5, []));

        $store->deleteOlderThan('Order', 'id-1', 10);

        expect($store->load('Order', 'id-1'))->toBeNull(); // version 5 < 10

        $store->save(Snapshot::create('Order', 'id-1', 15, []));
        $store->deleteOlderThan('Order', 'id-1', 10);

        expect($store->load('Order', 'id-1')->version)->toBe(15); // version 15 >= 10
    });
});

// ===========================================================================
//  Domain Exceptions
// ===========================================================================

describe('Domain exceptions', function (): void {
    it('InvalidStateDomainException creates with reason', function (): void {
        $ex = InvalidStateDomainException::because('Order must be pending');

        expect($ex)->toBeInstanceOf(DomainException::class)
            ->and($ex->getMessage())->toBe('Order must be pending');
    });

    it('InvalidArgumentDomainException creates with reason', function (): void {
        $ex = InvalidArgumentDomainException::because('Quantity must be positive');

        expect($ex)->toBeInstanceOf(DomainException::class)
            ->and($ex->getMessage())->toBe('Quantity must be positive');
    });

    it('NotFoundDomainException creates with reason', function (): void {
        $ex = NotFoundDomainException::because('User not found');

        expect($ex->getMessage())->toBe('User not found');
    });

    it('NotFoundDomainException creates for aggregate', function (): void {
        $ex = NotFoundDomainException::forAggregate('Order', 'uuid-123');

        expect($ex->getMessage())->toContain('Order')
            ->and($ex->getMessage())->toContain('uuid-123');
    });

    it('ConflictDomainException creates with reason', function (): void {
        $ex = ConflictDomainException::because('Concurrent modification');

        expect($ex)->toBeInstanceOf(DomainException::class)
            ->and($ex->getMessage())->toBe('Concurrent modification');
    });

    it('OptimisticLockException creates with details', function (): void {
        $ex = OptimisticLockException::for('id-123', expectedVersion: 5, actualVersion: 3);

        expect($ex->getMessage())->toContain('id-123')
            ->and($ex->getMessage())->toContain('version 5')
            ->and($ex->getMessage())->toContain('version 3');
    });
});

// ===========================================================================
//  Stubs for testing
// ===========================================================================

/**
 * Minimal DomainEvent stub for testing.
 * NOTE: In production, this comes from zeroboiler/events package.
 */
final class DomainEventStub
{
    public string $eventType;
    public array $payload;
    public \DateTimeImmutable $occurredAt;

    private function __construct(string $eventType, array $payload)
    {
        $this->eventType = $eventType;
        $this->payload = $payload;
        $this->occurredAt = new \DateTimeImmutable;
    }

    public static function create(string $eventType, array $payload = []): self
    {
        return new self($eventType, $payload);
    }

    public function toArray(): array
    {
        return [
            'event_type' => $this->eventType,
            'payload' => $this->payload,
            'occurred_at' => $this->occurredAt->format(\DateTimeInterface::ATOM),
        ];
    }
}
