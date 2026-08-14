<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Tests\Feature;

use PHPUnit\Framework\TestCase;
use ZeroBoiler\Domain\Contracts\Repository;
use ZeroBoiler\Domain\Contracts\UnitOfWork;
use ZeroBoiler\Domain\Exceptions\ConflictDomainException;
use ZeroBoiler\Domain\Exceptions\DomainException;
use ZeroBoiler\Domain\Exceptions\InvalidArgumentDomainException;
use ZeroBoiler\Domain\Exceptions\InvalidStateDomainException;
use ZeroBoiler\Domain\Exceptions\NotFoundDomainException;
use ZeroBoiler\Domain\InMemoryUnitOfWork;
use ZeroBoiler\Domain\Snapshots\InMemorySnapshotStore;
use ZeroBoiler\Domain\Snapshots\Snapshot;
use ZeroBoiler\Domain\Snapshots\SnapshotPolicy;
use ZeroBoiler\Domain\Snapshots\SnapshottingRepository;

/**
 * Production integration tests for domain infrastructure:
 * Unit of Work, Snapshots, SnapshottingRepository, and Exception hierarchy.
 *
 * @covers \ZeroBoiler\Domain\InMemoryUnitOfWork
 * @covers \ZeroBoiler\Domain\Snapshots\Snapshot
 * @covers \ZeroBoiler\Domain\Snapshots\SnapshotStore
 * @covers \ZeroBoiler\Domain\Snapshots\SnapshotPolicy
 * @covers \ZeroBoiler\Domain\Snapshots\InMemorySnapshotStore
 * @covers \ZeroBoiler\Domain\Snapshots\SnapshottingRepository
 * @covers \ZeroBoiler\Domain\Exceptions\DomainException
 * @covers \ZeroBoiler\Domain\Exceptions\ConflictDomainException
 * @covers \ZeroBoiler\Domain\Exceptions\InvalidStateDomainException
 * @covers \ZeroBoiler\Domain\Exceptions\InvalidArgumentDomainException
 * @covers \ZeroBoiler\Domain\Exceptions\NotFoundDomainException
 */
final class DomainInfrastructureIntegrationTest extends TestCase
{
    // -------------------------------------------------------
    // DomainException hierarchy
    // -------------------------------------------------------

    public function test_domain_exception_is_json_serializable(): void
    {
        $exception = InvalidStateDomainException::withMessage('Order must be pending.');
        $array = $exception->toErrorArray();

        self::assertArrayHasKey('title', $array);
        self::assertArrayHasKey('detail', $array);
        self::assertArrayHasKey('code', $array);
        self::assertSame('Order must be pending.', $array['detail']);
        self::assertSame('INVALID_STATE', $array['code']);
    }

    public function test_domain_exception_array_roundtrip(): void
    {
        $exception = ConflictDomainException::withMessage('Version conflict.');
        $array = $exception->toArray();

        self::assertIsArray($array);
        self::assertArrayHasKey('title', $array);
        self::assertArrayHasKey('detail', $array);
        self::assertArrayHasKey('code', $array);
        self::assertArrayHasKey('message', $array);
    }

    public function test_domain_exception_from_array_roundtrip(): void
    {
        $exception = NotFoundDomainException::withMessage('Not found.');
        $array = $exception->toArray();

        $restored = DomainException::fromArray($array);
        self::assertInstanceOf(DomainException::class, $restored);
        self::assertSame($exception->getMessage(), $restored->getMessage());
        self::assertSame($array['title'], $restored->toErrorArray()['title']);
        self::assertSame($array['detail'], $restored->toErrorArray()['detail']);
    }

    public function test_domain_exception_json_roundtrip(): void
    {
        $exception = InvalidArgumentDomainException::withMessage('Bad arg.');
        $json = json_encode($exception->toArray());
        self::assertIsString($json);

        $restored = DomainException::fromJson($json);
        self::assertInstanceOf(DomainException::class, $restored);
        self::assertSame('Bad arg.', $restored->getMessage());
    }

