<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Tests\Unit\Concerns;

use ZeroBoiler\Domain\Exceptions\InvalidArgumentDomainException;
use ZeroBoiler\Domain\Exceptions\InvalidStateDomainException;
use ZeroBoiler\Domain\Exceptions\NotFoundDomainException;

/**
 * Comprehensive Guards trait production contract verification.
 *
 * Tests all 11 guard methods, their exception types, error codes,
 * RFC 9457 serialization, and integration with AggregateRoot.
 *
 * @covers \ZeroBoiler\Domain\Concerns\Guards
 */
final class GuardsTraitProductionContractTest extends \PHPUnit\Framework\TestCase
{
    // -------------------------------------------------------
    // Helpers
    // -------------------------------------------------------

    private function createOrder(string $status = 'pending', int $total = 100): OrderWithGuards
    {
        return OrderWithGuards::create($status, $total);
    }

    // -------------------------------------------------------
    // assertNotEmptyString
    // -------------------------------------------------------

    public function test_assertNotEmptyString_passes_for_non_empty(): void
    {
        $order = $this->createOrder(status: 'pending');
        $this->assertInstanceOf(OrderWithGuards::class, $order);
    }

    public function test_assertNotEmptyString_throws_for_empty_string(): void
    {
        $this->expectException(InvalidArgumentDomainException::class);
        $this->expectExceptionMessage('"status" must not be empty');

        OrderWithGuards::create(status: '', total: 100);
    }

    public function test_assertNotEmptyString_error_code_is_EMPTY_STRING(): void
    {
        try {
            OrderWithGuards::create(status: '', total: 100);
            $this->fail('Expected InvalidArgumentDomainException');
        } catch (InvalidArgumentDomainException $e) {
            $this->assertSame('EMPTY_STRING', $e->errorCode());
        }
    }

    // -------------------------------------------------------
    // assertPositiveInteger
    // -------------------------------------------------------

    public function test_assertPositiveInteger_passes_for_positive(): void
    {
        $order = $this->createOrder(total: 1);
        $this->assertSame(1, $order->total);
    }

    public function test_assertPositiveInteger_throws_for_zero(): void
    {
        $this->expectException(InvalidArgumentDomainException::class);
        $this->expectExceptionMessage('"total" must be a positive integer');

        OrderWithGuards::create(status: 'pending', total: 0);
    }

    public function test_assertPositiveInteger_throws_for_negative(): void
    {
        $this->expectException(InvalidArgumentDomainException::class);
        $this->expectExceptionMessage('"total" must be a positive integer');

        OrderWithGuards::create(status: 'pending', total: -5);
    }

    public function test_assertPositiveInteger_error_code_is_NON_POSITIVE_INTEGER(): void
    {
        try {
            OrderWithGuards::create(status: 'pending', total: 0);
            $this->fail('Expected InvalidArgumentDomainException');
        } catch (InvalidArgumentDomainException $e) {
            $this->assertSame('NON_POSITIVE_INTEGER', $e->errorCode());
        }
    }

    // -------------------------------------------------------
    // assertNonNegativeInteger
    // -------------------------------------------------------

    public function test_assertNonNegativeInteger_passes_for_zero(): void
    {
        $this->expectNotToPerformAssertions();
        $order = $this->createOrder();
        $order->validateBalance(0);
    }

    public function test_assertNonNegativeInteger_passes_for_positive(): void
    {
        $this->expectNotToPerformAssertions();
        $order = $this->createOrder();
        $order->validateBalance(42);
    }

    public function test_assertNonNegativeInteger_throws_for_negative(): void
    {
        $this->expectException(InvalidArgumentDomainException::class);
        $this->expectExceptionMessage('"balance" must be zero or positive');

        $order = $this->createOrder();
        $order->validateBalance(-1);
    }

    public function test_assertNonNegativeInteger_error_code_is_NEGATIVE_INTEGER(): void
    {
        try {
            $this->createOrder()->validateBalance(-1);
            $this->fail('Expected InvalidArgumentDomainException');
        } catch (InvalidArgumentDomainException $e) {
            $this->assertSame('NEGATIVE_INTEGER', $e->errorCode());
        }
    }

