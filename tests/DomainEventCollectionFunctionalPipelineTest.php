<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace Tests\Domain;

use PHPUnit\Framework\Attributes\Test;
use ZeroBoiler\Domain\DomainEventCollection;
use ZeroBoiler\Events\Domain\DomainEvent;

class DomainEventCollectionFunctionalPipelineTest extends \PHPUnit\Framework\TestCase
{
    #[Test]
    public function map_returns_correctly_typed_results(): void
    {
        $events = [
            DomainEvent::occur('order.placed', ['amount' => 100]),
            DomainEvent::occur('order.item_added', ['amount' => 50]),
            DomainEvent::occur('order.placed', ['amount' => 200]),
        ];

        $collection = new DomainEventCollection($events);
        $amounts = $collection->map(fn (DomainEvent $e, int $i): int => $e->payload['amount'] + $i);

        $this->assertSame([100, 51, 202], $amounts);
    }

    #[Test]
    public function filter_returns_new_immutable_collection(): void
    {
        $events = [
            DomainEvent::occur('order.placed', []),
            DomainEvent::occur('order.item_added', []),
            DomainEvent::occur('order.paid', []),
        ];

        $collection = new DomainEventCollection($events);
        $filtered = $collection->filter(fn (DomainEvent $e): bool => str_starts_with($e->eventType, 'order.p'));

        $this->assertSame(2, $filtered->count());
        $this->assertSame(3, $collection->count()); // Original unchanged
        $this->assertTrue($filtered->hasType('order.placed'));
        $this->assertTrue($filtered->hasType('order.paid'));
        $this->assertFalse($filtered->hasType('order.item_added'));
    }

    #[Test]
    public function merge_combines_events_from_both_sources(): void
    {
        $a = new DomainEventCollection([
            DomainEvent::occur('a', ['n' => 1]),
        ]);
        $b = new DomainEventCollection([
            DomainEvent::occur('b', ['n' => 2]),
            DomainEvent::occur('c', ['n' => 3]),
        ]);

        $merged = $a->merge($b);

        $this->assertSame(3, $merged->count());
        $this->assertSame(['a', 'b', 'c'], $merged->types());
    }

    #[Test]
    public function merge_accepts_plain_list(): void
    {
        $a = new DomainEventCollection([DomainEvent::occur('x', [])]);
        $merged = $a->merge([DomainEvent::occur('y', []), DomainEvent::occur('z', [])]);

        $this->assertSame(3, $merged->count());
    }

    #[Test]
    public function some_none_find_countBy_work_correctly(): void
    {
        $events = [
            DomainEvent::occur('order.placed', ['total' => 100]),
            DomainEvent::occur('order.item_added', ['total' => 0]),
            DomainEvent::occur('order.paid', ['total' => 100]),
        ];

        $collection = new DomainEventCollection($events);

        // some — at least one match
        $this->assertTrue($collection->some(fn (DomainEvent $e): bool => $e->eventType === 'order.placed'));
        $this->assertFalse($collection->some(fn (DomainEvent $e): bool => $e->eventType === 'order.cancelled'));

        // none — no match (inverse of some)
        $this->assertTrue($collection->none(fn (DomainEvent $e): bool => $e->eventType === 'order.cancelled'));
        $this->assertFalse($collection->none(fn (DomainEvent $e): bool => $e->eventType === 'order.placed'));

        // find — first matching event
        $found = $collection->find(fn (DomainEvent $e): bool => $e->payload['total'] > 0);
        $this->assertNotNull($found);
        $this->assertSame('order.placed', $found->eventType);

        $notFound = $collection->find(fn (DomainEvent $e): bool => $e->eventType === 'order.shipped');
        $this->assertNull($notFound);

        // countBy — count matching events
        $this->assertSame(2, $collection->countBy(fn (DomainEvent $e): bool => $e->payload['total'] > 0));
        $this->assertSame(1, $collection->countBy(fn (DomainEvent $e): bool => $e->payload['total'] === 0));
        $this->assertSame(0, $collection->countBy(fn (DomainEvent $e): bool => $e->eventType === 'order.shipped'));
    }

    #[Test]
    public function reduce_accumulates_correctly(): void
    {
        $events = [
            DomainEvent::occur('order.placed', ['amount' => 100]),
            DomainEvent::occur('order.item_added', ['amount' => 50]),
            DomainEvent::occur('order.item_added', ['amount' => 25]),
        ];

        $collection = new DomainEventCollection($events);

        $total = $collection->reduce(
            fn (int $sum, DomainEvent $e): int => $sum + ($e->payload['amount'] ?? 0),
            0,
        );

        $this->assertSame(175, $total);
    }