    public function test_each_exception_type_has_distinct_code(): void
    {
        $invalidState = InvalidStateDomainException::withMessage('x');
        $invalidArg = InvalidArgumentDomainException::withMessage('x');
        $notFound = NotFoundDomainException::withMessage('x');
        $conflict = ConflictDomainException::withMessage('x');

        self::assertSame('INVALID_STATE', $invalidState->toErrorArray()['code']);
        self::assertSame('INVALID_ARGUMENT', $invalidArg->toErrorArray()['code']);
        self::assertSame('NOT_FOUND', $notFound->toErrorArray()['code']);
        self::assertSame('CONFLICT', $conflict->toErrorArray()['code']);
    }

    // -------------------------------------------------------
    // Snapshot
    // -------------------------------------------------------

    public function test_snapshot_is_json_serializable(): void
    {
        $snapshot = new Snapshot(
            aggregateType: 'TestOrder',
            aggregateId: 'order-123',
            version: 5,
            state: ['status' => 'paid', 'total' => 1000],
            occurredAt: new \DateTimeImmutable('2026-08-14T12:00:00+00:00'),
        );

        $json = json_encode($snapshot);
        self::assertIsString($json);

        $data = json_decode($json, true);
        self::assertSame('TestOrder', $data['aggregate_type']);
        self::assertSame('order-123', $data['aggregate_id']);
        self::assertSame(5, $data['version']);
        self::assertSame('paid', $data['state']['status']);
    }

    public function test_snapshot_array_roundtrip(): void
    {
        $snapshot = new Snapshot(
            aggregateType: 'TestOrder',
            aggregateId: 'order-123',
            version: 3,
            state: ['key' => 'value'],
            occurredAt: new \DateTimeImmutable('2026-08-14T12:00:00+00:00'),
        );

        $array = $snapshot->toArray();
        $restored = Snapshot::fromArray($array);

        self::assertSame($snapshot->aggregateType, $restored->aggregateType);
        self::assertSame($snapshot->aggregateId, $restored->aggregateId);
        self::assertSame($snapshot->version, $restored->version);
        self::assertSame($snapshot->state, $restored->state);
    }

    public function test_snapshot_json_roundtrip(): void
    {
        $snapshot = new Snapshot(
            aggregateType: 'TestOrder',
            aggregateId: 'order-456',
            version: 10,
            state: ['items' => ['A', 'B']],
            occurredAt: new \DateTimeImmutable('2026-08-14T12:00:00+00:00'),
        );

        $json = json_encode($snapshot);
        $restored = Snapshot::fromJson($json);

        self::assertSame($snapshot->aggregateId, $restored->aggregateId);
        self::assertSame($snapshot->version, $restored->version);
        self::assertSame($snapshot->state, $restored->state);
    }

    // -------------------------------------------------------
    // InMemorySnapshotStore
    // -------------------------------------------------------

    public function test_snapshot_store_save_load_and_has(): void
    {
        $store = new InMemorySnapshotStore;
        $snapshot = new Snapshot(
            aggregateType: 'TestOrder',
            aggregateId: 'order-1',
            version: 2,
            state: ['s' => 'a'],
            occurredAt: new \DateTimeImmutable('2026-08-14T12:00:00+00:00'),
        );

        self::assertFalse($store->has('TestOrder', 'order-1'));

        $store->save($snapshot);
        self::assertTrue($store->has('TestOrder', 'order-1'));
        self::assertSame(1, $store->count());

        $loaded = $store->load('TestOrder', 'order-1');
        self::assertNotNull($loaded);
        self::assertSame(2, $loaded->version);
        self::assertSame(['s' => 'a'], $loaded->state);
    }

    public function test_snapshot_store_delete(): void
    {
        $store = new InMemorySnapshotStore;
        $snapshot = new Snapshot(
            aggregateType: 'TestOrder',
            aggregateId: 'order-x',
            version: 1,
            state: [],
            occurredAt: new \DateTimeImmutable('2026-08-14T12:00:00+00:00'),
        );

        $store->save($snapshot);
        self::assertSame(1, $store->count());

        $store->delete('TestOrder', 'order-x');
        self::assertSame(0, $store->count());
        self::assertNull($store->load('TestOrder', 'order-x'));
    }

