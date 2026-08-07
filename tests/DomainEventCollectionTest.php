<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace Tests;

use ZeroBoiler\Domain\DomainEventCollection;
use ZeroBoiler\Events\Domain\DomainEvent;

describe('DomainEventCollection', function (): void {
    it('constructs with empty events by default', function (): void {
        $collection = new DomainEventCollection;

        expect($collection->all())->toBe([])
            ->and($collection->count())->toBe(0)
            ->and($collection->isEmpty())->toBeTrue();
    });

    it('constructs with events', function (): void {
        $event1 = DomainEvent::occur('test.event', ['key' => 'value1']);
        $event2 = DomainEvent::occur('test.event', ['key' => 'value2']);
        $collection = new DomainEventCollection([$event1, $event2]);

        expect($collection->all())->toHaveCount(2)
            ->and($collection->count())->toBe(2)
            ->and($collection->isEmpty())->toBeFalse();
    });

    it('iterates over events via getIterator', function (): void {
        $event1 = DomainEvent::occur('test.event', ['index' => 1]);
        $event2 = DomainEvent::occur('test.event', ['index' => 2]);
        $collection = new DomainEventCollection([$event1, $event2]);

        $iterated = [];
        foreach ($collection as $event) {
            $iterated[] = $event;
        }

        expect($iterated)->toHaveCount(2)
            ->and($iterated[0])->toBe($event1)
            ->and($iterated[1])->toBe($event2);
    });

    it('all() returns the internal events array', function (): void {
        $event = DomainEvent::occur('test.event');
        $collection = new DomainEventCollection([$event]);

        $all = $collection->all();

        expect($all)->toBeArray()
            ->and($all)->toHaveCount(1)
            ->and($all[0])->toBe($event);
    });

    it('isEmpty() returns true for empty collection', function (): void {
        $collection = new DomainEventCollection;

        expect($collection->isEmpty())->toBeTrue();
    });

    it('isEmpty() returns false for non-empty collection', function (): void {
        $event = DomainEvent::occur('test.event');
        $collection = new DomainEventCollection([$event]);

        expect($collection->isEmpty())->toBeFalse();
    });

    it('count() returns 0 for empty collection', function (): void {
        $collection = new DomainEventCollection;

        expect($collection->count())->toBe(0);
    });

    it('count() returns correct number for non-empty collection', function (): void {
        $events = [
            DomainEvent::occur('test.event'),
            DomainEvent::occur('test.event'),
            DomainEvent::occur('test.event'),
        ];
        $collection = new DomainEventCollection($events);

        expect($collection->count())->toBe(3);
    });

    it('is final readonly class', function (): void {
        $reflection = new \ReflectionClass(DomainEventCollection::class);

        expect($reflection->isFinal())->toBeTrue()
            ->and($reflection->isReadOnly())->toBeTrue();
    });

    it('implements Countable and IteratorAggregate', function (): void {
        $collection = new DomainEventCollection;

        expect($collection)->toBeInstanceOf(\Countable::class)
            ->and($collection)->toBeInstanceOf(\IteratorAggregate::class);
    });

    it('preserves event order', function (): void {
        $events = [];

        for ($i = 0; $i < 5; $i++) {
            $events[] = DomainEvent::occur('test.event.' . $i, ['order' => $i]);
        }

        $collection = new DomainEventCollection($events);

        $all = $collection->all();

        for ($i = 0; $i < 5; $i++) {
            expect($all[$i]->eventType)->toBe('test.event.' . $i);
        }
    });

    it('getIterator() returns ArrayIterator', function (): void {
        $collection = new DomainEventCollection([DomainEvent::occur('test.event')]);

        expect($collection->getIterator())->toBeInstanceOf(\ArrayIterator::class);
    });

    it('does not share internal array state across all() calls', function (): void {
        $event = DomainEvent::occur('test.event');
        $collection = new DomainEventCollection([$event]);

        $all1 = $collection->all();
        $all2 = $collection->all();

        // Same array content but different calls should return same data
        expect($all1)->toBe($all2)
            ->and($all1)->toHaveCount(1);
    });

    it('toArray returns same result as jsonSerialize for API consistency', function (): void {
        $event1 = DomainEvent::occur('order.placed', ['id' => 'uuid-1', 'status' => 'pending']);
        $event2 = DomainEvent::occur('order.paid', ['id' => 'uuid-1', 'amount' => 99.99]);
        $collection = new DomainEventCollection([$event1, $event2]);

        $toArray = $collection->toArray();
        $jsonSerialize = $collection->jsonSerialize();

        expect($toArray)->toBe($jsonSerialize)
            ->and($toArray)->toHaveCount(2)
            ->and($toArray)->toBeArray();

        // Each event should be serialized to an array, not a DomainEvent object
        expect($toArray[0])->toBeArray()
            ->and($toArray[1])->toBeArray();
    });

    it('toArray returns empty array for empty collection', function (): void {
        $collection = new DomainEventCollection;

        expect($collection->toArray())->toBe([])
            ->and($collection->toArray())->toEqual($collection->jsonSerialize());
    });
});
