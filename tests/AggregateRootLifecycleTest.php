<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Tests;

use PHPUnit\Framework\TestCase;
use ZeroBoiler\Domain\AggregateRoot;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\DomainEventCollection;
use ZeroBoiler\Domain\Identifiers\UuidIdentifier;
use ZeroBoiler\Domain\Identifiers\UlidIdentifier;
use ZeroBoiler\Domain\Identifiers\StringIdentifier;
use ZeroBoiler\Domain\Identifiers\IntegerIdentifier;
use ZeroBoiler\Events\Domain\DomainEvent;

/**
 * Comprehensive AggregateRoot lifecycle tests.
 *
 * Covers the full lifecycle: creation → events → versioning → toArray → JSON
 * serialization → equality → snapshot reconstitution → event replay.
 *
 * @covers \ZeroBoiler\Domain\AggregateRoot
 * @covers \ZeroBoiler\Domain\AggregateRootId
 * @covers \ZeroBoiler\Domain\Concerns\HasDomainEvents
 * @covers \ZeroBoiler\Domain\Concerns\EventSourced
 * @covers \ZeroBoiler\Domain\Concerns\HasSnapshots
 * @covers \ZeroBoiler\Domain\DomainEventCollection
 */
final class AggregateRootLifecycleTest extends TestCase
{
    private AggregateRootId $id;

    protected function setUp(): void
    {
        parent::setUp();
        $this->id = AggregateRootId::generate();
    }

    // ── Creation & Identity ──────────────────────────────────────────────

    public function test_create_generates_domain_event(): void
    {
        $order = LifecycleTestOrder::create($this->id);

        self::assertSame('pending', $order->status);
        self::assertSame(1, $order->version());
    }

    public function test_pull_domain_events_returns_collection(): void
    {
        $order = LifecycleTestOrder::create($this->id);
        $events = $order->pullDomainEvents();

        self::assertInstanceOf(DomainEventCollection::class, $events);
        self::assertSame(1, $events->count());
        self::assertSame('order.placed', $events->first()->eventType);
    }

    public function test_pull_domain_events_is_destructive(): void
    {
        $order = LifecycleTestOrder::create($this->id);
        $order->pullDomainEvents();

        self::assertFalse($order->hasUncommittedEvents());
    }

    public function test_clear_domain_events_discards_without_returning(): void
    {
        $order = LifecycleTestOrder::create($this->id);
        $order->clearDomainEvents();

        self::assertFalse($order->hasUncommittedEvents());
    }

    public function test_increment_version_advances_counter(): void
    {
        $order = LifecycleTestOrder::create($this->id);
        $initial = $order->version();
        $order->incrementVersion();

        self::assertSame($initial + 1, $order->version());
    }

    // ── Identity & Equality ───────────────────────────────────────────────

    public function test_id_returns_string(): void
    {
        $order = LifecycleTestOrder::create($this->id);

        self::assertSame($this->id->toString(), $order->id());
    }

    public function test_aggregate_id_returns_typed_id(): void
    {
        $order = LifecycleTestOrder::create($this->id);

        self::assertSame($this->id, $order->aggregateId());
    }

    public function test_equals_same_id_and_class(): void
    {
        $order1 = LifecycleTestOrder::create($this->id);
        $order2 = LifecycleTestOrder::create($this->id);

        self::assertTrue($order1->equals($order2));
    }

    public function test_equals_different_class_returns_false(): void
    {
        $order = LifecycleTestOrder::create($this->id);
        $invoice = LifecycleTestInvoice::create($this->id);

        self::assertFalse($order->equals($invoice));
    }

    public function test_equals_different_id_returns_false(): void
    {
        $order1 = LifecycleTestOrder::create($this->id);
        $order2 = LifecycleTestOrder::create(AggregateRootId::generate());

        self::assertFalse($order1->equals($order2));
    }

    // ── toArray() Serialization ───────────────────────────────────────────

    public function test_to_array_has_id_version_type(): void
    {
        $order = LifecycleTestOrder::create($this->id);
        $array = $order->toArray();

        self::assertSame($this->id->toString(), $array['id']);
        self::assertSame(1, $array['version']);
        self::assertSame('LifecycleTestOrder', $array['type']);
    }

    public function test_to_array_reflects_current_version(): void
    {
        $order = LifecycleTestOrder::create($this->id);
        $order->pay(100.0);
        $order->ship();

        $array = $order->toArray();

        self::assertSame(3, $array['version']);
        self::assertSame('shipped', $order->status);
    }

    // ── Event Sourcing: fromHistory() ────────────────────────────────────

