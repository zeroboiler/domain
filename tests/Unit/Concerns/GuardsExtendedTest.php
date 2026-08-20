<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Tests\Unit\Concerns;

use ZeroBoiler\Domain\Exceptions\InvalidArgumentDomainException;
use ZeroBoiler\Domain\Exceptions\InvalidStateDomainException;
use ZeroBoiler\Domain\Exceptions\NotFoundDomainException;
use ZeroBoiler\Domain\Tests\TestCase;
use ZeroBoiler\Domain\Concerns\Guards;

uses(TestCase::class);

describe('Guards trait — new assertions', function () {
    /** @var object */
    $guard = new class
    {
        use Guards;
    };

    describe('assertInstanceOf', function () use ($guard) {
        it('passes when value is instance of expected class', function () use ($guard) {
            $guard->assertInstanceOf(new \stdClass, \stdClass::class, 'obj');
            expect(true)->toBeTrue();
        });

        it('throws InvalidArgumentDomainException when not an instance', function () use ($guard) {
            $guard->assertInstanceOf('not-an-object', \stdClass::class, 'obj');
        })->throws(InvalidArgumentDomainException::class, 'must be an instance of stdClass');

        it('includes the actual type in the error message', function () use ($guard) {
            try {
                $guard->assertInstanceOf(42, \stdClass::class, 'num');
            } catch (InvalidArgumentDomainException $e) {
                expect($e->getMessage())->toContain('int');
            }
        });
    });

    describe('assertMatchesRegex', function () use ($guard) {
        it('passes when string matches the pattern', function () use ($guard) {
            $guard->assertMatchesRegex('AB123456', '/^[A-Z]{2}[0-9]{6}$/', 'code');
            expect(true)->toBeTrue();
        });

        it('throws InvalidArgumentDomainException when pattern does not match', function () use ($guard) {
            $guard->assertMatchesRegex('invalid', '/^[A-Z]{2}[0-9]{6}$/', 'code');
        })->throws(InvalidArgumentDomainException::class, 'REGEX_MISMATCH');
    });

    describe('assertGreaterThan', function () use ($guard) {
        it('passes when value is greater than min', function () use ($guard) {
            $guard->assertGreaterThan(10, 5, 'amount');
            expect(true)->toBeTrue();
        });

        it('throws when value equals min', function () use ($guard) {
            $guard->assertGreaterThan(5, 5, 'amount');
        })->throws(InvalidArgumentDomainException::class, 'VALUE_TOO_SMALL');

        it('throws when value is less than min', function () use ($guard) {
            $guard->assertGreaterThan(3, 5, 'amount');
        })->throws(InvalidArgumentDomainException::class, 'VALUE_TOO_SMALL');

        it('works with float values', function () use ($guard) {
            $guard->assertGreaterThan(0.01, 0.0, 'rate');
            expect(true)->toBeTrue();
        });
    });

    describe('assertLessThan', function () use ($guard) {
        it('passes when value is less than max', function () use ($guard) {
            $guard->assertLessThan(5, 10, 'discount');
            expect(true)->toBeTrue();
        });

        it('throws when value equals max', function () use ($guard) {
            $guard->assertLessThan(10, 10, 'discount');
        })->throws(InvalidArgumentDomainException::class, 'VALUE_TOO_LARGE');

        it('throws when value exceeds max', function () use ($guard) {
            $guard->assertLessThan(15, 10, 'discount');
        })->throws(InvalidArgumentDomainException::class, 'VALUE_TOO_LARGE');
    });

    describe('error code consistency', function () use ($guard) {
        it('assertInstanceOf uses INVALID_TYPE error code', function () use ($guard) {
            try {
                $guard->assertInstanceOf(42, \stdClass::class, 'x');
            } catch (InvalidArgumentDomainException $e) {
                expect($e->errorCode())->toBe('INVALID_TYPE');
            }
        });

        it('assertMatchesRegex uses REGEX_MISMATCH error code', function () use ($guard) {
            try {
                $guard->assertMatchesRegex('bad', '/^[0-9]+$/', 'x');
            } catch (InvalidArgumentDomainException $e) {
                expect($e->errorCode())->toBe('REGEX_MISMATCH');
            }
        });

        it('assertGreaterThan uses VALUE_TOO_SMALL error code', function () use ($guard) {
            try {
                $guard->assertGreaterThan(0, 0, 'x');
            } catch (InvalidArgumentDomainException $e) {
                expect($e->errorCode())->toBe('VALUE_TOO_SMALL');
            }
        });

        it('assertLessThan uses VALUE_TOO_LARGE error code', function () use ($guard) {
            try {
                $guard->assertLessThan(100, 100, 'x');
            } catch (InvalidArgumentDomainException $e) {
                expect($e->errorCode())->toBe('VALUE_TOO_LARGE');
            }
        });
    });
});
