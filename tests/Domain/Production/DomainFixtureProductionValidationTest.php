<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Tests\Domain\Production;

use Ramsey\Uuid\Uuid;
use ZeroBoiler\Domain\AggregateRoot;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\DomainEventCollection;
use ZeroBoiler\Domain\Exceptions\InvalidArgumentDomainException;
use ZeroBoiler\Domain\Exceptions\InvalidStateDomainException;
use ZeroBoiler\Domain\Exceptions\NotFoundDomainException;
use ZeroBoiler\Domain\Tests\Fixtures\Production\Order;
use ZeroBoiler\Domain\Tests\Fixtures\Production\OrderItem;
use ZeroBoiler\Domain\Tests\Fixtures\Production\OrderStatus;
use ZeroBoiler\Events\Domain\DomainEvent;

/**
 * Production readiness validation test suite for domain fixtures.
 *
 * Tests the full lifecycle of domain objects:
 * 1. Aggregate root creation with domain events
 * 2. State mutations with invariant enforcement
 * 3. Version tracking for optimistic locking
 * 4. Serialization round-trip (toArray/fromArray/JSON)
 * 5. Identity equality and type safety
 * 6. Value object equality and transitions
 * 7. Domain event collection operations
 *
 * Run: vendor/bin/pest tests/Domain/Production/DomainFixtureProductionValidationTest.php
 *
 * @since 1.0.0
 */
final class DomainFixtureProductionValidationTest extends \PHPUnit\Framework\TestCase
{
    // ── Aggregate Root Lifecycle ──────────────────────────────────────

    public function test_order_creation_records_placement_event(): void
    {
        $id = AggregateRootId::generate();
        $order = Order::create($id, total: 99.99);

        self::assertSame('pending', $order->status);
        self::assertSame(99.99, $order->total);
        self::assertSame(0, $order->version());
        self::assertTrue($order->hasUncommittedEvents());

        $events = $order->pullDomainEvents();
        self::assertCount(1, $events);
        self::assertSame('order.placed', $events->first()->eventType);
    }

    public function test_order_add_item_increments_version_and_total(): void
    {
        $id = AggregateRootId::generate();
        $order = Order::create($id);
        $order->pullDomainEvents();

        $order->addItem('prod-1', 2, 9.99);

        self::assertSame(1, $order->version());
        self::assertSame(19.98, $order->total);
        self::assertCount(1, $order->items);
        self::assertSame('prod-1', $order->items[0]['productId']);
    }

    public function test_order_pay_transitions_status(): void
    {
        $id = AggregateRootId::generate();
        $order = Order::create($id, total: 50.0);
        $order->pullDomainEvents();

        $order->pay();

        self::assertSame('paid', $order->status);
        self::assertSame(1, $order->version());
    }

    public function test_order_ship_transitions_status(): void
    {
        $id = AggregateRootId::generate();
        $order = Order::create($id);
        $order->pullDomainEvents();
        $order->pay();
        $order->pullDomainEvents();

        $order->ship();

        self::assertSame('shipped', $order->status);
        self::assertSame(2, $order->version());
    }

    // ── Domain Invariants ────────────────────────────────────────────

    public function test_cannot_add_item_to_paid_order(): void
    {
        $id = AggregateRootId::generate();
        $order = Order::create($id);
        $order->pullDomainEvents();
        $order->pay();
        $order->pullDomainEvents();

        $this->expectException(InvalidStateDomainException::class);
        $this->expectExceptionMessage('Cannot add items to a non-pending order.');

        $order->addItem('prod-1', 1, 10.0);
    }

    public function test_cannot_pay_already_paid_order(): void
    {
        $id = AggregateRootId::generate();
        $order = Order::create($id);
        $order->pullDomainEvents();
        $order->pay();
        $order->pullDomainEvents();

        $this->expectException(InvalidStateDomainException::class);
        $this->expectExceptionMessage('Order must be pending to pay.');

        $order->pay();
    }

    public function test_cannot_ship_unpaid_order(): void
    {
        $id = AggregateRootId::generate();
        $order = Order::create($id);
        $order->pullDomainEvents();

        $this->expectException(InvalidStateDomainException::class);
        $this->expectExceptionMessage('Order must be paid to ship.');

        $order->ship();
    }

    public function test_cannot_cancel_shipped_order(): void
    {
        $id = AggregateRootId::generate();
        $order = Order::create($id);
        $order->pullDomainEvents();
        $order->pay();
        $order->pullDomainEvents();
        $order->ship();
        $order->pullDomainEvents();

        $this->expectException(InvalidStateDomainException::class);
        $this->expectExceptionMessage('Cannot cancel a shipped order.');

        $order->cancel();
    }

