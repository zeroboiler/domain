<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Domain\DomainEventCollection;
use ZeroBoiler\Events\Domain\DomainEvent;

/*
 * DomainEventCollection round-trip serialization tests.
 *
 * Tests that DomainEventCollection supports complete round-trip
 * serialization via toArray() → fromArray() for caching, queue jobs,
 * and event replay from storage.
 */

describe('DomainEventCollection round-trip', function (): void {
    it('round-trips single event via toArray/fromArray', function (): void {
        $event = DomainEvent::occur('order.placed', ['order_id' => '42']);
        $collection = new DomainEventCollection([$event]);

        $restored = DomainEventCollection::fromArray($collection->toArray());

        expect($restored)->toHaveCount(1);
        expect($restored->first()?->eventType)->toBe('order.placed');
        expect($restored->first()?->payload)->toBe(['order_id' => '42']);
    });

    it('round-trips multiple events preserving order', function (): void {
        $events = [
            DomainEvent::occur('order.placed', ['order_id' => '1']),
            DomainEvent::occur('order.item_added', ['sku' => 'ABC']),
            DomainEvent::occur('order.paid', ['amount' => 100]),
        ];
        $collection = new DomainEventCollection($events);

        $restored = DomainEventCollection::fromArray($collection->toArray());

        expect($restored)->toHaveCount(3);
        expect($restored->get(0)?->eventType)->toBe('order.placed');
        expect($restored->get(1)?->eventType)->toBe('order.item_added');
        expect($restored->get(2)?->eventType)->toBe('order.paid');
    });

    it('round-trips empty collection', function (): void {
        $collection = new DomainEventCollection;

        $restored = DomainEventCollection::fromArray($collection->toArray());

        expect($restored)->toHaveCount(0);
        expect($restored->isEmpty())->toBeTrue();
    });

    it('preserves eventId and occurredAt through round-trip', function (): void {
        $originalEvent = DomainEvent::occur('order.placed', ['order_id' => '1']);
        $originalEventId = $originalEvent->eventId->toString();
        $originalTimestamp = $originalEvent->occurredAt->format(\DateTimeImmutable::ATOM);

        $collection = new DomainEventCollection([$originalEvent]);
        $restored = DomainEventCollection::fromArray($collection->toArray());
        $restoredEvent = $restored->first();

        expect($restoredEvent?->eventId->toString())->toBe($originalEventId);
        expect($restoredEvent?->occurredAt->format(\DateTimeImmutable::ATOM))->toBe($originalTimestamp);
    });

    it('round-trips via JSON encode/decode', function (): void {
        $events = [
            DomainEvent::occur('user.registered', ['email' => 'test@example.com']),
            DomainEvent::occur('user.verified', []),
        ];
        $collection = new DomainEventCollection($events);

        $json = json_encode($collection);
        $decoded = json_decode($json, true);
        $restored = DomainEventCollection::fromArray($decoded);

        expect($restored)->toHaveCount(2);
        expect($restored->get(0)?->eventType)->toBe('user.registered');
        expect($restored->get(1)?->eventType)->toBe('user.verified');
    });

    it('fromArray rejects non-list arrays', function (): void {
        expect(function (): void {
            DomainEventCollection::fromArray(['key' => ['eventType' => 'test']]);
        })->toThrow(\InvalidArgumentException::class);
    });

    it('fromArray rejects non-array items', function (): void {
        expect(function (): void {
            DomainEventCollection::fromArray(['not-an-array', 'also-not']);
        })->toThrow(\InvalidArgumentException::class);
    });

    it('fromArray rejects items missing eventType', function (): void {
        expect(function (): void {
            DomainEventCollection::fromArray([['payload' => []]]);
        })->toThrow(\InvalidArgumentException::class);
    });
});

describe('DomainEventCollection fromArray consistency', function (): void {
    it('restored collection supports all collection operations', function (): void {
        $events = [
            DomainEvent::occur('order.placed', ['id' => 1]),
            DomainEvent::occur('order.paid', ['id' => 2]),
            DomainEvent::occur('order.shipped', ['id' => 3]),
        ];

        $restored = DomainEventCollection::fromArray(
            (new DomainEventCollection($events))->toArray(),
        );

        // filter
        $paid = $restored->filter(fn (DomainEvent $e): bool => $e->eventType === 'order.paid');
        expect($paid)->toHaveCount(1);

        // map
        $types = $restored->map(fn (DomainEvent $e): string => $e->eventType);
        expect($types)->toBe(['order.placed', 'order.paid', 'order.shipped']);

        // first/last
        expect($restored->first()?->eventType)->toBe('order.placed');
        expect($restored->last()?->eventType)->toBe('order.shipped');

        // merge
        $merged = $restored->merge([DomainEvent::occur('order.delivered', [])]);
        expect($merged)->toHaveCount(4);

        // isEmpty
        expect($restored->isEmpty())->toBeFalse();

        // all
        expect($restored->all())->toHaveCount(3);
    });

    it('toArray output is compatible with json_encode', function (): void {
        $collection = new DomainEventCollection([
            DomainEvent::occur('test.event', ['key' => 'value']),
        ]);

        $array = $collection->toArray();
        $json = json_encode($array);

        expect($json)->not->toBeFalse();
        $decoded = json_decode($json, true);
        expect($decoded)->toBeArray();
        expect($decoded[0]['eventType'])->toBe('test.event');
    });
});
