<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\DomainEventCollection;
use ZeroBoiler\Domain\Exceptions\{
    InvalidArgumentDomainException,
    InvalidStateDomainException,
    NotFoundDomainException,
    OptimisticLockException,
};
use ZeroBoiler\Domain\Tests\Fixtures\Production\Order;
use ZeroBoiler\Domain\Tests\Fixtures\Production\OrderItem;
use ZeroBoiler\Domain\Tests\Fixtures\Production\OrderStatus;

/**
 * Full lifecycle test for Order aggregate root.
 *
 * Validates the complete domain behavior of a production-grade aggregate:
 * - Creation and factory methods
 * - State transitions with domain invariant enforcement
 * - Domain event recording, pulling, and peeking
 * - Version tracking through the aggregate lifecycle
 * - Serialization (toArray/toJson/fromArray/fromJson) round-trips
 * - Entity identity equality semantics
 * - Value object state machine behavior
 * - Exception hierarchy and error codes
 * - Collection operations (DomainEventCollection)
 *
 * These tests document the expected API contract and serve as a
 * regression suite for any refactoring of the domain package.
 *
 * @since 1.80.0
 */
final class OrderAggregateFullLifecycleTest extends TestCase
{
    // ─────────────────────────────────────────────────────────────
    // Aggregate Root: Creation & Identity
    // ─────────────────────────────────────────────────────────────

    #[Test]
    public function create_order_sets_pending_status_and_zero_version(): void
    {
        $id = AggregateRootId::generate();
        $order = Order::create($id, total: 0.0);

        $this->assertSame('pending', $order->status);
        $this->assertSame(0.0, $order->total);
        $this->assertSame(0, $order->version());
        $this->assertSame($id->toString(), $order->id());
        $this->assertTrue($order->hasUncommittedEvents());
    }

    #[Test]
    public function aggregate_root_identity_is_typed_aggregate_root_id(): void
    {
        $id = AggregateRootId::generate();
        $order = Order::create($id);

        $this->assertSame($id->toString(), $order->aggregateId()->toString());
    }

    #[Test]
    public function two_aggregates_with_same_id_are_equal(): void
    {
        $id = AggregateRootId::generate();
        $a = Order::create($id);
        $b = Order::create($id);

        $this->assertTrue($a->equals($b));
    }

    #[Test]
    public function two_aggregates_with_different_ids_are_not_equal(): void
    {
        $a = Order::create(AggregateRootId::generate());
        $b = Order::create(AggregateRootId::generate());

        $this->assertFalse($a->equals($b));
    }

    // ─────────────────────────────────────────────────────────────
    // State Transitions & Domain Invariants
    // ─────────────────────────────────────────────────────────────

    #[Test]
    public function add_item_increments_version_and_total(): void
    {
        $order = Order::create(AggregateRootId::generate());
        $order->addItem('prod-1', 2, 9.99);

        $this->assertSame(2, $order->version());
        $this->assertSame(19.98, $order->total);
        $this->assertSame(1, $order->itemCount());
    }

    #[Test]
    public function add_multiple_items_accumulates_total(): void
    {
        $order = Order::create(AggregateRootId::generate());
        $order->addItem('prod-1', 2, 10.0);
        $order->addItem('prod-2', 1, 5.0);

        $this->assertSame(3, $order->version());
        $this->assertSame(25.0, $order->total);
        $this->assertSame(2, $order->itemCount());
    }

    #[Test]
    public function pay_transitions_status_to_paid(): void
    {
        $order = Order::create(AggregateRootId::generate());
        $order->addItem('prod-1', 1, 50.0);
        $order->pay();

        $this->assertSame('paid', $order->status);
        $this->assertSame(3, $order->version());
    }

    #[Test]
    public function ship_transitions_status_to_shipped(): void
    {
        $order = Order::create(AggregateRootId::generate());
        $order->addItem('prod-1', 1, 50.0);
        $order->pay();
        $order->ship();

        $this->assertSame('shipped', $order->status);
        $this->assertSame(4, $order->version());
    }

    #[Test]
    public function cancel_transitions_from_pending(): void
    {
        $order = Order::create(AggregateRootId::generate());
        $order->cancel();

        $this->assertSame('cancelled', $order->status);
    }

    #[Test]
    public function cancel_transitions_from_paid(): void
    {
        $order = Order::create(AggregateRootId::generate());
        $order->addItem('prod-1', 1, 50.0);
        $order->pay();
        $order->cancel();

        $this->assertSame('cancelled', $order->status);
    }