    // -------------------------------------------------------
    // assertNotNull
    // -------------------------------------------------------

    public function test_assertNotNull_passes_for_non_null(): void
    {
        $this->expectNotToPerformAssertions();
        $this->createOrder()->validateCustomer('user-id');
    }

    public function test_assertNotNull_throws_for_null(): void
    {
        $this->expectException(InvalidArgumentDomainException::class);
        $this->expectExceptionMessage('"customer" must not be null');

        $this->createOrder()->validateCustomer(null);
    }

    public function test_assertNotNull_error_code_is_NULL_VALUE(): void
    {
        try {
            $this->createOrder()->validateCustomer(null);
            $this->fail('Expected InvalidArgumentDomainException');
        } catch (InvalidArgumentDomainException $e) {
            $this->assertSame('NULL_VALUE', $e->errorCode());
        }
    }

    // -------------------------------------------------------
    // assertFound
    // -------------------------------------------------------

    public function test_assertFound_passes_for_non_null(): void
    {
        $this->expectNotToPerformAssertions();
        $this->createOrder()->assertOrderExists(new \stdClass);
    }

    public function test_assertFound_throws_NotFoundDomainException_for_null(): void
    {
        $this->expectException(NotFoundDomainException::class);
        $this->expectExceptionMessage('Order was not found.');

        $this->createOrder()->assertOrderExists(null);
    }

    public function test_assertFound_error_code_is_NOT_FOUND(): void
    {
        try {
            $this->createOrder()->assertOrderExists(null);
            $this->fail('Expected NotFoundDomainException');
        } catch (NotFoundDomainException $e) {
            $this->assertSame('NOT_FOUND', $e->errorCode());
        }
    }

    // -------------------------------------------------------
    // assertStateIs
    // -------------------------------------------------------

    public function test_assertStateIs_passes_when_state_matches(): void
    {
        $order = $this->createOrder(status: 'pending');
        $this->expectNotToPerformAssertions();
        $order->pay();
    }

    public function test_assertStateIs_throws_InvalidStateDomainException_when_mismatches(): void
    {
        $order = OrderWithGuards::create(status: 'shipped', total: 100);

        $this->expectException(InvalidStateDomainException::class);
        $this->expectExceptionMessage('Cannot pay — must be in "pending" state, got "shipped".');

        $order->pay();
    }

    public function test_assertStateIs_error_code_is_INVALID_STATE(): void
    {
        try {
            OrderWithGuards::create(status: 'shipped', total: 100)->pay();
            $this->fail('Expected InvalidStateDomainException');
        } catch (InvalidStateDomainException $e) {
            $this->assertSame('INVALID_STATE', $e->errorCode());
        }
    }

    // -------------------------------------------------------
    // assertStateIn
    // -------------------------------------------------------

    public function test_assertStateIn_passes_when_state_in_allowed_list(): void
    {
        $order = $this->createOrder(status: 'confirmed', total: 100);
        $this->expectNotToPerformAssertions();
        $order->cancel();
    }

    public function test_assertStateIn_throws_when_state_not_in_allowed_list(): void
    {
        $order = OrderWithGuards::create(status: 'shipped', total: 100);

        $this->expectException(InvalidStateDomainException::class);
        $this->expectExceptionMessage('Cannot cancel — must be in one of ["pending", "confirmed"] state, got "shipped".');

        $order->cancel();
    }

    // -------------------------------------------------------
    // assertStateIsNot
    // -------------------------------------------------------

    public function test_assertStateIsNot_passes_when_state_differs(): void
    {
        $order = $this->createOrder(status: 'pending', total: 100);
        $this->expectNotToPerformAssertions();
        $order->ship();
    }

    public function test_assertStateIsNot_throws_when_state_matches(): void
    {
        $order = OrderWithGuards::create(status: 'cancelled', total: 100);

        $this->expectException(InvalidStateDomainException::class);
        $this->expectExceptionMessage('Cannot ship — must not be in "cancelled" state.');

        $order->ship();
    }

    // -------------------------------------------------------
    // assertRange
    // -------------------------------------------------------

    public function test_assertRange_passes_within_bounds(): void
    {
        $this->expectNotToPerformAssertions();
        $this->createOrder()->validateTotalRange(50);
    }

