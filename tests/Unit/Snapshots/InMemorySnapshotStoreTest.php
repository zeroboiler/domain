<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Tests\Unit\Snapshots;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ZeroBoiler\Domain\Snapshots\InMemorySnapshotStore;
use ZeroBoiler\Domain\Snapshots\Snapshot;

#[CoversClass(InMemorySnapshotStore::class)]
#[Group('unit')]
#[Group('snapshots')]
final class InMemorySnapshotStoreTest extends TestCase
{
    private InMemorySnapshotStore $store;

    protected function setUp(): void
    {
        $this->store = new InMemorySnapshotStore;
    }

    // ─── Save & Load ─────────────────────────────────────────────

    public function testSaveAndLoadSnapshot(): void
    {
        $snapshot = Snapshot::create('App\Domain\Order', 'uuid-1', 1, ['status' => 'new']);

        $this->store->save($snapshot);
        $loaded = $this->store->load('App\Domain\Order', 'uuid-1');

        $this->assertNotNull($loaded);
        $this->assertSame(1, $loaded->version);
        $this->assertSame(['status' => 'new'], $loaded->state);
    }

    public function testLoadReturnsNullForMissing(): void
    {
        $this->assertNull($this->store->load('App\Domain\Order', 'missing-uuid'));
    }

    // ─── Has ─────────────────────────────────────────────────────

    public function testHasReturnsTrueForExisting(): void
    {
        $snapshot = Snapshot::create('App\Domain\Order', 'uuid-1', 1, []);

        $this->store->save($snapshot);

        $this->assertTrue($this->store->has('App\Domain\Order', 'uuid-1'));
    }

    public function testHasReturnsFalseForMissing(): void
    {
        $this->assertFalse($this->store->has('App\Domain\Order', 'missing'));
    }

    // ─── Delete ──────────────────────────────────────────────────

    public function testDeleteRemovesSnapshot(): void
    {
        $snapshot = Snapshot::create('App\Domain\Order', 'uuid-1', 1, []);
        $this->store->save($snapshot);
        $this->assertTrue($this->store->has('App\Domain\Order', 'uuid-1'));

        $this->store->delete('App\Domain\Order', 'uuid-1');

        $this->assertFalse($this->store->has('App\Domain\Order', 'uuid-1'));
        $this->assertNull($this->store->load('App\Domain\Order', 'uuid-1'));
    }

    // ─── deleteOlderThan ─────────────────────────────────────────

    public function testDeleteOlderThanRemovesOldVersions(): void
    {
        $old = Snapshot::create('App\Domain\Order', 'uuid-1', 10, []);
        $this->store->save($old);

        $this->store->deleteOlderThan('App\Domain\Order', 'uuid-1', 20);

        $this->assertNull($this->store->load('App\Domain\Order', 'uuid-1'));
    }

    public function testDeleteOlderThanKeepsNewVersions(): void
    {
        $new = Snapshot::create('App\Domain\Order', 'uuid-1', 50, []);
        $this->store->save($new);

        $this->store->deleteOlderThan('App\Domain\Order', 'uuid-1', 30);

        $this->assertNotNull($this->store->load('App\Domain\Order', 'uuid-1'));
        $this->assertSame(50, $this->store->load('App\Domain\Order', 'uuid-1')->version);
    }

    // ─── Count ────────────────────────────────────────────────────

    public function testCountTotal(): void
    {
        $this->store->save(Snapshot::create('TypeA', 'id-1', 1, []));
        $this->store->save(Snapshot::create('TypeA', 'id-2', 1, []));
        $this->store->save(Snapshot::create('TypeB', 'id-3', 1, []));

        $this->assertSame(3, $this->store->count());
    }

    public function testCountByType(): void
    {
        $this->store->save(Snapshot::create('TypeA', 'id-1', 1, []));
        $this->store->save(Snapshot::create('TypeA', 'id-2', 1, []));
        $this->store->save(Snapshot::create('TypeB', 'id-3', 1, []));

        $this->assertSame(2, $this->store->count('TypeA'));
        $this->assertSame(1, $this->store->count('TypeB'));
    }

    // ─── Stats ───────────────────────────────────────────────────

    public function testStatsReturnsStructure(): void
    {
        $this->store->save(Snapshot::create('TypeA', 'id-1', 1, []));
        $this->store->save(Snapshot::create('TypeB', 'id-2', 1, []));

        $stats = $this->store->stats();

        $this->assertArrayHasKey('total', $stats);
        $this->assertArrayHasKey('by_type', $stats);
        $this->assertSame(2, $stats['total']);
        $this->assertCount(2, $stats['by_type']);
    }

    // ─── Purge ──────────────────────────────────────────────────

    public function testPurgeByType(): void
    {
        $this->store->save(Snapshot::create('TypeA', 'id-1', 1, []));
        $this->store->save(Snapshot::create('TypeA', 'id-2', 1, []));
        $this->store->save(Snapshot::create('TypeB', 'id-3', 1, []));

        $removed = $this->store->purge('TypeA');

        $this->assertSame(2, $removed);
        $this->assertSame(1, $this->store->count());
        $this->assertSame(1, $this->store->count('TypeB'));
    }

    public function testPurgeAll(): void
    {
        $this->store->save(Snapshot::create('TypeA', 'id-1', 1, []));
        $this->store->save(Snapshot::create('TypeB', 'id-2', 1, []));

        $removed = $this->store->purge();

        $this->assertSame(2, $removed);
        $this->assertSame(0, $this->store->count());
    }

    public function testPurgeEmptyStoreReturnsZero(): void
    {
        $this->assertSame(0, $this->store->purge());
    }

    // ─── Overwrite ──────────────────────────────────────────────

    public function testSaveOverwritesExisting(): void
    {
        $v1 = Snapshot::create('TypeA', 'id-1', 1, ['status' => 'old']);
        $v2 = Snapshot::create('TypeA', 'id-1', 5, ['status' => 'new']);

        $this->store->save($v1);
        $this->store->save($v2);

        $loaded = $this->store->load('TypeA', 'id-1');

        $this->assertSame(5, $loaded->version);
        $this->assertSame(['status' => 'new'], $loaded->state);
    }

    // ─── Separate Types ─────────────────────────────────────────

    public function testSeparateTypesAreIndependent(): void
    {
        $this->store->save(Snapshot::create('TypeA', 'same-id', 1, ['type' => 'A']));
        $this->store->save(Snapshot::create('TypeB', 'same-id', 2, ['type' => 'B']));

        $a = $this->store->load('TypeA', 'same-id');
        $b = $this->store->load('TypeB', 'same-id');

        $this->assertSame(1, $a->version);
        $this->assertSame(2, $b->version);
        $this->assertSame(['type' => 'A'], $a->state);
        $this->assertSame(['type' => 'B'], $b->state);
    }
}