    #[Test]
    public function cannot_add_item_to_paid_order(): void
    {
        $order = Order::create(AggregateRootId::generate());
        $order->addItem('prod-1', 1, 50.0);
        $order->pay();

        $this->expectException(InvalidStateDomainException::class);
        $this->expectExceptionMessage('non-pending');
        $order->addItem('prod-2', 1, 10.0);
    }

    #[Test]
    public function cannot_pay_already_paid_order(): void
    {
        $order = Order::create(AggregateRootId::generate());
        $order->pay();

        $this->expectException(InvalidStateDomainException::class);
        $this->expectExceptionMessage('pending');
        $order->pay();
    }

    #[Test]
    public function cannot_ship_unpaid_order(): void
    {
        $order = Order::create(AggregateRootId::generate());

        $this->expectException(InvalidStateDomainException::class);
        $this->expectExceptionMessage('paid');
        $order->ship();
    }

    #[Test]
    public function cannot_cancel_shipped_order(): void
    {
        $order = Order::create(AggregateRootId::generate());
        $order->addItem('prod-1', 1, 50.0);
        $order->pay();
        $order->ship();

        $this->expectException(InvalidStateDomainException::class);
        $this->expectExceptionMessage('shipped');
        $order->cancel();
    }

    #[Test]
    public function cannot_add_item_with_zero_quantity(): void
    {
        $order = Order::create(AggregateRootId::generate());

        $this->expectException(InvalidArgumentDomainException::class);
        $this->expectExceptionMessage('positive');
        $order->addItem('prod-1', 0, 9.99);
    }

    #[Test]
    public function cannot_add_item_with_negative_price(): void
    {
        $order = Order::create(AggregateRootId::generate());

        $this->expectException(InvalidArgumentDomainException::class);
        $order->addItem('prod-1', 1, -5.0);
    }

    // ─────────────────────────────────────────────────────────────
    // Domain Events
    // ─────────────────────────────────────────────────────────────

    #[Test]
    public function pull_domain_events_returns_typed_collection_and_clears_buffer(): void
    {
        $order = Order::create(AggregateRootId::generate());
        $order->addItem('prod-1', 1, 10.0);

        $events = $order->pullDomainEvents();

        $this->assertInstanceOf(DomainEventCollection::class, $events);
        $this->assertCount(2, $events);
        $this->assertFalse($order->hasUncommittedEvents());
        $this->assertSame('order.placed', $events->first()->eventType);
        $this->assertSame('order.item_added', $events->last()->eventType);
    }

    #[Test]
    public function peek_domain_events_returns_copy_without_consuming(): void
    {
        $order = Order::create(AggregateRootId::generate());

        $peeked = $order->peekDomainEvents();

        $this->assertCount(1, $peeked);
        $this->assertTrue($order->hasUncommittedEvents()); // still true

        $pulled = $order->pullDomainEvents();
        $this->assertCount(1, $pulled);
        $this->assertFalse($order->hasUncommittedEvents());
    }

    #[Test]
    public function domain_event_collection_functional_operations(): void
    {
        $order = Order::create(AggregateRootId::generate());
        $order->addItem('prod-1', 1, 10.0);
        $order->pay();

        $events = $order->pullDomainEvents();

        // some/none
        $this->assertTrue($events->some(fn ($e) => $e->eventType === 'order.paid'));
        $this->assertFalse($events->none(fn ($e) => $e->eventType === 'order.placed'));

        // find
        $paidEvent = $events->find(fn ($e) => $e->eventType === 'order.paid');
        $this->assertNotNull($paidEvent);
        $this->assertSame('order.paid', $paidEvent->eventType);

        // hasType
        $this->assertTrue($events->hasType('order.placed'));
        $this->assertFalse($events->hasType('order.refunded'));

        // types
        $types = $events->types();
        $this->assertSame(['order.placed', 'order.item_added', 'order.paid'], $types);

        // countBy
        $count = $events->countBy(fn ($e) => $e->eventType === 'order.item_added');
        $this->assertSame(1, $count);

        // map
        $eventTypes = $events->map(fn ($e) => $e->eventType);
        $this->assertSame(['order.placed', 'order.item_added', 'order.paid'], $eventTypes);

        // filter
        $placedOnly = $events->filter(fn ($e) => $e->eventType === 'order.placed');
        $this->assertCount(1, $placedOnly);
        $this->assertSame(3, $events->count()); // original unchanged
    }

    // ─────────────────────────────────────────────────────────────
    // Serialization
    // ─────────────────────────────────────────────────────────────

