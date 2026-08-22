<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Domain\AggregateRoot;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\Concerns\EventSourced;
use ZeroBoiler\Domain\Concerns\HasSnapshots;
use ZeroBoiler\Domain\Snapshots\InMemorySnapshotStore;
use ZeroBoiler\Domain\Snapshots\Snapshot;
use ZeroBoiler\Domain\Snapshots\SnapshotPolicy;
use ZeroBoiler\Domain\Tests\Fixtures\TestStatus;
use ZeroBoiler\Events\Domain\DomainEvent;

/**
 * Edge case tests for HasSnapshots::toSnapshotState() and restoreFromSnapshotState().
 *
 * Covers: DateTimeInterface serialization, BackedEnum/UnitEnum handling,
 * stdClass passthrough, Closure exclusion, resource exclusion, readonly
 * property restoration, and domainEvents/version exclusion.
 */
describe('HasSnapshots serialization edge cases', function (): void {
    // ---------------------------------------------------------------------------
    // Fixture: An aggregate with various property types for snapshot testing
    // ---------------------------------------------------------------------------
    $fixtureClass = new #[SnapshotPolicy(every: 2)] class extends AggregateRoot
    {
        use EventSourced;
        use HasSnapshots;

        // Scalar types
        public string $name = '';
        public $count = 0;
        public float $rate = 0.0;
        public bool $active = false;

        // Nullable types
        public ?string $nullable = null;
        public ?int $nullableInt = null;

        // DateTimeInterface — should serialize to ISO string
        public \DateTimeImmutable $createdAt;

        // BackedEnum — should serialize to value
        public TestStatus $status;

        // Pure UnitEnum — should serialize to name
        public \UnitEnum $unitEnum;

        // stdClass — should pass through as-is
        public \stdClass $extra;

        // Array with mixed content
        public array $tags = [];

        // Closure — should be EXCLUDED from snapshot state
        public \Closure $callback;

        // Static property — should be EXCLUDED from snapshot state
        public static string $staticField = 'should-not-appear';

        public function __construct()
        {
            parent::__construct(AggregateRootId::generate());

            $this->createdAt = new \DateTimeImmutable('2026-01-15T10:30:00+00:00');
            $this->status = TestStatus::ACTIVE;
            $this->unitEnum = TestStatus::PENDING;
            $this->extra = (object) ['key' => 'value'];
            $this->tags = ['new', 'featured'];
            $this->callback = fn (): string => 'closure';
        }
    };

    // ---------------------------------------------------------------------------
    // toSnapshotState() — DateTimeInterface serialization
    // ---------------------------------------------------------------------------
    it('serializes DateTimeImmutable to ISO string', function () use ($fixtureClass): void {
        $state = $fixtureClass->toSnapshotState();

        expect($state['createdAt'])->toBe('2026-01-15T10:30:00+00:00');
    });

    // ---------------------------------------------------------------------------
    // toSnapshotState() — BackedEnum serialization
    // ---------------------------------------------------------------------------
    it('serializes BackedEnum to its value', function () use ($fixtureClass): void {
        $state = $fixtureClass->toSnapshotState();

        expect($state['status'])->toBe('active');
    });

    // ---------------------------------------------------------------------------
    // toSnapshotState() — UnitEnum serialization
    // ---------------------------------------------------------------------------
    it('serializes UnitEnum to its name', function () use ($fixtureClass): void {
        $state = $fixtureClass->toSnapshotState();

        expect($state['unitEnum'])->toBe('PENDING');
    });

    // ---------------------------------------------------------------------------
    // toSnapshotState() — stdClass passthrough
    // ---------------------------------------------------------------------------
    it('passes stdClass through without modification', function () use ($fixtureClass): void {
        $state = $fixtureClass->toSnapshotState();

        expect($state['extra'])->toBeInstanceOf(\stdClass::class)
            ->and($state['extra']->key)->toBe('value');
    });

    // ---------------------------------------------------------------------------
    // toSnapshotState() — Closure exclusion
    // ---------------------------------------------------------------------------
    it('excludes Closure properties from snapshot state', function () use ($fixtureClass): void {
        $state = $fixtureClass->toSnapshotState();

        expect($state)->not->toHaveKey('callback');
    });

    // ---------------------------------------------------------------------------
    // toSnapshotState() — Static property exclusion
    // ---------------------------------------------------------------------------
    it('excludes static properties from snapshot state', function () use ($fixtureClass): void {
        $state = $fixtureClass->toSnapshotState();

        expect($state)->not->toHaveKey('staticField');
    });

    // ---------------------------------------------------------------------------
    // toSnapshotState() — domainEvents exclusion
    // ---------------------------------------------------------------------------
    it('excludes domainEvents from snapshot state', function () use ($fixtureClass): void {
        $state = $fixtureClass->toSnapshotState();

        expect($state)->not->toHaveKey('domainEvents');
    });

    // ---------------------------------------------------------------------------
    // toSnapshotState() — version exclusion
    // ---------------------------------------------------------------------------
    it('excludes version from snapshot state', function () use ($fixtureClass): void {
        $fixtureClass->setVersion(42);
        $state = $fixtureClass->toSnapshotState();

        expect($state)->not->toHaveKey('version');
    });

    // ---------------------------------------------------------------------------
    // toSnapshotState() — Scalar types preserved
    // ---------------------------------------------------------------------------
    it('preserves all scalar types', function () use ($fixtureClass): void {
        $fixtureClass->name = 'Test Order';
        $fixtureClass->count = 10;
        $fixtureClass->rate = 99.99;
        $fixtureClass->active = true;
        $fixtureClass->nullable = 'has-value';
        $fixtureClass->nullableInt = 42;

        $state = $fixtureClass->toSnapshotState();

        expect($state['name'])->toBe('Test Order')
            ->and($state['count'])->toBe(10)
            ->and($state['rate'])->toBe(99.99)
            ->and($state['active'])->toBe(true)
            ->and($state['nullable'])->toBe('has-value')
            ->and($state['nullableInt'])->toBe(42);
    });

    // ---------------------------------------------------------------------------
    // toSnapshotState() — Array preservation
    // ---------------------------------------------------------------------------
    it('preserves array properties', function () use ($fixtureClass): void {
        $state = $fixtureClass->toSnapshotState();

        expect($state['tags'])->toBe(['new', 'featured']);
    });

    // ---------------------------------------------------------------------------
    // toSnapshotState() — Nullable null preservation
    // ---------------------------------------------------------------------------
    it('preserves null nullable values', function () use ($fixtureClass): void {
        $state = $fixtureClass->toSnapshotState();

        expect($state['nullable'])->toBeNull()
            ->and($state['nullableInt'])->toBeNull();
    });

    // ---------------------------------------------------------------------------
    // Full round-trip: toSnapshotState → restoreFromSnapshotState
    // ---------------------------------------------------------------------------
    it('round-trips state through toSnapshotState and restoreFromSnapshotState', function () use ($fixtureClass): void {
        $fixtureClass->name = 'Round-Trip Order';
        $fixtureClass->count = 25;
        $fixtureClass->rate = 149.50;
        $fixtureClass->active = true;
        $fixtureClass->tags = ['premium', 'verified'];

        $originalState = $fixtureClass->toSnapshotState();

        // Restore into a fresh instance
        $fresh = clone $fixtureClass;
        $fresh->name = '';
        $fresh->count = 0;
        $fresh->status = TestStatus::INACTIVE;

        $fresh->restoreFromSnapshotState($originalState);

        expect($fresh->name)->toBe('Round-Trip Order')
            ->and($fresh->count)->toBe(25)
            ->and($fresh->rate)->toBe(149.50)
            ->and($fresh->active)->toBe(true)
            ->and($fresh->tags)->toBe(['premium', 'verified'])
            // BackedEnum value restored
            ->and($fresh->status)->toBe(TestStatus::ACTIVE);
    });

    // ---------------------------------------------------------------------------
    // restoreFromSnapshot — sets version correctly
    // ---------------------------------------------------------------------------
    it('restoreFromSnapshot sets aggregate version', function () use ($fixtureClass): void {
        $fixtureClass->name = 'Version Test';
        $fixtureClass->count = 5;
        $fixtureClass->setVersion(0);

        $state = $fixtureClass->toSnapshotState();

        $snapshot = Snapshot::create(
            aggregateType: $fixtureClass::class,
            aggregateId: $fixtureClass->id(),
            version: 30,
            state: $state,
        );

        $fresh = clone $fixtureClass;
        $fresh->name = '';
        $fresh->setVersion(0);

        $fresh->restoreFromSnapshot($snapshot);

        expect($fresh->version())->toBe(30)
            ->and($fresh->name)->toBe('Version Test');
    });

    // ---------------------------------------------------------------------------
    // restoreFromSnapshotState — ignores unknown properties gracefully
    // ---------------------------------------------------------------------------
    it('ignores properties in state that do not exist on the aggregate', function () use ($fixtureClass): void {
        $state = $fixtureClass->toSnapshotState();
        $state['nonExistentProperty'] = 'should-be-ignored';

        // Should not throw
        $fixtureClass->restoreFromSnapshotState($state);

        expect($fixtureClass->name)->toBe('');
    });

    // ---------------------------------------------------------------------------
    // createSnapshot — full lifecycle with store
    // ---------------------------------------------------------------------------
    it('createSnapshot stores snapshot and restores correctly', function () use ($fixtureClass): void {
        $fixtureClass->name = 'Lifecycle Order';
        $fixtureClass->count = 7;
        $fixtureClass->setVersion(10);

        $store = new InMemorySnapshotStore;
        $snapshot = $fixtureClass->createSnapshot($store);

        expect($snapshot->version)->toBe(10)
            ->and($snapshot->state['name'])->toBe('Lifecycle Order')
            ->and($snapshot->state['count'])->toBe(7)
            ->and($snapshot->state['status'])->toBe('active')
            ->and($store->has($fixtureClass::class, $fixtureClass->id()))->toBeTrue();

        // Verify loaded snapshot matches
        $loaded = $store->load($fixtureClass::class, $fixtureClass->id());

        expect($loaded->version)->toBe(10)
            ->and($loaded->state['name'])->toBe('Lifecycle Order');
    });

    // ---------------------------------------------------------------------------
    // shouldSnapshot — respects policy interval
    // ---------------------------------------------------------------------------
    it('shouldSnapshot returns true at exact interval', function () use ($fixtureClass): void {
        $fixtureClass->setVersion(0);
        expect($fixtureClass->shouldSnapshot())->toBeFalse();

        $fixtureClass->setVersion(2); // every: 2
        expect($fixtureClass->shouldSnapshot())->toBeTrue();

        $fixtureClass->setVersion(4); // every: 2
        expect($fixtureClass->shouldSnapshot())->toBeTrue();

        $fixtureClass->setVersion(3);
        expect($fixtureClass->shouldSnapshot())->toBeFalse();
    });

    // ---------------------------------------------------------------------------
    // Snapshot JSON serialization — round-trip through json_encode/json_decode
    // ---------------------------------------------------------------------------
    it('snapshot JSON serialization round-trips correctly', function () use ($fixtureClass): void {
        $fixtureClass->name = 'JSON Order';
        $fixtureClass->count = 42;

        $snapshot = Snapshot::create(
            aggregateType: $fixtureClass::class,
            aggregateId: $fixtureClass->id(),
            version: 15,
            state: $fixtureClass->toSnapshotState(),
        );

        $json = json_encode($snapshot);
        expect($json)->not->toBeFalse();

        $decoded = json_decode($json, true);
        $restored = Snapshot::fromArray($decoded);

        expect($restored->aggregateType)->toBe($fixtureClass::class)
            ->and($restored->aggregateId)->toBe($fixtureClass->id())
            ->and($restored->version)->toBe(15)
            ->and($restored->state['name'])->toBe('JSON Order')
            ->and($restored->state['count'])->toBe(42)
            ->and($restored->state['status'])->toBe('active')
            ->and($restored->state['unitEnum'])->toBe('PENDING');
    });
});