    public function test_snapshot_store_stats(): void
    {
        $store = new InMemorySnapshotStore;
        $store->save($this->makeSnapshot('TestOrder', 'o1', 1));
        $store->save($this->makeSnapshot('TestOrder', 'o2', 1));
        $store->save($this->makeSnapshot('TestUser', 'u1', 1));

        $stats = $store->stats();
        self::assertSame(3, $stats['total']);
        self::assertSame(2, $stats['by_type']['TestOrder']);
        self::assertSame(1, $stats['by_type']['TestUser']);
    }

    public function test_snapshot_store_purge_by_type(): void
    {
        $store = new InMemorySnapshotStore;
        $store->save($this->makeSnapshot('TestOrder', 'o1', 1));
        $store->save($this->makeSnapshot('TestOrder', 'o2', 1));
        $store->save($this->makeSnapshot('TestUser', 'u1', 1));

        $removed = $store->purge('TestOrder');
        self::assertSame(2, $removed);
        self::assertSame(1, $store->count());
        self::assertTrue($store->has('TestUser', 'u1'));
        self::assertFalse($store->has('TestOrder', 'o1'));
    }

    public function test_snapshot_store_delete_older_than(): void
    {
        $store = new InMemorySnapshotStore;
        $store->save($this->makeSnapshot('TestOrder', 'o1', 3));
        $store->save($this->makeSnapshot('TestOrder', 'o2', 5));

        // Delete version < 4 → removes o1, keeps o2
        $store->deleteOlderThan('TestOrder', 'o1', 4);
        self::assertNull($store->load('TestOrder', 'o1'));
        self::assertNotNull($store->load('TestOrder', 'o2'));
    }

    // -------------------------------------------------------
    // SnapshottingRepository integration
    // -------------------------------------------------------

    public function test_snapshotting_repository_uses_inner_repository(): void
    {
        $inner = new InMemoryTestRepository;
        $store = new InMemorySnapshotStore;
        $repo = new SnapshottingRepository(
            inner: $inner,
            snapshotStore: $store,
            aggregateType: 'TestOrder',
        );

        // No snapshot → delegates to inner
        $result = $repo->find('nonexistent');
        self::assertNull($result);
    }

    public function test_snapshotting_repository_exposes_snapshot_store(): void
    {
        $store = new InMemorySnapshotStore;
        $repo = new SnapshottingRepository(
            inner: new InMemoryTestRepository,
            snapshotStore: $store,
            aggregateType: 'TestOrder',
        );

        self::assertSame($store, $repo->snapshotStore());
    }

    // -------------------------------------------------------
    // Helpers
    // -------------------------------------------------------

    private function makeSnapshot(string $type, string $id, int $version): Snapshot
    {
        return new Snapshot(
            aggregateType: $type,
            aggregateId: $id,
            version: $version,
            state: ['v' => $version],
            occurredAt: new \DateTimeImmutable('2026-08-14T12:00:00+00:00'),
        );
    }
}

// ---------------------------------------------------------------
// Minimal stub repository for testing SnapshottingRepository
// (Outside namespace — uses FQCN for all references)
// ---------------------------------------------------------------

final class InMemoryTestRepository implements \ZeroBoiler\Domain\Contracts\Repository
{
    /** @var array<string, \ZeroBoiler\Domain\AggregateRoot> */
    private array $aggregates = [];

    public function find(string|int $id): ?\ZeroBoiler\Domain\AggregateRoot
    {
        return $this->aggregates[(string) $id] ?? null;
    }

    public function save(\ZeroBoiler\Domain\AggregateRoot $aggregate): void
    {
        $id = (string) $aggregate->id();
        $this->aggregates[$id] = $aggregate;
    }

    public function delete(string|int $id): void
    {
        unset($this->aggregates[(string) $id]);
    }
}
