<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\Identifiers\IntegerIdentifier;
use ZeroBoiler\Domain\Identifiers\StringIdentifier;
use ZeroBoiler\Domain\Identifiers\UuidIdentifier;
use ZeroBoiler\Domain\Identifiers\UlidIdentifier;
use ZeroBoiler\Domain\Snapshots\Snapshot;

/**
 * Comprehensive round-trip serialization tests for domain contracts.
 *
 * Validates that all domain types support lossless fromArray()/toArray()
 * serialization and JSON encoding/decoding.
 *
 * @since 2.10.0
 */
describe('Domain Contract Round-Trip Serialization', function () {
    describe('AggregateRootId', function () {
        it('round-trips through toArray/fromArray', function () {
            $id = AggregateRootId::generate();
            $restored = AggregateRootId::fromArray($id->toArray());

            expect($id->equals($restored))->toBeTrue();
            expect($id->toString())->toBe($restored->toString());
        });

        it('round-trips through JSON encode/decode', function () {
            $id = AggregateRootId::generate();
            $json = json_encode($id);
            $restored = AggregateRootId::fromString($json);

            expect($id->equals($restored))->toBeTrue();
        });

        it('fromArray accepts both uuid and id keys', function () {
            $id = AggregateRootId::generate();

            $fromUuid = AggregateRootId::fromArray(['uuid' => $id->toString()]);
            $fromId = AggregateRootId::fromArray(['id' => $id->toString()]);

            expect($id->equals($fromUuid))->toBeTrue();
            expect($id->equals($fromId))->toBeTrue();
        });

        it('fromArray throws on missing keys', function () {
            AggregateRootId::fromArray([]);
        })->throws(InvalidArgumentException::class);

        it('fromArray throws on invalid type', function () {
            AggregateRootId::fromArray(['uuid' => 123]);
        })->throws(InvalidArgumentException::class);

        it('isValid returns false for non-UUID', function () {
            expect(AggregateRootId::isValid('not-a-uuid'))->toBeFalse();
        });

        it('isValid returns true for valid UUID', function () {
            expect(AggregateRootId::isValid('550e8400-e29b-41d4-a716-446655440000'))->toBeTrue();
        });
    });

    describe('UuidIdentifier', function () {
        it('round-trips through toArray/fromArray', function () {
            $id = TestUuidIdentifier::generate();
            $restored = TestUuidIdentifier::fromArray($id->toArray());

            expect($id->equals($restored))->toBeTrue();
            expect($id->toString())->toBe($restored->toString());
        });

        it('validates in constructor', function () {
            TestUuidIdentifier::fromString('not-a-uuid');
        })->throws(\Ramsey\Uuid\Exception\InvalidUuidStringException::class);

        it('equals returns false for different classes with same value', function () {
            $a = TestUuidIdentifier::generate();
            $uuid = $a->toString();
            $b = TestUuidIdentifierAlt::fromString($uuid);

            // Same UUID value but different concrete class
            expect($a->equals($b))->toBeFalse();
        });
    });

    describe('UlidIdentifier', function () {
        it('round-trips through toArray/fromArray', function () {
            $id = TestUlidIdentifier::generate();
            $restored = TestUlidIdentifier::fromArray($id->toArray());

            expect($id->equals($restored))->toBeTrue();
            expect($id->toString())->toBe($restored->toString());
        });

        it('generates monotonic ULIDs', function () {
            $a = TestUlidIdentifier::generate();
            $b = TestUlidIdentifier::generate();

            // Monotonic: second should be lexicographically greater
            expect($a->toString() < $b->toString())->toBeTrue();
        });

        it('provides Symfony ULID object', function () {
            $id = TestUlidIdentifier::generate();
            $ulid = $id->toUlid();

            expect($ulid)->toBeInstanceOf(\Symfony\Component\Uid\Ulid::class);
            expect($ulid->toBase32())->toBe($id->toString());
        });

        it('isValid returns false for invalid ULID', function () {
            expect(TestUlidIdentifier::isValid('not-a-ulid'))->toBeFalse();
        });

        it('isValid returns true for valid ULID', function () {
            expect(TestUlidIdentifier::isValid('01H5XZJN00Z3Q3Y7W8K9M0VNXR'))->toBeTrue();
        });
    });

    describe('StringIdentifier', function () {
        it('round-trips through toArray/fromArray', function () {
            $id = StringIdentifier::from('my-slug');
            $restored = StringIdentifier::fromArray($id->toArray());

            expect($id->equals($restored))->toBeTrue();
            expect($id->toString())->toBe($restored->toString());
        });

        it('rejects empty strings', function () {
            StringIdentifier::from('');
        })->throws(\ValueError::class);

        it('isValid returns false for empty string', function () {
            expect(StringIdentifier::isValid(''))->toBeFalse();
        });

        it('jsonSerialize returns the string value', function () {
            $id = StringIdentifier::from('test-slug');
            expect(json_encode($id))->toBe('"test-slug"');
        });
    });

    describe('IntegerIdentifier', function () {
        it('round-trips through toArray/fromArray', function () {
            $id = IntegerIdentifier::from(42);
            $restored = IntegerIdentifier::fromArray($id->toArray());

            expect($id->equals($restored))->toBeTrue();
            expect($id->toInt())->toBe($restored->toInt());
        });

        it('round-trips from string id key', function () {
            $id = IntegerIdentifier::from(42);
            $restored = IntegerIdentifier::fromArray(['id' => '42']);

            expect($id->equals($restored))->toBeTrue();
        });

        it('jsonSerialize returns the integer value', function () {
            $id = IntegerIdentifier::from(42);
            expect(json_encode($id))->toBe('42');
        });

        it('isValid returns true for positive integer string', function () {
            expect(IntegerIdentifier::isValid('42'))->toBeTrue();
        });

        it('isValid returns true for negative integer string', function () {
            expect(IntegerIdentifier::isValid('-5'))->toBeTrue();
        });

        it('isValid returns false for non-numeric string', function () {
            expect(IntegerIdentifier::isValid('abc'))->toBeFalse();
        });
    });

    describe('Snapshot', function () {
        it('round-trips through toArray/fromArray', function () {
            $snapshot = Snapshot::create(
                aggregateType: 'App\\Domain\\Order',
                aggregateId: '550e8400-e29b-41d4-a716-446655440000',
                version: 10,
                state: ['status' => 'paid', 'total' => 99.99],
            );

            $restored = Snapshot::fromArray($snapshot->toArray());

            expect($snapshot->equals($restored))->toBeTrue();
            expect($restored->aggregateType)->toBe('App\\Domain\\Order');
            expect($restored->aggregateId)->toBe('550e8400-e29b-41d4-a716-446655440000');
            expect($restored->version)->toBe(10);
            expect($restored->state)->toBe(['status' => 'paid', 'total' => 99.99]);
        });

        it('jsonSerialize delegates to toArray', function () {
            $snapshot = Snapshot::create('App\\Domain\\Order', 'uuid-1', 1, ['key' => 'val']);

            expect($snapshot->jsonSerialize())->toBe($snapshot->toArray());
        });

        it('equals returns false for different snapshots', function () {
            $a = Snapshot::create('Order', 'id-1', 1, ['status' => 'pending']);
            $b = Snapshot::create('Order', 'id-1', 2, ['status' => 'paid']);

            expect($a->equals($b))->toBeFalse();
        });

        it('fromArray throws on invalid data', function () {
            Snapshot::fromArray(['invalid' => 'data']);
        })->throws(InvalidArgumentException::class);
    });
});

// ─── Concrete test doubles ──────────────────────────────────────────────────

final readonly class TestUuidIdentifier extends UuidIdentifier {}

final readonly class TestUuidIdentifierAlt extends UuidIdentifier {}

final readonly class TestUlidIdentifier extends UlidIdentifier {}
