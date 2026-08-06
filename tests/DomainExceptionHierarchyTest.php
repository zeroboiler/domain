<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace Tests\Domain;

use ZeroBoiler\Domain\AggregateRoot;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\Contracts\Identifier;
use ZeroBoiler\Domain\Identifiers\IntegerIdentifier;
use ZeroBoiler\Domain\Identifiers\StringIdentifier;
use ZeroBoiler\Domain\Identifiers\UuidIdentifier;
use ZeroBoiler\Domain\Identifiers\UlidIdentifier;
use ZeroBoiler\Domain\Snapshots\Snapshot;
use ZeroBoiler\Domain\Snapshots\SnapshotPolicy;
use ZeroBoiler\Events\Domain\DomainEvent;

/**
 * Tests for domain exception hierarchy and factory methods.
 *
 * @see \ZeroBoiler\Domain\Exceptions\DomainException
 * @see \ZeroBoiler\Domain\Exceptions\InvalidStateDomainException
 * @see \ZeroBoiler\Domain\Exceptions\InvalidArgumentDomainException
 * @see \ZeroBoiler\Domain\Exceptions\NotFoundDomainException
 * @see \ZeroBoiler\Domain\Exceptions\ConflictDomainException
 * @see \ZeroBoiler\Domain\Exceptions\AggregateNotFoundException
 * @see \ZeroBoiler\Domain\Exceptions\OptimisticLockException
 */
final class DomainExceptionHierarchyTest extends \PHPUnit\Framework\TestCase
{
    // ─── DomainException base ─────────────────────────────────────────

    public function testDomainExceptionIsAbstract(): void
    {
        $reflection = new \ReflectionClass(\ZeroBoiler\Domain\Exceptions\DomainException::class);
        $this->assertTrue($reflection->isAbstract());
    }

    public function testCustomDomainExceptionCanBeExtended(): void
    {
        $exception = OrderAlreadyShippedException::forOrder('order-123');

        $this->assertInstanceOf(\ZeroBoiler\Domain\Exceptions\DomainException::class, $exception);
        $this->assertInstanceOf(\Exception::class, $exception);
        $this->assertSame(
            'Order order-123 has already been shipped.',
            $exception->getMessage(),
        );
    }

    // ─── InvalidStateDomainException ──────────────────────────────────

    public function testInvalidStateDomainExceptionBecause(): void
    {
        $exception = \ZeroBoiler\Domain\Exceptions\InvalidStateDomainException::because(
            'Order must be pending to pay.',
        );

        $this->assertSame('Order must be pending to pay.', $exception->getMessage());
        $this->assertInstanceOf(\ZeroBoiler\Domain\Exceptions\DomainException::class, $exception);
    }

    // ─── InvalidArgumentDomainException ──────────────────────────────

    public function testInvalidArgumentDomainExceptionBecause(): void
    {
        $exception = \ZeroBoiler\Domain\Exceptions\InvalidArgumentDomainException::because(
            'Quantity must be positive.',
        );

        $this->assertSame('Quantity must be positive.', $exception->getMessage());
        $this->assertInstanceOf(\ZeroBoiler\Domain\Exceptions\DomainException::class, $exception);
    }

    // ─── NotFoundDomainException ──────────────────────────────────────

    public function testNotFoundDomainExceptionBecause(): void
    {
        $exception = \ZeroBoiler\Domain\Exceptions\NotFoundDomainException::because(
            'User not found with ID: 42',
        );

        $this->assertSame('User not found with ID: 42', $exception->getMessage());
    }

    public function testNotFoundDomainExceptionForAggregate(): void
    {
        $exception = \ZeroBoiler\Domain\Exceptions\NotFoundDomainException::forAggregate(
            'Order',
            '550e8400-e29b-41d4-a716-446655440000',
        );

        $this->assertSame(
            'Aggregate "Order" with ID "550e8400-e29b-41d4-a716-446655440000" was not found.',
            $exception->getMessage(),
        );
    }

    // ─── AggregateNotFoundException ───────────────────────────────────

    public function testAggregateNotFoundExceptionFor(): void
    {
        $exception = \ZeroBoiler\Domain\Exceptions\AggregateNotFoundException::for(
            'App\\Domain\\Order',
            '550e8400-e29b-41d4-a716-446655440000',
        );

        $this->assertSame(
            'Aggregate App\Domain\Order with ID 550e8400-e29b-41d4-a716-446655440000 not found.',
            $exception->getMessage(),
        );
    }

    // ─── ConflictDomainException ────────────────────────────────────

    public function testConflictDomainExceptionBecause(): void
    {
        $exception = \ZeroBoiler\Domain\Exceptions\ConflictDomainException::because(
            'Concurrent modification detected.',
        );

        $this->assertSame('Concurrent modification detected.', $exception->getMessage());
    }

    // ─── OptimisticLockException ─────────────────────────────────────

    public function testOptimisticLockExceptionFor(): void
    {
        $exception = \ZeroBoiler\Domain\Exceptions\OptimisticLockException::for(
            aggregateId: 'order-123',
            expectedVersion: 5,
            actualVersion: 3,
        );

        $this->assertStringContainsString('order-123', $exception->getMessage());
        $this->assertStringContainsString('expected version 5', $exception->getMessage());
        $this->assertStringContainsString('current version 3', $exception->getMessage());
    }

    // ─── InvalidAggregateRootException ────────────────────────────────

    public function testInvalidAggregateRootExceptionNotAnAggregate(): void
    {
        $notAnAggregate = new \stdClass();
        $exception = \ZeroBoiler\Domain\Exceptions\InvalidAggregateRootException::notAnAggregate(
            $notAnAggregate,
        );

        $this->assertStringContainsString('stdClass', $exception->getMessage());
        $this->assertStringContainsString('AggregateRoot', $exception->getMessage());
    }

    // ─── InvalidStateException (non-domain) ──────────────────────────

    public function testInvalidStateExceptionBecause(): void
    {
        $exception = \ZeroBoiler\Domain\Exceptions\InvalidStateException::because(
            'Application configuration is invalid.',
        );

        $this->assertSame('Application configuration is invalid.', $exception->getMessage());
        // Note: InvalidStateException extends Exception, NOT DomainException
        $this->assertNotInstanceOf(
            \ZeroBoiler\Domain\Exceptions\DomainException::class,
            $exception,
        );
    }
}

// ─── Test fixture ───────────────────────────────────────────────────

final class OrderAlreadyShippedException extends \ZeroBoiler\Domain\Exceptions\DomainException
{
    public static function forOrder(string $orderId): self
    {
        return new self("Order {$orderId} has already been shipped.");
    }
}
