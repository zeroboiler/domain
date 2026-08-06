<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Tests;

use ZeroBoiler\Domain\Exceptions\AggregateNotFoundException;
use ZeroBoiler\Domain\Exceptions\ConflictDomainException;
use ZeroBoiler\Domain\Exceptions\DomainException;
use ZeroBoiler\Domain\Exceptions\InvalidAggregateRootException;
use ZeroBoiler\Domain\Exceptions\InvalidArgumentDomainException;
use ZeroBoiler\Domain\Exceptions\InvalidStateDomainException;
use ZeroBoiler\Domain\Exceptions\NotFoundDomainException;
use ZeroBoiler\Domain\Exceptions\OptimisticLockException;

// ===========================================================================
//  DomainException errorCode() correctness tests
//
//  Verifies that the custom error code parameter in because() and for()
//  factory methods is correctly preserved and returned by errorCode().
//  This regression test catches the bug where $code was passed as a 4th
//  argument to Exception::__construct() (which only accepts 3 args).
// ===========================================================================

describe('DomainException errorCode correctness', function (): void {
    // --- Default error codes (no custom code) ---
    describe('default error codes', function (): void {
        it('InvalidStateDomainException defaults to INVALID_STATE', function (): void {
            $e = InvalidStateDomainException::because('test');
            expect($e->errorCode())->toBe('INVALID_STATE');
        });

        it('InvalidArgumentDomainException defaults to INVALID_ARGUMENT', function (): void {
            $e = InvalidArgumentDomainException::because('test');
            expect($e->errorCode())->toBe('INVALID_ARGUMENT');
        });

        it('NotFoundDomainException defaults to NOT_FOUND', function (): void {
            $e = NotFoundDomainException::because('test');
            expect($e->errorCode())->toBe('NOT_FOUND');
        });

        it('ConflictDomainException defaults to CONFLICT', function (): void {
            $e = ConflictDomainException::because('test');
            expect($e->errorCode())->toBe('CONFLICT');
        });

        it('AggregateNotFoundException defaults to AGGREGATE_NOT_FOUND', function (): void {
            $e = AggregateNotFoundException::for('Order', 'uuid');
            expect($e->errorCode())->toBe('AGGREGATE_NOT_FOUND');
        });

        it('OptimisticLockException defaults to OPTIMISTIC_LOCK', function (): void {
            $e = OptimisticLockException::for('uuid', 5, 3);
            expect($e->errorCode())->toBe('OPTIMISTIC_LOCK');
        });

        it('InvalidAggregateRootException defaults to INVALID_AGGREGATE_ROOT', function (): void {
            $e = InvalidAggregateRootException::notAnAggregate(new \stdClass);
            expect($e->errorCode())->toBe('INVALID_AGGREGATE_ROOT');
        });
    });

    // --- Custom error codes (the regression) ---
    describe('custom error codes via because() factories', function (): void {
        it('InvalidStateDomainException preserves custom code', function (): void {
            $e = InvalidStateDomainException::because('Order must be pending', 'ORDER_NOT_PENDING');
            expect($e->errorCode())->toBe('ORDER_NOT_PENDING');
        });

        it('InvalidArgumentDomainException preserves custom code', function (): void {
            $e = InvalidArgumentDomainException::because('Invalid email', 'INVALID_EMAIL');
            expect($e->errorCode())->toBe('INVALID_EMAIL');
        });

        it('NotFoundDomainException::because preserves custom code', function (): void {
            $e = NotFoundDomainException::because('User not found', 'USER_NOT_FOUND');
            expect($e->errorCode())->toBe('USER_NOT_FOUND');
        });

        it('NotFoundDomainException::forAggregate preserves custom code', function (): void {
            $e = NotFoundDomainException::forAggregate('Order', 'uuid-123', 'ORDER_MISSING');
            expect($e->errorCode())->toBe('ORDER_MISSING');
        });

        it('ConflictDomainException preserves custom code', function (): void {
            $e = ConflictDomainException::because('Concurrent write', 'WRITE_CONFLICT');
            expect($e->errorCode())->toBe('WRITE_CONFLICT');
        });

        it('AggregateNotFoundException::for preserves custom code', function (): void {
            $e = AggregateNotFoundException::for('Order', 'uuid-123', 'AGG_MISSING');
            expect($e->errorCode())->toBe('AGG_MISSING');
        });

        it('OptimisticLockException::for preserves custom code', function (): void {
            $e = OptimisticLockException::for('uuid-123', 5, 3, 'STALE_VERSION');
            expect($e->errorCode())->toBe('STALE_VERSION');
        });

        it('InvalidAggregateRootException::notAnAggregate preserves custom code', function (): void {
            $e = InvalidAggregateRootException::notAnAggregate(new \stdClass, 'NOT_AGG');
            expect($e->errorCode())->toBe('NOT_AGG');
        });
    });

    // --- Direct constructor usage ---
    describe('direct constructor with domain code', function (): void {
        it('accepts domain code via 4th constructor parameter', function (): void {
            $e = new InvalidStateDomainException('test', 0, null, 'CUSTOM_CODE');
            expect($e->errorCode())->toBe('CUSTOM_CODE');
        });

        it('falls back to default when domain code is empty', function (): void {
            $e = new InvalidStateDomainException('test');
            expect($e->errorCode())->toBe('INVALID_STATE');
        });

        it('PHP getCode returns int, not string domain code', function (): void {
            $e = InvalidStateDomainException::because('test', 'MY_CODE');
            // PHP's getCode() returns the int code (0), not the string domain code
            expect($e->getCode())->toBe(0);
            // But errorCode() returns the string domain code
            expect($e->errorCode())->toBe('MY_CODE');
        });
    });

    // --- Custom DomainException subclass ---
    describe('custom domain exception subclass', function (): void {
        it('can override defaultErrorCode()', function (): void {
            $custom = new class('test') extends DomainException {
                protected function defaultErrorCode(): string
                {
                    return 'CUSTOM_DEFAULT';
                }
            };

            expect($custom->errorCode())->toBe('CUSTOM_DEFAULT');
        });

        it('custom code takes priority over default', function (): void {
            $custom = new class('test', 0, null, 'OVERRIDE_CODE') extends DomainException {
                protected function defaultErrorCode(): string
                {
                    return 'CUSTOM_DEFAULT';
                }
            };

            expect($custom->errorCode())->toBe('OVERRIDE_CODE');
        });
    });
});
