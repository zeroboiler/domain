<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Tests;

use PHPUnit\Framework\TestCase;
use ZeroBoiler\Domain\AggregateRoot;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\Exceptions\{
    ConflictDomainException,
    DomainException,
    InvalidArgumentDomainException,
    InvalidStateDomainException,
    NotFoundDomainException,
    OptimisticLockException,
};
use ZeroBoiler\Events\Domain\DomainEvent;

/**
 * Tests for AggregateRoot::toArray() — domain entity serialization.
 *
 * Verifies that aggregate roots provide a base array representation
 * with identity, version, and type information for response mapping.
 *
 * @covers \ZeroBoiler\Domain\AggregateRoot
 */
final class AggregateRootToArrayTest extends TestCase
{
    private AggregateRootId $id;

    protected function setUp(): void
    {
        parent::setUp();
        $this->id = AggregateRootId::generate();
    }

    public function test_to_array_returns_id_and_version_and_type(): void
    {
        $order = TestOrderForToArray::create($this->id);

        $array = $order->toArray();

        self::assertArrayHasKey('id', $array);
        self::assertArrayHasKey('version', $array);
        self::assertArrayHasKey('type', $array);
        self::assertSame($this->id->toString(), $array['id']);
        self::assertSame(1, $array['version']);
        self::assertSame('TestOrderForToArray', $array['type']);
    }

    public function test_to_array_version_increments_with_events(): void
    {
        $order = TestOrderForToArray::create($this->id);
        $order->applyTestEvent('order.paid', []);
        $order->applyTestEvent('order.shipped', []);

        $array = $order->toArray();

        self::assertSame(3, $array['version']);
    }

    public function test_to_array_id_matches_aggregate_id(): void
    {
        $order = TestOrderForToArray::create($this->id);

        self::assertSame($this->id->toString(), $order->toArray()['id']);
    }

    public function test_to_array_type_is_short_class_name(): void
    {
        $order = TestOrderForToArray::create($this->id);

        self::assertSame('TestOrderForToArray', $order->toArray()['type']);
    }
}

/**
 * Tests for DomainException::toErrorArray() — exception → response mapping.
 *
 * Verifies that domain exceptions provide RFC 9457-compatible error arrays
 * for seamless integration with DomainResponseFactory::error().
 *
 * @covers \ZeroBoiler\Domain\Exceptions\DomainException
 * @covers \ZeroBoiler\Domain\Exceptions\InvalidStateDomainException
 * @covers \ZeroBoiler\Domain\Exceptions\InvalidArgumentDomainException
 * @covers \ZeroBoiler\Domain\Exceptions\NotFoundDomainException
 * @covers \ZeroBoiler\Domain\Exceptions\ConflictDomainException
 * @covers \ZeroBoiler\Domain\Exceptions\OptimisticLockException
 */
final class DomainExceptionToErrorArrayTest extends TestCase
{
    public function test_to_error_array_returns_title_detail_and_code(): void
    {
        $exception = InvalidStateDomainException::because('Order must be pending.');
        $error = $exception->toErrorArray();

        self::assertArrayHasKey('title', $error);
        self::assertArrayHasKey('detail', $error);
        self::assertArrayHasKey('code', $error);
        self::assertSame('InvalidStateDomainException', $error['title']);
        self::assertSame('Order must be pending.', $error['detail']);
        self::assertSame('INVALID_STATE', $error['code']);
    }

    public function test_to_error_array_invalid_argument(): void
    {
        $exception = InvalidArgumentDomainException::because('Quantity must be positive.');
        $error = $exception->toErrorArray();

        self::assertSame('InvalidArgumentDomainException', $error['title']);
        self::assertSame('Quantity must be positive.', $error['detail']);
        self::assertSame('INVALID_ARGUMENT', $error['code']);
    }

    public function test_to_error_array_not_found(): void
    {
        $exception = NotFoundDomainException::because('User not found.');
        $error = $exception->toErrorArray();

        self::assertSame('NotFoundDomainException', $error['title']);
        self::assertSame('NOT_FOUND', $error['code']);
    }

    public function test_to_error_array_conflict(): void
    {
        $exception = ConflictDomainException::because('Concurrent modification.');
        $error = $exception->toErrorArray();

        self::assertSame('ConflictDomainException', $error['title']);
        self::assertSame('CONFLICT', $error['code']);
    }

    public function test_to_error_array_optimistic_lock(): void
    {
        $exception = OptimisticLockException::for('order-123', 5, 3);
        $error = $exception->toErrorArray();

        self::assertSame('OptimisticLockException', $error['title']);
        self::assertSame('OPTIMISTIC_LOCK', $error['code']);
        self::assertStringContainsString('order-123', $error['detail']);
    }

    public function test_to_error_array_custom_code(): void
    {
        $exception = InvalidStateDomainException::because('Custom reason.', code: 'ORDER_EXPIRED');
        $error = $exception->toErrorArray();

        self::assertSame('ORDER_EXPIRED', $error['code']);
    }

    public function test_to_array_returns_debug_info(): void
    {
        $exception = InvalidStateDomainException::because('Test message.');
        $array = $exception->toArray();

        self::assertArrayHasKey('error_code', $array);
        self::assertArrayHasKey('message', $array);
        self::assertArrayHasKey('file', $array);
        self::assertArrayHasKey('line', $array);
        self::assertSame('INVALID_STATE', $array['error_code']);
        self::assertSame('Test message.', $array['message']);
    }
}

/**
 * Minimal aggregate root stub for toArray() testing.
 *
 * Provides public constructor and test event helper for direct access.
 *
 * @internal Used only in AggregateRootToArrayTest.
 */
final class TestOrderForToArray extends AggregateRoot
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
        $order->apply(DomainEvent::occur('order.placed', []));

        return $order;
    }

    /** @internal Exposes protected apply() for testing. */
    public function applyTestEvent(string $type, array $payload): void
    {
        $this->apply(DomainEvent::occur($type, $payload));
    }

    protected function applyOrderPlaced(DomainEvent $event): void
    {
        $this->status = 'placed';
    }
}
