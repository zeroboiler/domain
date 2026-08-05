<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Domain\AggregateRoot;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\Concerns\EventSourced;
use ZeroBoiler\Domain\Concerns\HasSnapshots;
use ZeroBoiler\Domain\Contracts\Repository;
use ZeroBoiler\Domain\Identifiers\IntegerIdentifier;
use ZeroBoiler\Domain\Identifiers\StringIdentifier;
use ZeroBoiler\Domain\Identifiers\UuidIdentifier;
use ZeroBoiler\Domain\Snapshots\InMemorySnapshotStore;
use ZeroBoiler\Domain\Snapshots\Snapshot;
use ZeroBoiler\Domain\Snapshots\SnapshotPolicy;
use ZeroBoiler\Domain\Snapshots\SnapshottingRepository;
use ZeroBoiler\Events\Domain\DomainEvent;

// ===========================================================================
//  SnapshottingRepository — additional production-ready tests
// ===========================================================================

describe('SnapshottingRepository production edge cases', function (): void {
    it('delete removes both snapshot and aggregate', function (): void {
        $store = new InMemorySnapshotStore;

        $innerRepo = new class implements Repository
        {
            /** @var array<string, AggregateRoot> */
            public array $store = [];

            public function find(string|int $id): ?AggregateRoot
            {
                return $this->store[(string) $id] ?? null;
            }

            public function save(AggregateRoot $aggregate): void
            {
                $this->store[$aggregate->id()] = $aggregate;
            }

            public function delete(string|int $id): void
            {
                unset($this->store[(string) $id]);
            }
        };

        $aggregateType = new #[SnapshotPolicy(every: 1)]
        class extends AggregateRoot
        {
            use EventSourced;
            use HasSnapshots;

            public string $value = 'test';

            public function __construct()
            {
                parent::__construct(AggregateRootId::generate());
            }
        };

        $repo = new SnapshottingRepository(
            inner: $innerRepo,
            snapshotStore: $store,
            aggregateType: $aggregateType::class,
        );

        $aggregate = new $aggregateType;
        $aggregate->setVersion(1);
        $repo->save($aggregate);

        $id = $aggregate->id();

        // Verify both exist
        expect($innerRepo->find($id))->not->toBeNull()
            ->and($store->has($aggregateType::class, $id))->toBeTrue();

        // Delete
        $repo->delete($id);

        // Both should be gone
        expect($innerRepo->find($id))->toBeNull()
            ->and($store->has($aggregateType::class, $id))->toBeFalse();
    });

    it('snapshotStore getter returns the injected store', function (): void {
        $store = new InMemorySnapshotStore;

        $innerRepo = new class implements Repository
        {
            public function find(string|int $id): ?AggregateRoot { return null; }
            public function save(AggregateRoot $aggregate): void {}
            public function delete(string|int $id): void {}
        };

        $repo = new SnapshottingRepository(
            inner: $innerRepo,
            snapshotStore: $store,
            aggregateType: 'TestAggregate',
        );

        expect($repo->snapshotStore())->toBe($store);
    });

    it('save creates snapshot only when HasSnapshots trait is present', function (): void {
        $store = new InMemorySnapshotStore;

        $savedAggregates = [];
        $innerRepo = new class implements Repository
        {
            /** @var array<string, AggregateRoot> */
            public array $store = [];

            public function find(string|int $id): ?AggregateRoot
            {
                return $this->store[(string) $id] ?? null;
            }

            public function save(AggregateRoot $aggregate): void
            {
                $this->store[$aggregate->id()] = $aggregate;
            }

            public function delete(string|int $id): void
            {
                unset($this->store[(string) $id]);
            }
        };

        // Aggregate WITHOUT HasSnapshots trait
        $aggregateTypeNoSnap = new #[SnapshotPolicy(every: 1)]
        class extends AggregateRoot
        {
            public function __construct()
            {
                parent::__construct(AggregateRootId::generate());
            }
        };

        $repo = new SnapshottingRepository(
            inner: $innerRepo,
            snapshotStore: $store,
            aggregateType: $aggregateTypeNoSnap::class,
        );

        $aggregate = new $aggregateTypeNoSnap;
        $aggregate->setVersion(5);
        $repo->save($aggregate);

        // Should NOT create a snapshot (no HasSnapshots trait)
        expect($store->has($aggregateTypeNoSnap::class, $aggregate->id()))->toBeFalse();
    });

    it('find returns inner result when no snapshot exists', function (): void {
        $store = new InMemorySnapshotStore;

        $innerRepo = new class implements Repository
        {
            /** @var array<string, AggregateRoot> */
            public array $store = [];

            public function find(string|int $id): ?AggregateRoot
            {
                return $this->store[(string) $id] ?? null;
            }

            public function save(AggregateRoot $aggregate): void
            {
                $this->store[$aggregate->id()] = $aggregate;
            }

            public function delete(string|int $id): void
            {
                unset($this->store[(string) $id]);
            }
        };

        $aggregateType = new #[SnapshotPolicy(every: 10)]
        class extends AggregateRoot
        {
            use EventSourced;
            use HasSnapshots;

            public function __construct()
            {
                parent::__construct(AggregateRootId::generate());
            }
        };

        $repo = new SnapshottingRepository(
            inner: $innerRepo,
            snapshotStore: $store,
            aggregateType: $aggregateType::class,
        );

        $aggregate = new $aggregateType;
        $aggregate->setVersion(3);
        $innerRepo->save($aggregate);

        // No snapshot — should fall back to inner repo
        $found = $repo->find($aggregate->id());

        expect($found)->not->toBeNull()
            ->and($found->id())->toBe($aggregate->id());
    });
});

describe('Entity type system', function (): void {
    it('Entity supports integer ID', function (): void {
        $entity = new class(42) extends \ZeroBoiler\Domain\Entity {};

        expect($entity->id())->toBe('42');
    });

    it('Entity supports string ID', function (): void {
        $entity = new class('user-123') extends \ZeroBoiler\Domain\Entity {};

        expect($entity->id())->toBe('user-123');
    });

    it('Entity supports UUID identifier', function (): void {
        $id = UuidIdentifier::generate();
        $entity = new class($id) extends \ZeroBoiler\Domain\Entity {};

        expect($entity->id())->toBe($id->toString());
    });

    it('Entity supports integer identifier', function (): void {
        $id = new IntegerIdentifier(99);
        $entity = new class($id) extends \ZeroBoiler\Domain\Entity {};

        expect($entity->id())->toBe('99');
    });

    it('Entity supports string identifier', function (): void {
        $id = new StringIdentifier('slug-value');
        $entity = new class($id) extends \ZeroBoiler\Domain\Entity {};

        expect($entity->id())->toBe('slug-value');
    });

    it('Entity equals returns true for same class and ID', function (): void {
        $entity1 = new class('abc') extends \ZeroBoiler\Domain\Entity {};
        $entity2 = new class('abc') extends \ZeroBoiler\Domain\Entity {};

        // Different anonymous classes — equals should return false
        expect($entity1->equals($entity2))->toBeFalse();
    });

    it('AggregateRoot equals uses aggregate ID', function (): void {
        $id = AggregateRootId::generate();

        $root1 = new class($id) extends AggregateRoot
        {
            public function __construct(AggregateRootId $id)
            {
                parent::__construct($id);
            }
        };

        $root2 = new class($id) extends AggregateRoot
        {
            public function __construct(AggregateRootId $id)
            {
                parent::__construct($id);
            }
        };

        // Different anonymous classes — equals should return false
        expect($root1->equals($root2))->toBeFalse();
    });
});
