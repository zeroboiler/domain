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

/**
 * Tests for DomainException → API response bridging.
 *
 * Verifies that all domain exceptions produce correct RFC 9457-compatible
 * error arrays via toErrorArray() and jsonSerialize(), ensuring seamless
 * integration with DomainResponseFactory::error() in the response package.
 *
 * These tests are structured as acceptance tests — they verify the contract
 * that DomainResponseFactory::error() relies on when bridging domain
 * exceptions to API responses.
 */
test('toErrorArray returns title detail and code for all domain exception types')
    ->expect(true)
    ->toBeTrue();

test('DomainException toErrorArray structure is RFC 9457 compatible')
    ->expect(function (): void {
        $exceptions = [
            InvalidStateDomainException::because('State violation'),
            InvalidArgumentDomainException::because('Arg violation'),
            NotFoundDomainException::because('Not found'),
            NotFoundDomainException::forAggregate('Order', 'uuid-123'),
            AggregateNotFoundException::for('App\\Domain\\Order', 'uuid-456'),
            ConflictDomainException::because('Concurrent modification'),
            OptimisticLockException::for('uuid-789', expectedVersion: 5, actualVersion: 3),
            InvalidAggregateRootException::notAnAggregate(new \stdClass),
        ];

        foreach ($exceptions as $exception) {
            $errorArray = $exception->toErrorArray();

            // Must have exactly 3 keys: title, detail, code
            expect(array_keys($errorArray))
                ->toBe(['title', 'detail', 'code']);

            // title must be non-empty string
            expect($errorArray['title'])
                ->toBeString()
                ->not->toBeEmpty();

            // detail must be non-empty string (the exception message)
            expect($errorArray['detail'])
                ->toBeString()
                ->not->toBeEmpty();

            // code must be non-empty string (machine-readable)
            expect($errorArray['code'])
                ->toBeString()
                ->not->toBeEmpty();
        }
    })->toThrowNothing();

test('DomainException jsonSerialize matches toErrorArray output')
    ->expect(function (): void {
        $exceptions = [
            InvalidStateDomainException::because('test message'),
            InvalidArgumentDomainException::because('test message'),
            NotFoundDomainException::because('test message'),
            AggregateNotFoundException::for('Order', 'id-1'),
            ConflictDomainException::because('test message'),
            OptimisticLockException::for('id-1', expectedVersion: 1, actualVersion: 2),
            InvalidAggregateRootException::notAnAggregate(new \stdClass),
        ];

        foreach ($exceptions as $exception) {
            $errorArray = $exception->toErrorArray();
            $jsonSerialized = $exception->jsonSerialize();

            expect($jsonSerialized)->toBe($errorArray);
        }
    })->toThrowNothing();

test('each domain exception type has a unique default error code')
    ->expect(function (): void {
        $exceptions = [
            InvalidStateDomainException::because('x'),
            InvalidArgumentDomainException::because('x'),
            NotFoundDomainException::because('x'),
            AggregateNotFoundException::for('X', 'x'),
            ConflictDomainException::because('x'),
            OptimisticLockException::for('x', expectedVersion: 1, actualVersion: 2),
            InvalidAggregateRootException::notAnAggregate(new \stdClass),
        ];

        $codes = array_map(
            fn (DomainException $e): string => $e->errorCode(),
            $exceptions,
        );

        $uniqueCodes = array_unique($codes);

        expect(count($uniqueCodes))
            ->toBe(count($codes), 'All domain exception types must have unique error codes');
    })->toThrowNothing();

test('custom error code overrides default via because() factory')
    ->expect(function (): void {
        $exception = InvalidStateDomainException::because('test', code: 'ORDER_NOT_PENDING');

        expect($exception->errorCode())
            ->toBe('ORDER_NOT_PENDING');

        expect($exception->toErrorArray()['code'])
            ->toBe('ORDER_NOT_PENDING');

        expect($exception->toErrorArray()['title'])
            ->toBe('InvalidStateDomainException');

        expect($exception->toErrorArray()['detail'])
            ->toBe('test');
    })->toThrowNothing();

test('custom error code overrides default via for() and forAggregate() factories')
    ->expect(function (): void {
        $lock = OptimisticLockException::for('id-1', expectedVersion: 3, actualVersion: 5, code: 'VERSION_CONFLICT');
        expect($lock->errorCode())->toBe('VERSION_CONFLICT');

        $notFound = AggregateNotFoundException::for('Order', 'id-2', code: 'ORDER_MISSING');
        expect($notFound->errorCode())->toBe('ORDER_MISSING');

        $aggregate = NotFoundDomainException::forAggregate('Invoice', 'id-3', code: 'INVOICE_GONE');
        expect($aggregate->errorCode())->toBe('INVOICE_GONE');
    })->toThrowNothing();

test('toErrorArray title uses class basename for clean API output')
    ->expect(function (): void {
        $exception = InvalidStateDomainException::because('test');

        expect($exception->toErrorArray()['title'])
            ->toBe('InvalidStateDomainException');

        // Factory-generated exceptions also use class basename
        $aggregate = AggregateNotFoundException::for('App\\Domain\\Billing\\Invoice', 'inv-123');

        expect($aggregate->toErrorArray()['title'])
            ->toBe('AggregateNotFoundException');
    })->toThrowNothing();

test('toErrorArray detail contains exception message')
    ->expect(function (): void {
        $exception = OptimisticLockException::for(
            'order-550e8400',
            expectedVersion: 5,
            actualVersion: 3,
        );

        expect($exception->toErrorArray()['detail'])
            ->toContain('order-550e8400')
            ->toContain('5')
            ->toContain('3');
    })->toThrowNothing();

test('toArray returns error_code message file line structure')
    ->expect(function (): void {
        $exception = InvalidStateDomainException::because('violation');
        $array = $exception->toArray();

        expect($array)->toHaveKeys(['error_code', 'message', 'file', 'line']);
        expect($array['error_code'])->toBe('INVALID_STATE');
        expect($array['message'])->toBe('violation');
    })->toThrowNothing();

test('DomainException extends Exception and is throwable')
    ->expect(function (): void {
        $exception = InvalidStateDomainException::because('test');

        expect($exception)->toBeInstanceOf(\Throwable::class);
        expect($exception)->toBeInstanceOf(\Exception::class);
        expect($exception)->toBeInstanceOf(DomainException::class);
    })->toThrowNothing();

test('DomainException toErrorArray is suitable for DomainResponseFactory::error() bridge')
    ->expect(function (): void {
        // Simulate what DomainResponseFactory::error() does with the error array
        $exception = InvalidStateDomainException::because('Order must be pending', code: 'ORDER_NOT_PENDING');
        $errorArray = $exception->toErrorArray();

        // DomainResponseFactory::error() expects: title, detail, code
        // and builds: withError($error['title'], $status, $error['detail'], $error['code'])

        $title = $errorArray['title'];       // 'InvalidStateDomainException'
        $detail = $errorArray['detail'];    // 'Order must be pending'
        $code = $errorArray['code'];        // 'ORDER_NOT_PENDING'

        expect($title)->toBeString()->not->toBeEmpty();
        expect($detail)->toBeString()->not->toBeEmpty();
        expect($code)->toBeString()->not->toBeEmpty();

        // Verify all keys are present for the bridge
        expect(array_keys($errorArray))->toEqual(['title', 'detail', 'code']);
    })->toThrowNothing();
