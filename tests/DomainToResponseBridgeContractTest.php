<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Tests;

use ZeroBoiler\Domain\AggregateRoot;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\Contracts\Identifier;
use ZeroBoiler\Domain\Identifiers\IntegerIdentifier;
use ZeroBoiler\Domain\Identifiers\StringIdentifier;
use ZeroBoiler\Domain\Identifiers\UuidIdentifier;
use ZeroBoiler\Domain\Identifiers\UlidIdentifier;
use ZeroBoiler\Domain\Exceptions\ConflictDomainException;
use ZeroBoiler\Domain\Exceptions\DomainException;
use ZeroBoiler\Domain\Exceptions\InvalidArgumentDomainException;
use ZeroBoiler\Domain\Exceptions\InvalidStateDomainException;
use ZeroBoiler\Domain\Exceptions\NotFoundDomainException;
use ZeroBoiler\Domain\Exceptions\OptimisticLockException;

/**
 * Domain-to-Response bridge contract test.
 *
 * Validates that domain objects produce correct array representations
 * for consumption by the response layer's DomainTransformer and
 * DomainResponseFactory. Uses duck-typed assertions to verify
 * the bridge contract without depending on zeroboiler/response.
 *
 * Covers:
 * - AggregateRoot::toArray() produces id, version, type
 * - AggregateRootId::toArray()/fromArray() round-trip
 * - Identifier::toArray()/fromArray() round-trip for all types
 * - DomainException::toErrorArray() produces RFC 9457-compatible structure
 * - Entity::toArray() produces id, type
 * - DomainEventCollection serialization
 * - Snapshot::toArray()/fromArray() round-trip
 * - All identifiers implement JsonSerializable
 * - All identifiers implement Identifier contract
 *
 * @since 1.45.0
 */

// ── AggregateRoot toArray() produces response-ready structure ──

test('AggregateRoot toArray() contains id, version, and type', function (): void {
    $id = AggregateRootId::generate();
    $aggregate = new class ($id) extends AggregateRoot
    {
        public string $status = 'pending';
    };

    $array = $aggregate->toArray();

    expect($array)->toHaveKeys(['id', 'version', 'type']);
    expect($array['id'])->toBe($id->toString());
    expect($array['version'])->toBe(0);
    expect($array['type'])->toBe(class_basename($aggregate::class));
});

test('AggregateRoot toArray() reflects version changes', function (): void {
    $id = AggregateRootId::generate();
    $aggregate = new class ($id) extends AggregateRoot {};

    $aggregate->incrementVersion();
    $aggregate->incrementVersion();

    expect($aggregate->toArray()['version'])->toBe(2);
    expect($aggregate->version())->toBe(2);
});

test('AggregateRoot equals() compares class and identity', function (): void {
    $id1 = AggregateRootId::generate();
    $id2 = AggregateRootId::generate();

    $a1 = new class ($id1) extends AggregateRoot {};
    $a2 = new class ($id1) extends AggregateRoot {};
    $a3 = new class ($id2) extends AggregateRoot {};

    expect($a1->equals($a2))->toBeTrue();
    expect($a1->equals($a3))->toBeFalse();
});

// ── AggregateRootId round-trip serialization ──

test('AggregateRootId toArray/fromArray round-trip', function (): void {
    $id = AggregateRootId::generate();
    $array = $id->toArray();
    expect($array)->toBe(['uuid' => $id->toString()]);

    $restored = AggregateRootId::fromArray($array);
    expect($id->equals($restored))->toBeTrue();
});

test('AggregateRootId fromArray accepts id key as fallback', function (): void {
    $id = AggregateRootId::generate();
    $restored = AggregateRootId::fromArray(['id' => $id->toString()]);
    expect($id->equals($restored))->toBeTrue();
});

test('AggregateRootId fromArray throws on missing key', function (): void {
    expect(fn () => AggregateRootId::fromArray([]))
        ->toThrow(\InvalidArgumentException::class);
});

test('AggregateRootId jsonSerialize returns UUID string', function (): void {
    $id = AggregateRootId::generate();
    $json = json_encode($id);
    expect($json)->toBeJson();
    expect(json_decode($json, true))->toBe($id->toString());
});

// ── UuidIdentifier round-trip ──

