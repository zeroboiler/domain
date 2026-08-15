<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Tests\Unit\Exceptions;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ZeroBoiler\Domain\Exceptions\{
    ConflictDomainException,
    DomainException,
    InvalidArgumentDomainException,
    InvalidStateDomainException,
    NotFoundDomainException,
    OptimisticLockException,
    AggregateNotFoundException,
    InvalidAggregateRootException,
    InvalidStateException,
};

#[CoversClass(DomainException::class)]
#[CoversClass(InvalidStateDomainException::class)]
#[CoversClass(InvalidArgumentDomainException::class)]
#[CoversClass(NotFoundDomainException::class)]
#[CoversClass(ConflictDomainException::class)]
#[CoversClass(OptimisticLockException::class)]
#[CoversClass(AggregateNotFoundException::class)]
#[CoversClass(InvalidAggregateRootException::class)]
#[CoversClass(InvalidStateException::class)]
#[Group('unit')]
#[Group('exceptions')]
final class DomainExceptionTest extends TestCase
{
    // ─── InvalidStateDomainException ─────────────────────────────

    public function testInvalidStateBecause(): void
    {
        $e = InvalidStateDomainException::because('Order must be pending to pay.');

        $this->assertSame('Order must be pending to pay.', $e->getMessage());
        $this->assertSame('INVALID_STATE', $e->errorCode());
        $this->assertSame(422, $e->httpStatus());
    }

    // ─── InvalidArgumentDomainException ─────────────────────────

    public function testInvalidArgumentBecause(): void
    {
        $e = InvalidArgumentDomainException::because('Quantity must be > 0.');

        $this->assertSame('Quantity must be > 0.', $e->getMessage());
        $this->assertSame('INVALID_ARGUMENT', $e->errorCode());
        $this->assertSame(422, $e->httpStatus());
    }

    // ─── NotFoundDomainException ─────────────────────────────────

    public function testNotFoundForAggregate(): void
    {
        $e = NotFoundDomainException::forAggregate('Order', 'order-uuid');

        $this->assertStringContainsString('Order', $e->getMessage());
        $this->assertStringContainsString('order-uuid', $e->getMessage());
        $this->assertSame('NOT_FOUND', $e->errorCode());
        $this->assertSame(404, $e->httpStatus());
    }

    public function testNotFoundForId(): void
    {
        $e = NotFoundDomainException::forId('entity-123');

        $this->assertStringContainsString('entity-123', $e->getMessage());
        $this->assertSame('NOT_FOUND', $e->errorCode());
    }

    // ─── ConflictDomainException ─────────────────────────────────

    public function testConflictBecause(): void
    {
        $e = ConflictDomainException::because('Concurrent modification detected.');

        $this->assertSame('CONFLICT', $e->errorCode());
        $this->assertSame(409, $e->httpStatus());
    }

    // ─── OptimisticLockException ────────────────────────────────

    public function testOptimisticLockFor(): void
    {
        $e = OptimisticLockException::for(
            aggregateId: 'order-uuid',
            expectedVersion: 5,
            actualVersion: 6,
        );

        $this->assertSame('OPTIMISTIC_LOCK', $e->errorCode());
        $this->assertSame(409, $e->httpStatus());
        $this->assertStringContainsString('expected version 5', $e->getMessage());
        $this->assertStringContainsString('current version 6', $e->getMessage());
    }

    // ─── AggregateNotFoundException ───────────────────────────────

    public function testAggregateNotFoundFor(): void
    {
        $e = AggregateNotFoundException::for('App\Domain\Order', 'order-uuid');

        $this->assertSame('AGGREGATE_NOT_FOUND', $e->errorCode());
        $this->assertSame(404, $e->httpStatus());
        $this->assertStringContainsString('App\Domain\Order', $e->getMessage());
    }

    // ─── InvalidAggregateRootException ───────────────────────────

    public function testInvalidAggregateRootNotAnAggregate(): void
    {
        $obj = new \stdClass;

        $e = InvalidAggregateRootException::notAnAggregate($obj);

        $this->assertSame('INVALID_AGGREGATE_ROOT', $e->errorCode());
        $this->assertSame(500, $e->httpStatus());
        $this->assertStringContainsString('stdClass', $e->getMessage());
    }

    // ─── InvalidStateException (infrastructure) ────────────────

    public function testInfrastructureInvalidStateException(): void
    {
        $e = InvalidStateException::because('Configuration is invalid.');

        $this->assertSame('INVALID_STATE_SYSTEM', $e->errorCode());
        $this->assertSame(500, $e->httpStatus());
    }

    // ─── Common Interface ────────────────────────────────────────

    public function testAllExceptionsImplementJsonSerializable(): void
    {
        $exceptions = [
            InvalidStateDomainException::because('test'),
            InvalidArgumentDomainException::because('test'),
            NotFoundDomainException::forId('test'),
            ConflictDomainException::because('test'),
            OptimisticLockException::for('id', 1, 2),
            AggregateNotFoundException::for('Class', 'id'),
            InvalidAggregateRootException::notAnAggregate(new \stdClass),
            InvalidStateException::because('test'),
        ];

        foreach ($exceptions as $e) {
            $this->assertInstanceOf(\JsonSerializable::class, $e, get_class($e) . ' must implement JsonSerializable');
        }
    }

    public function testToErrorArrayReturnsRfc9457Structure(): void
    {
        $e = NotFoundDomainException::forId('order-123');
        $error = $e->toErrorArray();

        $this->assertArrayHasKey('title', $error);
        $this->assertArrayHasKey('detail', $error);
        $this->assertArrayHasKey('code', $error);
        $this->assertArrayHasKey('status', $error);
        $this->assertSame('NOT_FOUND', $error['code']);
        $this->assertSame(404, $error['status']);
    }

    public function testJsonEncodeReturnsValidJson(): void
    {
        $e = InvalidStateDomainException::because('test error');
        $json = json_encode($e);

        $this->assertIsString($json);
        $this->assertNotEmpty($json);

        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
    }

    public function testToArrayRoundTrip(): void
    {
        $original = InvalidStateDomainException::because('test');
        $array = $original->toArray();

        $this->assertArrayHasKey('error_code', $array);
        $this->assertArrayHasKey('message', $array);
    }

    public function testCustomErrorCode(): void
    {
        $e = InvalidStateDomainException::because('test', code: 'CUSTOM_CODE');

        $this->assertSame('CUSTOM_CODE', $e->errorCode());
    }
}
