<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Tests;

use ZeroBoiler\Domain\AggregateRoot;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\Concerns\EventSourced;
use ZeroBoiler\Domain\Concerns\HasSnapshots;
use ZeroBoiler\Domain\Identifiers\UuidIdentifier;
use ZeroBoiler\Domain\Snapshots\InMemorySnapshotStore;
use ZeroBoiler\Domain\Snapshots\Snapshot;
use ZeroBoiler\Domain\Snapshots\SnapshotPolicy;
use ZeroBoiler\Domain\Snapshots\SnapshotStore;
use ZeroBoiler\Domain\Snapshots\SnapshottingRepository;

/**
 * Tests validating the InMemorySnapshotStore documentation examples
 * in README.md (v1.34.0 — InMemorySnapshotStore usage section).
 *
 * These tests ensure that all code examples in the README actually work
 * as documented.
 */
it('save creates a retrievable snapshot', function (): void {
    $store = new InMemorySnapshotStore();

    $orderId = AggregateRootId::generate();
    $snapshot = Snapshot::create(
        'Order',
        $orderId->toString(),
        50,
        ['status' => 'paid', 'total' => 100.0],
    );

    $store->save($snapshot);

    expect($store->has('Order', $orderId->toString()))->toBeTrue();
});

it('has returns false for non-existent snapshot', function (): void {
    $store = new InMemorySnapshotStore();

    expect($store->has('Order', 'non-existent-id'))->toBeFalse();
});

it('load retrieves saved snapshot with correct properties', function (): void {
    $store = new InMemorySnapshotStore();

    $orderId = AggregateRootId::generate();
    $snapshot = Snapshot::create(
        'Order',
        $orderId->toString(),
        50,
        ['status' => 'paid', 'total' => 100.0],
    );

    $store->save($snapshot);

    $loaded = $store->load('Order', $orderId->toString());

    expect($loaded)->not->toBeNull()
        ->and($loaded->version)->toBe(50)
        ->and($loaded->aggregateType)->toBe('Order')
        ->and($loaded->aggregateId)->toBe($orderId->toString())
        ->and($loaded->state)->toBe(['status' => 'paid', 'total' => 100.0]);
});

it('equals returns true for structurally identical snapshots', function (): void {
    $store = new InMemorySnapshotStore();

    $orderId = AggregateRootId::generate();
    $snapshot = Snapshot::create(
        'Order',
        $orderId->toString(),
        50,
        ['status' => 'paid', 'total' => 100.0],
    );

    $store->save($snapshot);
    $loaded = $store->load('Order', $orderId->toString());

    expect($loaded->equals($snapshot))->toBeTrue();
});

it('count returns total snapshots across all types', function (): void {
    $store = new InMemorySnapshotStore();

    $store->save(Snapshot::create('Order', 'id-1', 10, []));
    $store->save(Snapshot::create('Order', 'id-2', 20, []));
    $store->save(Snapshot::create('Product', 'id-3', 5, []));

    expect($store->count())->toBe(3);
});

it('count with type filter returns type-specific count', function (): void {
    $store = new InMemorySnapshotStore();

    $store->save(Snapshot::create('Order', 'id-1', 10, []));
    $store->save(Snapshot::create('Order', 'id-2', 20, []));
    $store->save(Snapshot::create('Product', 'id-3', 5, []));

    expect($store->count('Order'))->toBe(2)
        ->and($store->count('Product'))->toBe(1)
        ->and($store->count('Invoice'))->toBe(0);
});

it('stats returns total and by_type breakdown', function (): void {
    $store = new InMemorySnapshotStore();

    $store->save(Snapshot::create('Order', 'id-1', 10, []));
    $store->save(Snapshot::create('Order', 'id-2', 20, []));
    $store->save(Snapshot::create('Product', 'id-3', 5, []));

    $stats = $store->stats();

    expect($stats)->toHaveKey('total')
        ->and($stats['total'])->toBe(3)
        ->and($stats)->toHaveKey('by_type')
        ->and($stats['by_type']['Order'])->toBe(2)
        ->and($stats['by_type']['Product'])->toBe(1);
});

it('deleteOlderThan removes snapshots below version threshold', function (): void {
    $store = new InMemorySnapshotStore();

    $store->save(Snapshot::create('Order', 'id-1', 50, []));

    // Should delete the snapshot (version 50 < 51)
    $store->deleteOlderThan('Order', 'id-1', 51);

    expect($store->has('Order', 'id-1'))->toBeFalse();
});

it('deleteOlderThan keeps snapshots at or above version threshold', function (): void {
    $store = new InMemorySnapshotStore();

    $store->save(Snapshot::create('Order', 'id-1', 50, []));

    // Should NOT delete (version 50 >= 50)
    $store->deleteOlderThan('Order', 'id-1', 50);

    expect($store->has('Order', 'id-1'))->toBeTrue();
});

it('delete removes a specific snapshot', function (): void {
    $store = new InMemorySnapshotStore();

    $store->save(Snapshot::create('Order', 'id-1', 10, []));
    $store->save(Snapshot::create('Order', 'id-2', 20, []));

    $store->delete('Order', 'id-1');

    expect($store->has('Order', 'id-1'))->toBeFalse()
        ->and($store->has('Order', 'id-2'))->toBeTrue();
});

it('purge removes all snapshots when called without type', function (): void {
    $store = new InMemorySnapshotStore();

    $store->save(Snapshot::create('Order', 'id-1', 10, []));
    $store->save(Snapshot::create('Product', 'id-2', 5, []));

    $count = $store->purge();

    expect($count)->toBe(2)
        ->and($store->count())->toBe(0);
});

it('purge with type filter removes only matching type', function (): void {
    $store = new InMemorySnapshotStore();

    $store->save(Snapshot::create('Order', 'id-1', 10, []));
    $store->save(Snapshot::create('Order', 'id-2', 20, []));
    $store->save(Snapshot::create('Product', 'id-3', 5, []));

    $count = $store->purge('Order');

    expect($count)->toBe(2)
        ->and($store->count('Order'))->toBe(0)
        ->and($store->count('Product'))->toBe(1);
});

it('implements SnapshotStore interface', function (): void {
    $store = new InMemorySnapshotStore();

    expect($store)->toBeInstanceOf(SnapshotStore::class);
});

it('snapshot round-trip via store (save → load → equals)', function (): void {
    $store = new InMemorySnapshotStore();

    $orderId = AggregateRootId::generate();
    $original = Snapshot::create(
        'Order',
        $orderId->toString(),
        100,
        ['status' => 'shipped', 'items' => 5],
    );

    $store->save($original);
    $restored = $store->load('Order', $orderId->toString());

    expect($restored)->not->toBeNull();
    expect($restored->equals($original))->toBeTrue();
    expect($restored->version)->toBe(100);
    expect($restored->aggregateType)->toBe('Order');
    expect($restored->aggregateId)->toBe($orderId->toString());
    expect($restored->state)->toBe(['status' => 'shipped', 'items' => 5]);
});

it('snapshot JSON round-trip consistency', function (): void {
    $store = new InMemorySnapshotStore();

    $orderId = AggregateRootId::generate();
    $snapshot = Snapshot::create(
        'Order',
        $orderId->toString(),
        75,
        ['status' => 'processing'],
    );

    $store->save($snapshot);

    $originalJson = json_encode($snapshot);
    $loaded = $store->load('Order', $orderId->toString());
    $loadedJson = json_encode($loaded);

    expect($originalJson)->toBe($loadedJson);
});