test('UuidIdentifier toArray/fromArray round-trip', function (): void {
    $id = TestUuidIdentifier::generate();
    $array = $id->toArray();
    expect($array)->toHaveKey('uuid');

    $restored = TestUuidIdentifier::fromArray($array);
    expect($id->equals($restored))->toBeTrue();
});

test('UuidIdentifier jsonSerialize returns string', function (): void {
    $id = TestUuidIdentifier::generate();
    $json = json_encode($id);
    expect($json)->toBeJson();
    expect(json_decode($json, true))->toBe($id->toString());
});

test('UuidIdentifier equals is type-safe', function (): void {
    $id1 = TestUuidIdentifier::generate();
    $id2 = TestUuidIdentifier::fromString($id1->toString());
    $id3 = TestUuidIdentifier::generate();

    expect($id1->equals($id2))->toBeTrue();
    expect($id1->equals($id3))->toBeFalse();
});

// ── UlidIdentifier round-trip ──

test('UlidIdentifier toArray/fromArray round-trip', function (): void {
    $id = TestUlidIdentifier::generate();
    $array = $id->toArray();
    expect($array)->toHaveKey('ulid');

    $restored = TestUlidIdentifier::fromArray($array);
    expect($id->equals($restored))->toBeTrue();
});

test('UlidIdentifier jsonSerialize returns string', function (): void {
    $id = TestUlidIdentifier::generate();
    $json = json_encode($id);
    expect($json)->toBeJson();
    expect(json_decode($json, true))->toBe($id->toString());
});

// ── StringIdentifier round-trip ──

test('StringIdentifier toArray/fromArray round-trip', function (): void {
    $id = StringIdentifier::from('my-slug');
    $array = $id->toArray();
    expect($array)->toBe(['string' => 'my-slug']);

    $restored = StringIdentifier::fromArray($array);
    expect($id->equals($restored))->toBeTrue();
});

test('StringIdentifier jsonSerialize returns string', function (): void {
    $id = StringIdentifier::from('test-slug');
    $json = json_encode($id);
    expect(json_decode($json, true))->toBe('test-slug');
});

test('StringIdentifier rejects empty string', function (): void {
    expect(fn () => StringIdentifier::from(''))
        ->toThrow(\ValueError::class);
});

// ── IntegerIdentifier round-trip ──

test('IntegerIdentifier toArray/fromArray round-trip', function (): void {
    $id = IntegerIdentifier::from(42);
    $array = $id->toArray();
    expect($array)->toBe(['integer' => 42]);

    $restored = IntegerIdentifier::fromArray($array);
    expect($id->equals($restored))->toBeTrue();
});

test('IntegerIdentifier jsonSerialize returns int', function (): void {
    $id = IntegerIdentifier::from(42);
    $json = json_encode($id);
    expect(json_decode($json, true))->toBe(42);
});

test('IntegerIdentifier fromArray accepts string id', function (): void {
    $restored = IntegerIdentifier::fromArray(['id' => '99']);
    expect($restored->toInt())->toBe(99);
});

// ── DomainException produces RFC 9457-compatible error array ──

test('DomainException toErrorArray produces title, detail, code', function (): void {
    $e = InvalidStateDomainException::because('Order must be pending.');
    $array = $e->toErrorArray();

    expect($array)->toHaveKeys(['title', 'detail', 'code']);
    expect($array['code'])->toBe('INVALID_STATE');
    expect($array['detail'])->toBe('Order must be pending.');
    expect($array['title'])->toBe('InvalidStateDomainException');
});

test('DomainException errorCode returns custom code', function (): void {
    $e = InvalidArgumentDomainException::because('Qty must be > 0.', code: 'INVALID_QTY');
    expect($e->errorCode())->toBe('INVALID_QTY');
});

test('DomainException jsonSerialize matches toErrorArray', function (): void {
    $e = NotFoundDomainException::forAggregate('Order', 'order-123');
    $json = json_encode($e);
    $decoded = json_decode($json, true);

    expect($decoded['code'])->toBe('NOT_FOUND');
    expect($decoded['detail'])->toContain('order-123');
});

