<?php

declare(strict_types=1);

/**
 * Tests for DomainEventCollection.
 *
 * @covers \ZeroBoiler\Domain\DomainEventCollection
 */
describe('DomainEventCollection', function (): void {
    it('creates empty collection', function (): void {
        $collection = new \ZeroBoiler\Domain\DomainEventCollection;
        expect($collection->count())->toBe(0);
        expect($collection->isEmpty())->toBeTrue();
    });

    it('creates from array of events', function (): void {
        $events = [
            new TestDomainEvent('test.a'),
            new TestDomainEvent('test.b'),
        ];
        $collection = new \ZeroBoiler\Domain\DomainEventCollection($events);

        expect($collection->count())->toBe(2);
        expect($collection->isEmpty())->toBeFalse();
    });

    it('rejects non-list arrays', function (): void {
        expect(function (): void {
            new \ZeroBoiler\Domain\DomainEventCollection(['key' => new TestDomainEvent]);
        })->toThrow(\InvalidArgumentException::class);
    });

    it('rejects non-DomainEvent items', function (): void {
        expect(function (): void {
            new \ZeroBoiler\Domain\DomainEventCollection(['not-an-event']);
        })->toThrow(\InvalidArgumentException::class);
    });

    it('iterates over events', function (): void {
        $events = [
            new TestDomainEvent('test.a'),
            new TestDomainEvent('test.b'),
            new TestDomainEvent('test.c'),
        ];
        $collection = new \ZeroBoiler\Domain\DomainEventCollection($events);

        $types = [];
        foreach ($collection as $event) {
            $types[] = $event->eventType;
        }
        expect($types)->toBe(['test.a', 'test.b', 'test.c']);
    });

    it('returns first event', function (): void {
        $events = [
            new TestDomainEvent('test.first'),
            new TestDomainEvent('test.second'),
        ];
        $collection = new \ZeroBoiler\Domain\DomainEventCollection($events);
        expect($collection->first()->eventType)->toBe('test.first');
    });

    it('returns null first on empty', function (): void {
        $collection = new \ZeroBoiler\Domain\DomainEventCollection;
        expect($collection->first())->toBeNull();
    });

    it('returns last event', function (): void {
        $events = [
            new TestDomainEvent('test.first'),
            new TestDomainEvent('test.last'),
        ];
        $collection = new \ZeroBoiler\Domain\DomainEventCollection($events);
        expect($collection->last()->eventType)->toBe('test.last');
    });

    it('returns null last on empty', function (): void {
        $collection = new \ZeroBoiler\Domain\DomainEventCollection;
        expect($collection->last())->toBeNull();
    });

    it('filters events by predicate', function (): void {
        $events = [
            new TestDomainEvent('test.a'),
            new TestDomainEvent('test.b'),
            new TestDomainEvent('test.a'),
        ];
        $collection = new \ZeroBoiler\Domain\DomainEventCollection($events);

        $filtered = $collection->filter(
            fn (\ZeroBoiler\Events\Domain\DomainEvent $e): bool => $e->eventType === 'test.a'
        );

        expect($filtered->count())->toBe(2);
        expect($filtered->first()->eventType)->toBe('test.a');
    });

    it('maps events to scalars', function (): void {
        $events = [
            new TestDomainEvent('test.a'),
            new TestDomainEvent('test.b'),
        ];
        $collection = new \ZeroBoiler\Domain\DomainEventCollection($events);

        $types = $collection->map(fn ($e) => $e->eventType);
        expect($types)->toBe(['test.a', 'test.b']);
    });

    it('merges with another collection', function (): void {
        $a = new \ZeroBoiler\Domain\DomainEventCollection([new TestDomainEvent('test.a')]);
        $b = new \ZeroBoiler\Domain\DomainEventCollection([new TestDomainEvent('test.b')]);

        $merged = $a->merge($b);
        expect($merged->count())->toBe(2);
    });

    it('gets event by index', function (): void {
        $events = [
            new TestDomainEvent('test.a'),
            new TestDomainEvent('test.b'),
        ];
        $collection = new \ZeroBoiler\Domain\DomainEventCollection($events);

        expect($collection->get(0)->eventType)->toBe('test.a');
        expect($collection->get(1)->eventType)->toBe('test.b');
        expect($collection->get(99))->toBeNull();
    });

    it('returns all events as array', function (): void {
        $events = [
            new TestDomainEvent('test.a'),
            new TestDomainEvent('test.b'),
        ];
        $collection = new \ZeroBoiler\Domain\DomainEventCollection($events);
        $all = $collection->all();

        expect(count($all))->toBe(2);
        expect($all[0])->toBeInstanceOf(\ZeroBoiler\Events\Domain\DomainEvent::class);
    });

    it('implements JsonSerializable', function (): void {
        $collection = new \ZeroBoiler\Domain\DomainEventCollection([
            new TestDomainEvent('test.json'),
        ]);

        $json = json_encode($collection);
        expect($json)->toBeString()->toBeJson();
    });
});
