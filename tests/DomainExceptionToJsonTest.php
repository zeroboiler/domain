<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Tests;

use PHPUnit\Framework\TestCase;
use ZeroBoiler\Domain\Exceptions\DomainException;
use ZeroBoiler\Domain\Exceptions\InvalidStateDomainException;
use ZeroBoiler\Domain\Exceptions\NotFoundDomainException;

/**
 * Tests for DomainException::toJson() and round-trip serialization.
 *
 * @covers \ZeroBoiler\Domain\Exceptions\DomainException
 * @covers \ZeroBoiler\Domain\Exceptions\InvalidStateDomainException
 * @covers \ZeroBoiler\Domain\Exceptions\NotFoundDomainException
 */
final class DomainExceptionToJsonTest extends TestCase
{
    public function test_domain_exception_to_json_returns_valid_rfc9457_json(): void
    {
        $exception = InvalidStateDomainException::because('Order must be pending to pay.');

        $json = $exception->toJson();

        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        $this->assertIsArray($decoded);
        $this->assertSame('InvalidStateDomainException', $decoded['title']);
        $this->assertSame('Order must be pending to pay.', $decoded['detail']);
        $this->assertSame('INVALID_STATE', $decoded['code']);
        $this->assertSame(422, $decoded['status']);
    }

    public function test_domain_exception_json_round_trip_preserves_message(): void
    {
        $original = NotFoundDomainException::forAggregate('Order', 'order-123');

        $json = $original->toJson();
        $restored = InvalidStateDomainException::fromJson($json, InvalidStateDomainException::class);

        // fromJson with class parameter creates the correct type
        $this->assertInstanceOf(DomainException::class, $restored);
        $this->assertSame($original->getMessage(), $restored->getMessage());
    }

    public function test_not_found_exception_to_json_preserves_all_fields(): void
    {
        $original = NotFoundDomainException::forAggregate('Order', 'order-123');

        $json = $original->toJson();
        $restored = NotFoundDomainException::fromJson($json, NotFoundDomainException::class);

        $this->assertSame($original->getMessage(), $restored->getMessage());
        $this->assertSame('NOT_FOUND', $restored->errorCode());
    }

    public function test_to_json_with_custom_options(): void
    {
        $exception = InvalidStateDomainException::because('Test');

        $json = $exception->toJson(JSON_PRETTY_PRINT);

        // Should contain newlines if pretty-printed
        $this->assertStringContainsString("\n", $json);
    }
}
