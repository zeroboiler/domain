<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Tests\Production;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use ZeroBoiler\Domain\DomainEventCollection;
use ZeroBoiler\Events\Domain\DomainEvent;

/**
 * Comprehensive edge-case tests for DomainEventCollection functional methods.
 *
 * Covers boundary conditions for: some, none, find, first (with/without predicate),
 * last, hasType, countBy, types, each, reduce, merge, map, filter, get,
 * and serialization round-trip with complex event payloads.
 *
 * @since 1.67.0
 */
#[CoversClass(DomainEventCollection::class)]
#[Group('production')]
#[Group('domain-event-collection')]
#[Group('edge-cases')]
final class DomainEventCollectionFunctionalEdgeCaseTest extends \PHPUnit\Framework\TestCase
{
    // ─── Empty collection edge cases ─────────────────────────────────

    public function test_empty_collection_some_returns_false(): void
    {
        $collection = new DomainEventCollection;
        $this->assertFalse($collection->some(fn (DomainEvent $e): bool => true));
    }

    public function test_empty_collection_none_returns_true(): void
    {
        $collection = new DomainEventCollection;
        $this->assertTrue($collection->none(fn (DomainEvent $e): bool => true));
    }

    public function test_empty_collection_find_returns_null(): void
    {
        $collection = new DomainEventCollection;
        $this->assertNull($collection->find(fn (DomainEvent $e): bool => true));
    }

    public function test_empty_collection_first_returns_null(): void
    {
        $collection = new DomainEventCollection;
        $this->assertNull($collection->first());
    }

    public function test_empty_collection_first_with_predicate_returns_null(): void
    {
        $collection = new DomainEventCollection;
        $this->assertNull($collection->first(fn (DomainEvent $e): bool => true));
    }

    public function test_empty_collection_last_returns_null(): void
    {
        $collection = new DomainEventCollection;
        $this->assertNull($collection->last());
    }

    public function test_empty_collection_hasType_returns_false(): void
    {
        $collection = new DomainEventCollection;
        $this->assertFalse($collection->hasType('order.placed'));
    }

    public function test_empty_collection_countBy_returns_zero(): void
    {
        $collection = new DomainEventCollection;
        $this->assertSame(0, $collection->countBy(fn (DomainEvent $e): bool => true));
    }

    public function test_empty_collection_types_returns_empty_array(): void
    {
        $collection = new DomainEventCollection;
        $this->assertSame([], $collection->types());
    }

    public function test_empty_collection_reduce_returns_initial(): void
    {
        $collection = new DomainEventCollection;
        $this->assertSame('initial', $collection->reduce(
            fn (string $carry, DomainEvent $e): string => $carry . $e->eventType,
            'initial',
        ));
    }

    public function test_empty_collection_reduce_with_null_initial_returns_null(): void
    {
        $collection = new DomainEventCollection;
        $this->assertNull($collection->reduce(fn (mixed $c, DomainEvent $e): mixed => $c));
    }

    public function test_empty_collection_each_returns_self(): void
    {
        $collection = new DomainEventCollection;
        $result = $collection->each(fn (DomainEvent $e, int $i) => $this->fail('Should not be called'));
        $this->assertSame($collection, $result);
    }

    public function test_empty_collection_map_returns_empty_array(): void
    {
        $collection = new DomainEventCollection;
        $this->assertSame([], $collection->map(fn (DomainEvent $e): string => $e->eventType));
    }

    public function test_empty_collection_filter_returns_empty_collection(): void
    {
        $collection = new DomainEventCollection;
        $filtered = $collection->filter(fn (DomainEvent $e): bool => true);
        $this->assertTrue($filtered->isEmpty());
    }

    public function test_empty_collection_merge_with_empty_returns_empty(): void
    {
        $c1 = new DomainEventCollection;
        $c2 = new DomainEventCollection;
        $merged = $c1->merge($c2);
        $this->assertTrue($merged->isEmpty());
    }

    public function test_empty_collection_get_returns_null(): void
    {
        $collection = new DomainEventCollection;
        $this->assertNull($collection->get(0));
    }

