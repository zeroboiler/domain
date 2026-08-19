<?php

declare(strict_types=1);

namespace ZeroBoiler\Domain\Tests\Unit;

use ZeroBoiler\Domain\Exceptions\AggregateNotFoundException;
use ZeroBoiler\Domain\Exceptions\ConflictDomainException;
use ZeroBoiler\Domain\Exceptions\DomainException;
use ZeroBoiler\Domain\Exceptions\InvalidAggregateRootException;
use ZeroBoiler\Domain\Exceptions\InvalidArgumentDomainException;
use ZeroBoiler\Domain\Exceptions\InvalidStateDomainException;
use ZeroBoiler\Domain\Exceptions\InvalidStateDomainException;
use ZeroBoiler\Domain\Exceptions\InvalidStateException;
use ZeroBoiler\Domain\Exceptions\NotFoundDomainException;
use ZeroBoiler\Domain\Exceptions\OptimisticLockException;

/**
 * Unit tests for the complete DomainException hierarchy.
 *
 * Verifies: unique error codes, correct HTTP status mapping,
 * factory methods, custom code override, JsonSerializable output,
 * toArray()/fromArray() round-trip, toJson()/fromJson() round-trip,
 * toErrorArray() RFC 9457 compliance, and exception chaining.
 *
 * @since 2.18.0
 */
final class DomainExceptionHierarchyTest
{
    // ─── Error Code Uniqueness ────────────────────────────────────────────

    public function test_each_exception_has_unique_default_error_code(): void
    {
        $exceptions = [
            InvalidStateDomainException::class,
            InvalidArgumentDomainException::class,
            NotFoundDomainException::class,
            ConflictDomainException::class,
            AggregateNotFoundException::class,
            OptimisticLockException::class,
            InvalidAggregateRootException::class,
            InvalidStateException::class,
        ];

        $codes = [];
        foreach ($exceptions as $class) {
            /** @var DomainException $e */
            $e = new $class('test');
            $codes[$class] = $e->errorCode();
        }

        // Verify no two exceptions share the same default code
        $uniqueCodes = array_unique($codes);
        $this->assertCount(count($codes), $uniqueCodes, 'Each exception type must have a unique default error code.');
    }

    public function test_invalid_state_exception_code(): void
    {
        $e = new InvalidStateDomainException('test');
        $this->assertSame('INVALID_STATE', $e->errorCode());
    }

    public function test_invalid_argument_exception_code(): void
    {
        $e = new InvalidArgumentDomainException('test');
        $this->assertSame('INVALID_ARGUMENT', $e->errorCode());
    }

    public function test_not_found_exception_code(): void
    {
        $e = new NotFoundDomainException('test');
        $this->assertSame('NOT_FOUND', $e->errorCode());
    }

    public function test_conflict_exception_code(): void
    {
        $e = new ConflictDomainException('test');
        $this->assertSame('CONFLICT', $e->errorCode());
    }

    public function test_aggregate_not_found_exception_code(): void
    {
        $e = new AggregateNotFoundException('test');
        $this->assertSame('AGGREGATE_NOT_FOUND', $e->errorCode());
    }

    public function test_optimistic_lock_exception_code(): void
    {
        $e = new OptimisticLockException('test');
        $this->assertSame('OPTIMISTIC_LOCK', $e->errorCode());
    }

    public function test_invalid_aggregate_root_exception_code(): void
    {
        $e = new InvalidAggregateRootException('test');
        $this->assertSame('INVALID_AGGREGATE_ROOT', $e->errorCode());
    }

    public function test_invalid_state_system_exception_code(): void
    {
        $e = new InvalidStateException('test');
        $this->assertSame('INVALID_STATE_SYSTEM', $e->errorCode());
    }

    // ─── HTTP Status Mapping ─────────────────────────────────────────────

    public function test_invalid_state_maps_to_422(): void
    {
        $e = new InvalidStateDomainException('test');
        $this->assertSame(422, $e->httpStatus());
    }

    public function test_invalid_argument_maps_to_422(): void
    {
        $e = new InvalidArgumentDomainException('test');
        $this->assertSame(422, $e->httpStatus());
    }

    public function test_not_found_maps_to_404(): void
    {
        $e = new NotFoundDomainException('test');
        $this->assertSame(404, $e->httpStatus());
    }

    public function test_conflict_maps_to_409(): void
    {
        $e = new ConflictDomainException('test');
        $this->assertSame(409, $e->httpStatus());
    }

    public function test_aggregate_not_found_maps_to_404(): void
    {
        $e = new AggregateNotFoundException('test');
        $this->assertSame(404, $e->httpStatus());
    }

