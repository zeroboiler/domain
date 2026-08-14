<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace Tests\Domain;

use PHPUnit\Framework\TestCase;
use ZeroBoiler\Domain\DomainEventCollection;
use ZeroBoiler\Events\Domain\DomainEvent;

/**
 * Functional tests for DomainEventCollection extended API.
 *
 * Covers the newer functional methods added in v1.58.0:
 * each(), reduce(), some(), none(), find(), hasType(), countBy(), types()
 *
 * Also validates edge cases: empty collection, single-item, predicate short-circuit.
 */
final class DomainEventCollectionFunctionalTest extends TestCase
{
    private function makeEvent(string $type, array $payload = []): DomainEvent
    {
        return DomainEvent::occur($type, $payload);
    }

    // ---- each() ----

    public function test_each_iterates_all_events_and_returns_self(): void
    {
        $events = [
            $this->makeEvent('order.placed'),
            $this->makeEvent('order.paid'),
        ];
        $collection = new DomainEventCollection($events);

        $visited = [];
        $result = $collection->each(function (DomainEvent $event, int $index) use (&$visited): void {
            $visited[$index] = $event->eventType;
        });

        $this->assertSame($collection, $result, 'each() should return $this for fluent chaining.');
        $this->assertSame(['order.placed', 'order.paid'], $visited);
    }

    public function test_each_on_empty_collection_does_not_call_callback(): void
    {
        $called = false;
        $collection = new DomainEventCollection([]);

        $collection->each(function () use (&$called): void {
            $called = true;
        });

        $this->assertFalse($called);
    }

    // ---- reduce() ----

    public function test_reduce_sums_payload_amounts(): void
    {
        $events = [
            $this->makeEvent('payment', ['amount' => 10.0]),
            $this->makeEvent('payment', ['amount' => 20.0]),
            $this->makeEvent('refund', ['amount' => -5.0]),
        ];
        $collection = new DomainEventCollection($events);

        $total = $collection->reduce(
            fn (float $sum, DomainEvent $event): float => $sum + ($event->payload['amount'] ?? 0),
            0.0,
        );

        $this->assertSame(25.0, $total);
    }

    public function test_reduce_groups_events_by_type(): void
    {
        $events = [
            $this->makeEvent('order.placed'),
            $this->makeEvent('order.paid'),
            $this->makeEvent('order.placed'),
        ];
        $collection = new DomainEventCollection($events);

        $grouped = $collection->reduce(
            fn (array $groups, DomainEvent $event): array => [
                ...$groups,
                $event->eventType => [...($groups[$event->eventType] ?? []), $event->eventType],
            ],
            [],
        );

        $this->assertCount(2, $grouped['order.placed']);
        $this->assertCount(1, $grouped['order.paid']);
    }

    public function test_reduce_on_empty_collection_returns_initial(): void
    {
        $collection = new DomainEventCollection([]);

        $result = $collection->reduce(fn (int $c, DomainEvent $e): int => $c + 1, 42);

        $this->assertSame(42, $result);
    }

    public function test_reduce_index_is_passed_correctly(): void
    {
        $events = [
            $this->makeEvent('a'),
            $this->makeEvent('b'),
            $this->makeEvent('c'),
        ];
        $collection = new DomainEventCollection($events);

        $indices = $collection->reduce(
            fn (array $acc, DomainEvent $e, int $i): array => [...$acc, $i],
            [],
        );

        $this->assertSame([0, 1, 2], $indices);
    }

    // ---- some() / none() ----

    public function test_some_returns_true_when_predicate_matches(): void
    {
        $collection = new DomainEventCollection([
            $this->makeEvent('order.placed'),
            $this->makeEvent('order.paid'),
        ]);

        $this->assertTrue($collection->some(fn (DomainEvent $e): bool => $e->eventType === 'order.paid'));
    }

    public function test_some_returns_false_when_no_match(): void
    {
        $collection = new DomainEventCollection([
            $this->makeEvent('order.placed'),
        ]);

        $this->assertFalse($collection->some(fn (DomainEvent $e): bool => $e->eventType === 'order.shipped'));
    }

    public function test_some_short_circuits_on_first_match(): void
    {
        $events = [
            $this->makeEvent('order.placed'),
            $this->makeEvent('order.paid'),
            $this->makeEvent('order.shipped'),
        ];
        $collection = new DomainEventCollection($events);

        $callCount = 0;
        $result = $collection->some(function (DomainEvent $e) use (&$callCount): bool {
            $callCount++;
            return true; // Always match — should short-circuit after first
        });

        $this->assertTrue($result);
        $this->assertSame(1, $callCount, 'some() should short-circuit on first match.');
    }

