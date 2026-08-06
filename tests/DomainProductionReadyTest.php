<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Domain\AggregateRoot;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\Concerns\EventSourced;
use ZeroBoiler\Domain\Concerns\HasSnapshots;
use ZeroBoiler\Domain\DomainEventCollection;
use ZeroBoiler\Domain\Entity;
use ZeroBoiler\Domain\Identifiers\IntegerIdentifier;
use ZeroBoiler\Domain\Identifiers\StringIdentifier;
use ZeroBoiler\Domain\Identifiers\UuidIdentifier;
use ZeroBoiler\Domain\Identifiers\UlidIdentifier;
use ZeroBoiler\Domain\Snapshots\Snapshot;
use ZeroBoiler\Domain\ValueObject;
use ZeroBoiler\Events\Domain\DomainEvent;

beforeEach(function (): void {
    $this->id = AggregateRootId::generate();
});

describe('AggregateRoot::reconstituteFromSnapshot', function (): void {
    it('reconstitutes an aggregate from a snapshot', function (): void {
        $snapshot = Snapshot::create(
            aggregateType: TestReconstitutableAggregate::class,
            aggregateId: $this->id->toString(),
            version: 5,
            state: ['name' => 'Restored Order', 'total' => 99.99],
        );

        $aggregate = TestReconstitutableAggregate::reconstituteFromSnapshot($snapshot);

        expect($aggregate)
            ->toBeInstanceOf(TestReconstitutableAggregate::class)
            ->id()->toBe($this->id->toString())
            ->version()->toBe(5)
            ->name->toBe('Restored Order')
            ->total->toBe(99.99);
    });

    it('replays post-snapshot events after restoration', function (): void {
        $snapshot = Snapshot::create(
            aggregateType: TestReconstitutableAggregate::class,
            aggregateId: $this->id->toString(),
            version: 2,
            state: ['name' => 'Initial', 'total' => 10.0],
        );

        $event1 = DomainEvent::occur('test.item_added', ['item' => 'Widget', 'price' => 5.0]);
        $event2 = DomainEvent::occur('test.item_added', ['item' => 'Gadget', 'price' => 3.0]);

        $aggregate = TestReconstitutableAggregate::reconstituteFromSnapshot(
            $snapshot,
            [$event1, $event2],
        );

        expect($aggregate)
            ->version()->toBe(4) // 2 (snapshot) + 2 (events)
            ->name->toBe('Initial')
            ->total->toBe(18.0); // 10 + 5 + 3
    });

    it('throws if class does not extend AggregateRoot', function (): void {
        $snapshot = Snapshot::create(
            aggregateType: 'stdClass',
            aggregateId: $this->id->toString(),
            version: 1,
            state: [],
        );

        expect(fn (): mixed => AggregateRoot::reconstituteFromSnapshot($snapshot))
            ->toThrow(\RuntimeException::class);
    });

    it('clears domain events after reconstitution', function (): void {
        $snapshot = Snapshot::create(
            aggregateType: TestReconstitutableAggregate::class,
            aggregateId: $this->id->toString(),
            version: 1,
            state: ['name' => 'Clean', 'total' => 0],
        );

        $aggregate = TestReconstitutableAggregate::reconstituteFromSnapshot($snapshot);

        expect($aggregate->hasUncommittedEvents())->toBeFalse();
    });
});