    #[Test]
    public function aggregate_to_array_includes_identity_version_and_type(): void
    {
        $id = AggregateRootId::generate();
        $order = Order::create($id, total: 99.99);
        $order->addItem('prod-1', 2, 9.99);

        $array = $order->toArray();

        $this->assertSame($id->toString(), $array['id']);
        $this->assertSame(2, $array['version']);
        $this->assertSame('Order', $array['type']);
        $this->assertSame('pending', $array['status']);
        $this->assertSame(19.98, $array['total']);
        $this->assertSame(1, $array['item_count']);
        $this->assertCount(1, $array['items']);
    }

    #[Test]
    public function aggregate_root_id_serialization_round_trip(): void
    {
        $id = AggregateRootId::generate();
        $array = $id->toArray();
        $restored = AggregateRootId::fromArray($array);

        $this->assertTrue($id->equals($restored));
        $this->assertArrayHasKey('uuid', $array);
    }

    #[Test]
    public function aggregate_root_id_json_round_trip(): void
    {
        $id = AggregateRootId::generate();
        $json = $id->toJson();
        $restored = AggregateRootId::fromJson($json);

        $this->assertTrue($id->equals($restored));
    }

    #[Test]
    public function aggregate_root_id_accepts_id_key_for_deserialization(): void
    {
        $id = AggregateRootId::generate();
        $restored = AggregateRootId::fromArray(['id' => $id->toString()]);

        $this->assertTrue($id->equals($restored));
    }

    #[Test]
    public function aggregate_root_id_json_serialize_returns_string(): void
    {
        $id = AggregateRootId::generate();

        $this->assertSame($id->toString(), json_encode($id));
    }

    #[Test]
    public function entity_to_json_and_from_json_round_trip(): void
    {
        $item = new OrderItem('42', 'prod-1', 3, 9.99);
        $json = $item->toJson();
        $restored = OrderItem::fromJson($json);

        $this->assertTrue($item->equals($restored));
        $this->assertSame('42', $restored->id());
    }

    // ─────────────────────────────────────────────────────────────
    // Entity Identity Equality
    // ─────────────────────────────────────────────────────────────

    #[Test]
    public function entity_equality_same_id_same_type(): void
    {
        $a = new OrderItem('42', 'prod-1', 1, 10.0);
        $b = new OrderItem('42', 'prod-2', 5, 20.0);

        $this->assertTrue($a->equals($b));
    }

    #[Test]
    public function entity_inequality_different_id(): void
    {
        $a = new OrderItem('42', 'prod-1', 1, 10.0);
        $b = new OrderItem('99', 'prod-1', 1, 10.0);

        $this->assertFalse($a->equals($b));
    }

    #[Test]
    public function entity_inequality_different_type(): void
    {
        $item = new OrderItem('42', 'prod-1', 1, 10.0);
        $id = AggregateRootId::generate();
        $order = Order::create($id);

        // Different concrete classes → not equal (despite both being "entities")
        $this->assertFalse($item->equals($order));
    }

    #[Test]
    public function entity_accepts_stringable_id(): void
    {
        $id = AggregateRootId::generate();
        $item = new OrderItem($id, 'prod-1', 1, 10.0);

        $this->assertSame($id->toString(), $item->id());
    }

    #[Test]
    public function entity_accepts_integer_id(): void
    {
        $item = new OrderItem(42, 'prod-1', 1, 10.0);

        $this->assertSame('42', $item->id());
    }

    // ─────────────────────────────────────────────────────────────
    // Value Object: OrderStatus
    // ─────────────────────────────────────────────────────────────

    #[Test]
    public function value_object_equality_by_structure(): void
    {
        $a = OrderStatus::pending();
        $b = OrderStatus::pending();

        $this->assertTrue($a->equals($b));
    }

    #[Test]
    public function value_object_inequality_by_value(): void
    {
        $a = OrderStatus::pending();
        $b = OrderStatus::paid();

        $this->assertFalse($a->equals($b));
    }

    #[Test]
    public function value_object_serialization_round_trip(): void
    {
        $status = OrderStatus::shipped();
        $json = $status->toJson();
        $restored = OrderStatus::fromJson($json);

        $this->assertTrue($status->equals($restored));
    }

    #[Test]
    public function order_status_state_transitions_are_validated(): void
    {
        $this->assertTrue(OrderStatus::pending()->canTransitionTo(OrderStatus::paid()));
        $this->assertTrue(OrderStatus::pending()->canTransitionTo(OrderStatus::cancelled()));
        $this->assertTrue(OrderStatus::paid()->canTransitionTo(OrderStatus::shipped()));
        $this->assertFalse(OrderStatus::shipped()->canTransitionTo(OrderStatus::paid()));
        $this->assertFalse(OrderStatus::cancelled()->canTransitionTo(OrderStatus::paid()));
    }