    public function test_none_is_inverse_of_some(): void
    {
        $collection = new DomainEventCollection([
            $this->makeEvent('order.placed'),
            $this->makeEvent('order.paid'),
        ]);

        $this->assertTrue($collection->none(fn (DomainEvent $e): bool => $e->eventType === 'order.shipped'));
        $this->assertFalse($collection->none(fn (DomainEvent $e): bool => $e->eventType === 'order.placed'));
    }

    // ---- find() ----

    public function test_find_returns_first_matching_event(): void
    {
        $collection = new DomainEventCollection([
            $this->makeEvent('order.placed', ['id' => '1']),
            $this->makeEvent('order.paid', ['id' => '1', 'amount' => 100]),
            $this->makeEvent('order.placed', ['id' => '2']),
        ]);

        $found = $collection->find(fn (DomainEvent $e): bool => $e->eventType === 'order.paid');

        $this->assertNotNull($found);
        $this->assertSame('order.paid', $found->eventType);
        $this->assertSame(100, $found->payload['amount']);
    }

    public function test_find_returns_null_when_no_match(): void
    {
        $collection = new DomainEventCollection([
            $this->makeEvent('order.placed'),
        ]);

        $this->assertNull($collection->find(fn (DomainEvent $e): bool => $e->eventType === 'order.shipped'));
    }

    // ---- hasType() ----

    public function test_hasType_checks_for_event_type(): void
    {
        $collection = new DomainEventCollection([
            $this->makeEvent('order.placed'),
            $this->makeEvent('order.paid'),
        ]);

        $this->assertTrue($collection->hasType('order.placed'));
        $this->assertTrue($collection->hasType('order.paid'));
        $this->assertFalse($collection->hasType('order.shipped'));
    }

    // ---- countBy() ----

    public function test_countBy_counts_matching_events(): void
    {
        $collection = new DomainEventCollection([
            $this->makeEvent('order.placed'),
            $this->makeEvent('order.paid'),
            $this->makeEvent('order.placed'),
            $this->makeEvent('order.shipped'),
        ]);

        $this->assertSame(2, $collection->countBy(fn (DomainEvent $e): bool => $e->eventType === 'order.placed'));
        $this->assertSame(1, $collection->countBy(fn (DomainEvent $e): bool => $e->eventType === 'order.paid'));
        $this->assertSame(0, $collection->countBy(fn (DomainEvent $e): bool => $e->eventType === 'order.cancelled'));
    }

    // ---- types() ----

    public function test_types_returns_unique_ordered_types(): void
    {
        $collection = new DomainEventCollection([
            $this->makeEvent('order.placed'),
            $this->makeEvent('order.paid'),
            $this->makeEvent('order.placed'),
            $this->makeEvent('order.shipped'),
            $this->makeEvent('order.paid'),
        ]);

        $types = $collection->types();

        $this->assertSame(['order.placed', 'order.paid', 'order.shipped'], $types);
    }

    public function test_types_on_empty_collection_returns_empty_array(): void
    {
        $collection = new DomainEventCollection([]);

        $this->assertSame([], $collection->types());
    }

    // ---- Chaining ----

    public function test_each_and_some_can_be_chained(): void
    {
        $collection = new DomainEventCollection([
            $this->makeEvent('a'),
            $this->makeEvent('b'),
        ]);

        $count = 0;
        $result = $collection
            ->each(function (DomainEvent $e) use (&$count): void {
                $count++;
            })
            ->some(fn (DomainEvent $e): bool => $e->eventType === 'b');

        $this->assertSame(2, $count);
        $this->assertTrue($result);
    }

    // ---- Round-trip with extended API ----

    public function test_extended_api_survives_round_trip(): void
    {
        $original = new DomainEventCollection([
            $this->makeEvent('order.placed', ['id' => '123']),
            $this->makeEvent('order.paid', ['amount' => 99.99]),
        ]);

        $restored = DomainEventCollection::fromArray($original->toArray());

        $this->assertTrue($restored->hasType('order.placed'));
        $this->assertSame(1, $restored->countBy(fn (DomainEvent $e): bool => $e->eventType === 'order.paid'));
        $this->assertSame(99.99, $restored->find(fn (DomainEvent $e): bool => $e->eventType === 'order.paid')?->payload['amount']);
        $this->assertSame(['order.placed', 'order.paid'], $restored->types());
    }
}
