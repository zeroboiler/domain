<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Domain\AggregateRoot;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\Contracts\Entity as EntityContract;
use ZeroBoiler\Domain\Identifiers\IntegerIdentifier;
use ZeroBoiler\Domain\Identifiers\StringIdentifier;
use ZeroBoiler\Domain\Identifiers\UuidIdentifier;
use ZeroBoiler\Domain\Identifiers\UlidIdentifier;

/**
 * Verifies that domain entities and identifiers enforce immutability invariants.
 *
 * Production contract tests:
 * - Readonly properties cannot be reassigned after construction
 * - Final classes prevent uncontrolled subclassing
 * - Value objects produce structural equality via toArray()
 * - Identifiers produce type-safe equality across the hierarchy
 * - AggregateRootId round-trips through toArray/fromArray and jsonSerialize
 *
 * @covers \ZeroBoiler\Domain\Entity
 * @covers \ZeroBoiler\Domain\AggregateRootId
 * @covers \ZeroBoiler\Domain\Identifiers\UuidIdentifier
 * @covers \ZeroBoiler\Domain\Identifiers\UlidIdentifier
 * @covers \ZeroBoiler\Domain\Identifiers\StringIdentifier
 * @covers \ZeroBoiler\Domain\Identifiers\IntegerIdentifier
 * @covers \ZeroBoiler\Domain\ValueObject
 */
