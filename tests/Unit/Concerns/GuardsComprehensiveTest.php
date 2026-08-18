<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace Tests\Domain\Unit\Concerns;

use PHPUnit\Framework\TestCase;
use ZeroBoiler\Domain\Concerns\Guards;
use ZeroBoiler\Domain\Exceptions\InvalidArgumentDomainException;
use ZeroBoiler\Domain\Exceptions\InvalidStateDomainException;
use ZeroBoiler\Domain\Exceptions\NotFoundDomainException;

/**
 * Comprehensive tests for the Guards trait.
 *
 * Verifies that every guard method throws the correct exception type
 * with a descriptive message when the assertion fails, and passes
 * silently when the assertion holds.
 */
final class GuardsComprehensiveTest extends TestCase
{
    /** @var object{assertNotEmptyString, assertPositiveInteger, assertNonNegativeInteger, assertNotNull, assertFound, assertStateIs, assertStateIn, assertStateIsNot, assertRange, assertIn, assertMaxLength} */
    private object $subject;

    protected function setUp(): void
    {
        $this->subject = new class
        {
            use Guards;
        };
    }

    // ── assertNotEmptyString ────────────────────────────────────────────

    public function test_assert_not_empty_string_passes_for_non_empty(): void
    {
        $this->subject->assertNotEmptyString('hello', 'name');
        $this->assertTrue(true); // No exception = pass
    }

    public function test_assert_not_empty_string_throws_for_empty(): void
    {
        $this->expectException(InvalidArgumentDomainException::class);
        $this->expectExceptionMessage('"name" must not be empty');

        $this->subject->assertNotEmptyString('', 'name');
    }

    // ── assertMaxLength ─────────────────────────────────────────────────

    public function test_assert_max_length_passes_within_limit(): void
    {
        $this->subject->assertMaxLength('abc', 10, 'field');
        $this->assertTrue(true);
    }

    public function test_assert_max_length_passes_at_exact_limit(): void
    {
        $this->subject->assertMaxLength('abc', 3, 'field');
        $this->assertTrue(true);
    }

    public function test_assert_max_length_throws_when_exceeded(): void
    {
        $this->expectException(InvalidArgumentDomainException::class);
        $this->expectExceptionMessage('"title" must not exceed 5 characters, got 10');

        $this->subject->assertMaxLength('1234567890', 5, 'title');
    }

    // ── assertPositiveInteger ───────────────────────────────────────────

    public function test_assert_positive_integer_passes(): void
    {
        $this->subject->assertPositiveInteger(1, 'count');
        $this->subject->assertPositiveInteger(100, 'count');
        $this->assertTrue(true);
    }

    public function test_assert_positive_integer_throws_for_zero(): void
    {
        $this->expectException(InvalidArgumentDomainException::class);
        $this->expectExceptionMessage('"qty" must be a positive integer, got 0');

        $this->subject->assertPositiveInteger(0, 'qty');
    }

    public function test_assert_positive_integer_throws_for_negative(): void
    {
        $this->expectException(InvalidArgumentDomainException::class);

        $this->subject->assertPositiveInteger(-1, 'qty');
    }

    // ── assertNonNegativeInteger ────────────────────────────────────────

    public function test_assert_non_negative_integer_passes_for_zero(): void
    {
        $this->subject->assertNonNegativeInteger(0, 'balance');
        $this->assertTrue(true);
    }

    public function test_assert_non_negative_integer_passes_for_positive(): void
    {
        $this->subject->assertNonNegativeInteger(42, 'balance');
        $this->assertTrue(true);
    }

    public function test_assert_non_negative_integer_throws_for_negative(): void
    {
        $this->expectException(InvalidArgumentDomainException::class);
        $this->expectExceptionMessage('"balance" must be zero or positive, got -5');

        $this->subject->assertNonNegativeInteger(-5, 'balance');
    }

    // ── assertNotNull ───────────────────────────────────────────────────

    public function test_assert_not_null_passes_for_value(): void
    {
        $this->subject->assertNotNull('value', 'field');
        $this->assertTrue(true);
    }

    public function test_assert_not_null_throws_for_null(): void
    {
        $this->expectException(InvalidArgumentDomainException::class);
        $this->expectExceptionMessage('"user" must not be null');

        $this->subject->assertNotNull(null, 'user');
    }

    // ── assertFound ─────────────────────────────────────────────────────

    public function test_assert_found_passes_for_value(): void
    {
        $this->subject->assertFound(new \stdClass, 'Order');
        $this->assertTrue(true);
    }

    public function test_assert_found_throws_for_null(): void
    {
        $this->expectException(NotFoundDomainException::class);
        $this->expectExceptionMessage('Order was not found');

        $this->subject->assertFound(null, 'Order');
    }

    // ── assertStateIs ───────────────────────────────────────────────────

    public function test_assert_state_is_passes(): void
    {
        $this->subject->assertStateIs('pending', 'pending', 'pay');
        $this->assertTrue(true);
    }

    public function test_assert_state_is_throws_on_mismatch(): void
    {
        $this->expectException(InvalidStateDomainException::class);
        $this->expectExceptionMessage('Cannot pay — must be in "pending" state, got "shipped"');

        $this->subject->assertStateIs('pending', 'shipped', 'pay');
    }