    public function test_optimistic_lock_maps_to_409(): void
    {
        $e = new OptimisticLockException('test');
        $this->assertSame(409, $e->httpStatus());
    }

    public function test_invalid_aggregate_root_maps_to_500(): void
    {
        $e = new InvalidAggregateRootException('test');
        $this->assertSame(500, $e->httpStatus());
    }

    public function test_invalid_state_system_maps_to_500(): void
    {
        $e = new InvalidStateException('test');
        $this->assertSame(500, $e->httpStatus());
    }

    // ─── Factory Methods ─────────────────────────────────────────────────

    public function test_because_factory_sets_message(): void
    {
        $e = InvalidStateDomainException::because('Order must be pending.');
        $this->assertSame('Order must be pending.', $e->getMessage());
    }

    public function test_because_factory_preserves_default_code(): void
    {
        $e = InvalidStateDomainException::because('test');
        $this->assertSame('INVALID_STATE', $e->errorCode());
    }

    public function test_because_factory_allows_custom_code(): void
    {
        $e = InvalidArgumentDomainException::because('test', code: 'NEGATIVE_AMOUNT');
        $this->assertSame('NEGATIVE_AMOUNT', $e->errorCode());
    }

    public function test_not_found_for_aggregate_factory(): void
    {
        $e = NotFoundDomainException::forAggregate('Order', 'order-123');
        $this->assertStringContainsString('Order', $e->getMessage());
        $this->assertStringContainsString('order-123', $e->getMessage());
    }

    public function test_not_found_for_id_factory(): void
    {
        $e = NotFoundDomainException::forId('entity-456');
        $this->assertStringContainsString('entity-456', $e->getMessage());
    }

    public function test_optimistic_lock_for_factory(): void
    {
        $e = OptimisticLockException::for('id-1', expectedVersion: 5, actualVersion: 6);
        $this->assertStringContainsString('5', $e->getMessage());
        $this->assertStringContainsString('6', $e->getMessage());
    }

    // ─── toErrorArray() RFC 9457 ─────────────────────────────────────────

    public function test_to_error_array_has_required_keys(): void
    {
        $e = NotFoundDomainException::forId('test-1');
        $array = $e->toErrorArray();

        $this->assertArrayHasKey('title', $array);
        $this->assertArrayHasKey('detail', $array);
        $this->assertArrayHasKey('code', $array);
        $this->assertArrayHasKey('status', $array);

        $this->assertSame('NotFoundDomainException', $array['title']);
        $this->assertSame('NOT_FOUND', $array['code']);
        $this->assertSame(404, $array['status']);
    }

    // ─── toArray()/fromArray() Round-Trip ─────────────────────────────────

    public function test_to_array_from_array_round_trip(): void
    {
        $original = InvalidStateDomainException::because('Cannot pay shipped order.', code: 'INVALID_ORDER_STATE');
        $array = $original->toArray();

        $this->assertArrayHasKey('error_code', $array);
        $this->assertArrayHasKey('message', $array);
        $this->assertArrayHasKey('file', $array);
        $this->assertArrayHasKey('line', $array);

        $restored = DomainException::fromArray($array, InvalidStateDomainException::class);
        $this->assertSame('Cannot pay shipped order.', $restored->getMessage());
        $this->assertSame('INVALID_ORDER_STATE', $restored->errorCode());
    }

    public function test_to_error_array_from_array_round_trip(): void
    {
        $original = ConflictDomainException::because('Duplicate email.');
        $errorArray = $original->toErrorArray();

        $restored = DomainException::fromArray($errorArray, ConflictDomainException::class);
        $this->assertSame('Duplicate email.', $restored->getMessage());
        $this->assertSame('CONFLICT', $restored->errorCode());
    }

    // ─── toJson()/fromJson() Round-Trip ───────────────────────────────────

    public function test_to_json_from_json_round_trip(): void
    {
        $original = NotFoundDomainException::forAggregate('User', 'user-99');
        $json = $original->toJson();

        $this->assertNotEmpty($json);
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);