    public function test_rejects_zero_quantity(): void
    {
        $id = AggregateRootId::generate();
        $order = Order::create($id);
        $order->pullDomainEvents();

        $this->expectException(InvalidArgumentDomainException::class);
        $this->expectExceptionMessage('Quantity must be positive.');

        $order->addItem('prod-1', 0, 10.0);
    }

    public function test_rejects_negative_unit_price(): void
    {
        $id = AggregateRootId::generate();
        $order = Order::create($id);
        $order->pullDomainEvents();

        $this->expectException(InvalidArgumentDomainException::class);
        $this->expectExceptionMessage('Unit price cannot be negative.');

        $order->addItem('prod-1', 1, -5.0);
    }

    // ── Identity & Equality ───────────────────────────────────────────

    public function test_aggregate_root_identity_equality(): void
    {
        $id = AggregateRootId::generate();
        $order1 = Order::create($id);
        $order2 = Order::create($id);

        self::assertTrue($order1->equals($order2));
        self::assertSame($order1->id(), $order2->id());
    }

    public function test_different_ids_not_equal(): void
    {
        $order1 = Order::create(AggregateRootId::generate());
        $order2 = Order::create(AggregateRootId::generate());

        self::assertFalse($order1->equals($order2));
    }

    public function test_entity_equality(): void
    {
        $item1 = new OrderItem('42', 'prod-1', 2, 9.99);
        $item2 = new OrderItem('42', 'prod-1', 3, 19.99);

        self::assertTrue($item1->equals($item2)); // Same ID = equal
        self::assertSame('42', $item1->id());
    }

    // ── Serialization ─────────────────────────────────────────────────

    public function test_aggregate_root_to_array_includes_all_fields(): void
    {
        $id = AggregateRootId::generate();
        $order = Order::create($id, total: 100.0);
        $order->pullDomainEvents();
        $order->addItem('prod-1', 2, 25.0);
        $order->pullDomainEvents();

        $array = $order->toArray();

        self::assertSame($id->toString(), $array['id']);
        self::assertSame(1, $array['version']);
        self::assertSame('Order', $array['type']);
        self::assertSame('pending', $array['status']);
        self::assertSame(150.0, $array['total']);
        self::assertCount(1, $array['items']);
        self::assertSame(1, $array['item_count']);
    }

    public function test_aggregate_root_json_serialize(): void
    {
        $id = AggregateRootId::generate();
        $order = Order::create($id);

        $json = json_encode($order);
        $decoded = json_decode($json, true);

        self::assertArrayHasKey('id', $decoded);
        self::assertArrayHasKey('version', $decoded);
        self::assertArrayHasKey('type', $decoded);
        self::assertArrayHasKey('status', $decoded);
    }

    public function test_entity_json_serialize(): void
    {
        $item = new OrderItem('42', 'prod-1', 3, 10.0);

        $json = json_encode($item);
        $decoded = json_decode($json, true);

        self::assertSame('42', $decoded['id']);
        self::assertSame('OrderItem', $decoded['type']);
        self::assertSame(30.0, $decoded['subtotal']);
    }

    public function test_entity_from_array_hydration(): void
    {
        $item = OrderItem::fromArray([
            'id' => '99',
            'productId' => 'prod-2',
            'quantity' => 5,
            'unitPrice' => 20.0,
        ]);

        self::assertSame('99', $item->id());
        self::assertSame('prod-2', $item->productId);
        self::assertSame(5, $item->quantity);
        self::assertSame(100.0, $item->subtotal());
    }

    // ── AggregateRootId ──────────────────────────────────────────────

    public function test_aggregate_root_id_generation(): void
    {
        $id = AggregateRootId::generate();

        self::assertTrue(Uuid::isValid($id->toString()));
        self::assertSame($id->toString(), (string) $id);
        self::assertSame($id->toString(), json_encode($id));
    }

    public function test_aggregate_root_id_equality(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $id1 = AggregateRootId::fromString($uuid);
        $id2 = AggregateRootId::fromString($uuid);

        self::assertTrue($id1->equals($id2));
    }

    public function test_aggregate_root_id_round_trip(): void
    {
        $id = AggregateRootId::generate();
        $restored = AggregateRootId::fromArray($id->toArray());

        self::assertTrue($id->equals($restored));
    }

    // ── Value Object ──────────────────────────────────────────────────

    public function test_order_status_value_object_equality(): void
    {
        $s1 = OrderStatus::pending();
        $s2 = OrderStatus::pending();

        self::assertTrue($s1->equals($s2));
    }

