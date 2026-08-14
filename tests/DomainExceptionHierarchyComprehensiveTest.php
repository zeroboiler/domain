<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace Tests\Domain;

use PHPUnit\Framework\TestCase;
use ZeroBoiler\Domain\Exceptions\{
    DomainException,
    InvalidStateDomainException,
    InvalidArgumentDomainException,
    NotFoundDomainException,
    ConflictDomainException,
    OptimisticLockException,
    AggregateNotFoundException,
    InvalidAggregateRootException,
    InvalidStateException,
};

/**
 * Tests for DomainException hierarchy — factory methods, error codes,
 * RFC 9457 serialization, and round-trip fromArray()/toJson().
 *
 * Ensures all 8 exception types are production-ready with consistent behavior.
 */
final class DomainExceptionHierarchyComprehensiveTest extends TestCase
{
    // ---- Factory Method Tests ----

    public function test_invalid_state_because_creates_exception(): void
    {
        $e = InvalidStateDomainException::because('Order must be pending.');
        $this->assertSame('Order must be pending.', $e->getMessage());
        $this->assertSame('INVALID_STATE', $e->errorCode());
        $this->assertInstanceOf(InvalidStateDomainException::class, $e);
        $this->assertInstanceOf(DomainException::class, $e);
    }

    public function test_invalid_argument_because_creates_exception(): void
    {
        $e = InvalidArgumentDomainException::because('Qty must be > 0.');
        $this->assertSame('Qty must be > 0.', $e->getMessage());
        $this->assertSame('INVALID_ARGUMENT', $e->errorCode());
    }

    public function test_not_found_for_aggregate_creates_exception(): void
    {
        $e = NotFoundDomainException::forAggregate('Order', '123');
        $this->assertStringContainsString('Order', $e->getMessage());
        $this->assertStringContainsString('123', $e->getMessage());
        $this->assertSame('NOT_FOUND', $e->errorCode());
    }

    public function test_aggregate_not_found_for_creates_exception(): void
    {
        $e = AggregateNotFoundException::for('App\\Domain\\Order', 'uuid-123');
        $this->assertStringContainsString('App\\Domain\\Order', $e->getMessage());
        $this->assertStringContainsString('uuid-123', $e->getMessage());
        $this->assertSame('AGGREGATE_NOT_FOUND', $e->errorCode());
    }

    public function test_conflict_because_creates_exception(): void
    {
        $e = ConflictDomainException::because('Concurrent modification detected.');
        $this->assertSame('Concurrent modification detected.', $e->getMessage());
        $this->assertSame('CONFLICT', $e->errorCode());
    }

    public function test_optimistic_lock_for_creates_exception(): void
    {
        $e = OptimisticLockException::for('order-id', expected: 5, actual: 3);
        $this->assertStringContainsString('order-id', $e->getMessage());
        $this->assertStringContainsString('5', $e->getMessage());
        $this->assertStringContainsString('3', $e->getMessage());
        $this->assertSame('OPTIMISTIC_LOCK', $e->errorCode());
    }

    public function test_invalid_aggregate_root_not_an_aggregate_creates_exception(): void
    {
        $obj = new \stdClass();
        $e = InvalidAggregateRootException::notAnAggregate($obj);
        $this->assertStringContainsString('stdClass', $e->getMessage());
        $this->assertSame('INVALID_AGGREGATE_ROOT', $e->errorCode());
    }

    public function test_invalid_state_because_creates_exception2(): void
    {
        $e = InvalidStateException::because('Config is invalid.');
        $this->assertSame('Config is invalid.', $e->getMessage());
        $this->assertSame('INVALID_STATE', $e->errorCode());
    }

    // ---- Custom Error Code Override ----

    public function test_custom_error_code_overrides_default(): void
    {
        $e = InvalidStateDomainException::because('Custom message');
        $this->assertSame('INVALID_STATE', $e->errorCode());

        // Construct with explicit code override
        $e2 = new InvalidStateDomainException('msg', 0, null, 'CUSTOM_CODE');
        $this->assertSame('CUSTOM_CODE', $e2->errorCode());
    }

    // ---- toErrorArray() (RFC 9457) ----

    public function test_to_error_array_returns_structured_format(): void
    {
        $e = InvalidStateDomainException::because('Cannot pay completed order.');
        $error = $e->toErrorArray();

        $this->assertArrayHasKey('title', $error);
        $this->assertArrayHasKey('detail', $error);
        $this->assertArrayHasKey('code', $error);
        $this->assertSame('InvalidStateDomainException', $error['title']);
        $this->assertSame('Cannot pay completed order.', $error['detail']);
        $this->assertSame('INVALID_STATE', $error['code']);
    }

    // ---- jsonSerialize() ----

    public function test_json_serialize_matches_to_error_array(): void
    {
        $e = NotFoundDomainException::forAggregate('User', '456');
        $this->assertSame($e->toErrorArray(), $e->jsonSerialize());
    }

