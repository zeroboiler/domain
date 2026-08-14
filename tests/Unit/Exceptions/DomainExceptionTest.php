<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Tests\Unit\Exceptions;

use PHPUnit\Framework\TestCase;
use ZeroBoiler\Domain\Exceptions\AggregateNotFoundException;
use ZeroBoiler\Domain\Exceptions\ConflictDomainException;
use ZeroBoiler\Domain\Exceptions\DomainException;
use ZeroBoiler\Domain\Exceptions\InvalidAggregateRootException;
use ZeroBoiler\Domain\Exceptions\InvalidArgumentDomainException;
use ZeroBoiler\Domain\Exceptions\InvalidStateDomainException;
use ZeroBoiler\Domain\Exceptions\InvalidStateException;
use ZeroBoiler\Domain\Exceptions\NotFoundDomainException;
use ZeroBoiler\Domain\Exceptions\OptimisticLockException;

/**
 * @covers \ZeroBoiler\Domain\Exceptions\DomainException
 * @covers \ZeroBoiler\Domain\Exceptions\AggregateNotFoundException
 * @covers \ZeroBoiler\Domain\Exceptions\ConflictDomainException
 * @covers \ZeroBoiler\Domain\Exceptions\InvalidAggregateRootException
 * @covers \ZeroBoiler\Domain\Exceptions\InvalidArgumentDomainException
 * @covers \ZeroBoiler\Domain\Exceptions\InvalidStateDomainException
 * @covers \ZeroBoiler\Domain\Exceptions\InvalidStateException
 * @covers \ZeroBoiler\Domain\Exceptions\NotFoundDomainException
 * @covers \ZeroBoiler\Domain\Exceptions\OptimisticLockException
 */
final class DomainExceptionTest extends TestCase
{
    // ── DomainException (base, abstract) ──────────────────────────────

    public function test_domain_exception_extends_runtime_exception(): void
    {
        $e = InvalidArgumentDomainException::because('test');

        self::assertInstanceOf(\RuntimeException::class, $e);
        self::assertInstanceOf(DomainException::class, $e);
        self::assertSame('test', $e->getMessage());
    }

    public function test_error_code_returns_default_when_no_custom(): void
    {
        $e = InvalidArgumentDomainException::because('test');

        self::assertSame('INVALID_ARGUMENT', $e->errorCode());
    }

    public function test_error_code_returns_custom_when_provided(): void
    {
        $e = InvalidArgumentDomainException::because('test', 'CUSTOM_CODE');

        self::assertSame('CUSTOM_CODE', $e->errorCode());
    }

    public function test_to_error_array_structure(): void
    {
        $e = InvalidArgumentDomainException::because('Price must be positive.');

        $array = $e->toErrorArray();

        self::assertSame([
            'title' => 'InvalidArgumentDomainException',
            'detail' => 'Price must be positive.',
            'code' => 'INVALID_ARGUMENT',
        ], $array);
    }

    public function test_to_array_includes_debug_info(): void
    {
        $e = InvalidArgumentDomainException::because('bad arg');

        $array = $e->toArray();

        self::assertArrayHasKey('error_code', $array);
        self::assertArrayHasKey('message', $array);
        self::assertArrayHasKey('file', $array);
        self::assertArrayHasKey('line', $array);
        self::assertSame('bad arg', $array['message']);
    }

    public function test_json_serialize_returns_rfc9457_format(): void
    {
        $e = InvalidStateDomainException::because('Cannot ship unpaid order.');

        $json = json_encode($e);

        self::assertIsString($json);

        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('InvalidStateDomainException', $decoded['title']);
        self::assertSame('Cannot ship unpaid order.', $decoded['detail']);
        self::assertSame('INVALID_STATE', $decoded['code']);
    }

    public function test_from_array_roundtrip(): void
    {
        $original = InvalidStateDomainException::because('Order already shipped.');
        $serialized = $original->toArray();

        $restored = DomainException::fromArray($serialized, InvalidStateDomainException::class);

        self::assertInstanceOf(InvalidStateDomainException::class, $restored);
        self::assertSame('Order already shipped.', $restored->getMessage());
        self::assertSame('INVALID_STATE', $restored->errorCode());
    }

    public function test_from_json_roundtrip(): void
    {
        $original = ConflictDomainException::because('Duplicate email.');

        $json = json_encode($original->toErrorArray(), flags: JSON_THROW_ON_ERROR);
        $restored = DomainException::fromJson($json, ConflictDomainException::class);

        self::assertInstanceOf(ConflictDomainException::class, $restored);
        self::assertSame('Duplicate email.', $restored->getMessage());
    }

    // ── InvalidArgumentDomainException ──────────────────────────────

