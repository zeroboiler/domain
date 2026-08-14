<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Tests\Unit\Snapshots;

use PHPUnit\Framework\TestCase;
use ZeroBoiler\Domain\Snapshots\InMemorySnapshotStore;
use ZeroBoiler\Domain\Snapshots\Snapshot;

/**
 * @covers \ZeroBoiler\Domain\Snapshots\InMemorySnapshotStore
 */
final class InMemorySnapshotStoreTest extends TestCase
{
    private InMemorySnapshotStore $store;

    protected function setUp(): void
    {
        $this->store = new InMemorySnapshotStore;
    }

    // ── save / load ───────────────────────────────────────────────────

    public function test_save_and_load(): void
    {
        $snapshot = Snapshot::create('Order', 'ord-1', 5, ['total' => 100]);
        $this->store->save($snapshot);

        $loaded = $this->store->load('Order', 'ord-1');

        self::assertInstanceOf(Snapshot::class, $loaded);
        self::assertTrue($snapshot->equals($loaded));
    }

    public function test_load_returns_null_when_not_found(): void
    {
        self::assertNull($this->store->load('Order', 'nonexistent'));
    }

    // ── has ──────────────────────────────────────────────────────────

    public function test_has_returns_true_when_exists(): void
    {
        $snapshot = Snapshot::create('Order', 'ord-1', 1, []);
        $this->store->save($snapshot);

        self::assertTrue($this->store->has('Order', 'ord-1'));
    }

    public function test_has_returns_false_when_not_exists(): void
    {
        self::assertFalse($this->store->has('Order', 'ord-1'));
    }

    // ── delete ───────────────────────────────────────────────────────

    public function test_delete_removes_snapshot(): void
    {
        $snapshot = Snapshot::create('Order', 'ord-1', 1, []);
        $this->store->save($snapshot);
        $this->store->delete('Order', 'ord-1');

        self::assertNull($this->store->load('Order', 'ord-1'));
    }

    // ── deleteOlderThan ──────────────────────────────────────────────

    public function test_deleteOlderThan_removes_older_versions(): void
    {
        $v5 = Snapshot::create('Order', 'ord-1', 5, ['v' => 5]);
        $this->store->save($v5);
        $this->store->deleteOlderThan('Order', 'ord-1', 10);

        self::assertNull($this->store->load('Order', 'ord-1'));
    }

    public function test_deleteOlderThan_keeps_newer_versions(): void
    {
        $v15 = Snapshot::create('Order', 'ord-1', 15, ['v' => 15]);
        $this->store->save($v15);
        $this->store->deleteOlderThan('Order', 'ord-1', 10);

        $loaded = $this->store->load('Order', 'ord-1');
        self::assertInstanceOf(Snapshot::class, $loaded);
        self::assertSame(15, $loaded->version);
    }

    // ── count ───────────────────────────────────────────────────────

    public function test_count_returns_total(): void
    {
        $this->store->save(Snapshot::create('Order', 'ord-1', 1, []));
        $this->store->save(Snapshot::create('Order', 'ord-2', 1, []));
        $this->store->save(Snapshot::create('Invoice', 'inv-1', 1, []));

        self::assertSame(3, $this->store->count());
        self::assertSame(2, $this->store->count('Order'));
        self::assertSame(1, $this->store->count('Invoice'));
        self::assertSame(0, $this->store->count('Product'));
    }

    // ── stats ────────────────────────────────────────────────────────

    public function test_stats_returns_aggregate_counts(): void
    {
        $this->store->save(Snapshot::create('Order', 'ord-1', 1, []));
        $this->store->save(Snapshot::create('Order', 'ord-2', 1, []));
        $this->store->save(Snapshot::create('Invoice', 'inv-1', 1, []));

        $stats = $this->store->stats();

        self::assertSame(3, $stats['total']);
        self::assertSame(2, $stats['by_type']['Order']);
        self::assertSame(1, $stats['by_type']['Invoice']);
    }

    // ── purge ───────────────────────────────────────────────────────

    public function test_purge_all(): void
    {
        $this->store->save(Snapshot::create('Order', 'ord-1', 1, []));
        $this->store->save(Snapshot::create('Invoice', 'inv-1', 1, []));

        $removed = $this->store->purge();

        self::assertSame(2, $removed);
        self::assertSame(0, $this->store->count());
    }

    public function test_purge_by_type(): void
    {
        $this->store->save(Snapshot::create('Order', 'ord-1', 1, []));
        $this->store->save(Snapshot::create('Invoice', 'inv-1', 1, []));

        $removed = $this->store->purge('Order');

        self::assertSame(1, $removed);
        self::assertSame(1, $this->store->count());
        self::assertNull($this->store->load('Order', 'ord-1'));
        self::assertInstanceOf(Snapshot::class, $this->store->load('Invoice', 'inv-1'));
    }

    // ── overwrite behavior ────────────────────────────────────────────

    public function test_save_overwrites_previous_snapshot(): void
    {
        $v1 = Snapshot::create('Order', 'ord-1', 1, ['status' => 'new']);
        $v2 = Snapshot::create('Order', 'ord-1', 2, ['status' => 'paid']);
        $this->store->save($v1);
        $this->store->save($v2);

        $loaded = $this->store->load('Order', 'ord-1');
        self::assertInstanceOf(Snapshot::class, $loaded);
        self::assertSame(2, $loaded->version);
        self::assertSame(['status' => 'paid'], $loaded->state);
    }
}
