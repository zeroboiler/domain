<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Domain\DomainEventCollection;
use ZeroBoiler\Events\Domain\DomainEvent;

describe('DomainEventCollection Extended API', function (): void {
    describe('each()', function (): void {
        it('iterates over all events with index', function (): void {
            $events = [
                DomainEvent::occur('a', ['x' => 1]),
                DomainEvent::occur('b', ['x' => 2]),
                DomainEvent::occur('c', ['x' => 3]),
            ];
            $collection = new DomainEventCollection($events);

            $visited = [];
            $result = $collection->each(function (DomainEvent $event, int $index) use (&$visited): void {
                $visited[] = ['index' => $index, 'type' => $event->eventType];
            });

            // each() returns self for fluent chaining
            expect($result)->toBe($collection);
            expect($visited)->toBe([
                ['index' => 0, 'type' => 'a'],
                ['index' => 1, 'type' => 'b'],
                ['index' => 2, 'type' => 'c'],
            ]);
        });

        it('works on empty collection', function (): void {
            $collection = new DomainEventCollection([]);
            $called = false;

            $collection->each(function () use (&$called): void {
                $called = true;
            });

            expect($called)->toBeFalse();
        });
    });

    describe('reduce()', function (): void {
        it('reduces to a single value', function (): void {
            $events = [
                DomainEvent::occur('payment', ['amount' => 10.0]),
                DomainEvent::occur('payment', ['amount' => 20.0]),
                DomainEvent::occur('payment', ['amount' => 30.0]),
            ];
            $collection = new DomainEventCollection($events);

            $total = $collection->reduce(
                fn (float $sum, DomainEvent $event): float => $sum + ($event->payload['amount'] ?? 0),
                0.0,
            );

            expect($total)->toBe(60.0);
        });

        it('groups events by type', function (): void {
            $events = [
                DomainEvent::occur('order.placed', ['id' => 1]),
                DomainEvent::occur('order.paid', ['id' => 1]),
                DomainEvent::occur('order.placed', ['id' => 2]),
            ];
            $collection = new DomainEventCollection($events);

            $grouped = $collection->reduce(
                fn (array $groups, DomainEvent $event): array => [
                    ...$groups,
                    $event->eventType => [...($groups[$event->eventType] ?? []), $event->payload['id']],
                ],
                [],
            );

            expect($grouped)->toHaveKey('order.placed');
            expect($grouped)->toHaveKey('order.paid');
            expect($grouped['order.placed'])->toBe([1, 2]);
            expect($grouped['order.paid'])->toBe([1]);
        });

        it('returns initial value for empty collection', function (): void {
            $collection = new DomainEventCollection([]);

            $result = $collection->reduce(fn (int $carry) => $carry + 1, 42);

            expect($result)->toBe(42);
        });

        it('provides index as third parameter', function (): void {
            $events = [
                DomainEvent::occur('a', []),
                DomainEvent::occur('b', []),
            ];
            $collection = new DomainEventCollection($events);

            $indices = $collection->reduce(
                fn (array $acc, DomainEvent $_, int $index): array => [...$acc, $index],
                [],
            );

            expect($indices)->toBe([0, 1]);
        });
    });

    describe('some()', function (): void {
        it('returns true when predicate matches', function (): void {
            $events = [
                DomainEvent::occur('order.placed', []),
                DomainEvent::occur('order.paid', []),
            ];
            $collection = new DomainEventCollection($events);

            expect($collection->some(fn (DomainEvent $e): bool => $e->eventType === 'order.paid'))->toBeTrue();
        });

        it('returns false when no predicate matches', function (): void {
            $events = [
                DomainEvent::occur('order.placed', []),
            ];
            $collection = new DomainEventCollection($events);

            expect($collection->some(fn (DomainEvent $e): bool => $e->eventType === 'order.cancelled'))->toBeFalse();
        });

        it('returns false for empty collection', function (): void {
            $collection = new DomainEventCollection([]);

            expect($collection->some(fn (): bool => true))->toBeFalse();
        });

        it('short-circuits on first match', function (): void {
            $events = [
                DomainEvent::occur('order.placed', []),
                DomainEvent::occur('order.paid', []),
            ];
            $collection = new DomainEventCollection($events);

            $callCount = 0;
            $result = $collection->some(function (DomainEvent $e) use (&$callCount): bool {
                $callCount++;

                return $e->eventType === 'order.placed';
            });

            expect($result)->toBeTrue();
            expect($callCount)->toBe(1);
        });
    });

    describe('none()', function (): void {
        it('returns true when no events match', function (): void {
            $events = [DomainEvent::occur('order.placed', [])];
            $collection = new DomainEventCollection($events);

            expect($collection->none(fn (DomainEvent $e): bool => $e->eventType === 'order.cancelled'))->toBeTrue();
        });

        it('returns false when at least one event matches', function (): void {
            $events = [
                DomainEvent::occur('order.placed', []),
                DomainEvent::occur('order.cancelled', []),
            ];
            $collection = new DomainEventCollection($events);

            expect($collection->none(fn (DomainEvent $e): bool => $e->eventType === 'order.cancelled'))->toBeFalse();
        });

        it('is the inverse of some()', function (): void {
            $events = [DomainEvent::occur('order.placed', [])];
            $collection = new DomainEventCollection($events);
            $predicate = fn (DomainEvent $e): bool => $e->eventType === 'order.placed';

            expect($collection->some($predicate))->toBe(! $collection->none($predicate));
        });
    });

    describe('find()', function (): void {
        it('returns the first matching event', function (): void {
            $events = [
                DomainEvent::occur('order.placed', ['id' => 1]),
                DomainEvent::occur('order.paid', ['id' => 1]),
                DomainEvent::occur('order.paid', ['id' => 2]),
            ];
            $collection = new DomainEventCollection($events);

            $found = $collection->find(fn (DomainEvent $e): bool => $e->eventType === 'order.paid');

            expect($found)->not->toBeNull();
            expect($found->eventType)->toBe('order.paid');
            expect($found->payload['id'])->toBe(1);
        });

        it('returns null when no event matches', function (): void {
            $collection = new DomainEventCollection([DomainEvent::occur('a', [])]);

            expect($collection->find(fn (DomainEvent $e): bool => $e->eventType === 'z'))->toBeNull();
        });

        it('returns null for empty collection', function (): void {
            $collection = new DomainEventCollection([]);

            expect($collection->find(fn (): bool => true))->toBeNull();
        });
    });

    describe('hasType()', function (): void {
        it('returns true for existing event type', function (): void {
            $events = [
                DomainEvent::occur('order.placed', []),
                DomainEvent::occur('order.paid', []),
            ];
            $collection = new DomainEventCollection($events);

            expect($collection->hasType('order.placed'))->toBeTrue();
            expect($collection->hasType('order.paid'))->toBeTrue();
        });

        it('returns false for non-existing event type', function (): void {
            $collection = new DomainEventCollection([DomainEvent::occur('order.placed', [])]);

            expect($collection->hasType('order.cancelled'))->toBeFalse();
        });

        it('returns false for empty collection', function (): void {
            $collection = new DomainEventCollection([]);

            expect($collection->hasType('order.placed'))->toBeFalse();
        });
    });

    describe('countBy()', function (): void {
        it('counts matching events', function (): void {
            $events = [
                DomainEvent::occur('order.placed', ['id' => 1]),
                DomainEvent::occur('order.placed', ['id' => 2]),
                DomainEvent::occur('order.paid', ['id' => 1]),
            ];
            $collection = new DomainEventCollection($events);

            expect($collection->countBy(fn (DomainEvent $e): bool => $e->eventType === 'order.placed'))->toBe(2);
            expect($collection->countBy(fn (DomainEvent $e): bool => $e->eventType === 'order.paid'))->toBe(1);
            expect($collection->countBy(fn (DomainEvent $e): bool => $e->eventType === 'order.cancelled'))->toBe(0);
        });

        it('returns 0 for empty collection', function (): void {
            $collection = new DomainEventCollection([]);

            expect($collection->countBy(fn (): bool => true))->toBe(0);
        });
    });

    describe('types()', function (): void {
        it('returns unique event types in order of first appearance', function (): void {
            $events = [
                DomainEvent::occur('order.placed', []),
                DomainEvent::occur('order.item_added', []),
                DomainEvent::occur('order.placed', []),
                DomainEvent::occur('order.paid', []),
                DomainEvent::occur('order.item_added', []),
            ];
            $collection = new DomainEventCollection($events);

            $types = $collection->types();

            expect($types)->toBe([
                'order.placed',
                'order.item_added',
                'order.paid',
            ]);
        });

        it('returns empty array for empty collection', function (): void {
            $collection = new DomainEventCollection([]);

            expect($collection->types())->toBe([]);
        });

        it('returns single type for single event', function (): void {
            $collection = new DomainEventCollection([DomainEvent::occur('order.placed', [])]);

            expect($collection->types())->toBe(['order.placed']);
        });
    });

    describe('existing API compatibility', function (): void {
        it('maintains immutability — filter returns new collection', function (): void {
            $events = [
                DomainEvent::occur('a', []),
                DomainEvent::occur('b', []),
            ];
            $collection = new DomainEventCollection($events);

            $filtered = $collection->filter(fn (DomainEvent $e): bool => $e->eventType === 'a');

            expect($collection)->not->toBe($filtered);
            expect($collection->count())->toBe(2);
            expect($filtered->count())->toBe(1);
        });

        it('map still works correctly', function (): void {
            $events = [
                DomainEvent::occur('a', ['v' => 1]),
                DomainEvent::occur('b', ['v' => 2]),
            ];
            $collection = new DomainEventCollection($events);

            $result = $collection->map(fn (DomainEvent $e): string => $e->eventType);

            expect($result)->toBe(['a', 'b']);
        });
    });
});