    public function test_empty_collection_to_array_returns_empty_list(): void
    {
        $collection = new DomainEventCollection;
        $this->assertSame([], $collection->toArray());
    }

    public function test_empty_collection_json_serializes_to_empty_array(): void
    {
        $collection = new DomainEventCollection;
        $this->assertSame('[]', json_encode($collection));
    }

    public function test_empty_collection_to_json_returns_empty_array(): void
    {
        $collection = new DomainEventCollection;
        $this->assertSame('[]', $collection->toJson());
    }

    // ─── Single-item collection edge cases ───────────────────────────

    public function test_single_item_first_without_predicate(): void
    {
        $event = DomainEvent::occur('order.placed', ['id' => '1']);
        $collection = new DomainEventCollection([$event]);
        $this->assertSame($event, $collection->first());
    }

    public function test_single_item_first_and_last_are_same(): void
    {
        $event = DomainEvent::occur('order.placed', []);
        $collection = new DomainEventCollection([$event]);
        $this->assertSame($collection->first(), $collection->last());
    }

    public function test_single_item_some_matching(): void
    {
        $collection = new DomainEventCollection([DomainEvent::occur('order.placed', [])]);
        $this->assertTrue($collection->some(fn (DomainEvent $e): bool => $e->eventType === 'order.placed'));
    }

    public function test_single_item_some_not_matching(): void
    {
        $collection = new DomainEventCollection([DomainEvent::occur('order.placed', [])]);
        $this->assertFalse($collection->some(fn (DomainEvent $e): bool => $e->eventType === 'order.paid'));
    }

    public function test_single_item_none_inverse_of_some(): void
    {
        $collection = new DomainEventCollection([DomainEvent::occur('order.placed', [])]);
        $this->assertFalse($collection->none(fn (DomainEvent $e): bool => $e->eventType === 'order.placed'));
        $this->assertTrue($collection->none(fn (DomainEvent $e): bool => $e->eventType === 'order.paid'));
    }

    public function test_single_item_find_matching(): void
    {
        $event = DomainEvent::occur('order.placed', ['amount' => 100]);
        $collection = new DomainEventCollection([$event]);
        $found = $collection->find(fn (DomainEvent $e): bool => $e->eventType === 'order.placed');
        $this->assertSame($event, $found);
    }

    public function test_single_item_find_not_matching(): void
    {
        $collection = new DomainEventCollection([DomainEvent::occur('order.placed', [])]);
        $this->assertNull($collection->find(fn (DomainEvent $e): bool => $e->eventType === 'order.cancelled'));
    }

    public function test_single_item_hasType(): void
    {
        $collection = new DomainEventCollection([DomainEvent::occur('order.placed', [])]);
        $this->assertTrue($collection->hasType('order.placed'));
        $this->assertFalse($collection->hasType('order.paid'));
    }

    public function test_single_item_countBy(): void
    {
        $collection = new DomainEventCollection([DomainEvent::occur('order.placed', [])]);
        $this->assertSame(1, $collection->countBy(fn (DomainEvent $e): bool => true));
        $this->assertSame(0, $collection->countBy(fn (DomainEvent $e): bool => false));
    }

    // ─── Multi-item collection edge cases ───────────────────────────

    public function test_types_preserves_order_of_first_appearance(): void
    {
        $e1 = DomainEvent::occur('order.placed', []);
        $e2 = DomainEvent::occur('order.paid', []);
        $e3 = DomainEvent::occur('order.placed', []); // duplicate type
        $e4 = DomainEvent::occur('order.shipped', []);

        $collection = new DomainEventCollection([$e1, $e2, $e3, $e4]);
        $this->assertSame(
            ['order.placed', 'order.paid', 'order.shipped'],
            $collection->types(),
        );
    }