test('All domain exceptions produce valid error arrays', function (): void {
    $exceptions = [
        InvalidStateDomainException::because('test'),
        InvalidArgumentDomainException::because('test'),
        NotFoundDomainException::because('test'),
        ConflictDomainException::because('test'),
        OptimisticLockException::for('id', 5, 3),
    ];

    foreach ($exceptions as $e) {
        $array = $e->toErrorArray();
        expect($array)->toHaveKeys(['title', 'detail', 'code']);
        expect($array['code'])->toBeString();
        expect($array['code'])->not->toBeEmpty();
    }
});

// ── Entity toArray produces id and type ──

test('Entity toArray produces id and type', function (): void {
    $entity = new TestEntity('entity-1');

    $array = $entity->toArray();
    expect($array)->toHaveKeys(['id', 'type']);
    expect($array['id'])->toBe('entity-1');
    expect($array['type'])->toBe('TestEntity');
});

test('Entity equals compares class and identity', function (): void {
    $e1 = new TestEntity('1');
    $e2 = new TestEntity('1');
    $e3 = new TestEntity('2');

    expect($e1->equals($e2))->toBeTrue();
    expect($e1->equals($e3))->toBeFalse();
});

// ── ValueObject toArray/equals ──

test('ValueObject equality is structural via toArray()', function (): void {
    $vo1 = TestValueObject::fromArray(['street' => '123 Main', 'city' => 'NYC', 'country' => 'US']);
    $vo2 = TestValueObject::fromArray(['street' => '123 Main', 'city' => 'NYC', 'country' => 'US']);
    $vo3 = TestValueObject::fromArray(['street' => '456 Oak', 'city' => 'LA', 'country' => 'US']);

    expect($vo1->equals($vo2))->toBeTrue();
    expect($vo1->equals($vo3))->toBeFalse();
});

test('ValueObject fromArray/toArray round-trip', function (): void {
    $original = TestValueObject::fromArray(['street' => '123 Main', 'city' => 'NYC', 'country' => 'US']);
    $array = $original->toArray();
    $restored = TestValueObject::fromArray($array);

    expect($original->equals($restored))->toBeTrue();
});

// ── Identifier contract compliance ──

test('All identifiers implement Identifier contract', function (): void {
    $identifiers = [
        TestUuidIdentifier::generate(),
        TestUlidIdentifier::generate(),
        StringIdentifier::from('test'),
        IntegerIdentifier::from(1),
    ];

    foreach ($identifiers as $id) {
        expect($id)->toBeInstanceOf(Identifier::class);
        expect($id->toString())->toBeString();
        expect($id->toString())->not->toBeEmpty();
    }
});

test('All identifiers implement JsonSerializable', function (): void {
    $identifiers = [
        TestUuidIdentifier::generate(),
        TestUlidIdentifier::generate(),
        StringIdentifier::from('test'),
        IntegerIdentifier::from(1),
        AggregateRootId::generate(),
    ];

    foreach ($identifiers as $id) {
        $json = json_encode($id);
        expect($json)->not->toBeFalse();
    }
});

// ── Snapshot round-trip ──

test('Snapshot toArray/fromArray round-trip', function (): void {
    $snapshot = \ZeroBoiler\Domain\Snapshots\Snapshot::create(
        aggregateType: 'App\\Domain\\Order',
        aggregateId: 'order-42',
        version: 10,
        state: ['status' => 'paid', 'total' => 99.99],
    );

    $array = $snapshot->toArray();
    expect($array['aggregate_type'])->toBe('App\Domain\Order');
    expect($array['aggregate_id'])->toBe('order-42');
    expect($array['version'])->toBe(10);
    expect($array['state']['status'])->toBe('paid');
    expect($array['state']['total'])->toBe(99.99);
    expect($array)->toHaveKey('created_at');

    $restored = \ZeroBoiler\Domain\Snapshots\Snapshot::fromArray($array);
    expect($snapshot->equals($restored))->toBeTrue();
});

test('Snapshot jsonSerialize delegates to toArray', function (): void {
    $snapshot = \ZeroBoiler\Domain\Snapshots\Snapshot::create(
        aggregateType: 'TestAggregate',
        aggregateId: 'id-1',
        version: 1,
        state: ['value' => 42],
    );

    $json = json_encode($snapshot);
    $decoded = json_decode($json, true);

    expect($decoded['aggregate_type'])->toBe('TestAggregate');
    expect($decoded['version'])->toBe(1);
});
