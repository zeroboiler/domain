<?php

/**
 * Round 5 — BUG FIX tests.
 *
 * Covers:
 * 1. Entity::equals() with uninitialized/null ID — no crash
 * 2. HasSnapshots::toSnapshotState() excludes version property
 * 3. HasSnapshots round-trip restore does not double-set version
 */

declare(strict_types=1);

use ZeroBoiler\Domain\AggregateRoot;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\Concerns\EventSourced;
use ZeroBoiler\Domain\Concerns\HasSnapshots;
use ZeroBoiler\Domain\Entity;
use ZeroBoiler\Domain\Snapshots\InMemorySnapshotStore;
use ZeroBoiler\Events\Domain\DomainEvent;

// ─── BUG 1: Entity::equals() does not crash with uninitialized ID ───

it('equals() returns false when both entities have uninitialized IDs', function (): void {
    $entity1 = new class extends Entity
    {
        public function __construct()
        {
            // Skip parent constructor to simulate uninitialized ID
        }

        public function id(): string
        {
            throw new LogicException('Entity id must be string, int, or Stringable; got null');
        }
    };

    $entity2 = new class extends Entity
    {
        public function __construct() {}

        public function id(): string
        {
            throw new LogicException('Entity id must be string, int, or Stringable; got null');
        }
    };

    // Should NOT throw LogicException — should gracefully return false
    expect($entity1->equals($entity2))->toBeFalse();
});

it('equals() returns false when comparing entity with uninitialized ID to one with ID', function (): void {
    $entityWithId = new class(42) extends Entity {};

    $entityWithoutId = new class extends Entity
    {
        public function __construct() {}

        public function id(): string
        {
            throw new LogicException('Entity id must be string, int, or Stringable; got null');
        }
    };

    expect($entityWithId->equals($entityWithoutId))->toBeFalse();
    expect($entityWithoutId->equals($entityWithId))->toBeFalse();
});

// ─── BUG 2: toSnapshotState() excludes version property ───

it('toSnapshotState() does not include version in snapshot state', function (): void {
    $id = AggregateRootId::generate();

    $aggregate = SnapshotBugTestAggregate::create($id);
    $aggregate->clearEvents();

    $state = $aggregate->toSnapshotState();

    expect($state)
        ->not->toHaveKey('version')
        ->toHaveKey('status')
        ->and($state['status'])->toBe('created');
});

it('toSnapshotState() does not include domainEvents in snapshot state', function (): void {
    $id = AggregateRootId::generate();

    $aggregate = SnapshotBugTestAggregate::create($id);
    // Intentionally do NOT clear events — verify they're excluded from state
    $state = $aggregate->toSnapshotState();

    expect($state)->not->toHaveKey('domainEvents');
});

// ─── BUG 3: restoreFromSnapshot does not double-restore version ───

it('restoreFromSnapshot sets version from snapshot, not from state', function (): void {
    $id = AggregateRootId::generate();
    $store = new InMemorySnapshotStore;

    // Create aggregate and bump version to 5
    $aggregate = SnapshotBugTestAggregate::create($id);
    $aggregate->clearEvents();

    // Manually set version to 5
    for ($i = 0; $i < 4; $i++) {
        $aggregate->incrementVersion();
    }

    expect($aggregate->version())->toBe(5);

    // Create snapshot — state should NOT contain version
    $aggregate->createSnapshot($store);

    // Load snapshot into a fresh instance
    $snapshot = $store->load(SnapshotBugTestAggregate::class, $id->toString());
    expect($snapshot)->not->toBeNull();

    $restored = new ReflectionClass(SnapshotBugTestAggregate::class)
        ->newInstanceWithoutConstructor();

    /** @var SnapshotBugTestAggregate $restored */
    $restored->restoreFromSnapshot($snapshot);

    // Version should come from $snapshot->version (5), not from state
    expect($restored->version())->toBe(5);
    expect($restored->status)->toBe('created');
});

it('snapshot round-trip preserves status and version independently', function (): void {
    $id = AggregateRootId::generate();
    $store = new InMemorySnapshotStore;

    $aggregate = SnapshotBugTestAggregate::create($id);
    $aggregate->clearEvents();
    $aggregate->status = 'active';
    $aggregate->incrementVersion();
    $aggregate->incrementVersion(); // version = 3

    $aggregate->createSnapshot($store);

    $snapshot = $store->load(SnapshotBugTestAggregate::class, $id->toString());

    $restored = new ReflectionClass(SnapshotBugTestAggregate::class)
        ->newInstanceWithoutConstructor();

    /** @var SnapshotBugTestAggregate $restored */
    $restored->restoreFromSnapshot($snapshot);

    expect($restored->status)->toBe('active');
    expect($restored->version())->toBe(3);
});

// ─── Fixture ─────────────────────────────────────────────────────

final class SnapshotBugTestAggregate extends AggregateRoot
{
    use EventSourced;
    use HasSnapshots;

    public string $status = 'pending';

    public static function create(AggregateRootId $id): self
    {
        $aggregate = new self($id);

        $aggregate->apply(DomainEvent::occur('snapshotbug.created', [
            'id' => $id->toString(),
            'status' => 'created',
        ]));

        return $aggregate;
    }

    public function applySnapshotbugCreated(DomainEvent $event): void
    {
        $this->status = $event->payload['status'] ?? 'created';
    }
}