    public function test_assertRange_passes_at_exact_boundary_min(): void
    {
        $this->expectNotToPerformAssertions();
        $this->createOrder()->validateTotalRange(0.01);
    }

    public function test_assertRange_passes_at_exact_boundary_max(): void
    {
        $this->expectNotToPerformAssertions();
        $this->createOrder()->validateTotalRange(99999.99);
    }

    public function test_assertRange_throws_below_minimum(): void
    {
        $this->expectException(InvalidArgumentDomainException::class);
        $this->expectExceptionMessage('"total" must be between 0.01 and 99999.99');

        $this->createOrder()->validateTotalRange(0);
    }

    public function test_assertRange_throws_above_maximum(): void
    {
        $this->expectException(InvalidArgumentDomainException::class);
        $this->expectExceptionMessage('"total" must be between 0.01 and 99999.99');

        $this->createOrder()->validateTotalRange(100000);
    }

    public function test_assertRange_error_code_is_OUT_OF_RANGE(): void
    {
        try {
            $this->createOrder()->validateTotalRange(-1);
            $this->fail('Expected InvalidArgumentDomainException');
        } catch (InvalidArgumentDomainException $e) {
            $this->assertSame('OUT_OF_RANGE', $e->errorCode());
        }
    }

    // -------------------------------------------------------
    // assertIn
    // -------------------------------------------------------

    public function test_assertIn_passes_for_valid_option(): void
    {
        $this->expectNotToPerformAssertions();
        $this->createOrder()->validateCurrency('USD');
    }

    public function test_assertIn_throws_for_invalid_option(): void
    {
        $this->expectException(InvalidArgumentDomainException::class);
        $this->expectExceptionMessage('"currency" must be one of ["USD", "EUR", "GBP"], got "JPY"');

        $this->createOrder()->validateCurrency('JPY');
    }

    public function test_assertIn_error_code_is_INVALID_OPTION(): void
    {
        try {
            $this->createOrder()->validateCurrency('JPY');
            $this->fail('Expected InvalidArgumentDomainException');
        } catch (InvalidArgumentDomainException $e) {
            $this->assertSame('INVALID_OPTION', $e->errorCode());
        }
    }

    // -------------------------------------------------------
    // assertMaxLength
    // -------------------------------------------------------

    public function test_assertMaxLength_passes_for_short_string(): void
    {
        $this->expectNotToPerformAssertions();
        $this->createOrder()->validateNameLength('John');
    }

    public function test_assertMaxLength_throws_for_too_long_string(): void
    {
        $this->expectException(InvalidArgumentDomainException::class);
        $this->expectExceptionMessage('"name" must not exceed 5 characters');

        $this->createOrder()->validateNameLength('Jonathan');
    }

    public function test_assertMaxLength_error_code_is_STRING_TOO_LONG(): void
    {
        try {
            $this->createOrder()->validateNameLength('Too Long Name');
            $this->fail('Expected InvalidArgumentDomainException');
        } catch (InvalidArgumentDomainException $e) {
            $this->assertSame('STRING_TOO_LONG', $e->errorCode());
        }
    }

    // -------------------------------------------------------
    // RFC 9457 serialization across all guard exceptions
    // -------------------------------------------------------

    public function test_guard_exceptions_produce_rfc9457_error_array(): void
    {
        $tests = [
            'EMPTY_STRING' => fn () => OrderWithGuards::create(status: '', total: 100),
            'NON_POSITIVE_INTEGER' => fn () => OrderWithGuards::create(status: 'pending', total: 0),
            'INVALID_STATE' => fn () => OrderWithGuards::create(status: 'shipped', total: 100)->pay(),
            'OUT_OF_RANGE' => fn () => $this->createOrder()->validateTotalRange(-1),
            'INVALID_OPTION' => fn () => $this->createOrder()->validateCurrency('JPY'),
            'STRING_TOO_LONG' => fn () => $this->createOrder()->validateNameLength('Too Long'),
            'NULL_VALUE' => fn () => $this->createOrder()->validateCustomer(null),
        ];

        foreach ($tests as $expectedCode => $test) {
            try {
                $test();
                $this->fail("Expected exception for {$expectedCode}");
            } catch (InvalidArgumentDomainException|InvalidStateDomainException $e) {
                $array = $e->toErrorArray();
                $this->assertArrayHasKey('title', $array, "Missing 'title' for {$expectedCode}");
                $this->assertArrayHasKey('detail', $array, "Missing 'detail' for {$expectedCode}");
                $this->assertArrayHasKey('code', $array, "Missing 'code' for {$expectedCode}");
                $this->assertArrayHasKey('status', $array, "Missing 'status' for {$expectedCode}");
                $this->assertSame($expectedCode, $array['code'], "Wrong code for {$expectedCode}");
                $this->assertIsString($array['title']);
                $this->assertIsString($array['detail']);
                $this->assertIsInt($array['status']);
            }
        }
    }

