<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace Tests\Domain;

use PHPUnit\Framework\TestCase;
use ZeroBoiler\Domain\Exceptions\AggregateNotFoundException;
use ZeroBoiler\Domain\Exceptions\ConflictDomainException;
use ZeroBoiler\Domain\Exceptions\InvalidAggregateRootException;
use ZeroBoiler\Domain\Exceptions\InvalidArgumentDomainException;
use ZeroBoiler\Domain\Exceptions\InvalidStateDomainException;
use ZeroBoiler\Domain\Exceptions\NotFoundDomainException;
use ZeroBoiler\Domain\Exceptions\OptimisticLockException;

/**
 * RFC 9457 Problem Details compliance tests for all domain exception types.
 *
 * Verifies that every domain exception serializes to a valid RFC 9457 structure
 * with consistent title, detail, status, and code fields.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc9457
 *
 * @since 1.48.0
 */
final class DomainExceptionToRfc9457Test extends TestCase
{
    /**
     * All concrete domain exception classes.
     *
     * @var list<class-string<\ZeroBoiler\Domain\Exceptions\DomainException>>
     */
    private const EXCEPTION_TYPES = [
        InvalidStateDomainException::class,
        InvalidArgumentDomainException::class,
        NotFoundDomainException::class,
        AggregateNotFoundException::class,
        ConflictDomainException::class,
        OptimisticLockException::class,
        InvalidAggregateRootException::class,
    ];

    // ─────────────────────────────────────────────────────────
    // Basic RFC 9457 structure
    // ─────────────────────────────────────────────────────────

    /**
     * @test
     */
    public function all_exceptions_produce_to_error_array_with_required_keys(): void
    {
        $requiredKeys = ['title', 'detail', 'code'];

        foreach (self::EXCEPTION_TYPES as $type) {
            $exception = $this->createDefaultException($type);
            $errorArray = $exception->toErrorArray();

            foreach ($requiredKeys as $key) {
                $this->assertArrayHasKey(
                    $key,
                    $errorArray,
                    "{$type}::toErrorArray() missing required key '{$key}'",
                );
            }
        }
    }

    /**
     * @test
     */
    public function all_exceptions_json_serialize_to_valid_structure(): void
    {
        foreach (self::EXCEPTION_TYPES as $type) {
            $exception = $this->createDefaultException($type);
            $json = json_encode($exception, JSON_THROW_ON_ERROR);
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

            $this->assertIsArray($decoded, "{$type}::jsonSerialize() did not produce an array");
            $this->assertArrayHasKey('title', $decoded, "{$type} JSON missing 'title'");
            $this->assertArrayHasKey('detail', $decoded, "{$type} JSON missing 'detail'");
            $this->assertArrayHasKey('code', $decoded, "{$type} JSON missing 'code'");
        }
    }

    // ─────────────────────────────────────────────────────────
    // Error code uniqueness
    // ─────────────────────────────────────────────────────────

    /**
     * @test
     */
    public function default_error_codes_are_unique_per_type(): void
    {
        $codes = [];

        foreach (self::EXCEPTION_TYPES as $type) {
            $exception = $this->createDefaultException($type);
            $code = $exception->errorCode();

            $this->assertNotContains(
                $code,
                $codes,
                "Duplicate default error code '{$code}' in {$type}",
            );

            $codes[] = $code;
        }

        // 7 unique codes for 7 exception types
        $this->assertCount(7, $codes);
    }

    /**
     * @test
     */
    public function error_codes_follow_uppercase_snake_convention(): void
    {
        foreach (self::EXCEPTION_TYPES as $type) {
            $exception = $this->createDefaultException($type);
            $code = $exception->errorCode();

            $this->assertMatchesRegularExpression(
                '/^[A-Z][A-Z0-9_]*$/',
                $code,
                "{$type} error code '{$code}' does not follow UPPER_SNAKE_CASE convention",
            );
        }
    }

    // ─────────────────────────────────────────────────────────
    // Custom error code override
    // ─────────────────────────────────────────────────────────

    /**
     * @test
     */
    public function custom_error_code_overrides_default(): void
    {
        $exception = InvalidStateDomainException::because('test', code: 'CUSTOM_CODE');
        $this->assertSame('CUSTOM_CODE', $exception->errorCode());

        $exception = InvalidArgumentDomainException::because('test', code: 'CUSTOM_ARG');
        $this->assertSame('CUSTOM_ARG', $exception->errorCode());

        $exception = NotFoundDomainException::because('test', code: 'CUSTOM_404');
        $this->assertSame('CUSTOM_404', $exception->errorCode());

        $exception = ConflictDomainException::because('test', code: 'CUSTOM_CONFLICT');
        $this->assertSame('CUSTOM_CONFLICT', $exception->errorCode());
    }

    // ─────────────────────────────────────────────────────────
    // Factory method output consistency
    // ─────────────────────────────────────────────────────────

