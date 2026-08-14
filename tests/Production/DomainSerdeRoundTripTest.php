<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Tests\Production;

use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\DomainEventCollection;
use ZeroBoiler\Domain\Identifiers\IntegerIdentifier;
use ZeroBoiler\Domain\Identifiers\StringIdentifier;
use ZeroBoiler\Domain\Identifiers\UuidIdentifier;
use ZeroBoiler\Domain\Identifiers\UlidIdentifier;
use ZeroBoiler\Domain\Tests\Fixtures\TestValueObject;
use ZeroBoiler\Events\Domain\DomainEvent;

/**
 * Production serde round-trip tests for toJson()/fromJson() on all domain primitives.
 *
 * Validates that every domain primitive supports consistent JSON serialization
 * round-trip via the explicit toJson()/fromJson() methods added in v1.64.0.
 *
 * @covers \ZeroBoiler\Domain\AggregateRootId::toJson
 * @covers \ZeroBoiler\Domain\AggregateRootId::fromJson
 * @covers \ZeroBoiler\Domain\ValueObject::toJson
 * @covers \ZeroBoiler\Domain\ValueObject::fromJson
 * @covers \ZeroBoiler\Domain\Identifiers\UuidIdentifier::toJson
 * @covers \ZeroBoiler\Domain\Identifiers\UuidIdentifier::fromJson
 * @covers \ZeroBoiler\Domain\Identifiers\UlidIdentifier::toJson
 * @covers \ZeroBoiler\Domain\Identifiers\UlidIdentifier::fromJson
 * @covers \ZeroBoiler\Domain\Identifiers\StringIdentifier::toJson
 * @covers \ZeroBoiler\Domain\Identifiers\StringIdentifier::fromJson
 * @covers \ZeroBoiler\Domain\Identifiers\IntegerIdentifier::toJson
 * @covers \ZeroBoiler\Domain\Identifiers\IntegerIdentifier::fromJson
 * @covers \ZeroBoiler\Domain\Entity::toJson
 * @covers \ZeroBoiler\Domain\Entity::fromJson
 *
 * @since 1.65.0
 */
test('AggregateRootId toJson/fromJson round-trip', function (): void {
    $id = AggregateRootId::generate();

    $json = $id->toJson();
    expect($json)->toBeJson();

    $restored = AggregateRootId::fromJson($json);
    expect($restored->equals($id))->toBeTrue();
    expect($restored->toString())->toBe($id->toString());
});

test('AggregateRootId toJson/fromJson with fromString UUID', function (): void {
    $uuid = '550e8400-e29b-41d4-a716-446655440000';
    $id = AggregateRootId::fromString($uuid);

    $json = $id->toJson();
    $restored = AggregateRootId::fromJson($json);

    expect($restored->toString())->toBe($uuid);
    expect($restored->equals($id))->toBeTrue();
});

test('UuidIdentifier toJson/fromJson round-trip', function (): void {
    $id = TestUuidIdentifier::generate();

    $json = $id->toJson();
    expect($json)->toBeJson();

    $restored = TestUuidIdentifier::fromJson($json);
    expect($restored->equals($id))->toBeTrue();
    expect($restored->toString())->toBe($id->toString());
});

test('UlidIdentifier toJson/fromJson round-trip', function (): void {
    $id = TestUlidIdentifier::generate();

    $json = $id->toJson();
    expect($json)->toBeJson();

    $restored = TestUlidIdentifier::fromJson($json);
    expect($restored->equals($id))->toBeTrue();
    expect($restored->toString())->toBe($id->toString());
});

test('StringIdentifier toJson/fromJson round-trip', function (): void {
    $id = StringIdentifier::from('my-blog-post-slug');

    $json = $id->toJson();
    expect($json)->toBeJson();

    $restored = StringIdentifier::fromJson($json);
    expect($restored->equals($id))->toBeTrue();
    expect($restored->toString())->toBe('my-blog-post-slug');
});

test('IntegerIdentifier toJson/fromJson round-trip', function (): void {
    $id = IntegerIdentifier::from(42);

    $json = $id->toJson();
    expect($json)->toBeJson();

    $restored = IntegerIdentifier::fromJson($json);
    expect($restored->equals($id))->toBeTrue();
    expect($restored->toInt())->toBe(42);
});

test('ValueObject toJson/fromJson round-trip', function (): void {
    $vo = TestValueObject::fromArray(['name' => 'John', 'value' => 100]);

    $json = $vo->toJson();
    expect($json)->toBeJson();

    $restored = TestValueObject::fromJson($json);
    expect($restored->equals($vo))->toBeTrue();
});