    public function test_countBy_across_types(): void
    {
        $events = [
            DomainEvent::occur('order.placed', []),
            DomainEvent::occur('order.paid', []),
            DomainEvent::occur('order.placed', []),
            DomainEvent::occur('order.shipped', []),
            DomainEvent::occur('order.paid', []),
        ];

        $collection = new DomainEventCollection($events);

        $this->assertSame(2, $collection->countBy(fn (DomainEvent $e): bool => $e->eventType === 'order.placed'));
        $this->assertSame(2, $collection->countBy(fn (DomainEvent $e): bool => $e->eventType === 'order.paid'));
        $this->assertSame(1, $collection->countBy(fn (DomainEvent $e): bool => $e->eventType === 'order.shipped'));
        $this->assertSame(3, $collection->countBy(fn (DomainEvent $e): bool => str_starts_with($e->eventType, 'order.p')));
        $this->assertSame(0, $collection->countBy(fn (DomainEvent $e): bool => $e->eventType === 'order.cancelled'));
    }

    public function test_reduce_accumulates_complex_payload(): void
    {
        $events = [
            DomainEvent::occur('payment.received', ['amount' => 100]),
            DomainEvent::occur('payment.received', ['amount' => 50]),
            DomainEvent::occur('payment.received', ['amount' => 25]),
        ];

        $collection = new DomainEventCollection($events);

        $total = $collection->reduce(
            fn (float $sum, DomainEvent $e): float => $sum + ($e->payload['amount'] ?? 0),
            0.0,
        );

        $this->assertSame(175.0, $total);
    }

    public function test_reduce_groups_by_type(): void
    {
        $events = [
            DomainEvent::occur('order.placed', []),
            DomainEvent::occur('order.paid', []),
            DomainEvent::occur('order.placed', []),
        ];

        $collection = new DomainEventCollection($events);

        $grouped = $collection->reduce(
            fn (array $groups, DomainEvent $e): array => [
                ...$groups,
                $e->eventType => [...($groups[$e->eventType] ?? []), $e],
            ],
            [],
        );

        $this->assertCount(2, $grouped);
        $this->assertCount(2, $grouped['order.placed']);
        $this->assertCount(1, $grouped['order.paid']);
    }

    public function test_each_receives_correct_index(): void
    {
        $events = [
            DomainEvent::occur('a', []),
            DomainEvent::occur('b', []),
            DomainEvent::occur('c', []),
        ];

        $collection = new DomainEventCollection($events);

        $indexed = [];
        $collection->each(function (DomainEvent $e, int $i) use (&$indexed): void {
            $indexed[$i] = $e->eventType;
        });

        $this->assertSame(['a', 'b', 'c'], $indexed);
    }

    public function test_find_returns_first_match_not_last(): void
    {
        $events = [
            DomainEvent::occur('order.placed', ['seq' => 1]),
            DomainEvent::occur('order.paid', ['seq' => 2]),
            DomainEvent::occur('order.placed', ['seq' => 3]),
        ];

        $collection = new DomainEventCollection($events);

        $found = $collection->find(fn (DomainEvent $e): bool => $e->eventType === 'order.placed');
        $this->assertSame(1, $found->payload['seq']);
    }

    // ─── Merge edge cases ────────────────────────────────────────────

    public function test_merge_preserves_order_first_then_second(): void
    {
        $e1 = DomainEvent::occur('a', []);
        $e2 = DomainEvent::occur('b', []);
        $e3 = DomainEvent::occur('c', []);

        $c1 = new DomainEventCollection([$e1]);
        $c2 = new DomainEventCollection([$e2, $e3]);

        $merged = $c1->merge($c2);
        $types = $merged->types();
        $this->assertSame(['a', 'b', 'c'], $types);
    }

    public function test_merge_with_plain_array(): void
    {
        $e1 = DomainEvent::occur('a', []);
        $e2 = DomainEvent::occur('b', []);

        $collection = new DomainEventCollection([$e1]);
        $merged = $collection->merge([$e2]);

        $this->assertSame(2, $merged->count());
        $this->assertSame(['a', 'b'], $merged->types());
    }

    // ─── Serialization edge cases ──────────────────────────────────

    public function test_serialization_round_trip_preserves_event_order(): void
    {
        $events = [
            DomainEvent::occur('first', ['n' => 1]),
            DomainEvent::occur('second', ['n' => 2]),
            DomainEvent::occur('third', ['n' => 3]),
        ];

        $original = new DomainEventCollection($events);

        // toArray/fromArray round-trip
        $restored = DomainEventCollection::fromArray($original->toArray());
        $this->assertSame(3, $restored->count());

        $types = $restored->types();
        $this->assertSame(['first', 'second', 'third'], $types);

        $first = $restored->first();
        $this->assertSame(1, $first->payload['n']);
    }