    #[Test]
    public function types_returns_unique_ordered_list(): void
    {
        $events = [
            DomainEvent::occur('order.placed', []),
            DomainEvent::occur('order.item_added', []),
            DomainEvent::occur('order.placed', []),
            DomainEvent::occur('order.paid', []),
            DomainEvent::occur('order.item_added', []),
        ];

        $collection = new DomainEventCollection($events);

        $this->assertSame(['order.placed', 'order.item_added', 'order.paid'], $collection->types());
    }

    #[Test]
    public function each_returns_self_for_fluent_chaining(): void
    {
        $events = [DomainEvent::occur('a', []), DomainEvent::occur('b', [])];
        $collection = new DomainEventCollection($events);

        $collected = [];
        $result = $collection->each(function (DomainEvent $e, int $i) use (&$collected): void {
            $collected[] = "{$i}:{$e->eventType}";
        });

        $this->assertSame($collection, $result); // Same instance (fluent)
        $this->assertSame(['0:a', '1:b'], $collected);
        $this->assertSame(2, $collection->count()); // Events not consumed
    }

    #[Test]
    public function get_returns_event_at_index_or_null(): void
    {
        $events = [DomainEvent::occur('a', []), DomainEvent::occur('b', [])];
        $collection = new DomainEventCollection($events);

        $this->assertSame('a', $collection->get(0)?->eventType);
        $this->assertSame('b', $collection->get(1)?->eventType);
        $this->assertNull($collection->get(2));
        $this->assertNull($collection->get(-1));
    }

    #[Test]
    public function first_and_last_work_on_non_empty_and_empty(): void
    {
        $events = [DomainEvent::occur('first', []), DomainEvent::occur('middle', []), DomainEvent::occur('last', [])];
        $collection = new DomainEventCollection($events);

        $this->assertSame('first', $collection->first()?->eventType);
        $this->assertSame('last', $collection->last()?->eventType);
        $this->assertSame('middle', $collection->first(fn (DomainEvent $e): bool => $e->eventType === 'middle')?->eventType);

        $empty = new DomainEventCollection;
        $this->assertNull($empty->first());
        $this->assertNull($empty->last());
    }

    #[Test]
    public function full_serde_round_trip_preserves_events(): void
    {
        $events = [
            DomainEvent::occur('order.placed', ['customer_id' => 'cust-123', 'total' => 9999]),
            DomainEvent::occur('order.item_added', ['product_id' => 'prod-456', 'qty' => 3]),
        ];

        $original = new DomainEventCollection($events);

        // toArray → fromArray round-trip
        $array = $original->toArray();
        $fromArray = DomainEventCollection::fromArray($array);
        $this->assertSame($original->count(), $fromArray->count());
        $this->assertSame($original->types(), $fromArray->types());

        // toJson → fromJson round-trip
        $json = $original->toJson();
        $fromJson = DomainEventCollection::fromJson($json);
        $this->assertSame(2, $fromJson->count());
        $this->assertSame('order.placed', $fromJson->first()?->eventType);
        $this->assertSame('cust-123', $fromJson->first()?->payload['customer_id']);
    }

    #[Test]
    public function constructor_rejects_non_list(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('sequential list');
        new DomainEventCollection(['key' => DomainEvent::occur('a', [])]);
    }

    #[Test]
    public function constructor_rejects_non_domain_event_items(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be a DomainEvent');
        new DomainEventCollection([DomainEvent::occur('a', []), 'not-an-event']);
    }

    #[Test]
    public function fromArray_rejects_non_list(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('sequential list');
        DomainEventCollection::fromArray(['key' => ['eventType' => 'a', 'payload' => []]]);
    }

    #[Test]
    public function fromArray_rejects_non_array_items(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be an array');
        DomainEventCollection::fromArray([DomainEvent::occur('a', [])]);
    }

    #[Test]
    public function iteration_supports_foreach(): void
    {
        $events = [DomainEvent::occur('a', []), DomainEvent::occur('b', [])];
        $collection = new DomainEventCollection($events);

        $types = [];
        foreach ($collection as $event) {
            $types[] = $event->eventType;
        }

        $this->assertSame(['a', 'b'], $types);
    }

    #[Test]
    public function jsonSerialize_returns_list_of_event_arrays(): void
    {
        $events = [DomainEvent::occur('test.event', ['key' => 'val'])];
        $collection = new DomainEventCollection($events);

        $encoded = json_encode($collection);
        $decoded = json_decode($encoded, true);

        $this->assertCount(1, $decoded);
        $this->assertSame('test.event', $decoded[0]['eventType']);
        $this->assertSame('val', $decoded[0]['payload']['key']);
    }
}