        $restored = DomainException::fromJson($json, NotFoundDomainException::class);
        $this->assertSame(404, $restored->httpStatus());
        $this->assertStringContainsString('user-99', $restored->getMessage());
    }

    // ─── JsonSerializable ─────────────────────────────────────────────────

    public function test_json_serialize_matches_to_error_array(): void
    {
        $e = OptimisticLockException::for('id-1', expectedVersion: 3, actualVersion: 5);

        $this->assertSame($e->toErrorArray(), $e->jsonSerialize());
    }

    // ─── Custom Code Override via Constructor ─────────────────────────────

    public function test_constructor_custom_code_overrides_default(): void
    {
        $e = new InvalidStateDomainException('test', 0, null, 'CUSTOM_CODE');
        $this->assertSame('CUSTOM_CODE', $e->errorCode());
    }

    public function test_empty_custom_code_falls_back_to_default(): void
    {
        $e = new InvalidStateDomainException('test', 0, null, '');
        $this->assertSame('INVALID_STATE', $e->errorCode());
    }

    // ─── Exception Chaining ───────────────────────────────────────────────

    public function test_exception_chaining_preserves_previous(): void
    {
        $previous = new \RuntimeException('Original failure');
        $e = new InvalidStateDomainException('Wrapped', 0, $previous);

        $this->assertSame($previous, $e->getPrevious());
        $this->assertSame('Wrapped', $e->getMessage());
    }

    public function test_because_factory_with_previous(): void
    {
        // because() doesn't accept $previous, but the constructor does
        $previous = new \RuntimeException('DB error');
        $e = new NotFoundDomainException('Not found due to DB error', 0, $previous);

        $this->assertSame('Not found due to DB error', $e->getMessage());
        $this->assertSame(404, $e->httpStatus());
        $this->assertSame($previous, $e->getPrevious());
    }

    // ─── Inheritance Verification ─────────────────────────────────────────

    public function test_all_exceptions_extend_domain_exception(): void
    {
        $exceptions = [
            new InvalidStateDomainException('t'),
            new InvalidArgumentDomainException('t'),
            new NotFoundDomainException('t'),
            new ConflictDomainException('t'),
            new AggregateNotFoundException('t'),
            new OptimisticLockException('t'),
            new InvalidAggregateRootException('t'),
            new InvalidStateException('t'),
        ];

        foreach ($exceptions as $e) {
            $this->assertInstanceOf(DomainException::class, $e);
            $this->assertInstanceOf(\JsonSerializable::class, $e);
            $this->assertInstanceOf(\Exception::class, $e);
        }
    }

    public function test_all_exceptions_are_final(): void
    {
        $reflectionClasses = [
            InvalidStateDomainException::class,
            InvalidArgumentDomainException::class,
            NotFoundDomainException::class,
            ConflictDomainException::class,
            AggregateNotFoundException::class,
            OptimisticLockException::class,
            InvalidAggregateRootException::class,
            InvalidStateException::class,
        ];

        foreach ($reflectionClasses as $class) {
            $ref = new \ReflectionClass($class);
            $this->assertTrue($ref->isFinal(), "{$class} must be final.");
        }
    }

    // ─── Helpers ──────────────────────────────────────────────────────────

    private function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected !== $actual) {
            throw new \RuntimeException(
                ($message !== '' ? $message . ': ' : '')
                . sprintf('Expected %s, got %s.', var_export($expected, true), var_export($actual, true))
            );
        }
    }

    private function assertCount(int $expected, array|\Countable $array, string $message = ''): void
    {
        $actual = count($array);
        if ($expected !== $actual) {
            throw new \RuntimeException(
                ($message !== '' ? $message . ': ' : '')
                . sprintf('Expected count %d, got %d.', $expected, $actual)
            );
        }
    }

    private function assertInstanceOf(string $expected, mixed $actual): void
    {
        if (! $actual instanceof $expected) {
            throw new \RuntimeException(
                sprintf('Expected instance of %s, got %s.', $expected, get_debug_type($actual))
            );
        }
    }

    private function assertArrayHasKey(string|int $key, array $array): void
    {
        if (! array_key_exists($key, $array)) {
            throw new \RuntimeException(sprintf('Array does not contain key "%s".', $key));
        }
    }

    private function assertStringContainsString(string $needle, string $haystack): void
    {
        if (! str_contains($haystack, $needle)) {
            throw new \RuntimeException(
                sprintf('Failed asserting that "%s" contains "%s".', $haystack, $needle)
            );
        }
    }

    private function assertNotEmpty(mixed $value): void
    {
        if (empty($value)) {
            throw new \RuntimeException('Failed asserting that value is not empty.');
        }
    }

    private function assertIsArray(mixed $value): void
    {
        if (! is_array($value)) {
            throw new \RuntimeException(sprintf('Expected array, got %s.', get_debug_type($value)));
        }
    }

    private function assertTrue(bool $value, string $message = ''): void
    {
        if ($value !== true) {
            throw new \RuntimeException($message !== '' ? $message : 'Failed asserting that value is true.');
        }
    }
}