    public function test_from_history_reconstitutes_state(): void
    {
        $id = AggregateRootId::generate();
        $placed = DomainEvent::occur('order.placed', ['id' => $id->toString(), 'total' => 50.0]);
        $paid = DomainEvent::occur('order.paid', ['id' => $id->toString(), 'amount' => 50.0]);

        $order = LifecycleTestOrder::fromHistory($placed, $paid);

        self::assertSame('paid', $order->status);
        self::assertSame(50.0, $order->total);
        self::assertSame(2, $order->version());
        self::assertFalse($order->hasUncommittedEvents());
    }

    public function test_from_history_empty_throws(): void
    {
        $this->expectException(\RuntimeException::class);

        LifecycleTestOrder::fromHistory();
    }

    public function test_from_history_missing_id_throws(): void
    {
        $event = DomainEvent::occur('order.placed', ['total' => 50.0]);

        $this->expectException(\RuntimeException::class);

        LifecycleTestOrder::fromHistory($event);
    }

    // ── State Mutation via apply() ──────────────────────────────────────

    public function test_apply_records_event_and_increments_version(): void
    {
        $order = LifecycleTestOrder::create($this->id);

        self::assertSame(1, $order->version());

        $order->pay(25.0);
        self::assertSame(2, $order->version());
        self::assertSame('paid', $order->status);
        self::assertSame(25.0, $order->total);

        $order->ship();
        self::assertSame(3, $order->version());
        self::assertSame('shipped', $order->status);
    }

    public function test_invalid_state_throws_domain_exception(): void
    {
        $order = LifecycleTestOrder::create($this->id);
        $order->pay(10.0);

        $this->expectException(\ZeroBoiler\Domain\Exceptions\InvalidStateDomainException::class);

        $order->pay(5.0);
    }

    // ── AggregateRootId ──────────────────────────────────────────────────

    public function test_aggregate_root_id_generate_creates_uuid(): void
    {
        $id = AggregateRootId::generate();

        self::assertTrue(UuidIdentifier::isValid($id->toString()));
        self::assertSame(36, strlen($id->toString()));
    }

    public function test_aggregate_root_id_from_string_round_trip(): void
    {
        $id = AggregateRootId::generate();
        $restored = AggregateRootId::fromString($id->toString());

        self::assertTrue($id->equals($restored));
        self::assertSame($id->toString(), $restored->toString());
    }

    public function test_aggregate_root_id_json_serialization(): void
    {
        $id = AggregateRootId::generate();
        $json = json_encode($id);

        self::assertSame('"' . $id->toString() . '"', $json);
    }

    // ── Identifier Cross-Type Inequality ─────────────────────────────────

    public function test_uuid_and_ulid_never_equal(): void
    {
        $uuid = TestUuidId::generate();
        $ulid = TestUlidId::generate();

        self::assertFalse($uuid->equals($ulid));
    }

    public function test_uuid_and_string_never_equal(): void
    {
        $uuid = TestUuidId::generate();
        $str = StringIdentifier::from($uuid->toString());

        self::assertFalse($uuid->equals($str));
    }

    public function test_uuid_and_integer_never_equal(): void
    {
        $uuid = TestUuidId::generate();
        $int = IntegerIdentifier::from(1);

        self::assertFalse($uuid->equals($int));
    }

    public function test_different_subclasses_never_equal(): void
    {
        $id = TestUuidId::generate();
        $other = TestOtherUuidId::fromString($id->toString());

        self::assertFalse($id->equals($other));
    }

    // ── Identifier JSON Serialization Consistency ───────────────────────

    public function test_uuid_identifier_json_round_trip(): void
    {
        $id = TestUuidId::generate();
        $json = json_encode($id);
        $restored = TestUuidId::fromString(json_decode($json, true));

        self::assertTrue($id->equals($restored));
    }

    public function test_ulid_identifier_json_round_trip(): void
    {
        $id = TestUlidId::generate();
        $json = json_encode($id);
        $restored = TestUlidId::fromString(json_decode($json, true));

        self::assertTrue($id->equals($restored));
    }

    public function test_string_identifier_json_serialization(): void
    {
        $id = StringIdentifier::from('my-slug');
        $json = json_encode(['slug' => $id]);

        self::assertSame('{"slug":"my-slug"}', $json);
    }

    public function test_integer_identifier_json_serialization(): void
    {
        $id = IntegerIdentifier::from(42);
        $json = json_encode(['seq' => $id]);

        self::assertSame('{"seq":42}', $json);
    }

    // ── DomainException Error Codes ──────────────────────────────────────

