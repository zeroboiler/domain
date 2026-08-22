<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\DomainEventCollection;
use ZeroBoiler\Domain\Entity;
use ZeroBoiler\Domain\Exceptions\ConflictDomainException;
use ZeroBoiler\Domain\Exceptions\InvalidStateDomainException;
use ZeroBoiler\Domain\Exceptions\NotFoundDomainException;
use ZeroBoiler\Domain\Exceptions\OptimisticLockException;
use ZeroBoiler\Domain\Identifiers\IntegerIdentifier;
use ZeroBoiler\Domain\Identifiers\StringIdentifier;
use ZeroBoiler\Domain\Identifiers\UuidIdentifier;
use ZeroBoiler\Domain\Identifiers\UlidIdentifier;
use ZeroBoiler\Domain\InMemoryUnitOfWork;
use ZeroBoiler\Domain\SnapshottingRepository;
use ZeroBoiler\Domain\Snapshots\InMemorySnapshotStore;
use ZeroBoiler\Domain\Snapshots\Snapshot;

/**
 * Domain primitives production edge case tests.
 *
 * Covers: Identifier equality edge cases, exception serialization contracts,
 * InMemoryUnitOfWork transaction behavior, and Snapshot round-trip serialization.
 *
 * @since 1.51.0
 */

// ── Fixtures ──────────────────────────────────────────

final class EdgeTestOrder extends Entity
{
    public function __construct(
        int|string|\Stringable $id,
        public string $status = 'pending',
    ) {
        parent::__construct($id);
    }

    public function toArray(): array
    {
        return [
            ...parent::toArray(),
            'status' => $this->status,
        ];
    }
}

// ── Identifier Edge Cases ─────────────────────────────

describe('Identifier equality edge cases', function (): void {
    it('UuidIdentifier equals same value, not different value', function (): void {
        $a = UuidIdentifier::fromString('550e8400-e29b-41d4-a716-446655440000');
        $b = UuidIdentifier::fromString('550e8400-e29b-41d4-a716-446655440000');
        $c = UuidIdentifier::fromString('6ba7b810-9dad-11d1-80b4-00c04fd430c8');

        expect($a->equals($b))->toBeTrue()
            ->and($a->equals($c))->toBeFalse();
    });

    it('StringIdentifier equality is case-sensitive', function (): void {
        $a = StringIdentifier::from('my-slug');
        $b = StringIdentifier::from('my-slug');
        $c = StringIdentifier::from('My-Slug');

        expect($a->equals($b))->toBeTrue()
            ->and($a->equals($c))->toBeFalse();
    });

    it('IntegerIdentifier equals same integer', function (): void {
        $a = IntegerIdentifier::from(42);
        $b = IntegerIdentifier::from(42);
        $c = IntegerIdentifier::from(43);

        expect($a->equals($b))->toBeTrue()
            ->and($a->equals($c))->toBeFalse();
    });

    it('IntegerIdentifier from string coerces to int', function (): void {
        $id = IntegerIdentifier::fromString('100');

        expect($id->toInt())->toBe(100)
            ->and($id->toString())->toBe('100');
    });

    it('AggregateRootId round-trips through toArray/fromArray', function (): void {
        $id = AggregateRootId::fromString('550e8400-e29b-41d4-a716-446655440000');
        $array = $id->toArray();
        $restored = AggregateRootId::fromArray($array);

        expect($restored->equals($id))->toBeTrue()
            ->and($restored->toString())->toBe($id->toString());
    });

    it('all identifier types implement JsonSerializable', function (): void {
        $uuid = UuidIdentifier::fromString('550e8400-e29b-41d4-a716-446655440000');
        $ulid = UlidIdentifier::fromString('01H5J5S5S5S5S5S5S5S5S5S5S5');
        $string = StringIdentifier::from('slug');
        $integer = IntegerIdentifier::from(42);

        expect($uuid)->toBeInstanceOf(\JsonSerializable::class)
            ->and($ulid)->toBeInstanceOf(\JsonSerializable::class)
            ->and($string)->toBeInstanceOf(\JsonSerializable::class)
            ->and($integer)->toBeInstanceOf(\JsonSerializable::class);
    });
});

// ── Exception Serialization Contracts ───────────────

describe('Domain exception serialization contracts', function (): void {
    it('all exceptions produce toErrorArray with required keys', function (): void {
        $exceptions = [
            InvalidStateDomainException::because('Test message', 'TEST_CODE'),
            NotFoundDomainException::forId('order-1', 'Order'),
            ConflictDomainException::forEntity('Order', 'order-1'),
            OptimisticLockException::forAggregate('Order', 'order-1', expectedVersion: 1, actualVersion: 3),
        ];

        foreach ($exceptions as $exception) {
            $errorArray = $exception->toErrorArray();

            expect($errorArray)->toHaveKeys(['title', 'detail', 'code'])
                ->and($errorArray['title'])->toBeString()
                ->and($errorArray['detail'])->toBeString()
                ->and($errorArray['code'])->toBeString();
        }
    });

    it('exception jsonSerialize matches toErrorArray', function (): void {
        $exception = InvalidStateDomainException::because('State violation', 'STATE_ERR');
        expect($exception->jsonSerialize())->toBe($exception->toErrorArray());
    });

    it('exception error arrays produce valid JSON', function (): void {
        $exception = NotFoundDomainException::forId('user-99', 'User');
        $json = json_encode($exception->toErrorArray(), JSON_THROW_ON_ERROR);
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        expect($decoded['title'])->toBe('NotFoundDomainException')
            ->and($decoded['code'])->toBe('NOT_FOUND');
    });
});

