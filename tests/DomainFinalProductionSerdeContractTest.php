<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Ramsey\Uuid\Uuid;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\Contracts\Identifier as IdentifierContract;
use ZeroBoiler\Domain\Identifiers\IntegerIdentifier;
use ZeroBoiler\Domain\Identifiers\StringIdentifier;
use ZeroBoiler\Domain\Identifiers\UuidIdentifier;
use ZeroBoiler\Domain\Identifiers\UlidIdentifier;
use ZeroBoiler\Domain\Tests\Fixtures\TestUuidIdentifier;
use ZeroBoiler\Domain\Tests\Fixtures\TestUuidIdentifierAlt;
use ZeroBoiler\Domain\Tests\Fixtures\TestUlidIdentifier;

/**
 * Production-ready serde contract tests for all domain identifiers.
 *
 * Validates round-trip serialization consistency for every identifier type
 * across toArray()/fromArray(), jsonSerialize(), and toString() methods.
 *
 * @since 1.47.0
 */
test('AggregateRootId toArray/fromArray round-trip', function (): void {
    $id = AggregateRootId::generate();

    $array = $id->toArray();
    expect($array)->toHaveKey('uuid');
    expect($array['uuid'])->toBeString();

    $restored = AggregateRootId::fromArray($array);
    expect($id->equals($restored))->toBeTrue();
    expect($id->toString())->toBe($restored->toString());
});

test('AggregateRootId fromArray with id key fallback', function (): void {
    $id = AggregateRootId::generate();

    $restored = AggregateRootId::fromArray(['id' => $id->toString()]);
    expect($id->equals($restored))->toBeTrue();
});

test('AggregateRootId fromArray throws on missing keys', function (): void {
    $this->expectException(\InvalidArgumentException::class,
    );

    AggregateRootId::fromArray([]);
});

test('AggregateRootId jsonSerialize returns UUID string', function (): void {
    $id = AggregateRootId::generate();
    $json = json_encode($id);

    expect($json)->toBeJson();
    expect(json_decode($json, true))->toBe($id->toString());
});

test('AggregateRootId implements JsonSerializable and Stringable', function (): void {
    $id = AggregateRootId::generate();

    expect($id)->toBeInstanceOf(\JsonSerializable::class);
    expect($id)->toBeInstanceOf(\Stringable::class);
    expect((string) $id)->toBe($id->toString());
});

test('UuidIdentifier toArray/fromArray round-trip', function (): void {
    $uuid = Uuid::uuid4()->toString();
    $id = TestUuidIdentifier::fromString($uuid);

    $array = $id->toArray();
    expect($array)->toHaveKey('uuid');
    expect($array['uuid'])->toBe($uuid);

    $restored = TestUuidIdentifier::fromArray($array);
    expect($id->equals($restored))->toBeTrue();
});

test('UuidIdentifier fromArray with id key fallback', function (): void {
    $uuid = Uuid::uuid4()->toString();
    $id = TestUuidIdentifier::fromString($uuid);

    $restored = TestUuidIdentifier::fromArray(['id' => $uuid]);
    expect($id->equals($restored))->toBeTrue();
});

test('UuidIdentifier generate creates valid UUID v4', function (): void {
    $id = TestUuidIdentifier::generate();

    expect($id->toString())->toBeString();
    expect(Uuid::isValid($id->toString()))->toBeTrue();
    expect($id->toUuid()->getVersion())->toBe(4);
});

test('UuidIdentifier equals checks concrete class identity', function (): void {
    $uuid = Uuid::uuid4()->toString();
    $a = TestUuidIdentifier::fromString($uuid);
    $b = TestUuidIdentifier::fromString($uuid);

    expect($a->equals($b))->toBeTrue();

    // Same UUID, different subclass → not equal
    $c = TestUuidIdentifierAlt::fromString($uuid);
    expect($a->equals($c))->toBeFalse();
});

test('UlidIdentifier toArray/fromArray round-trip', function (): void {
    $id = TestUlidIdentifier::generate();

    $array = $id->toArray();
    expect($array)->toHaveKey('ulid');
    expect($array['ulid'])->toBeString();

    $restored = TestUlidIdentifier::fromArray($array);
    expect($id->equals($restored))->toBeTrue();
});

test('UlidIdentifier fromArray with id key fallback', function (): void {
    $id = TestUlidIdentifier::generate();

    $restored = TestUlidIdentifier::fromArray(['id' => $id->toString()]);
    expect($id->equals($restored))->toBeTrue();
});

test('UlidIdentifier isValid validates ULID strings', function (): void {
    $id = TestUlidIdentifier::generate();
    expect(TestUlidIdentifier::isValid($id->toString()))->toBeTrue();
    expect(TestUlidIdentifier::isValid('not-a-ulid'))->toBeFalse();
});