    public function test_serialization_round_trip_with_complex_payloads(): void
    {
        $events = [
            DomainEvent::occur('user.created', [
                'id' => 'uuid-123',
                'name' => 'John Doe',
                'metadata' => ['source' => 'web', 'ip' => '127.0.0.1'],
            ]),
            DomainEvent::occur('user.email.changed', [
                'id' => 'uuid-123',
                'old_email' => 'old@example.com',
                'new_email' => 'new@example.com',
            ]),
        ];

        $original = new DomainEventCollection($events);
        $json = $original->toJson();
        $restored = DomainEventCollection::fromArray(json_decode($json, true));

        $this->assertSame(2, $restored->count());
        $this->assertSame('user.created', $restored->get(0)->eventType);
        $this->assertSame('John Doe', $restored->get(0)->payload['name']);
        $this->assertSame('new@example.com', $restored->get(1)->payload['new_email']);
    }

    public function test_fromArray_validates_sequential_list(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('sequential list');

        DomainEventCollection::fromArray(['key' => DomainEvent::occur('test', [])]);
    }

    public function test_fromArray_validates_each_item_is_array(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be an array');

        DomainEventCollection::fromArray(['not-an-array', 'also-not']);
    }

    // ─── Constructor validation ──────────────────────────────────────

    public function test_constructor_rejects_non_sequential_array(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('sequential list');

        new DomainEventCollection([0 => DomainEvent::occur('a', []), 'x' => DomainEvent::occur('b', [])]);
    }

    public function test_constructor_rejects_mixed_types(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be a DomainEvent');

        new DomainEventCollection([DomainEvent::occur('a', []), 'not-an-event']);
    }

    // ─── Immutability ───────────────────────────────────────────────

    public function test_filter_returns_new_instance_does_not_mutate_original(): void
    {
        $e1 = DomainEvent::occur('a', []);
        $e2 = DomainEvent::occur('b', []);

        $original = new DomainEventCollection([$e1, $e2]);
        $filtered = $original->filter(fn (DomainEvent $e): bool => $e->eventType === 'a');

        $this->assertSame(2, $original->count());
        $this->assertSame(1, $filtered->count());
    }

    public function test_merge_returns_new_instance_does_not_mutate_original(): void
    {
        $e1 = DomainEvent::occur('a', []);
        $e2 = DomainEvent::occur('b', []);

        $original = new DomainEventCollection([$e1]);
        $merged = $original->merge([$e2]);

        $this->assertSame(1, $original->count());
        $this->assertSame(2, $merged->count());
    }

    // ─── Integration: JsonSerializable with functional methods ──────

    public function test_json_serialize_reflects_full_collection(): void
    {
        $events = [
            DomainEvent::occur('order.placed', ['total' => 99.99]),
            DomainEvent::occur('order.paid', ['method' => 'card']),
        ];

        $collection = new DomainEventCollection($events);
        $json = json_encode($collection);
        $decoded = json_decode($json, true);

        $this->assertCount(2, $decoded);
        $this->assertSame('order.placed', $decoded[0]['eventType']);
        $this->assertSame(99.99, $decoded[0]['payload']['total']);
        $this->assertSame('order.paid', $decoded[1]['eventType']);
    }

    public function test_collection_is_countable(): void
    {
        $events = [
            DomainEvent::occur('a', []),
            DomainEvent::occur('b', []),
            DomainEvent::occur('c', []),
        ];

        $collection = new DomainEventCollection($events);
        $this->assertCount(3, $collection); // Uses Countable interface
    }

    public function test_collection_is_iterable(): void
    {
        $events = [
            DomainEvent::occur('a', []),
            DomainEvent::occur('b', []),
        ];

        $collection = new DomainEventCollection($events);
        $collected = [];

        foreach ($collection as $event) {
            $collected[] = $event->eventType;
        }

        $this->assertSame(['a', 'b'], $collected);
    }
}
