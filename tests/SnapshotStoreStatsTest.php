<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Domain\Snapshots\InMemorySnapshotStore;
use ZeroBoiler\Domain\Snapshots\Snapshot;

beforeEach(function (): void {
    $this->store = new InMemorySnapshotStore;
});

function makeSnapshot(string $type, string $id, int $version): Snapshot
{
    return Snapshot::create(
        aggregateType: $type,
        aggregateId: $id,
        version: $version,
        state: ['status' => 'active'],
    );
}

describe('count()', function (): void {
    it('returns 0 for empty store', function (): void {
        expect($this->store->count())->toBe(0);
    });

    it('counts all snapshots when no filter given', function (): void {
        $this->store->save(makeSnapshot('App\Order', 'order-1', 10));
        $this->store->save(makeSnapshot('App\Order', 'order-2', 20));
        $this->store->save(makeSnapshot('App\User', 'user-1', 5));

        expect($this->store->count())->toBe(3);
    });

    it('counts snapshots for a specific aggregate type', function (): void {
        $this->store->save(makeSnapshot('App\Order', 'order-1', 10));
        $this->store->save(makeSnapshot('App\Order', 'order-2', 20));
        $this->store->save(makeSnapshot('App\User', 'user-1', 5));

        expect($this->store->count('App\Order'))->toBe(2);
        expect($this->store->count('App\User'))->toBe(1);
    });

    it('returns 0 for non-existent aggregate type', function (): void {
        $this->store->save(makeSnapshot('App\Order', 'order-1', 10));

        expect($this->store->count('App\Unknown'))->toBe(0);
    });

    it('handles namespace-prefix collisions correctly', function (): void {
        $this->store->save(makeSnapshot('App\Order', 'order-1', 10));
        $this->store->save(makeSnapshot('App\OrderItem', 'item-1', 5));

        // 'App\Order' prefix must NOT match 'App\OrderItem'
        expect($this->store->count('App\Order'))->toBe(1);
        expect($this->store->count('App\OrderItem'))->toBe(1);
    });

    it('updates count after overwriting same key', function (): void {
        $this->store->save(makeSnapshot('App\Order', 'order-1', 10));
        $this->store->save(makeSnapshot('App\Order', 'order-1', 20));

        expect($this->store->count())->toBe(1);
    });
});

describe('stats()', function (): void {
    it('returns zero stats for empty store', function (): void {
        $stats = $this->store->stats();

        expect($stats)->toHaveKey('total')
            ->and($stats['total'])->toBe(0)
            ->and($stats['by_type'])->toBe([]);
    });

    it('groups counts by aggregate type', function (): void {
        $this->store->save(makeSnapshot('App\Order', 'order-1', 10));
        $this->store->save(makeSnapshot('App\Order', 'order-2', 20));
        $this->store->save(makeSnapshot('App\Order', 'order-3', 30));
        $this->store->save(makeSnapshot('App\User', 'user-1', 5));
        $this->store->save(makeSnapshot('App\Product', 'product-1', 3));

        $stats = $this->store->stats();

        expect($stats['total'])->toBe(5)
            ->and($stats['by_type'])->toBe([
                'App\Order' => 3,
                'App\User' => 1,
                'App\Product' => 1,
            ]);
    });

    it('reflects deletions accurately', function (): void {
        $this->store->save(makeSnapshot('App\Order', 'order-1', 10));
        $this->store->save(makeSnapshot('App\Order', 'order-2', 20));
        $this->store->save(makeSnapshot('App\User', 'user-1', 5));

        $this->store->delete('App\Order', 'order-1');

        $stats = $this->store->stats();

        expect($stats['total'])->toBe(2)
            ->and($stats['by_type'])->toBe([
                'App\Order' => 1,
                'App\User' => 1,
            ]);
    });

    it('removes type from by_type when last snapshot of type is deleted', function (): void {
        $this->store->save(makeSnapshot('App\Order', 'order-1', 10));
        $this->store->delete('App\Order', 'order-1');

        $stats = $this->store->stats();

        expect($stats['total'])->toBe(0)
            ->and($stats['by_type'])->toBe([]);
    });
});

describe('purge()', function (): void {
    it('purges all snapshots when no type given', function (): void {
        $this->store->save(makeSnapshot('App\Order', 'order-1', 10));
        $this->store->save(makeSnapshot('App\User', 'user-1', 5));

        $removed = $this->store->purge();

        expect($removed)->toBe(2)
            ->and($this->store->count())->toBe(0);
    });

    it('purges only snapshots of given type', function (): void {
        $this->store->save(makeSnapshot('App\Order', 'order-1', 10));
        $this->store->save(makeSnapshot('App\Order', 'order-2', 20));
        $this->store->save(makeSnapshot('App\User', 'user-1', 5));

        $removed = $this->store->purge('App\Order');

        expect($removed)->toBe(2)
            ->and($this->store->count())->toBe(1)
            ->and($this->store->has('App\User', 'user-1'))->toBeTrue();
    });

    it('returns 0 when purging non-existent type', function (): void {
        $this->store->save(makeSnapshot('App\Order', 'order-1', 10));

        $removed = $this->store->purge('App\Unknown');

        expect($removed)->toBe(0)
            ->and($this->store->count())->toBe(1);
    });

    it('returns 0 when purging empty store', function (): void {
        expect($this->store->purge())->toBe(0);
    });

    it('handles namespace-prefix collisions correctly', function (): void {
        $this->store->save(makeSnapshot('App\Order', 'order-1', 10));
        $this->store->save(makeSnapshot('App\OrderItem', 'item-1', 5));

        $removed = $this->store->purge('App\Order');

        expect($removed)->toBe(1)
            ->and($this->store->has('App\OrderItem', 'item-1'))->toBeTrue();
    });

    it('purging all then adding new snapshots works correctly', function (): void {
        $this->store->save(makeSnapshot('App\Order', 'order-1', 10));
        $this->store->purge();
        $this->store->save(makeSnapshot('App\Order', 'order-2', 5));

        expect($this->store->count())->toBe(1)
            ->and($this->store->has('App\Order', 'order-2'))->toBeTrue()
            ->and($this->store->has('App\Order', 'order-1'))->toBeFalse();
    });
});

describe('backward compatibility', function (): void {
    it('clear() still works as alias for purge()', function (): void {
        $this->store->save(makeSnapshot('App\Order', 'order-1', 10));
        $this->store->save(makeSnapshot('App\User', 'user-1', 5));

        $this->store->clear();

        expect($this->store->count())->toBe(0);
    });
});