    #[Test]
    public function order_status_to_string_returns_value(): void
    {
        $this->assertSame('pending', (string) OrderStatus::pending());
        $this->assertSame('paid', (string) OrderStatus::paid());
    }

    #[Test]
    public function order_status_rejects_invalid_value(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new OrderStatus('invalid');
    }

    // ─────────────────────────────────────────────────────────────
    // Domain Exceptions
    // ─────────────────────────────────────────────────────────────

    #[Test]
    public function invalid_state_exception_has_correct_error_code_and_status(): void
    {
        $e = InvalidStateDomainException::because('Test message');

        $this->assertSame('INVALID_STATE', $e->errorCode());
        $this->assertSame(422, $e->httpStatus());
        $this->assertSame('Test message', $e->getMessage());
    }

    #[Test]
    public function invalid_argument_exception_has_correct_error_code_and_status(): void
    {
        $e = InvalidArgumentDomainException::because('Bad input', code: 'CUSTOM_CODE');

        $this->assertSame('CUSTOM_CODE', $e->errorCode());
        $this->assertSame(422, $e->httpStatus());
    }

    #[Test]
    public function not_found_exception_factory_methods(): void
    {
        $e1 = NotFoundDomainException::forAggregate('Order', 'order-123');
        $this->assertSame('NOT_FOUND', $e1->errorCode());
        $this->assertSame(404, $e1->httpStatus());
        $this->assertStringContainsString('Order', $e1->getMessage());

        $e2 = NotFoundDomainException::forId('user-456');
        $this->assertSame('NOT_FOUND', $e2->errorCode());
        $this->assertSame(404, $e2->httpStatus());
        $this->assertStringContainsString('user-456', $e2->getMessage());
    }

    #[Test]
    public function optimistic_lock_exception_formats_message(): void
    {
        $id = AggregateRootId::generate();
        $e = OptimisticLockException::for($id, expectedVersion: 5, actualVersion: 3);

        $this->assertSame('OPTIMISTIC_LOCK', $e->errorCode());
        $this->assertSame(409, $e->httpStatus());
        $this->assertStringContainsString('5', $e->getMessage());
        $this->assertStringContainsString('3', $e->getMessage());
    }

    #[Test]
    public function exception_to_error_array_is_rfc9457_compatible(): void
    {
        $e = InvalidStateDomainException::because('Order must be pending.');
        $error = $e->toErrorArray();

        $this->assertArrayHasKey('title', $error);
        $this->assertArrayHasKey('detail', $error);
        $this->assertArrayHasKey('code', $error);
        $this->assertArrayHasKey('status', $error);
        $this->assertSame('INVALID_STATE', $error['code']);
        $this->assertSame(422, $error['status']);
        $this->assertSame('Order must be pending.', $error['detail']);
    }

    #[Test]
    public function exception_json_serialization_returns_rfc9457(): void
    {
        $e = NotFoundDomainException::forId('order-123');
        $json = json_encode($e);
        $decoded = json_decode($json, true);

        $this->assertSame('NOT_FOUND', $decoded['code']);
        $this->assertSame(404, $decoded['status']);
    }

    #[Test]
    public function exception_round_trip_via_to_array_and_from_array(): void
    {
        $original = InvalidStateDomainException::because('Test message');
        $restored = InvalidStateDomainException::fromArray($original->toArray());

        $this->assertSame($original->getMessage(), $restored->getMessage());
        $this->assertSame($original->errorCode(), $restored->errorCode());
    }

    #[Test]
    public function exception_round_trip_via_to_json_and_from_json(): void
    {
        $original = NotFoundDomainException::forAggregate('Order', '123');
        $json = $original->toJson();
        $restored = NotFoundDomainException::fromJson($json, NotFoundDomainException::class);

        $this->assertSame($original->getMessage(), $restored->getMessage());
        $this->assertSame($original->errorCode(), $restored->errorCode());
    }

    // ─────────────────────────────────────────────────────────────
    // AggregateRootId Validation
    // ─────────────────────────────────────────────────────────────

    #[Test]
    public function aggregate_root_id_validates_uuid(): void
    {
        $this->assertFalse(AggregateRootId::isValid('not-a-uuid'));
        $this->assertFalse(AggregateRootId::isValid(''));
        $this->assertTrue(AggregateRootId::isValid((string) AggregateRootId::generate()));
    }

    #[Test]
    public function aggregate_root_id_from_string_throws_for_invalid(): void
    {
        $this->expectException(\Ramsey\Uuid\Exception\InvalidUuidStringException::class);
        AggregateRootId::fromString('not-a-uuid');
    }

