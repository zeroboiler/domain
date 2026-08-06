<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Tests;

use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\DomainEventCollection;
use ZeroBoiler\Domain\Identifiers\IntegerIdentifier;
use ZeroBoiler\Domain\Identifiers\StringIdentifier;
use ZeroBoiler\Domain\Identifiers\UuidIdentifier;
use ZeroBoiler\Domain\Identifiers\UlidIdentifier;
use ZeroBoiler\Domain\Snapshots\Snapshot;
use ZeroBoiler\Domain\SnapshottingRepository;
use ZeroBoiler\Domain\Identifiers\Identifier as LegacyIdentifier;
use ZeroBoiler\Events\Domain\DomainEvent;

// ===========================================================================
//  Production-ready serialization, round-trip, and cross-type tests
//  for all domain value types.
// ===========================================================================

/**
 * Concrete UuidIdentifier subclass for testing.
 */
final readonly class SerdeUuidId extends UuidIdentifier {}

/**
 * Concrete UlidIdentifier subclass for testing.
 */
final readonly class SerdeUlidId extends UlidIdentifier {}

describe('Domain production serialization', function (): void {
    // ===========================================================================
    //  AggregateRootId
    // ===========================================================================
    describe('AggregateRootId', function (): void {
        it('generates, serializes to JSON, and round-trips', function (): void {
            $id = AggregateRootId::generate();

            // JSON serialization
            $json = json_encode(['order_id' => $id]);
            $data = json_decode($json, true);
            expect($data['order_id'])->toBe($id->toString());

            // Round-trip
            $restored = AggregateRootId::fromString($id->toString());
            expect($id->equals($restored))->toBeTrue();
        });

        it('Stringable and JsonSerializable return identical values', function (): void {
            $id = AggregateRootId::generate();

            expect((string) $id)
                ->toBe($id->toString())
                ->toBe($id->jsonSerialize());
        });

        it('two different IDs are never equal', function (): void {
            $a = AggregateRootId::generate();
            $b = AggregateRootId::generate();

            expect($a->equals($b))->toBeFalse();
        });
    });

    // ===========================================================================
    //  UuidIdentifier
    // ===========================================================================
    describe('UuidIdentifier', function (): void {
        it('generates and validates UUID format', function (): void {
            $id = SerdeUuidId::generate();

            expect($id->toString())->toMatch(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i'
            );
        });

        it('serializes to JSON as string value', function (): void {
            $id = SerdeUuidId::generate();
            $json = json_encode(['id' => $id]);

            expect($json)->toBeJson();
            $data = json_decode($json, true);
            expect($data['id'])->toBe($id->toString());
        });

        it('round-trips through fromString', function (): void {
            $original = SerdeUuidId::generate();
            $restored = SerdeUuidId::fromString($original->toString());

            expect($original->equals($restored))->toBeTrue();
        });

        it('rejects invalid UUID in constructor', function (): void {
            expect(fn () => new SerdeUuidId('not-a-uuid'))
                ->toThrow(\Ramsey\Uuid\Exception\InvalidUuidStringException::class);
        });

        it('toUuid() returns valid Ramsey instance', function (): void {
            $id = SerdeUuidId::generate();
            $ramsey = $id->toUuid();

            expect($ramsey->toString())->toBe($id->toString());
        });
    });

    // ===========================================================================
    //  UlidIdentifier
    // ===========================================================================
    describe('UlidIdentifier', function (): void {
        it('generates monotonic ULID', function (): void {
            $id = SerdeUlidId::generate();

            expect($id->toString())->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/');
            expect(UlidIdentifier::isValid($id->toString()))->toBeTrue();
        });

        it('serializes to JSON as string value', function (): void {
            $id = SerdeUlidId::generate();
            $json = json_encode(['product_id' => $id]);

            $data = json_decode($json, true);
            expect($data['product_id'])->toBe($id->toString());
        });

        it('round-trips through fromString', function (): void {
            $original = SerdeUlidId::generate();
            $restored = SerdeUlidId::fromString($original->toString());

            expect($original->equals($restored))->toBeTrue();
        });

        it('rejects invalid ULID in constructor', function (): void {
            expect(fn () => new SerdeUlidId('not-a-ulid!!!'))
                ->toThrow(\InvalidArgumentException::class);
        });

        it('toUlid() returns valid Symfony instance', function (): void {
            $id = SerdeUlidId::generate();
            $ulid = $id->toUlid();

            expect($ulid->toBase32())->toBe($id->toString());
        });

        it('isValid returns false for invalid ULIDs', function (): void {
            expect(UlidIdentifier::isValid(''))->toBeFalse()
                ->and(UlidIdentifier::isValid('abc'))->toBeFalse();
        });
    });

    // ===========================================================================
    //  StringIdentifier
    // ===========================================================================
    describe('StringIdentifier', function (): void {
        it('creates from non-empty string', function (): void {
            $id = StringIdentifier::from('my-slug');

            expect($id->toString())->toBe('my-slug');
        });

        it('serializes to JSON as string', function (): void {
            $id = StringIdentifier::from('hello');
            $json = json_encode(['slug' => $id]);

            expect($json)->toBe('{"slug":"hello"}');
        });

        it('rejects empty string with ValueError', function (): void {
            expect(fn () => StringIdentifier::from(''))
                ->toThrow(\ValueError::class);
        });

        it('round-trips through fromString', function (): void {
            $original = StringIdentifier::from('test-key');
            $restored = StringIdentifier::fromString('test-key');

            expect($original->equals($restored))->toBeTrue();
        });

        it('isValid returns correct booleans', function (): void {
            expect(StringIdentifier::isValid('hello'))->toBeTrue()
                ->and(StringIdentifier::isValid(''))->toBeFalse();
        });

        it('different values are not equal', function (): void {
            $a = StringIdentifier::from('alpha');
            $b = StringIdentifier::from('beta');

            expect($a->equals($b))->toBeFalse();
        });
    });

    // ===========================================================================
    //  IntegerIdentifier
    // ===========================================================================
    describe('IntegerIdentifier', function (): void {
        it('creates from integer', function (): void {
            $id = IntegerIdentifier::from(42);

            expect($id->toInt())->toBe(42)
                ->and($id->toString())->toBe('42');
        });

        it('serializes to JSON as integer (not string)', function (): void {
            $id = IntegerIdentifier::from(42);
            $json = json_encode(['id' => $id]);

            expect($json)->toBe('{"id":42}');
        });

        it('round-trips through fromString', function (): void {
            $original = IntegerIdentifier::from(99);
            $restored = IntegerIdentifier::fromString('99');

            expect($original->equals($restored))->toBeTrue()
                ->and($restored->toInt())->toBe(99);
        });

        it('handles negative integers', function (): void {
            $id = IntegerIdentifier::from(-5);

            expect($id->toInt())->toBe(-5)
                ->and($id->toString())->toBe('-5');
        });

        it('jsonSerialize returns int type', function (): void {
            $id = IntegerIdentifier::from(7);

            expect($id->jsonSerialize())->toBe(7)
                ->and($id->jsonSerialize())->toBeInt();
        });

        it('isValid returns correct booleans', function (): void {
            expect(IntegerIdentifier::isValid('42'))->toBeTrue()
                ->and(IntegerIdentifier::isValid('-5'))->toBeTrue()
                ->and(IntegerIdentifier::isValid('abc'))->toBeFalse();
        });
    });

    // ===========================================================================
    //  Snapshot serialization
    // ===========================================================================
    describe('Snapshot round-trip', function (): void {
        it('toArray/fromArray preserves all fields', function (): void {
            $original = Snapshot::create(
                aggregateType: 'App\\Domain\\Order',
                aggregateId: 'order-123',
                version: 10,
                state: ['status' => 'paid', 'total' => 99.99, 'items' => [1, 2, 3]],
            );

            $restored = Snapshot::fromArray($original->toArray());

            expect($restored->aggregateType)->toBe('App\\Domain\\Order')
                ->and($restored->aggregateId)->toBe('order-123')
                ->and($restored->version)->toBe(10)
                ->and($restored->state)->toBe(['status' => 'paid', 'total' => 99.99, 'items' => [1, 2, 3]]);
        });

        it('full JSON round-trip: encode → decode → fromArray', function (): void {
            $original = Snapshot::create('App\\Test', 'id-1', 3, ['x' => 'value']);
            $json = json_encode($original);
            $restored = Snapshot::fromArray(json_decode($json, true));

            expect($restored->aggregateType)->toBe('App\\Test')
                ->and($restored->aggregateId)->toBe('id-1')
                ->and($restored->version)->toBe(3)
                ->and($restored->state)->toBe(['x' => 'value']);
        });

        it('jsonSerialize returns same structure as toArray', function (): void {
            $snapshot = Snapshot::create('App\\X', 'y', 1, []);

            expect($snapshot->jsonSerialize())->toBe($snapshot->toArray());
        });

        it('createdAt is preserved as ISO string', function (): void {
            $snapshot = Snapshot::create('App\\T', 't-1', 0, []);
            $data = $snapshot->toArray();

            expect($data['created_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T/');
        });
    });

    // ===========================================================================
    //  DomainEventCollection serialization
    // ===========================================================================
    describe('DomainEventCollection serialization', function (): void {
        it('serializes events to JSON array', function (): void {
            $e1 = DomainEvent::occur('order.placed', ['id' => '1']);
            $e2 = DomainEvent::occur('order.paid', ['id' => '1', 'amount' => 50.0]);
            $collection = new DomainEventCollection([$e1, $e2]);

            $json = json_encode($collection);
            $data = json_decode($json, true);

            expect($data)->toBeArray()
                ->and($data)->toHaveCount(2)
                ->and($data[0]['event_type'])->toBe('order.placed')
                ->and($data[1]['payload']['amount'])->toBe(50.0);
        });

        it('empty collection serializes to empty JSON array', function (): void {
            $collection = new DomainEventCollection;

            expect(json_encode($collection))->toBe('[]');
        });

        it('is JSON serializable', function (): void {
            $collection = new DomainEventCollection([
                DomainEvent::occur('test.event', ['key' => 'val']),
            ]);

            expect(json_encode($collection))->toBeJson();
        });
    });

    // ===========================================================================
    //  Cross-identifier type safety
    // ===========================================================================
    describe('Identifier type safety', function (): void {
        it('different identifier types are never equal via equals()', function (): void {
            $uuid = SerdeUuidId::generate();
            $ulid = SerdeUlidId::generate();
            $string = StringIdentifier::from('test');
            $integer = IntegerIdentifier::from(1);

            // equals() checks instanceof first, so different types are always false
            expect($uuid->equals($ulid))->toBeFalse()
                ->and($uuid->equals($string))->toBeFalse()
                ->and($uuid->equals($integer))->toBeFalse()
                ->and($string->equals($integer))->toBeFalse();
        });

        it('same type with same value are equal', function (): void {
            $a = StringIdentifier::from('same');
            $b = StringIdentifier::from('same');

            expect($a->equals($b))->toBeTrue();
        });

        it('same type with different value are not equal', function (): void {
            $a = StringIdentifier::from('alpha');
            $b = StringIdentifier::from('beta');

            expect($a->equals($b))->toBeFalse();
        });

        it('IntegerIdentifier: same value are equal', function (): void {
            $a = IntegerIdentifier::from(42);
            $b = IntegerIdentifier::from(42);

            expect($a->equals($b))->toBeTrue();
        });

        it('IntegerIdentifier: different value are not equal', function (): void {
            $a = IntegerIdentifier::from(1);
            $b = IntegerIdentifier::from(2);

            expect($a->equals($b))->toBeFalse();
        });
    });

    // ===========================================================================
    //  Legacy Identifier compatibility
    // ===========================================================================
    describe('Legacy Identifier (deprecated) JSON serialization', function (): void {
        it('implements JsonSerializable', function (): void {
            $id = new class extends LegacyIdentifier {};

            $json = json_encode(['legacy_id' => $id]);
            expect($json)->toBeJson();

            $data = json_decode($json, true);
            expect($data['legacy_id'])->toBe($id->toString());
        });

        it('generates valid UUID', function (): void {
            $id = new class extends LegacyIdentifier {};

            expect(UuidIdentifier::isValid($id->toString()))->toBeTrue();
        });
    });
});