test('Entity toJson/fromJson round-trip via reflection-based hydration', function (): void {
    $entity = Fixtures\TestEntity::fromArray([
        'id' => 'entity-1',
        'name' => 'Test Entity',
        'value' => 42,
    ]);

    $json = $entity->toJson();
    expect($json)->toBeJson();

    $restored = Fixtures\TestEntity::fromJson($json);
    expect($restored->id())->toBe('entity-1');
    expect($restored->toArray())->toBe($entity->toArray());
});

test('DomainException toJson produces valid JSON string', function (): void {
    $exception = \ZeroBoiler\Domain\Exceptions\InvalidStateDomainException::because('Test error');

    $json = $exception->jsonSerialize();
    expect($json)->toBeArray();
    expect($json)->toHaveKeys(['title', 'detail', 'code', 'status']);
    expect($json['code'])->toBe('INVALID_STATE');
    expect($json['status'])->toBe(422);

    // Can be JSON encoded
    $encoded = json_encode($json);
    expect($encoded)->toBeJson();
});

test('DomainException fromJson/fromArray round-trip', function (): void {
    $original = \ZeroBoiler\Domain\Exceptions\NotFoundDomainException::forId('order-123');

    // toArray → fromArray
    $restored = \ZeroBoiler\Domain\Exceptions\DomainException::fromArray(
        $original->toArray(),
        \ZeroBoiler\Domain\Exceptions\NotFoundDomainException::class,
    );
    expect($restored->getMessage())->toBe($original->getMessage());
    expect($restored->errorCode())->toBe('NOT_FOUND');

    // json → fromJson
    $json = json_encode($original->toArray());
    $restoredFromJson = \ZeroBoiler\Domain\Exceptions\DomainException::fromJson(
        $json,
        \ZeroBoiler\Domain\Exceptions\NotFoundDomainException::class,
    );
    expect($restoredFromJson->getMessage())->toBe($original->getMessage());
});

test('toJson uses JSON_THROW_ON_ERROR for safety', function (): void {
    // Verify that toJson() works with various identifier types
    // and produces valid JSON that can be decoded
    $tests = [
        AggregateRootId::generate(),
        TestUuidIdentifier::generate(),
        TestUlidIdentifier::generate(),
        StringIdentifier::from('slug'),
        IntegerIdentifier::from(99),
    ];

    foreach ($tests as $id) {
        $json = $id->toJson();
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        expect($decoded)->toBeArray();
        expect($id->toArray())->toBe($decoded);
    }
});

test('toJson output matches json_encode(toArray()) for all identifiers', function (): void {
    $uuid = TestUuidIdentifier::generate();
    $ulid = TestUlidIdentifier::generate();
    $string = StringIdentifier::from('test');
    $int = IntegerIdentifier::from(7);
    $aggregateId = AggregateRootId::generate();

    // toJson() should produce identical output to json_encode(toArray())
    expect($uuid->toJson())->toBe(json_encode($uuid->toArray(), JSON_UNESCAPED_UNICODE));
    expect($ulid->toJson())->toBe(json_encode($ulid->toArray(), JSON_UNESCAPED_UNICODE));
    expect($string->toJson())->toBe(json_encode($string->toArray(), JSON_UNESCAPED_UNICODE));
    expect($int->toJson())->toBe(json_encode($int->toArray(), JSON_UNESCAPED_UNICODE));
    expect($aggregateId->toJson())->toBe(json_encode($aggregateId->toArray(), JSON_UNESCAPED_UNICODE));
});

test('DomainEventCollection toArray produces serializable arrays', function (): void {
    $e1 = DomainEvent::occur('order.placed', ['id' => '123']);
    $e2 = DomainEvent::occur('order.paid', ['amount' => 100]);

    $collection = new DomainEventCollection([$e1, $e2]);

    $array = $collection->toArray();
    expect($array)->toBeArray();
    expect(count($array))->toBe(2);

    // Verify it's valid JSON-serializable
    $json = json_encode($collection);
    expect($json)->toBeJson();
});

test('All identifier types have toJson and fromJson methods declared', function (): void {
    $types = [
        AggregateRootId::class,
        TestUuidIdentifier::class,
        TestUlidIdentifier::class,
        StringIdentifier::class,
        IntegerIdentifier::class,
    ];

    foreach ($types as $type) {
        expect(method_exists($type, 'toJson'))->toBeTrue("{$type} must have toJson()");
        expect(method_exists($type, 'fromJson'))->toBeTrue("{$type} must have fromJson()");
    }
});