describe('Domain Entity Immutability Contract', function (): void {
    describe('AggregateRootId immutability', function (): void {
        it('is final and readonly — cannot be subclassed or mutated', function (): void {
            $reflection = new ReflectionClass(AggregateRootId::class);
            expect($reflection->isFinal())->toBeTrue();
            expect($reflection->isReadOnly())->toBeTrue();
        });

        it('generates unique identities', function (): void {
            $id1 = AggregateRootId::generate();
            $id2 = AggregateRootId::generate();

            expect($id1->equals($id2))->toBeFalse();
            expect($id1->toString())->not->toBe($id2->toString());
        });

        it('round-trips through toArray/fromArray', function (): void {
            $id = AggregateRootId::generate();
            $restored = AggregateRootId::fromArray($id->toArray());

            expect($id->equals($restored))->toBeTrue();
            expect($id->toString())->toBe($restored->toString());
        });

        it('round-trips through jsonSerialize', function (): void {
            $id = AggregateRootId::generate();
            $json = json_encode($id);
            $restored = AggregateRootId::fromString($json);

            expect($id->equals($restored))->toBeTrue();
        });

        it('validates UUID strings correctly', function (): void {
            $valid = '550e8400-e29b-41d4-a716-446655440000';
            $invalid = 'not-a-uuid';

            expect(AggregateRootId::isValid($valid))->toBeTrue();
            expect(AggregateRootId::isValid($invalid))->toBeFalse();
        });

        it('accepts both uuid and id keys in fromArray', function (): void {
            $id = AggregateRootId::generate();

            $fromUuid = AggregateRootId::fromArray(['uuid' => $id->toString()]);
            $fromId = AggregateRootId::fromArray(['id' => $id->toString()]);

            expect($fromUuid->equals($id))->toBeTrue();
            expect($fromId->equals($id))->toBeTrue();
        });

        it('throws on invalid fromArray input', function (): void {
            expect(fn (): mixed => AggregateRootId::fromArray([]))
                ->toThrow(InvalidArgumentException::class);
            expect(fn (): mixed => AggregateRootId::fromArray(['uuid' => 123]))
                ->toThrow(InvalidArgumentException::class);
        });

        it('implements Stringable and JsonSerializable', function (): void {
            $id = AggregateRootId::generate();

            expect($id instanceof \Stringable)->toBeTrue();
            expect($id instanceof \JsonSerializable)->toBeTrue();
            expect((string) $id)->toBe($id->toString());
        });
    });

    describe('UuidIdentifier immutability', function (): void {
        it('is abstract readonly — subclasses are readonly', function (): void {
            $reflection = new ReflectionClass(UuidIdentifier::class);
            expect($reflection->isReadOnly())->toBeTrue();
            expect($reflection->isAbstract())->toBeTrue();
        });

        it('validates UUID on construction', function (): void {
            expect(fn (): mixed => new class('not-a-uuid') extends UuidIdentifier {})
                ->toThrow(\Ramsey\Uuid\Exception\InvalidUuidStringException::class);
        });

        it('round-trips through toArray/fromArray', function (): void {
            $id = TestUuidId::generate();
            $restored = TestUuidId::fromArray($id->toArray());

            expect($id->equals($restored))->toBeTrue();
        });

        it('enforces type-safe equality (different subclasses never equal)', function (): void {
            $id1 = TestUuidId::generate();
            $id2 = TestUuidIdAlt::fromString($id1->toString());

            expect($id1->equals($id2))->toBeFalse();
        });
    });

    describe('UlidIdentifier immutability', function (): void {
        it('is abstract readonly', function (): void {
            $reflection = new ReflectionClass(UlidIdentifier::class);
            expect($reflection->isReadOnly())->toBeTrue();
            expect($reflection->isAbstract())->toBeTrue();
        });

        it('validates ULID on construction', function (): void {
            expect(fn (): mixed => new class('not-a-ulid') extends UlidIdentifier {})
                ->toThrow(InvalidArgumentException::class);
        });

        it('generates monotonic ULIDs', function (): void {
            $id1 = TestUlidId::generate();
            $id2 = TestUlidId::generate();

            // ULIDs are monotonic — the second should sort after the first
            expect($id2->toString() > $id1->toString())->toBeTrue();
        });

        it('round-trips through toArray/fromArray', function (): void {
            $id = TestUlidId::generate();
            $restored = TestUlidId::fromArray($id->toArray());

            expect($id->equals($restored))->toBeTrue();
        });
    });

    describe('StringIdentifier immutability', function (): void {
        it('is final readonly', function (): void {
            $reflection = new ReflectionClass(StringIdentifier::class);
            expect($reflection->isFinal())->toBeTrue();
            expect($reflection->isReadOnly())->toBeTrue();
        });

        it('rejects empty strings', function (): void {
            expect(fn (): mixed => StringIdentifier::from(''))
                ->toThrow(ValueError::class);
        });

        it('round-trips through toArray/fromArray', function (): void {
            $id = StringIdentifier::from('my-slug');
            $restored = StringIdentifier::fromArray($id->toArray());

            expect($id->equals($restored))->toBeTrue();
        });

        it('serializes to JSON as a string', function (): void {
            $id = StringIdentifier::from('test-slug');
            expect(json_encode($id))->toBe('"test-slug"');
        });
    });

    describe('IntegerIdentifier immutability', function (): void {
        it('is final readonly', function (): void {
            $reflection = new ReflectionClass(IntegerIdentifier::class);
            expect($reflection->isFinal())->toBeTrue();
            expect($reflection->isReadOnly())->toBeTrue();
        });

        it('round-trips through toArray/fromArray', function (): void {
            $id = IntegerIdentifier::from(42);
            $restored = IntegerIdentifier::fromArray($id->toArray());

            expect($id->equals($restored))->toBeTrue();
        });

        it('serializes to JSON as an integer', function (): void {
            $id = IntegerIdentifier::from(42);
            expect(json_encode($id))->toBe('42');
        });

        it('accepts string IDs in fromArray', function (): void {
            $id = IntegerIdentifier::fromArray(['id' => '42']);
            expect($id->toInt())->toBe(42);
        });

        it('validates string representations', function (): void {
            expect(IntegerIdentifier::isValid('42'))->toBeTrue();
            expect(IntegerIdentifier::isValid('-5'))->toBeTrue();
            expect(IntegerIdentifier::isValid('abc'))->toBeFalse();
        });
    });

    describe('Entity identity equality', function (): void {
        it('uses identity-based equality (not property-based)', function (): void {
            $id = 'entity-1';
            $entity1 = new TestEntity($id);
            $entity2 = new TestEntity($id);

            expect($entity1->equals($entity2))->toBeTrue();
        });

        it('returns false for different identities', function (): void {
            $entity1 = new TestEntity('entity-1');
            $entity2 = new TestEntity('entity-2');

            expect($entity1->equals($entity2))->toBeFalse();
        });

        it('returns false for different types with same ID', function (): void {
            $entity1 = new TestEntity('1');
            $entity2 = new AnotherTestEntity('1');

            expect($entity1->equals($entity2))->toBeFalse();
        });

        it('round-trips through toArray/fromArray', function (): void {
            $entity = new TestEntity('entity-42');
            $restored = TestEntity::fromArray($entity->toArray());

            expect($entity->equals($restored))->toBeTrue();
        });

        it('round-trips through jsonSerialize', function (): void {
            $entity = new TestEntity('entity-42');
            $json = json_encode($entity);
            $data = json_decode($json, true);

            expect($data['id'])->toBe('entity-42');
            expect($data['type'])->toBe('TestEntity');
        });
    });
});

/**
 * Concrete UUID identifier for testing.
 */
class TestUuidId extends UuidIdentifier {}

/**
 * Concrete ULID identifier for testing.
 */
class TestUlidId extends UlidIdentifier {}

/**
 * Concrete entity for testing.
 */
class TestEntity extends \ZeroBoiler\Domain\Entity {}

/**
 * Another concrete entity for testing type-safe equality.
 */
class AnotherTestEntity extends \ZeroBoiler\Domain\Entity {}