// ── InMemoryUnitOfWork Edge Cases ────────────────────

describe('InMemoryUnitOfWork transaction behavior', function (): void {
    it('commit persists tracked aggregate', function (): void {
        $uow = new InMemoryUnitOfWork;
        $id = AggregateRootId::generate();
        $aggregate = new class ($id) extends \ZeroBoiler\Domain\AggregateRoot {
            public function __construct(AggregateRootId $id) { parent::__construct($id); }
        };

        $uow->track($aggregate);
        $uow->commit();

        $found = $uow->findById($aggregate::class, $id->toString());

        expect($found)->not->toBeNull();
    });

    it('rollback discards tracked aggregate', function (): void {
        $uow = new InMemoryUnitOfWork;
        $id = AggregateRootId::generate();
        $aggregate = new class ($id) extends \ZeroBoiler\Domain\AggregateRoot {
            public function __construct(AggregateRootId $id) { parent::__construct($id); }
        };

        $uow->track($aggregate);
        $uow->rollback();

        $found = $uow->findById($aggregate::class, $id->toString());

        expect($found)->toBeNull();
    });

    it('delete removes tracked aggregate', function (): void {
        $uow = new InMemoryUnitOfWork;
        $id = AggregateRootId::generate();
        $aggregate = new class ($id) extends \ZeroBoiler\Domain\AggregateRoot {
            public function __construct(AggregateRootId $id) { parent::__construct($id); }
        };

        $uow->track($aggregate);
        $uow->commit();
        $uow->delete($aggregate::class, $id->toString());

        $found = $uow->findById($aggregate::class, $id->toString());

        expect($found)->toBeNull();
    });

    it('findById returns null for unknown aggregate', function (): void {
        $uow = new InMemoryUnitOfWork;
        $found = $uow->findById('NonExistent', 'missing-id');

        expect($found)->toBeNull();
    });
});

// ── Snapshot Round-Trip ────────────────────────────────

describe('Snapshot round-trip serialization', function (): void {
    it('snapshot toArray/fromArray preserves all fields', function (): void {
        $snapshot = Snapshot::create(
            aggregateType: 'App\\Domain\\Order',
            aggregateId: '550e8400-e29b-41d4-a716-446655440000',
            version: 5,
            state: ['status' => 'confirmed', 'total' => 12500],
        );

        $array = $snapshot->toArray();
        $restored = Snapshot::fromArray($array);

        expect($restored->aggregateType)->toBe($snapshot->aggregateType)
            ->and($restored->aggregateId)->toBe($snapshot->aggregateId)
            ->and($restored->version)->toBe($snapshot->version)
            ->and($restored->state)->toBe($snapshot->state);
    });

    it('snapshot is JSON serializable', function (): void {
        $snapshot = Snapshot::create(
            aggregateType: 'App\\Domain\\Invoice',
            aggregateId: 'invoice-123',
            version: 3,
            state: ['amount' => 5000, 'paid' => true],
        );

        $json = json_encode($snapshot, JSON_THROW_ON_ERROR);
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        expect($decoded['version'])->toBe(3)
            ->and($decoded['state']['paid'])->toBeTrue();
    });

    it('InMemorySnapshotStore save and load round-trip', function (): void {
        $store = new InMemorySnapshotStore;
        $snapshot = Snapshot::create(
            aggregateType: 'App\\Domain\\Order',
            aggregateId: 'order-42',
            version: 10,
            state: ['items' => 3],
        );

        $store->save($snapshot);
        $loaded = $store->load('App\\Domain\\Order', 'order-42');

        expect($loaded)->not->toBeNull()
            ->and($loaded->version)->toBe(10)
            ->and($loaded->state)->toBe(['items' => 3]);
    });

    it('InMemorySnapshotStore returns null for missing snapshot', function (): void {
        $store = new InMemorySnapshotStore;
        $loaded = $store->load('App\\Domain\\Missing', 'missing-id');

        expect($loaded)->toBeNull();
    });
});

// ── Entity Edge Cases ────────────────────────────────

describe('Entity serialization edge cases', function (): void {
    it('entity fromArray/toArray round-trip preserves custom fields', function (): void {
        $entity = EdgeTestOrder::fromArray([
            'id' => 'order-1',
            'status' => 'shipped',
        ]);

        $array = $entity->toArray();

        expect($array['id'])->toBe('order-1')
            ->and($array['status'])->toBe('shipped')
            ->and($array['type'])->toBe('EdgeTestOrder');
    });

    it('entity id() returns string consistently', function (): void {
        $entity = EdgeTestOrder::fromArray(['id' => 'order-99', 'status' => 'pending']);

        expect($entity->id())->toBe('order-99')
            ->and($entity->id())->toBeString();
    });
});
