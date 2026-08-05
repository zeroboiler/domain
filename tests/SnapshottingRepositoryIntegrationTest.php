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
use ZeroBoiler\Domain\Snapshots\InMemorySnapshotStore;
use ZeroBoiler\Domain\Snapshots\Snapshot;
use ZeroBoiler\Domain\Snapshots\SnapshotPolicy;
use ZeroBoiler\Domain\Snapshots\SnapshottingRepository;
use ZeroBoiler\Events\Domain\DomainEvent;

// ===========================================================================
//  SnapshottingRepository — full integration tests
// ===========================================================================

describe('SnapshottingRepository integration', function (): void {
    it('full lifecycle: create aggregate, save, snapshot, find, replay', function (): void {
        $store = new InMemorySnapshotStore;

        // Track saved aggregates in the inner repo
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

        $aggregateType = new #[SnapshotPolicy(every: 5)]
        class extends AggregateRoot
        {
            use EventSourced;
            use HasSnapshots;

            public string $status = 'created';

            public int $eventCount = 0;

            public function __construct()
            {
                parent::__construct(AggregateRootId::generate());
            }

            public function advance(string $newStatus): void
            {
                $this->apply(DomainEvent::occur('status.changed', [
                    'status' => $newStatus,
                    'event_count' => $this->eventCount + 1,
                ]));
            }

            protected function applyStatusChanged(DomainEvent $event): void
            {
                $this->status = $event->payload['status'];
                $this->eventCount = $event->payload['event_count'];
            }
        };

        $repo = new SnapshottingRepository(
            inner: $innerRepo,
            snapshotStore: $store,
            aggregateType: $aggregateType::class,
        );

        // Create and advance through 10 events
        $aggregate = new $aggregateType;
        $aggregate->advance('processing');
        $aggregate->advance('validated');
        $aggregate->advance('packed');
        $aggregate->advance('shipped');
        $aggregate->advance('in_transit');
        $aggregate->advance('out_for_delivery');
        $aggregate->advance('delivered');
        $aggregate->advance('confirmed');
        $aggregate->advance('completed');

        // Save — should create snapshot at version 10 (every:5 → 5 and 10)
        $repo->save($aggregate);

        $id = $aggregate->id();

        // Verify snapshot was created
        $snapshot = $store->load($aggregateType::class, $id);
        expect($snapshot)->not->toBeNull()
            ->and($snapshot->version)->toBe(10)
            ->and($snapshot->state['status'])->toBe('completed')
            ->and($snapshot->state['eventCount'])->toBe(10);

        // Find via snapshotting repo — should use snapshot + replay 0 events
        $found = $repo->find($id);

        expect($found)->not->toBeNull()
            ->and($found->id())->toBe($id)
            ->and($found->status)->toBe('completed')
            ->and($found->eventCount)->toBe(10);

        // Verify identity equality
        expect($aggregate->equals($found))->toBeTrue();
    });

    it('findWithSnapshot with replay callback restores correct state', function (): void {
        $store = new InMemorySnapshotStore;

        $aggregateType = new #[SnapshotPolicy(every: 5)]
        class extends AggregateRoot
        {
            use EventSourced;
            use HasSnapshots;

            public int $counter = 0;

            public string $label = 'initial';

            public function __construct()
            {
                parent::__construct(AggregateRootId::generate());
            }

            protected function applyCounterIncremented(DomainEvent $event): void
            {
                $this->counter = $event->payload['counter'];
            }

            protected function applyLabelChanged(DomainEvent $event): void
            {
                $this->label = $event->payload['label'];
            }
        };

        // Create snapshot at version 5 with counter=5, label='snapshot'
        $id = AggregateRootId::generate()->toString();
        $store->save(Snapshot::create(
            aggregateType: $aggregateType::class,
            aggregateId: $id,
            version: 5,
            state: ['counter' => 5, 'label' => 'snapshot'],
        ));

        $innerRepo = new class implements Repository
        {
            public function find(string|int $id): ?AggregateRoot
            {
                return null;
            }

            public function save(AggregateRoot $aggregate): void {}

            public function delete(string|int $id): void {}
        };

        $repo = new SnapshottingRepository(
            inner: $innerRepo,
            snapshotStore: $store,
            aggregateType: $aggregateType::class,
        );

        // Provide 3 post-snapshot events
        $result = $repo->findWithSnapshot($id, function (int $snapshotVersion) use ($id): array {
            expect($snapshotVersion)->toBe(5);

            return [
                DomainEvent::occur('counter.incremented', ['id' => $id, 'counter' => 8]),
                DomainEvent::occur('label.changed', ['id' => $id, 'label' => 'updated']),
                DomainEvent::occur('counter.incremented', ['id' => $id, 'counter' => 10]),
            ];
        });

        expect($result)->not->toBeNull()
            ->and($result->counter)->toBe(10)
            ->and($result->label)->toBe('updated')
            ->and($result->version())->toBe(5); // Version from snapshot, not event count
    });

    it('concurrent snapshot types do not interfere', function (): void {
        $store = new InMemorySnapshotStore;

        $orderType = new #[SnapshotPolicy(every: 2)]
        class extends AggregateRoot
        {
            use HasSnapshots;

            public string $type = 'order';

            public function __construct()
            {
                parent::__construct(AggregateRootId::generate());
            }
        };

        $invoiceType = new #[SnapshotPolicy(every: 2)]
        class extends AggregateRoot
        {
            use HasSnapshots;

            public string $type = 'invoice';

            public function __construct()
            {
                parent::__construct(AggregateRootId::generate());
            }
        };

        $innerRepo = new class implements Repository
        {
            public function find(string|int $id): ?AggregateRoot
            {
                return null;
            }

            public function save(AggregateRoot $aggregate): void {}

            public function delete(string|int $id): void {}
        };

        $orderRepo = new SnapshottingRepository(
            inner: $innerRepo,
            snapshotStore: $store,
            aggregateType: $orderType::class,
        );

        $invoiceRepo = new SnapshottingRepository(
            inner: $innerRepo,
            snapshotStore: $store,
            aggregateType: $invoiceType::class,
        );

        $order = new $orderType;
        $order->setVersion(2);
        $orderRepo->save($order);

        $invoice = new $invoiceType;
        $invoice->setVersion(2);
        $invoiceRepo->save($invoice);

        // Both snapshots should exist
        expect($store->has($orderType::class, $order->id()))->toBeTrue()
            ->and($store->has($invoiceType::class, $invoice->id()))->toBeTrue();

        // Each should load only its own type
        $orderSnapshot = $store->load($orderType::class, $order->id());
        $invoiceSnapshot = $store->load($invoiceType::class, $invoice->id());

        expect($orderSnapshot->state['type'])->toBe('order')
            ->and($invoiceSnapshot->state['type'])->toBe('invoice');
    });

    it('snapshot at version 0 is never created', function (): void {
        $store = new InMemorySnapshotStore;

        $aggregateType = new #[SnapshotPolicy(every: 1)]
        class extends AggregateRoot
        {
            use HasSnapshots;

            public function __construct()
            {
                parent::__construct(AggregateRootId::generate());
            }
        };

        $aggregate = new $aggregateType;
        // Version 0 — should NOT trigger snapshot (0 % 1 === 0 but shouldSnapshot checks > 0)

        $innerRepo = new class implements Repository
        {
            public function find(string|int $id): ?AggregateRoot
            {
                return null;
            }

            public function save(AggregateRoot $aggregate): void {}

            public function delete(string|int $id): void {}
        };

        $repo = new SnapshottingRepository(
            inner: $innerRepo,
            snapshotStore: $store,
            aggregateType: $aggregateType::class,
        );

        $repo->save($aggregate);

        expect($store->has($aggregateType::class, $aggregate->id()))->toBeFalse();
    });

    it('instantiateFromSnapshot returns null for non-AggregateRoot class', function (): void {
        $store = new InMemorySnapshotStore;

        // Save a snapshot pointing to a non-existent class
        $store->save(Snapshot::create(
            'NonExistentClass',
            'fake-id',
            1,
            ['foo' => 'bar'],
        ));

        $innerRepo = new class implements Repository
        {
            public function find(string|int $id): ?AggregateRoot
            {
                return null;
            }

            public function save(AggregateRoot $aggregate): void {}

            public function delete(string|int $id): void {}
        };

        $repo = new SnapshottingRepository(
            inner: $innerRepo,
            snapshotStore: $store,
            aggregateType: 'NonExistentClass',
        );

        // Should fall back to inner repo since snapshot class doesn't exist
        $result = $repo->find('fake-id');

        expect($result)->toBeNull(); // Inner also returns null
    });
});