test('StringIdentifier toArray/fromArray round-trip', function (): void {
    $id = StringIdentifier::from('my-slug');

    $array = $id->toArray();
    expect($array)->toHaveKey('string');
    expect($array['string'])->toBe('my-slug');

    $restored = StringIdentifier::fromArray($array);
    expect($id->equals($restored))->toBeTrue();
});

test('StringIdentifier fromArray with id key fallback', function (): void {
    $id = StringIdentifier::from('test');

    $restored = StringIdentifier::fromArray(['id' => 'test']);
    expect($id->equals($restored))->toBeTrue();
});

test('StringIdentifier throws ValueError on empty string', function (): void {
    $this->expectException(ValueError::class);

    StringIdentifier::from('');
});

test('StringIdentifier isValid rejects empty string', function (): void {
    expect(StringIdentifier::isValid(''))->toBeFalse();
    expect(StringIdentifier::isValid('valid'))->toBeTrue();
});

test('IntegerIdentifier toArray/fromArray round-trip', function (): void {
    $id = IntegerIdentifier::from(42);

    $array = $id->toArray();
    expect($array)->toHaveKey('integer');
    expect($array['integer'])->toBe(42);

    $restored = IntegerIdentifier::fromArray($array);
    expect($id->equals($restored))->toBeTrue();
});

test('IntegerIdentifier fromArray with string id value', function (): void {
    $restored = IntegerIdentifier::fromArray(['id' => '99']);
    expect($restored->toInt())->toBe(99);
});

test('IntegerIdentifier jsonSerialize returns int', function (): void {
    $id = IntegerIdentifier::from(42);
    $json = json_encode($id);

    expect($json)->toBe('42');
});

test('IntegerIdentifier isValid validates integer strings', function (): void {
    expect(IntegerIdentifier::isValid('42'))->toBeTrue();
    expect(IntegerIdentifier::isValid('-5'))->toBeTrue();
    expect(IntegerIdentifier::isValid('abc'))->toBeFalse();
});

test('All identifiers implement IdentifierContract and Stringable', function (): void {
    $uuid = TestUuidIdentifier::generate();
    $ulid = TestUlidIdentifier::generate();
    $string = StringIdentifier::from('test');
    $integer = IntegerIdentifier::from(1);
    $aggregateId = AggregateRootId::generate();

    expect($uuid)->toBeInstanceOf(IdentifierContract::class);
    expect($ulid)->toBeInstanceOf(IdentifierContract::class);
    expect($string)->toBeInstanceOf(IdentifierContract::class);
    expect($integer)->toBeInstanceOf(IdentifierContract::class);

    // AggregateRootId implements Stringable and JsonSerializable but NOT IdentifierContract
    expect($aggregateId)->toBeInstanceOf(\Stringable::class);
    expect($aggregateId)->toBeInstanceOf(\JsonSerializable::class);
});

test('All identifiers implement JsonSerializable', function (): void {
    $uuid = TestUuidIdentifier::generate();
    $ulid = TestUlidIdentifier::generate();
    $string = StringIdentifier::from('test');
    $integer = IntegerIdentifier::from(1);

    foreach ([$uuid, $ulid, $string, $integer] as $id) {
        expect($id)->toBeInstanceOf(\JsonSerializable::class);
        $json = json_encode($id);
        expect($json)->toBeJson();
    }
});

test('UuidIdentifier fromArray throws on invalid data', function (): void {
    $this->expectException(\InvalidArgumentException::class,
    );

    TestUuidIdentifier::fromArray(['foo' => 'bar']);
});

test('UlidIdentifier fromArray throws on invalid data', function (): void {
    $this->expectException(\InvalidArgumentException::class,
    );

    TestUlidIdentifier::fromArray(['foo' => 'bar']);
});

test('StringIdentifier fromArray throws on empty string from array', function (): void {
    $this->expectException(ValueError::class,
    );

    StringIdentifier::fromArray(['string' => '']);
});

test('IntegerIdentifier fromArray throws on invalid data', function (): void {
    $this->expectException(\InvalidArgumentException::class,
    );

    IntegerIdentifier::fromArray(['foo' => 'bar']);
});

test('AggregateRootId fromString validates UUID', function (): void {
    $this->expectException(\Ramsey\Uuid\Exception\InvalidUuidStringException::class);

    AggregateRootId::fromString('not-a-uuid');
});

test('AggregateRootId fromArray throws on non-string uuid', function (): void {
    $this->expectException(\InvalidArgumentException::class,
    );

    AggregateRootId::fromArray(['uuid' => 123]);
});
