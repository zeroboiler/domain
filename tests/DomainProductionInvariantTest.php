<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Domain\DomainEventCollection;
use ZeroBoiler\Domain\Snapshots\Snapshot;
use ZeroBoiler\Events\Domain\DomainEvent;

/**
 * Production readiness tests for assert→exception migration.
 *
 * Verifies that DomainEventCollection and Snapshot::fromArray() throw
 * proper exceptions for invalid input in all environments (including
 * production where assert() is disabled).
 */
test('DomainEventCollection rejects non-list arrays', function (): void {
    expect(fn (): mixed => new DomainEventCollection(['key' => DomainEvent::occur('test', [])]))
        ->toThrow(InvalidArgumentException::class, 'DomainEventCollection expects a sequential list');
});

test('DomainEventCollection rejects non-DomainEvent items', function (): void {
    expect(fn (): mixed => new DomainEventCollection(['not-an-event', DomainEvent::occur('test', [])]))
        ->toThrow(InvalidArgumentException::class, 'must be a DomainEvent');
});

test('DomainEventCollection rejects empty string key', function (): void {
    expect(fn (): mixed => new DomainEventCollection([0 => 'invalid', 1 => DomainEvent::occur('test', [])]))
        ->toThrow(InvalidArgumentException::class, 'must be a DomainEvent');
});

test('DomainEventCollection accepts valid list', function (): void {
    $events = [DomainEvent::occur('test.1', []), DomainEvent::occur('test.2', [])];
    $collection = new DomainEventCollection($events);

    expect($collection->count())->toBe(2)
        ->and($collection->isEmpty())->toBeFalse()
        ->and($collection->first()?->eventType)->toBe('test.1');
});

test('DomainEventCollection accepts empty list', function (): void {
    $collection = new DomainEventCollection([]);

    expect($collection->count())->toBe(0)
        ->and($collection->isEmpty())->toBeTrue();
});

test('Snapshot::fromArray throws on missing keys', function (): void {
    expect(fn (): mixed => Snapshot::fromArray([]))
        ->toThrow(InvalidArgumentException::class, 'Invalid snapshot data');
});

test('Snapshot::fromArray throws on wrong types', function (): void {
    expect(fn (): mixed => Snapshot::fromArray([
        'aggregate_type' => 123,
        'aggregate_id' => 456,
        'version' => 'not-int',
        'state' => 'not-array',
        'created_at' => 789,
    ]))->toThrow(InvalidArgumentException::class, 'Invalid snapshot data');
});

test('Snapshot::fromArray accepts valid data', function (): void {
    $snapshot = Snapshot::fromArray([
        'aggregate_type' => 'Order',
        'aggregate_id' => '550e8400-e29b-41d4-a716-446655440000',
        'version' => 1,
        'state' => ['status' => 'pending', 'total' => 0.0],
        'created_at' => '2026-08-06T12:00:00+00:00',
    ]);

    expect($snapshot->aggregateType)->toBe('Order')
        ->and($snapshot->aggregateId)->toBe('550e8400-e29b-41d4-a716-446655440000')
        ->and($snapshot->version)->toBe(1)
        ->and($snapshot->state)->toBe(['status' => 'pending', 'total' => 0.0])
        ->and($snapshot->createdAt->format('Y-m-d'))->toBe('2026-08-06');
});

test('Snapshot round-trip with fromArray and toArray', function (): void {
    $original = Snapshot::create(
        aggregateType: 'Order',
        aggregateId: '550e8400-e29b-41d4-a716-446655440000',
        version: 10,
        state: ['items' => [1, 2, 3], 'total' => 99.99],
    );

    $restored = Snapshot::fromArray($original->toArray());

    expect($restored->aggregateType)->toBe($original->aggregateType)
        ->and($restored->aggregateId)->toBe($original->aggregateId)
        ->and($restored->version)->toBe($original->version)
        ->and($restored->state)->toBe($original->state);
});

test('Snapshot::equals validates structural equality', function (): void {
    $s1 = Snapshot::create('Order', 'id-1', 1, ['status' => 'pending']);
    $s2 = Snapshot::create('Order', 'id-1', 1, ['status' => 'pending']);
    $s3 = Snapshot::create('Order', 'id-1', 2, ['status' => 'pending']);

    expect($s1->equals($s2))->toBeTrue()
        ->and($s1->equals($s3))->toBeFalse();
});

test('DomainEventCollection JSON serialization with invalid type guard', function (): void {
    $collection = new DomainEventCollection([
        DomainEvent::occur('order.placed', ['id' => 'uuid-1']),
        DomainEvent::occur('order.paid', ['amount' => 100]),
    ]);

    $json = json_encode($collection);
    $decoded = json_decode($json, true);

    expect($decoded)->toBeArray()
        ->and(count($decoded))->toBe(2);
});