    // ── assertStateIn ────────────────────────────────────────────────────

    public function test_assert_state_in_passes(): void
    {
        $this->subject->assertStateIn(['pending', 'confirmed'], 'confirmed', 'cancel');
        $this->assertTrue(true);
    }

    public function test_assert_state_in_throws_on_invalid(): void
    {
        $this->expectException(InvalidStateDomainException::class);
        $this->expectExceptionMessage('Cannot cancel — must be in one of ["pending", "confirmed"] state, got "shipped"');

        $this->subject->assertStateIn(['pending', 'confirmed'], 'shipped', 'cancel');
    }

    // ── assertStateIsNot ────────────────────────────────────────────────

    public function test_assert_state_is_not_passes(): void
    {
        $this->subject->assertStateIsNot('cancelled', 'pending', 'ship');
        $this->assertTrue(true);
    }

    public function test_assert_state_is_not_throws_on_match(): void
    {
        $this->expectException(InvalidStateDomainException::class);
        $this->expectExceptionMessage('Cannot ship — must not be in "cancelled" state');

        $this->subject->assertStateIsNot('cancelled', 'cancelled', 'ship');
    }

    // ── assertRange ──────────────────────────────────────────────────────

    public function test_assert_range_passes_int_within(): void
    {
        $this->subject->assertRange(50, 1, 100, 'amount');
        $this->assertTrue(true);
    }

    public function test_assert_range_passes_float_within(): void
    {
        $this->subject->assertRange(99.99, 0.01, 99999.99, 'amount');
        $this->assertTrue(true);
    }

    public function test_assert_range_passes_at_boundaries(): void
    {
        $this->subject->assertRange(1, 1, 100, 'value');
        $this->subject->assertRange(100, 1, 100, 'value');
        $this->assertTrue(true);
    }

    public function test_assert_range_throws_below_min(): void
    {
        $this->expectException(InvalidArgumentDomainException::class);
        $this->expectExceptionMessage('"amount" must be between 1 and 100, got 0');

        $this->subject->assertRange(0, 1, 100, 'amount');
    }

    public function test_assert_range_throws_above_max(): void
    {
        $this->expectException(InvalidArgumentDomainException::class);

        $this->subject->assertRange(101, 1, 100, 'amount');
    }

    // ── assertIn ─────────────────────────────────────────────────────────

    public function test_assert_in_passes(): void
    {
        $this->subject->assertIn(['USD', 'EUR', 'GBP'], 'EUR', 'currency');
        $this->assertTrue(true);
    }

    public function test_assert_in_throws_for_invalid(): void
    {
        $this->expectException(InvalidArgumentDomainException::class);
        $this->expectExceptionMessage('"currency" must be one of ["USD", "EUR", "GBP"], got "JPY"');

        $this->subject->assertIn(['USD', 'EUR', 'GBP'], 'JPY', 'currency');
    }

    // ── Exception type verification ──────────────────────────────────────

    public function test_assert_not_empty_string_uses_invalid_argument_exception(): void
    {
        try {
            $this->subject->assertNotEmptyString('', 'field');
            $this->fail('Expected exception');
        } catch (InvalidArgumentDomainException $e) {
            $this->assertSame('EMPTY_STRING', $e->errorCode());
        }
    }

    public function test_assert_positive_integer_error_code(): void
    {
        try {
            $this->subject->assertPositiveInteger(-1, 'n');
            $this->fail('Expected exception');
        } catch (InvalidArgumentDomainException $e) {
            $this->assertSame('NON_POSITIVE_INTEGER', $e->errorCode());
        }
    }

    public function test_assert_non_negative_integer_error_code(): void
    {
        try {
            $this->subject->assertNonNegativeInteger(-1, 'n');
            $this->fail('Expected exception');
        } catch (InvalidArgumentDomainException $e) {
            $this->assertSame('NEGATIVE_INTEGER', $e->errorCode());
        }
    }

    public function test_assert_not_null_error_code(): void
    {
        try {
            $this->subject->assertNotNull(null, 'x');
            $this->fail('Expected exception');
        } catch (InvalidArgumentDomainException $e) {
            $this->assertSame('NULL_VALUE', $e->errorCode());
        }
    }

    public function test_assert_max_length_error_code(): void
    {
        try {
            $this->subject->assertMaxLength(str_repeat('x', 11), 10, 'name');
            $this->fail('Expected exception');
        } catch (InvalidArgumentDomainException $e) {
            $this->assertSame('STRING_TOO_LONG', $e->errorCode());
        }
    }

    public function test_assert_range_error_code(): void
    {
        try {
            $this->subject->assertRange(200, 1, 100, 'v');
            $this->fail('Expected exception');
        } catch (InvalidArgumentDomainException $e) {
            $this->assertSame('OUT_OF_RANGE', $e->errorCode());
        }
    }

    public function test_assert_in_error_code(): void
    {
        try {
            $this->subject->assertIn(['a', 'b'], 'c', 'x');
            $this->fail('Expected exception');
        } catch (InvalidArgumentDomainException $e) {
            $this->assertSame('INVALID_OPTION', $e->errorCode());
        }
    }
}
