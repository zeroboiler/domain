<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Tests;

use ZeroBoiler\Domain\Exceptions\AggregateNotFoundException;
use ZeroBoiler\Domain\Exceptions\ConflictDomainException;
use ZeroBoiler\Domain\Exceptions\InvalidAggregateRootException;
use ZeroBoiler\Domain\Exceptions\InvalidArgumentDomainException;
use ZeroBoiler\Domain\Exceptions\InvalidStateDomainException;
use ZeroBoiler\Domain\Exceptions\NotFoundDomainException;
use ZeroBoiler\Domain\Exceptions\OptimisticLockException;

/**
 * Tests for machine-readable error codes on domain exceptions.
 *
 * Each domain exception type returns a stable errorCode() string
 * for programmatic handling in API responses, middleware, and
 * client-side error processing.
 */
test('InvalidStateDomainException returns default error code', function (): void {
    $e = InvalidStateDomainException::because('Order must be pending to pay.');

    expect($e->errorCode())->toBe('INVALID_STATE');
});

test('InvalidStateDomainException accepts custom error code', function (): void {
    $e = InvalidStateDomainException::because('Order must be pending to pay.', 'ORDER_NOT_PENDING');

    expect($e->errorCode())->toBe('ORDER_NOT_PENDING');
});

test('InvalidArgumentDomainException returns default error code', function (): void {
    $e = InvalidArgumentDomainException::because('Quantity must be positive.');

    expect($e->errorCode())->toBe('INVALID_ARGUMENT');
});

test('InvalidArgumentDomainException accepts custom error code', function (): void {
    $e = InvalidArgumentDomainException::because('Quantity must be positive.', 'INVALID_QUANTITY');

    expect($e->errorCode())->toBe('INVALID_QUANTITY');
});

test('NotFoundDomainException returns default error code', function (): void {
    $e = NotFoundDomainException::because('User not found with ID: 123');

    expect($e->errorCode())->toBe('NOT_FOUND');
});

test('NotFoundDomainException forAggregate returns default error code', function (): void {
    $e = NotFoundDomainException::forAggregate('Order', '550e8400-...');

    expect($e->errorCode())->toBe('NOT_FOUND');
});

test('NotFoundDomainException forAggregate accepts custom error code', function (): void {
    $e = NotFoundDomainException::forAggregate('Order', '550e8400-...', 'ORDER_MISSING');

    expect($e->errorCode())->toBe('ORDER_MISSING');
});

test('ConflictDomainException returns default error code', function (): void {
    $e = ConflictDomainException::because('Concurrent modification detected.');

    expect($e->errorCode())->toBe('CONFLICT');
});

test('OptimisticLockException returns default error code', function (): void {
    $e = OptimisticLockException::for('order-123', expectedVersion: 5, actualVersion: 3);

    expect($e->errorCode())->toBe('OPTIMISTIC_LOCK');
});

test('OptimisticLockException accepts custom error code', function (): void {
    $e = OptimisticLockException::for('order-123', expectedVersion: 5, actualVersion: 3, code: 'STALE_VERSION');

    expect($e->errorCode())->toBe('STALE_VERSION');
});

test('AggregateNotFoundException returns default error code', function (): void {
    $e = AggregateNotFoundException::for('App\\Domain\\Order', '550e8400-...');

    expect($e->errorCode())->toBe('AGGREGATE_NOT_FOUND');
});

test('AggregateNotFoundException accepts custom error code', function (): void {
    $e = AggregateNotFoundException::for('App\\Domain\\Order', '550e8400-...', 'MISSING_ORDER');

    expect($e->errorCode())->toBe('MISSING_ORDER');
});

test('InvalidAggregateRootException returns default error code', function (): void {
    $e = InvalidAggregateRootException::notAnAggregate(new \stdClass);

    expect($e->errorCode())->toBe('INVALID_AGGREGATE_ROOT');
});

test('InvalidAggregateRootException accepts custom error code', function (): void {
    $e = InvalidAggregateRootException::notAnAggregate(new \stdClass, 'NOT_AGGREGATE');

    expect($e->errorCode())->toBe('NOT_AGGREGATE');
});

test('all domain exceptions have unique default error codes', function (): void {
    $exceptions = [
        InvalidStateDomainException::because('test'),
        InvalidArgumentDomainException::because('test'),
        NotFoundDomainException::because('test'),
        ConflictDomainException::because('test'),
        OptimisticLockException::for('id', 1, 2),
        AggregateNotFoundException::for('Type', 'id'),
        InvalidAggregateRootException::notAnAggregate(new \stdClass),
    ];

    $codes = array_map(fn ($e) => $e->errorCode(), $exceptions);

    // All codes must be unique
    expect($codes)->toHaveCount(count(array_unique($codes)));
});

test('error code is stable across multiple calls', function (): void {
    $e = InvalidStateDomainException::because('test');

    expect($e->errorCode())->toBe($e->errorCode());
});

test('error code is a non-empty string', function (): void {
    $exceptions = [
        InvalidStateDomainException::because('test'),
        InvalidArgumentDomainException::because('test'),
        NotFoundDomainException::because('test'),
        ConflictDomainException::because('test'),
        OptimisticLockException::for('id', 1, 2),
        AggregateNotFoundException::for('Type', 'id'),
        InvalidAggregateRootException::notAnAggregate(new \stdClass),
    ];

    foreach ($exceptions as $exception) {
        expect($exception->errorCode())
            ->toBeString()
            ->not->toBeEmpty()
            ->toMatch('/^[A-Z][A-Z0-9_]+$/');
    }
});

test('error code uses uppercase snake case convention', function (): void {
    $e = InvalidStateDomainException::because('test');

    // Standard default codes should be UPPER_SNAKE_CASE
    expect($e->errorCode())->toMatch('/^[A-Z][A-Z0-9_]*$/');
});

test('error message and code are independent', function (): void {
    $e1 = InvalidStateDomainException::because('Order must be pending', 'CUSTOM_CODE');
    $e2 = InvalidStateDomainException::because('Payment already processed', 'OTHER_CODE');

    expect($e1->getMessage())->toBe('Order must be pending');
    expect($e1->errorCode())->toBe('CUSTOM_CODE');
    expect($e2->getMessage())->toBe('Payment already processed');
    expect($e2->errorCode())->toBe('OTHER_CODE');
});