    public function test_order_status_transition_rules(): void
    {
        self::assertTrue(OrderStatus::pending()->canTransitionTo(OrderStatus::paid()));
        self::assertTrue(OrderStatus::pending()->canTransitionTo(OrderStatus::cancelled()));
        self::assertTrue(OrderStatus::paid()->canTransitionTo(OrderStatus::shipped()));
        self::assertFalse(OrderStatus::shipped()->canTransitionTo(OrderStatus::paid()));
        self::assertFalse(OrderStatus::cancelled()->canTransitionTo(OrderStatus::paid()));
    }

    public function test_order_status_rejects_invalid_value(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new OrderStatus('invalid');
    }

    public function test_order_status_round_trip(): void
    {
        $status = OrderStatus::paid();
        $restored = OrderStatus::fromArray($status->toArray());

        self::assertTrue($status->equals($restored));
    }

    // ── Domain Event Collection ───────────────────────────────────────

    public function test_domain_event_collection_operations(): void
    {
        $e1 = DomainEvent::occur('order.placed', ['id' => '1']);
        $e2 = DomainEvent::occur('order.paid', ['id' => '1']);
        $e3 = DomainEvent::occur('order.shipped', ['id' => '1']);

        $collection = new DomainEventCollection([$e1, $e2, $e3]);

        self::assertCount(3, $collection);
        self::assertFalse($collection->isEmpty());
        self::assertSame($e1, $collection->first());
        self::assertSame($e3, $collection->last());
        self::assertSame($e2, $collection->get(1));

        $paid = $collection->filter(fn (DomainEvent $e): bool => str_starts_with($e->eventType, 'order.paid'));
        self::assertCount(1, $paid);
        self::assertSame('order.paid', $paid->first()->eventType);

        $types = $collection->map(fn (DomainEvent $e, int $i): string => $e->eventType);
        self::assertSame(['order.placed', 'order.paid', 'order.shipped'], $types);
    }

    public function test_domain_event_collection_merge(): void
    {
        $c1 = new DomainEventCollection([DomainEvent::occur('a', [])]);
        $c2 = new DomainEventCollection([DomainEvent::occur('b', [])]);
        $merged = $c1->merge($c2);

        self::assertCount(2, $merged);
    }

    public function test_domain_event_collection_json_serialize(): void
    {
        $collection = new DomainEventCollection([
            DomainEvent::occur('order.placed', ['id' => '1', 'status' => 'pending']),
        ]);

        $json = json_encode($collection);
        $decoded = json_decode($json, true);

        self::assertCount(1, $decoded);
        self::assertArrayHasKey('event_id', $decoded[0]);
        self::assertSame('order.placed', $decoded[0]['event_type']);
    }

    public function test_domain_event_collection_round_trip(): void
    {
        $original = new DomainEventCollection([
            DomainEvent::occur('a', ['x' => 1]),
            DomainEvent::occur('b', ['y' => 2]),
        ]);

        $restored = DomainEventCollection::fromArray($original->toArray());
        self::assertCount($original->count(), $restored);
    }

    // ── Domain Exception ────────────────────────────────────────────

    public function test_domain_exception_error_code(): void
    {
        $e = InvalidStateDomainException::because('Test');
        self::assertSame('INVALID_STATE', $e->errorCode());
    }

    public function test_domain_exception_to_error_array(): void
    {
        $e = InvalidStateDomainException::because('Order must be pending.');
        $array = $e->toErrorArray();

        self::assertArrayHasKey('title', $array);
        self::assertArrayHasKey('detail', $array);
        self::assertArrayHasKey('code', $array);
        self::assertSame('INVALID_STATE', $array['code']);
        self::assertSame('Order must be pending.', $array['detail']);
    }

    public function test_domain_exception_json_serialize(): void
    {
        $e = NotFoundDomainException::forAggregate('Order', '123');
        $json = json_encode($e);
        $decoded = json_decode($json, true);

        self::assertSame('NotFoundDomainException', $decoded['title']);
        self::assertSame('NOT_FOUND', $decoded['code']);
    }

    // ── Peek vs Pull ─────────────────────────────────────────────────

    public function test_peek_does_not_consume_events(): void
    {
        $id = AggregateRootId::generate();
        $order = Order::create($id);

        $peeked = $order->peekDomainEvents();
        self::assertCount(1, $peeked);
        self::assertTrue($order->hasUncommittedEvents()); // Still has events

        $pulled = $order->pullDomainEvents();
        self::assertCount(1, $pulled);
        self::assertFalse($order->hasUncommittedEvents()); // Consumed
    }

    // ── Version tracking ──────────────────────────────────────────────

    public function test_version_increments_on_each_event(): void
    {
        $id = AggregateRootId::generate();
        $order = Order::create($id);
        self::assertSame(0, $order->version());

        $order->pullDomainEvents();
        $order->addItem('p1', 1, 10.0);
        self::assertSame(1, $order->version());

        $order->pullDomainEvents();
        $order->pay();
        self::assertSame(2, $order->version());
    }
}