    public function test_invalid_argument_error_code(): void
    {
        $e = InvalidArgumentDomainException::because('Quantity must be > 0');

        self::assertSame('INVALID_ARGUMENT', $e->errorCode());
    }

    // ── InvalidStateDomainException ──────────────────────────────────

    public function test_invalid_state_error_code(): void
    {
        $e = InvalidStateDomainException::because('Cannot pay shipped order');

        self::assertSame('INVALID_STATE', $e->errorCode());
    }

    // ── NotFoundDomainException ───────────────────────────────────────

    public function test_not_found_because(): void
    {
        $e = NotFoundDomainException::because('User not found');

        self::assertSame('NOT_FOUND', $e->errorCode());
        self::assertSame('User not found', $e->getMessage());
    }

    public function test_not_found_for_aggregate(): void
    {
        $e = NotFoundDomainException::forAggregate('Order', 'ord-123');

        self::assertSame('NOT_FOUND', $e->errorCode());
        self::assertSame('Aggregate "Order" with ID "ord-123" was not found.', $e->getMessage());
    }

    // ── ConflictDomainException ────────────────────────────────────────

    public function test_conflict_error_code(): void
    {
        $e = ConflictDomainException::because('Concurrent modification');

        self::assertSame('CONFLICT', $e->errorCode());
    }

    // ── AggregateNotFoundException ────────────────────────────────────

    public function test_aggregate_not_found(): void
    {
        $e = AggregateNotFoundException::for('App\\Domain\\Order', 'ord-999');

        self::assertInstanceOf(NotFoundDomainException::class, $e);
        self::assertSame('AGGREGATE_NOT_FOUND', $e->errorCode());
        self::assertStringContainsString('Order', $e->getMessage());
        self::assertStringContainsString('ord-999', $e->getMessage());
    }

    // ── OptimisticLockException ────────────────────────────────────────

    public function test_optimistic_lock(): void
    {
        $e = OptimisticLockException::for('ord-123', expectedVersion: 5, actualVersion: 7);

        self::assertInstanceOf(DomainException::class, $e);
        self::assertSame('OPTIMISTIC_LOCK', $e->errorCode());
        self::assertStringContainsString('ord-123', $e->getMessage());
        self::assertStringContainsString('expected version 5', $e->getMessage());
        self::assertStringContainsString('current version 7', $e->getMessage());
    }

    // ── InvalidAggregateRootException ────────────────────────────────

    public function test_invalid_aggregate_root(): void
    {
        $obj = new \stdClass;
        $e = InvalidAggregateRootException::notAnAggregate($obj);

        self::assertInstanceOf(DomainException::class, $e);
        self::assertSame('INVALID_AGGREGATE_ROOT', $e->errorCode());
        self::assertStringContainsString('stdClass', $e->getMessage());
    }

    // ── InvalidStateException (system-level) ──────────────────────────

    public function test_invalid_state_system(): void
    {
        $e = InvalidStateException::because('Config invalid');

        self::assertInstanceOf(DomainException::class, $e);
        self::assertSame('INVALID_STATE_SYSTEM', $e->errorCode());
        self::assertSame('Config invalid', $e->getMessage());
    }

    // ── Hierarchy: all extend DomainException ─────────────────────────

    /**
     * @dataProvider exceptionHierarchyProvider
     */
    public function test_all_exceptions_extend_domain_exception(string $class, string $factoryMethod, array $factoryArgs): void
    {
        $e = $class::$factoryMethod(...$factoryArgs);

        self::assertInstanceOf(DomainException::class, $e);
        self::assertNotEmpty($e->errorCode());
        self::assertNotEmpty($e->getMessage());
    }

    /**
     * @return array<string, array{class: class-string<DomainException>, factoryMethod: string, factoryArgs: array<int, mixed>}>
     */
    public static function exceptionHierarchyProvider(): array
    {
        return [
            'invalid_argument' => [InvalidArgumentDomainException::class, 'because', ['msg']],
            'invalid_state' => [InvalidStateDomainException::class, 'because', ['msg']],
            'not_found' => [NotFoundDomainException::class, 'because', ['msg']],
            'not_found_aggregate' => [NotFoundDomainException::class, 'forAggregate', ['Type', 'id']],
            'conflict' => [ConflictDomainException::class, 'because', ['msg']],
            'aggregate_not_found' => [AggregateNotFoundException::class, 'for', ['Type', 'id']],
            'optimistic_lock' => [OptimisticLockException::class, 'for', ['id', 1, 2]],
            'invalid_aggregate' => [InvalidAggregateRootException::class, 'notAnAggregate', [new \stdClass]],
            'invalid_state_system' => [InvalidStateException::class, 'because', ['msg']],
        ];
    }
}