    // ---- toArray() ----

    public function test_to_array_contains_debug_info(): void
    {
        $e = InvalidArgumentDomainException::because('Bad input.');
        $arr = $e->toArray();

        $this->assertArrayHasKey('error_code', $arr);
        $this->assertArrayHasKey('message', $arr);
        $this->assertArrayHasKey('file', $arr);
        $this->assertArrayHasKey('line', $arr);
        $this->assertSame('INVALID_ARGUMENT', $arr['error_code']);
    }

    // ---- Round-Trip: toArray → fromArray ----

    public function test_round_trip_to_array_from_array(): void
    {
        $original = InvalidStateDomainException::because('Round trip test.');
        $restored = DomainException::fromArray($original->toArray(), InvalidStateDomainException::class);

        $this->assertInstanceOf(InvalidStateDomainException::class, $restored);
        $this->assertSame('Round trip test.', $restored->getMessage());
        $this->assertSame('INVALID_STATE', $restored->errorCode());
    }

    public function test_round_trip_to_error_array_from_array(): void
    {
        $original = ConflictDomainException::because('Version mismatch.');
        $restored = DomainException::fromArray($original->toErrorArray(), ConflictDomainException::class);

        $this->assertSame('Version mismatch.', $restored->getMessage());
        $this->assertSame('CONFLICT', $restored->errorCode());
    }

    // ---- Round-Trip: toJson → fromJson ----

    public function test_round_trip_to_json_from_json(): void
    {
        $original = OptimisticLockException::for('id-123', expected: 10, actual: 7);
        $json = json_encode($original->toArray());
        $restored = DomainException::fromJson($json, OptimisticLockException::class);

        $this->assertInstanceOf(OptimisticLockException::class, $restored);
        $this->assertSame('OPTIMISTIC_LOCK', $restored->errorCode());
    }

    public function test_from_json_with_invalid_json_throws(): void
    {
        $this->expectException(\JsonException::class);
        DomainException::fromJson('not-json', InvalidStateDomainException::class);
    }

    public function test_from_json_with_non_object_json_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DomainException::fromJson('"just a string"', InvalidStateDomainException::class);
    }

    // ---- Hierarchy Type Safety ----

    public function test_all_exceptions_extend_domain_exception(): void
    {
        $exceptions = [
            InvalidStateDomainException::because('test'),
            InvalidArgumentDomainException::because('test'),
            NotFoundDomainException::forAggregate('X', '1'),
            ConflictDomainException::because('test'),
            OptimisticLockException::for('id', expected: 1, actual: 2),
            AggregateNotFoundException::for('App\\X', 'id'),
            InvalidAggregateRootException::notAnAggregate(new \stdClass()),
        ];

        foreach ($exceptions as $e) {
            $this->assertInstanceOf(DomainException::class, $e, get_class($e) . ' should extend DomainException.');
        }
    }

    public function test_invalid_state_exception_is_not_domain_exception_by_other_types(): void
    {
        // Ensure type discrimination between exception types
        $invalidState = InvalidStateException::because('test');
        $domainInvalidState = InvalidStateDomainException::because('test');

        $this->assertNotSame($invalidState::class, $domainInvalidState::class);
        $this->assertSame($invalidState->errorCode(), $domainInvalidState->errorCode());
    }

    // ---- PHP Throwable compliance ----

    public function test_exception_is_throwable(): void
    {
        $e = InvalidStateDomainException::because('test');

        $this->assertInstanceOf(\Throwable::class, $e);
        $this->assertInstanceOf(\Exception::class, $e);
    }

    public function test_exception_chaining_preserves_previous(): void
    {
        $previous = new \RuntimeException('DB error');
        $e = new InvalidStateDomainException(
            'Wrapped exception.',
            0,
            $previous,
            'WRAPPED_STATE',
        );

        $this->assertSame($previous, $e->getPrevious());
        $this->assertSame('WRAPPED_STATE', $e->errorCode());
        $this->assertSame('Wrapped exception.', $e->getMessage());
    }

    // ---- fromArray with default class ----

    public function test_from_array_uses_static_class_when_no_class_provided(): void
    {
        $arr = ['message' => 'test', 'error_code' => 'CUSTOM'];
        $restored = InvalidStateDomainException::fromArray($arr);

        $this->assertInstanceOf(InvalidStateDomainException::class, $restored);
        $this->assertSame('test', $restored->getMessage());
        $this->assertSame('CUSTOM', $restored->errorCode());
    }

    public function test_from_array_extracts_detail_as_message(): void
    {
        $errorArray = [
            'title' => 'ConflictDomainException',
            'detail' => 'Concurrent update detected.',
            'code' => 'CONFLICT',
        ];
        $restored = DomainException::fromArray($errorArray, ConflictDomainException::class);

        $this->assertSame('Concurrent update detected.', $restored->getMessage());
        $this->assertSame('CONFLICT', $restored->errorCode());
    }
}