    public function test_assertFound_exception_produces_rfc9457_error_array(): void
    {
        try {
            $this->createOrder()->assertOrderExists(null);
            $this->fail('Expected NotFoundDomainException');
        } catch (NotFoundDomainException $e) {
            $array = $e->toErrorArray();
            $this->assertArrayHasKey('title', $array);
            $this->assertArrayHasKey('detail', $array);
            $this->assertArrayHasKey('code', $array);
            $this->assertArrayHasKey('status', $array);
            $this->assertSame('NOT_FOUND', $array['code']);
        }
    }

    // -------------------------------------------------------
    // Exception type mapping: guard method → exception class
    // -------------------------------------------------------

    public function test_all_guard_methods_throw_correct_exception_type(): void
    {
        $invalidArgGuards = [
            'assertNotEmptyString' => fn () => OrderWithGuards::create(status: '', total: 100),
            'assertPositiveInteger' => fn () => OrderWithGuards::create(status: 'pending', total: -1),
            'assertNonNegativeInteger' => fn () => $this->createOrder()->validateBalance(-1),
            'assertNotNull' => fn () => $this->createOrder()->validateCustomer(null),
            'assertRange' => fn () => $this->createOrder()->validateTotalRange(-1),
            'assertIn' => fn () => $this->createOrder()->validateCurrency('JPY'),
            'assertMaxLength' => fn () => $this->createOrder()->validateNameLength('Too Long'),
        ];

        foreach ($invalidArgGuards as $method => $test) {
            try {
                $test();
                $this->fail("{$method} should throw InvalidArgumentDomainException");
            } catch (InvalidArgumentDomainException $e) {
                $this->assertNotEmpty($e->errorCode(), "{$method} should have an error code");
            }
        }

        $stateGuards = [
            'assertStateIs' => fn () => OrderWithGuards::create(status: 'shipped', total: 100)->pay(),
            'assertStateIn' => fn () => OrderWithGuards::create(status: 'delivered', total: 100)->cancel(),
            'assertStateIsNot' => fn () => OrderWithGuards::create(status: 'cancelled', total: 100)->ship(),
        ];

        foreach ($stateGuards as $method => $test) {
            try {
                $test();
                $this->fail("{$method} should throw InvalidStateDomainException");
            } catch (InvalidStateDomainException $e) {
                $this->assertSame('INVALID_STATE', $e->errorCode());
            }
        }

        try {
            $this->createOrder()->assertOrderExists(null);
            $this->fail('assertFound should throw NotFoundDomainException');
        } catch (NotFoundDomainException $e) {
            $this->assertSame('NOT_FOUND', $e->errorCode());
        }
    }

    // -------------------------------------------------------
    // AggregateRoot integration
    // -------------------------------------------------------

    public function test_guards_trait_works_with_aggregate_root_id(): void
    {
        $order = $this->createOrder(status: 'pending', total: 100);
        $this->assertNotNull($order->id());
        $this->assertIsString($order->id());
    }

    public function test_guards_trait_preserves_aggregate_root_serialization(): void
    {
        $order = $this->createOrder(status: 'pending', total: 100);
        $array = $order->toArray();

        $this->assertArrayHasKey('type', $array);
        $this->assertArrayHasKey('id', $array);
        $this->assertArrayHasKey('status', $array);
        $this->assertArrayHasKey('total', $array);
        $this->assertSame('pending', $array['status']);
        $this->assertSame(100, $array['total']);
    }
}
