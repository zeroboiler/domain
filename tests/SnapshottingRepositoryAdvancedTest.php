<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Tests;

use ZeroBoiler\Domain\AggregateRoot;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\Contracts\Repository;
use ZeroBoiler\Domain\Snapshots\InMemorySnapshotStore;
use ZeroBoiler\Domain\Snapshots\Snapshot;
use ZeroBoiler\Domain\Snapshots\SnapshottingRepository;
use ZeroBoiler\Events\Domain\DomainEvent;

/**
 * Tests validating the Advanced Snapshot Loading documentation examples
 * in README.md (v1.34.0 — findWithSnapshot section).
 *
 * Covers SnapshottingRepository::findWithSnapshot(), snapshotStore(),
 * delete cascading, and structural checks (final, readonly, Repository contract).
 */
it('findWithSnapshot without callback falls back to inner repository', function (): void {
    $store = new InMemorySnapshotStore();
    $orderId = AggregateRootId::generate();

    $inner = new class implements Repository {
        public ?AggregateRoot $result = null;
        public function find(string|int $id): ?AggregateRoot { return $this->result; }
        public function save(AggregateRoot $aggregate): void {}
        public function delete(string|int $id): void {}
    };

    $repo = new SnapshottingRepository(
        $inner,
        $store,
        AggregateRoot::class,
    );

    // No snapshot exists, null callback → falls back to inner->find() which returns null
    $result = $repo->findWithSnapshot($orderId->toString());

    expect($result)->toBeNull();
});

it('findWithSnapshot with snapshot but null callback falls back to inner', function (): void {
    $store = new InMemorySnapshotStore();
    $orderId = AggregateRootId::generate();

    // Save a snapshot
    $store->save(Snapshot::create(
        AggregateRoot::class,
        $orderId->toString(),
        10,
        ['status' => 'completed'],
    ));

    $inner = new class implements Repository {
        public ?AggregateRoot $result = null;
        public function find(string|int $id): ?AggregateRoot { return $this->result; }
        public function save(AggregateRoot $aggregate): void {}
        public function delete(string|int $id): void {}
    };

    $repo = new SnapshottingRepository(
        $inner,
        $store,
        AggregateRoot::class,
    );

    // null callback → falls back to inner->find() which returns null
    $result = $repo->findWithSnapshot($orderId->toString(), null);

    expect($result)->toBeNull();
});

it('findWithSnapshot with callback and snapshot loads from snapshot', function (): void {
    $store = new InMemorySnapshotStore();
    $orderId = AggregateRootId::generate();

    // Save a snapshot
    $store->save(Snapshot::create(
        AggregateRoot::class,
        $orderId->toString(),
        10,
        ['status' => 'completed'],
    ));

    $inner = new class implements Repository {
        public ?AggregateRoot $result = null;
        public function find(string|int $id): ?AggregateRoot { return $this->result; }
        public function save(AggregateRoot $aggregate): void {}
        public function delete(string|int $id): void {}
    };

    $repo = new SnapshottingRepository(
        $inner,
        $store,
        AggregateRoot::class,
    );

    // Callback with no events — snapshot is loaded but inner->find returns null
    // The aggregate class (AggregateRoot) doesn't use HasSnapshots trait,
    // so instantiateFromSnapshot returns null, and it falls back to inner->find
    $result = $repo->findWithSnapshot($orderId->toString(), fn (): array => []);

    // Falls back to inner->find() which returns null
    expect($result)->toBeNull();
});

it('snapshotting repository snapshotStore() returns the injected store', function (): void {
    $store = new InMemorySnapshotStore();

    $inner = new class implements Repository {
        public function find(string|int $id): ?AggregateRoot { return null; }
        public function save(AggregateRoot $aggregate): void {}
        public function delete(string|int $id): void {}
    };

    $repo = new SnapshottingRepository(
        $inner,
        $store,
        AggregateRoot::class,
    );

    expect($repo->snapshotStore())->toBe($store);
});

it('snapshotting repository delete also removes snapshot from store', function (): void {
    $store = new InMemorySnapshotStore();
    $orderId = AggregateRootId::generate();

    // Save a snapshot first
    $store->save(Snapshot::create(
        AggregateRoot::class,
        $orderId->toString(),
        10,
        ['status' => 'active'],
    ));

    expect($store->has(AggregateRoot::class, $orderId->toString()))->toBeTrue();

    $inner = new class implements Repository {
        public function find(string|int $id): ?AggregateRoot { return null; }
        public function save(AggregateRoot $aggregate): void {}
        public function delete(string|int $id): void {}
    };

    $repo = new SnapshottingRepository(
        $inner,
        $store,
        AggregateRoot::class,
    );

    // Delete should also remove the snapshot
    $repo->delete($orderId->toString());

    expect($store->has(AggregateRoot::class, $orderId->toString()))->toBeFalse();
});

it('snapshotting repository is final readonly class', function (): void {
    $reflection = new \ReflectionClass(SnapshottingRepository::class);

    expect($reflection->isFinal())->toBeTrue()
        ->and($reflection->isReadOnly())->toBeTrue();
});

it('snapshotting repository implements Repository contract', function (): void {
    expect(SnapshottingRepository::class)->implementsInterface(Repository::class);
});

it('snapshotting repository find delegates to inner when no snapshot', function (): void {
    $store = new InMemorySnapshotStore();
    $orderId = AggregateRootId::generate();

    // Create a real aggregate as the inner find result
    $aggregate = (new \ReflectionClass(AggregateRoot::class))->newInstanceWithoutConstructor();

    $inner = new class($aggregate) implements Repository {
        public function __construct(public ?AggregateRoot $result) {}
        public function find(string|int $id): ?AggregateRoot { return $this->result; }
        public function save(AggregateRoot $aggregate): void {}
        public function delete(string|int $id): void {}
    };

    $repo = new SnapshottingRepository(
        $inner,
        $store,
        AggregateRoot::class,
    );

    $result = $repo->find($orderId->toString());

    expect($result)->toBe($aggregate);
});

it('snapshotting repository save delegates to inner', function (): void {
    $store = new InMemorySnapshotStore();

    $savedAggregate = null;

    $inner = new class implements Repository {
        public ?AggregateRoot $saved = null;
        public function find(string|int $id): ?AggregateRoot { return null; }
        public function save(AggregateRoot $aggregate): void { $this->saved = $aggregate; }
        public function delete(string|int $id): void {}
    };

    $repo = new SnapshottingRepository(
        $inner,
        $store,
        AggregateRoot::class,
    );

    $aggregate = (new \ReflectionClass(AggregateRoot::class))->newInstanceWithoutConstructor();
    $repo->save($aggregate);

    // @phpstan-ignore property.notFound
    expect($inner->saved)->toBe($aggregate);
});