    public function test_all_domain_exceptions_have_unique_error_codes(): void
    {
        $exceptions = [
            'state' => \ZeroBoiler\Domain\Exceptions\InvalidStateDomainException::because('test'),
            'argument' => \ZeroBoiler\Domain\Exceptions\InvalidArgumentDomainException::because('test'),
            'not_found' => \ZeroBoiler\Domain\Exceptions\NotFoundDomainException::because('test'),
            'conflict' => \ZeroBoiler\Domain\Exceptions\ConflictDomainException::because('test'),
            'lock' => \ZeroBoiler\Domain\Exceptions\OptimisticLockException::for('id', 1, 2),
            'aggregate' => \ZeroBoiler\Domain\Exceptions\AggregateNotFoundException::for('Type', 'id'),
            'invalid_aggregate' => \ZeroBoiler\Domain\Exceptions\InvalidAggregateRootException::notAnAggregate(new \stdClass),
        ];

        $codes = array_map(fn ($e) => $e->errorCode(), $exceptions);
        $unique = array_unique($codes);

        self::assertCount(count($codes), $unique, 'All error codes must be unique');
    }

    public function test_custom_error_code_overrides_default(): void
    {
        $e = \ZeroBoiler\Domain\Exceptions\InvalidStateDomainException::because('test', code: 'CUSTOM_CODE');

        self::assertSame('CUSTOM_CODE', $e->errorCode());
    }

    public function test_domain_exception_json_serializable(): void
    {
        $e = \ZeroBoiler\Domain\Exceptions\InvalidStateDomainException::because('Test error.');
        $json = json_encode($e);
        $data = json_decode($json, true);

        self::assertArrayHasKey('title', $data);
        self::assertArrayHasKey('detail', $data);
        self::assertArrayHasKey('code', $data);
        self::assertSame('INVALID_STATE', $data['code']);
    }
}

// ── Test Doubles ────────────────────────────────────────────────────────

/**
 * Test aggregate root for lifecycle testing.
 *
 * Supports order.placed, order.paid, order.shipped events.
 * Enforces state invariant: can only pay if pending.
 */
final class LifecycleTestOrder extends AggregateRoot
{
    use \ZeroBoiler\Domain\Concerns\HasDomainEvents;
    use \ZeroBoiler\Domain\Concerns\EventSourced;

    public string $status = 'pending';
    public float $total = 0.0;

    public function __construct(AggregateRootId $id)
    {
        parent::__construct($id);
    }

    public static function create(AggregateRootId $id): self
    {
        $order = new self($id);
        $order->apply(DomainEvent::occur('order.placed', [
            'id' => $id->toString(),
            'total' => 0.0,
        ]));

        return $order;
    }

    public function pay(float $amount): void
    {
        if ($this->status !== 'pending') {
            throw \ZeroBoiler\Domain\Exceptions\InvalidStateDomainException::because(
                'Order must be pending to pay.',
            );
        }

        $this->apply(DomainEvent::occur('order.paid', [
            'id' => $this->id(),
            'amount' => $amount,
        ]));
    }

    public function ship(): void
    {
        $this->apply(DomainEvent::occur('order.shipped', [
            'id' => $this->id(),
        ]));
    }

    protected function applyOrderPlaced(DomainEvent $event): void
    {
        $this->status = 'pending';
        $this->total = $event->payload['total'] ?? 0.0;
    }

    protected function applyOrderPaid(DomainEvent $event): void
    {
        $this->status = 'paid';
        $this->total = $event->payload['amount'];
    }

    protected function applyOrderShipped(DomainEvent $event): void
    {
        $this->status = 'shipped';
    }
}

/**
 * Separate aggregate type for cross-type equality testing.
 */
final class LifecycleTestInvoice extends AggregateRoot
{
    use \ZeroBoiler\Domain\Concerns\HasDomainEvents;
    use \ZeroBoiler\Domain\Concerns\EventSourced;

    public function __construct(AggregateRootId $id)
    {
        parent::__construct($id);
    }

    public static function create(AggregateRootId $id): self
    {
        $invoice = new self($id);
        $invoice->apply(DomainEvent::occur('invoice.created', ['id' => $id->toString()]));

        return $invoice;
    }

    protected function applyInvoiceCreated(DomainEvent $event): void {}
}

/**
 * Test UUID identifier subclass.
 */
final class TestUuidId extends UuidIdentifier {}

/**
 * Another UUID identifier subclass for cross-type inequality.
 */
final class TestOtherUuidId extends UuidIdentifier {}

/**
 * Test ULID identifier subclass.
 */
final class TestUlidId extends UlidIdentifier {}