describe('DomainEventCollection utilities', function (): void {
    it('filters events by predicate', function (): void {
        $e1 = DomainEvent::occur('order.created', ['id' => 1]);
        $e2 = DomainEvent::occur('order.updated', ['id' => 1]);
        $e3 = DomainEvent::occur('order.item_added', ['id' => 1]);

        $collection = new DomainEventCollection([$e1, $e2, $e3]);
        $filtered = $collection->filter(
            fn (DomainEvent $e): bool => str_starts_with($e->eventType, 'order.'),
        );

        expect($filtered)
            ->count()->toBe(3);

        $filtered = $collection->filter(
            fn (DomainEvent $e): bool => $e->eventType === 'order.created',
        );

        expect($filtered)
            ->count()->toBe(1)
            ->first()->eventType->toBe('order.created');
    });

    it('maps events with index', function (): void {
        $e1 = DomainEvent::occur('order.created', ['id' => 1]);
        $e2 = DomainEvent::occur('order.shipped', ['id' => 1]);

        $collection = new DomainEventCollection([$e1, $e2]);
        $types = $collection->map(fn (DomainEvent $e, int $i): string => "{$i}:{$e->eventType}");

        expect($types)->toBe(['0:order.created', '1:order.shipped']);
    });

    it('returns first event with and without predicate', function (): void {
        $e1 = DomainEvent::occur('order.created', []);
        $e2 = DomainEvent::occur('order.shipped', []);
        $collection = new DomainEventCollection([$e1, $e2]);

        expect($collection->first())->toBe($e1);
        expect($collection->first(fn (DomainEvent $e): bool => $e->eventType === 'order.shipped'))->toBe($e2);
        expect($collection->first(fn (DomainEvent $e): bool => $e->eventType === 'order.cancelled'))->toBeNull();
    });

    it('returns last event', function (): void {
        $e1 = DomainEvent::occur('order.created', []);
        $e2 = DomainEvent::occur('order.shipped', []);
        $collection = new DomainEventCollection([$e1, $e2]);

        expect($collection->last())->toBe($e2);
    });

    it('merges two collections', function (): void {
        $e1 = DomainEvent::occur('order.created', []);
        $e2 = DomainEvent::occur('order.shipped', []);
        $e3 = DomainEvent::occur('order.delivered', []);

        $c1 = new DomainEventCollection([$e1]);
        $c2 = new DomainEventCollection([$e2, $e3]);
        $merged = $c1->merge($c2);

        expect($merged)
            ->count()->toBe(3)
            ->first()->eventType->toBe('order.created')
            ->last()->eventType->toBe('order.delivered');
    });

    it('gets event by index', function (): void {
        $e1 = DomainEvent::occur('order.created', []);
        $e2 = DomainEvent::occur('order.shipped', []);
        $collection = new DomainEventCollection([$e1, $e2]);

        expect($collection->get(0))->toBe($e1);
        expect($collection->get(1))->toBe($e2);
        expect($collection->get(2))->toBeNull();
    });

    it('returns empty for empty collection', function (): void {
        $collection = new DomainEventCollection;

        expect($collection->isEmpty())->toBeTrue();
        expect($collection->first())->toBeNull();
        expect($collection->last())->toBeNull();
        expect($collection->count())->toBe(0);
    });
});

describe('Identifier immutability', function (): void {
    it('UuidIdentifier is readonly and validated', function (): void {
        $id = TestUuidId::generate();
        $parsed = TestUuidId::fromString($id->toString());

        expect($id->equals($parsed))->toBeTrue();
        expect($id->toString())->toBe($parsed->toString());
    });

    it('UlidIdentifier is readonly and validated', function (): void {
        $id = TestUlidId::generate();
        $parsed = TestUlidId::fromString($id->toString());

        expect($id->equals($parsed))->toBeTrue();
        expect(UlidIdentifier::isValid($id->toString()))->toBeTrue();
    });

    it('StringIdentifier rejects empty strings', function (): void {
        expect(fn (): mixed => new StringIdentifier(''))
            ->throw(ValueError::class, 'String identifier cannot be empty');
    });

    it('IntegerIdentifier serializes as integer', function (): void {
        $id = IntegerIdentifier::from(42);

        expect($id->jsonSerialize())->toBe(42);
        expect($id->toString())->toBe('42');
        expect($id->toInt())->toBe(42);
    });

    it('identifiers implement JsonSerializable', function (): void {
        $uuid = TestUuidId::generate();
        $ulid = TestUlidId::generate();
        $str = StringIdentifier::from('slug-123');
        $int = IntegerIdentifier::from(7);

        expect(json_encode($uuid))->toBeJson()
            ->and(json_encode($ulid))->toBeJson()
            ->and(json_encode($str))->toBeJson()
            ->and(json_encode($int))->toBeJson();
    });
});

// Test fixtures for reconstituteFromSnapshot

final class TestReconstitutableAggregate extends AggregateRoot
{
    use HasSnapshots;
    use EventSourced;

    public string $name = '';
    public float $total = 0.0;

    public static function create(string $name): self
    {
        $aggregate = new self(AggregateRootId::generate());
        $aggregate->name = $name;

        return $aggregate;
    }

    protected function applyTestItemAdded(DomainEvent $event): void
    {
        $this->total += (float) ($event->payload['price'] ?? 0);
    }
}

final class TestUuidId extends UuidIdentifier {}

final class TestUlidId extends UlidIdentifier {}