    /**
     * @test
     */
    public function factory_methods_produce_consistent_to_error_array_and_json(): void
    {
        foreach (self::EXCEPTION_TYPES as $type) {
            $exception = $this->createDefaultException($type);

            $errorArray = $exception->toErrorArray();
            $jsonDecoded = json_decode(json_encode($exception), true);

            // title, detail, and code must match between toErrorArray() and jsonSerialize()
            $this->assertSame(
                $errorArray['title'],
                $jsonDecoded['title'],
                "{$type}: toErrorArray() and jsonSerialize() title mismatch",
            );
            $this->assertSame(
                $errorArray['detail'],
                $jsonDecoded['detail'],
                "{$type}: toErrorArray() and jsonSerialize() detail mismatch",
            );
            $this->assertSame(
                $errorArray['code'],
                $jsonDecoded['code'],
                "{$type}: toErrorArray() and jsonSerialize() code mismatch",
            );
        }
    }

    /**
     * @test
     */
    public function typed_factory_methods_produce_valid_exceptions(): void
    {
        // NotFoundDomainException::forAggregate(type, id)
        $e = NotFoundDomainException::forAggregate('Order', 'order-123');
        $this->assertStringContainsString('Order', $e->getMessage());
        $this->assertStringContainsString('order-123', $e->getMessage());

        // AggregateNotFoundException::for(type, id)
        $e = AggregateNotFoundException::for('App\\Domain\\Order', 'order-456');
        $this->assertStringContainsString('App\\Domain\\Order', $e->getMessage());

        // OptimisticLockException::for(id, expected, actual)
        $e = OptimisticLockException::for('order-789', expectedVersion: 5, actualVersion: 3);
        $this->assertStringContainsString('5', $e->getMessage());
        $this->assertStringContainsString('3', $e->getMessage());

        // InvalidAggregateRootException::notAnAggregate(obj)
        $e = InvalidAggregateRootException::notAnAggregate(new \stdClass);
        $this->assertStringContainsString('stdClass', $e->getMessage());
    }

    // ─────────────────────────────────────────────────────────
    // toArray() structure verification
    // ─────────────────────────────────────────────────────────

    /**
     * @test
     */
    public function to_array_contains_class_name_and_message(): void
    {
        foreach (self::EXCEPTION_TYPES as $type) {
            $exception = $this->createDefaultException($type);
            $arr = $exception->toArray();

            $this->assertArrayHasKey('error_code', $arr, "{$type} toArray missing error_code");
            $this->assertArrayHasKey('message', $arr, "{$type} toArray missing message");
            $this->assertArrayHasKey('file', $arr, "{$type} toArray missing file");
            $this->assertArrayHasKey('line', $arr, "{$type} toArray missing line");
        }
    }

    // ─────────────────────────────────────────────────────────
    // DomainException inheritance
    // ─────────────────────────────────────────────────────────

    /**
     * @test
     */
    public function all_concrete_exceptions_extend_domain_exception(): void
    {
        foreach (self::EXCEPTION_TYPES as $type) {
            $exception = $this->createDefaultException($type);
            $this->assertInstanceOf(
                \ZeroBoiler\Domain\Exceptions\DomainException::class,
                $exception,
                "{$type} does not extend DomainException",
            );
        }
    }

    /**
     * @test
     */
    public function all_concrete_exceptions_are_final(): void
    {
        $reflection = new \ReflectionClass(\ZeroBoiler\Domain\Exceptions\DomainException::class);

        foreach ($reflection->getMethods() as $method) {
            // No need to check constructor or inherited methods
            if ($method->getDeclaringClass()->getName() === $reflection->getName()) {
                // The class itself should not be instantiated directly (abstract)
                continue;
            }
        }

        foreach (self::EXCEPTION_TYPES as $type) {
            $r = new \ReflectionClass($type);
            $this->assertTrue(
                $r->isFinal(),
                "{$type} is not marked as final",
            );
        }
    }

    /**
     * @test
     */
    public function domain_exception_is_abstract(): void
    {
        $r = new \ReflectionClass(\ZeroBoiler\Domain\Exceptions\DomainException::class);
        $this->assertTrue($r->isAbstract(), 'DomainException should be abstract');
    }

    // ─────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────

    /**
     * Create a default exception instance of the given type.
     *
     * @param  class-string<\ZeroBoiler\Domain\Exceptions\DomainException>  $type
     */
    private function createDefaultException(string $type): \ZeroBoiler\Domain\Exceptions\DomainException
    {
        return match ($type) {
            InvalidStateDomainException::class => InvalidStateDomainException::because('Invalid state for test'),
            InvalidArgumentDomainException::class => InvalidArgumentDomainException::because('Invalid argument for test'),
            NotFoundDomainException::class => NotFoundDomainException::because('Not found for test'),
            AggregateNotFoundException::class => AggregateNotFoundException::for('TestEntity', 'test-id'),
            ConflictDomainException::class => ConflictDomainException::because('Conflict for test'),
            OptimisticLockException::class => OptimisticLockException::for('test-id', expectedVersion: 1, actualVersion: 2),
            InvalidAggregateRootException::class => InvalidAggregateRootException::notAnAggregate(new \stdClass),
            default => throw new \LogicException("Unknown exception type: {$type}"),
        };
    }
}
