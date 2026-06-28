<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\Exceptions\ConflictDomainException;
use ZeroBoiler\Domain\Exceptions\DomainException;
use ZeroBoiler\Domain\Exceptions\OptimisticLockException;
use ZeroBoiler\Domain\Tests\Fixtures\TestAggregate;

it('creates optimistic lock exception with correct message', function (): void {
    $exception = OptimisticLockException::for('order-123', 5, 7);

    expect($exception->getMessage())
        ->toContain('order-123')
        ->toContain('version 5')
        ->toContain('version 7');
});

it('aggregate root version starts at 0 and increments', function (): void {
    $id = AggregateRootId::generate();
    $aggregate = TestAggregate::create($id);

    // After create(), one event was applied
    expect($aggregate->getVersion())->toBe(1);
    expect($aggregate->version())->toBe(1);

    $aggregate->incrementVersion();
    expect($aggregate->getVersion())->toBe(2);
});

it('aggregate root version can be set for hydration', function (): void {
    $id = AggregateRootId::generate();
    $aggregate = TestAggregate::create($id);

    // Simulate loading from storage at version 10
    $aggregate->setVersion(10);

    expect($aggregate->getVersion())->toBe(10);
});

it('optimistic lock exception extends conflict domain exception', function (): void {
    $exception = OptimisticLockException::for('agg-1', 1, 2);

    expect($exception)
        ->toBeInstanceOf(ConflictDomainException::class)
        ->toBeInstanceOf(DomainException::class)
        ->toBeInstanceOf(Exception::class);
});