    #[Test]
    public function aggregate_root_id_stringable(): void
    {
        $id = AggregateRootId::generate();
        $this->assertSame($id->toString(), (string) $id);
    }

    // ─────────────────────────────────────────────────────────────
    // DomainEventCollection Serialization
    // ─────────────────────────────────────────────────────────────

    #[Test]
    public function domain_event_collection_round_trip_via_array(): void
    {
        $order = Order::create(AggregateRootId::generate());
        $order->addItem('prod-1', 1, 10.0);
        $events = $order->pullDomainEvents();

        $array = $events->toArray();
        $restored = \ZeroBoiler\Domain\DomainEventCollection::fromArray($array);

        $this->assertSame($events->count(), $restored->count());
    }

    #[Test]
    public function domain_event_collection_round_trip_via_json(): void
    {
        $order = Order::create(AggregateRootId::generate());
        $events = $order->pullDomainEvents();

        $json = $events->toJson();
        $restored = \ZeroBoiler\Domain\DomainEventCollection::fromJson($json);

        $this->assertSame($events->count(), $restored->count());
    }

    #[Test]
    public function domain_event_collection_empty_operations(): void
    {
        $empty = new \ZeroBoiler\Domain\DomainEventCollection;

        $this->assertTrue($empty->isEmpty());
        $this->assertCount(0, $empty);
        $this->assertNull($empty->first());
        $this->assertNull($empty->last());
        $this->assertFalse($empty->some(fn () => true));
        $this->assertTrue($empty->none(fn () => true));
    }

    #[Test]
    public function domain_event_collection_merge(): void
    {
        $order1 = Order::create(AggregateRootId::generate());
        $events1 = $order1->pullDomainEvents();

        $order2 = Order::create(AggregateRootId::generate());
        $events2 = $order2->pullDomainEvents();

        $merged = $events1->merge($events2);
        $this->assertSame(2, $merged->count());
    }

    #[Test]
    public function domain_event_collection_reduce(): void
    {
        $order = Order::create(AggregateRootId::generate());
        $order->addItem('prod-1', 2, 10.0);
        $order->addItem('prod-2', 1, 5.0);
        $events = $order->pullDomainEvents();

        // Count item_added events
        $count = $events->reduce(
            fn (int $carry, $e): int => $carry + ($e->eventType === 'order.item_added' ? 1 : 0),
            0,
        );

        $this->assertSame(2, $count);
    }

    // ─────────────────────────────────────────────────────────────
    // Guards Trait (via Order if it uses Guards)
    // ─────────────────────────────────────────────────────────────

    #[Test]
    public function guards_throw_expected_exceptions(): void
    {
        // Test via a simple class that uses Guards trait
        $guarded = new class extends \ZeroBoiler\Domain\Entity
        {
            use \ZeroBoiler\Domain\Concerns\Guards;

            public function __construct(int|string|\Stringable $id) { parent::__construct($id); }

            public function validateString(string $value, string $name): void
            {
                $this->assertNotEmptyString($value, $name);
            }

            public function validatePositive(int $value, string $name): void
            {
                $this->assertPositiveInteger($value, $name);
            }

            public function validateRange(int|float $value, int|float $min, int|float $max, string $name): void
            {
                $this->assertRange($value, $min, $max, $name);
            }

            public function validateIn(array $allowed, string $value, string $name): void
            {
                $this->assertIn($allowed, $value, $name);
            }

            public function validateFound(mixed $value, string $name): void
            {
                $this->assertFound($value, $name);
            }
        };

        $this->expectException(InvalidArgumentDomainException::class);
 $guarded->validateString('', 'field');
    }

    #[Test]
    public function guard_assert_positive_integer_throws_for_zero(): void
    {
        $guarded = new class extends \ZeroBoiler\Domain\Entity
        {
            use \ZeroBoiler\Domain\Concerns\Guards;

            public function __construct(int|string|\Stringable $id) { parent::__construct($id); }

            public function check(int $value): void { $this->assertPositiveInteger($value, 'qty'); }
        };

        $this->expectException(InvalidArgumentDomainException::class);
        $guarded->check(0);
    }

    #[Test]
    public function guard_assert_found_throws_not_found(): void
    {
        $guarded = new class extends \ZeroBoiler\Domain\Entity
        {
            use \ZeroBoiler\Domain\Concerns\Guards;

            public function __construct(int|string|\Stringable $id) { parent::__construct($id); }

            public function check(mixed $value): void { $this->assertFound($value, 'User'); }
        };

        $this->expectException(NotFoundDomainException::class);
        $guarded->check(null);
    }
}
