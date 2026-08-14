<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\Identifiers\UuidIdentifier;
use ZeroBoiler\Domain\Identifiers\UlidIdentifier;
use ZeroBoiler\Domain\Identifiers\StringIdentifier;
use ZeroBoiler\Domain\Identifiers\IntegerIdentifier;
use ZeroBoiler\Domain\Snapshots\Snapshot;

/**
 * Tests for PHP native __serialize()/__unserialize() support on readonly classes.
 *
 * Validates round-trip serialization via serialize()/unserialize() which
 * uses the magic __serialize/__unserialize methods instead of the deprecated
 * Serializable interface.
 *
 * @since 2.12.0
 */

describe('AggregateRootId PHP Serialize', function (): void {
    it('round-trips via serialize/unserialize', function (): void {
        $original = AggregateRootId::generate();
        $restored = unserialize(serialize($original));

        expect($restored)->toBeInstanceOf(AggregateRootId::class);
        expect($restored->equals($original))->toBeTrue();
        expect($restored->toString())->toBe($original->toString());
    });

    it('restores from serialized data with uuid key', function (): void {
        $id = AggregateRootId::generate();
        $serialized = serialize($id);
        $restored = unserialize($serialized);

        expect($restored->toString())->toBe($id->toString());
    });

    it('works with fromString-created IDs', function (): void {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $id = AggregateRootId::fromString($uuid);
        $restored = unserialize(serialize($id));

        expect($restored->toString())->toBe($uuid);
    });

    it('can be serialized multiple times', function (): void {
        $id = AggregateRootId::generate();
        $first = unserialize(serialize($id));
        $second = unserialize(serialize($first));

        expect($id->equals($second))->toBeTrue();
    });
});

describe('UuidIdentifier PHP Serialize', function (): void {
    it('round-trips via serialize/unserialize', function (): void {
        /** @var UuidIdentifier $id */
        $id = TestUuidIdentifier::generate();
        $restored = unserialize(serialize($id));

        expect($restored)->toBeInstanceOf(TestUuidIdentifier::class);
        expect($restored->equals($id))->toBeTrue();
        expect($restored->toString())->toBe($id->toString());
    });
});

describe('UlidIdentifier PHP Serialize', function (): void {
    it('round-trips via serialize/unserialize', function (): void {
        /** @var UlidIdentifier $id */
        $id = TestUlidIdentifier::generate();
        $restored = unserialize(serialize($id));

        expect($restored)->toBeInstanceOf(TestUlidIdentifier::class);
        expect($restored->equals($id))->toBeTrue();
    });
});

describe('StringIdentifier PHP Serialize', function (): void {
    it('round-trips via serialize/unserialize', function (): void {
        $id = StringIdentifier::from('my-slug-123');
        $restored = unserialize(serialize($id));

        expect($restored)->toBeInstanceOf(StringIdentifier::class);
        expect($restored->equals($id))->toBeTrue();
        expect($restored->toString())->toBe('my-slug-123');
    });
});

describe('IntegerIdentifier PHP Serialize', function (): void {
    it('round-trips via serialize/unserialize', function (): void {
        $id = IntegerIdentifier::from(42);
        $restored = unserialize(serialize($id));

        expect($restored)->toBeInstanceOf(IntegerIdentifier::class);
        expect($restored->equals($id))->toBeTrue();
        expect($restored->toInt())->toBe(42);
    });
});

describe('Snapshot PHP Serialize', function (): void {
    it('round-trips via serialize/unserialize', function (): void {
        $original = Snapshot::create('Order', 'uuid-here', 5, ['status' => 'pending', 'total' => 99.99]);
        $restored = unserialize(serialize($original));

        expect($restored)->toBeInstanceOf(Snapshot::class);
        expect($restored->aggregateType)->toBe('Order');
        expect($restored->aggregateId)->toBe('uuid-here');
        expect($restored->version)->toBe(5);
        expect($restored->state)->toBe(['status' => 'pending', 'total' => 99.99]);
        expect($restored->createdAt->format(\DateTimeInterface::ATOM))
            ->toBe($original->createdAt->format(\DateTimeInterface::ATOM));
    });

    it('snapshot equality holds after round-trip', function (): void {
        $original = Snapshot::create('Invoice', 'inv-123', 10, ['paid' => true]);
        $restored = unserialize(serialize($original));

        expect($original->equals($restored))->toBeTrue();
    });

    it('fromArray output matches __serialize output', function (): void {
        $snapshot = Snapshot::create('Order', 'order-1', 3, ['status' => 'shipped']);

        expect($snapshot->__serialize())->toBe($snapshot->toArray());
    });
});

/**
 * @internal Test-only fixture classes.
 */
final readonly class TestUuidIdentifier extends UuidIdentifier {}
final readonly class TestUlidIdentifier extends UlidIdentifier {}
