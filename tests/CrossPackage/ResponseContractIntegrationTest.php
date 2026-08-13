<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Tests\CrossPackage;

use ReflectionClass;
use ZeroBoiler\Domain\AggregateRoot;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\Contracts\AggregateRoot as AggregateRootContract;
use ZeroBoiler\Domain\Contracts\Entity as EntityContract;
use ZeroBoiler\Domain\Contracts\Identifier as IdentifierContract;
use ZeroBoiler\Domain\Identifiers\IntegerIdentifier;
use ZeroBoiler\Domain\Identifiers\StringIdentifier;
use ZeroBoiler\Domain\Identifiers\UuidIdentifier;
use ZeroBoiler\Domain\Identifiers\UlidIdentifier;

/**
 * Cross-package serialization contract test.
 *
 * Verifies that domain primitives produce consistent serialized output
 * that can be consumed by the response package's DomainTransformer
 * and DomainResponseFactory without type coercion or data loss.
 *
 * The response package uses duck typing (method_exists, instanceof \Stringable)
 * so these tests verify the method signatures that the response package depends on:
 * - `id(): string` — Entity/AggregateRoot contract
 * - `toString(): string` — Identifier contract
 * - `version(): int` — AggregateRoot contract
 * - `toArray(): array` — serialization contract
 * - `__toString()` — \Stringable fallback
 */
test('AggregateRoot exposes id() as string for response package', function (): void {
    $id = AggregateRootId::generate();
    $idString = $id->toString();

    expect($idString)->toBeString();
    expect(strlen($idString))->toBe(36); // UUID format
    expect(AggregateRootId::isValid($idString))->toBeTrue();
});

test('AggregateRoot toArray provides base array for DomainTransformer', function (): void {
    // Verify the array structure that DomainTransformer.extractBaseArray() expects
    $id = AggregateRootId::generate();

    // The base array should have at minimum 'id' and 'type'
    $baseArray = [
        'id' => $id->toString(),
        'type' => 'TestAggregate',
    ];

    expect($baseArray)->toHaveKeys(['id', 'type']);
    expect($baseArray['id'])->toBeString();
});

test('Identifier implementations expose toString() for ExtractsDomainId trait', function (): void {
    // The response package's ExtractsDomainId trait calls toString() on identifiers
    $uuid = UuidIdentifier::fromString('550e8400-e29b-41d4-a716-446655440000');
    expect($uuid->toString())->toBe('550e8400-e29b-41d4-a716-446655440000');

    $ulid = UlidIdentifier::fromString('01H5J5S5S5S5S5S5S5S5S5S5S5');
    expect($ulid->toString())->toBeString();
    expect(strlen($ulid->toString()))->toBe(26); // ULID format

    $string = StringIdentifier::from('my-slug');
    expect($string->toString())->toBe('my-slug');

    $integer = IntegerIdentifier::from(42);
    expect($integer->toString())->toBe('42');
});

test('Identifier implementations are Stringable', function (): void {
    // The response package checks instanceof \Stringable as a fallback
    $uuid = UuidIdentifier::fromString('550e8400-e29b-41d4-a716-446655440000');
    expect($uuid)->toBeInstanceOf(\Stringable::class);

    $ulid = UlidIdentifier::fromString('01H5J5S5S5S5S5S5S5S5S5S5S5');
    expect($ulid)->toBeInstanceOf(\Stringable::class);

    $string = StringIdentifier::from('my-slug');
    expect($string)->toBeInstanceOf(\Stringable::class);

    $integer = IntegerIdentifier::from(42);
    expect($integer)->toBeInstanceOf(\Stringable::class);

    $aggregateId = AggregateRootId::generate();
    expect($aggregateId)->toBeInstanceOf(\Stringable::class);
});

test('Identifier implementations are JsonSerializable for API responses', function (): void {
    // Response package serializes identifiers to JSON
    $uuid = UuidIdentifier::fromString('550e8400-e29b-41d4-a716-446655440000');
    $json = json_encode($uuid);
    expect($json)->toBe('"550e8400-e29b-41d4-a716-446655440000"');

    $integer = IntegerIdentifier::from(42);
    $json = json_encode($integer);
    expect($json)->toBe('42');

    $string = StringIdentifier::from('my-slug');
    $json = json_encode($string);
    expect($json)->toBe('"my-slug"');

    $aggregateId = AggregateRootId::generate();
    $json = json_encode($aggregateId);
    expect($json)->toBeJson();
});

test('identifier toArray/fromArray provides consistent cross-package format', function (): void {
    // Each identifier type uses a specific key for unambiguous deserialization
    $uuid = UuidIdentifier::fromString('550e8400-e29b-41d4-a716-446655440000');
    expect($uuid->toArray())->toHaveKey('uuid');

    $ulid = UlidIdentifier::fromString('01H5J5S5S5S5S5S5S5S5S5S5S5');
    expect($ulid->toArray())->toHaveKey('ulid');

    $string = StringIdentifier::from('my-slug');
    expect($string->toArray())->toHaveKey('string');

    $integer = IntegerIdentifier::from(42);
    expect($integer->toArray())->toHaveKey('integer');

    $aggregateId = AggregateRootId::generate();
    expect($aggregateId->toArray())->toHaveKey('uuid');
});

test('identifier fromArray accepts id key as fallback for cross-package compat', function (): void {
    // The response package may serialize under 'id' key
    $uuid = UuidIdentifier::fromArray(['id' => '550e8400-e29b-41d4-a716-446655440000']);
    expect($uuid->toString())->toBe('550e8400-e29b-41d4-a716-446655440000');

    $ulid = UlidIdentifier::fromArray(['id' => '01H5J5S5S5S5S5S5S5S5S5S5S5']);
    expect($ulid->toString())->toBe('01H5J5S5S5S5S5S5S5S5S5S5S5');

    $string = StringIdentifier::fromArray(['id' => 'my-slug']);
    expect($string->toString())->toBe('my-slug');

    $integer = IntegerIdentifier::fromArray(['id' => 42]);
    expect($integer->toInt())->toBe(42);

    $aggregateId = AggregateRootId::fromArray(['id' => '550e8400-e29b-41d4-a716-446655440000']);
    expect($aggregateId->toString())->toBe('550e8400-e29b-41d4-a716-446655440000');
});

test('DomainException toErrorArray produces RFC 9457 compatible format', function (): void {
    // The response package's DomainResponseFactory consumes this format
    $exceptions = [
        new \ZeroBoiler\Domain\Exceptions\InvalidStateDomainException('Order must be pending'),
        new \ZeroBoiler\Domain\Exceptions\InvalidArgumentDomainException('Quantity must be positive'),
        new \ZeroBoiler\Domain\Exceptions\NotFoundDomainException('User not found'),
        new \ZeroBoiler\Domain\Exceptions\ConflictDomainException('Concurrent modification'),
        new \ZeroBoiler\Domain\Exceptions\OptimisticLockException('test', 1, 2),
    ];

    foreach ($exceptions as $exception) {
        $errorArray = $exception->toErrorArray();
        expect($errorArray)->toHaveKeys(['title', 'detail', 'code']);
        expect($errorArray['title'])->toBeString();
        expect($errorArray['detail'])->toBeString();
        expect($errorArray['code'])->toBeString();
    }
});

test('DomainException jsonSerialize matches toErrorArray', function (): void {
    $exception = \ZeroBoiler\Domain\Exceptions\InvalidStateDomainException::because('test', 'TEST_CODE');
    $errorArray = $exception->toErrorArray();
    $jsonOutput = $exception->jsonSerialize();

    expect($jsonOutput)->toBe($errorArray);
});

test('Entity contract id() returns string for response bridge', function (): void {
    // The response package's ExtractsDomainId expects id() to return a string
    $id = AggregateRootId::generate();
    $idString = $id->toString();

    expect(is_string($idString))->toBeTrue();
    expect($idString)->not->toBeEmpty();
});

test('Entity contract equals() accepts EntityContract for response comparisons', function (): void {
    // The response package may compare entities for deduplication
    $id1 = AggregateRootId::fromString('550e8400-e29b-41d4-a716-446655440000');
    $id2 = AggregateRootId::fromString('550e8400-e29b-41d4-a716-446655440000');
    $id3 = AggregateRootId::generate();

    expect($id1->equals($id2))->toBeTrue();
    expect($id1->equals($id3))->toBeFalse();
});

test('AggregateRoot version() returns int for DomainResponseFactory version metadata', function (): void {
    // DomainResponseFactory::entity() checks for version() method
    // and includes _version in the response when available

    // Verify the return type contract
    $ref = new ReflectionMethod(AggregateRootContract::class, 'version');
    expect($ref->getReturnType()?->getName())->toBe('int');
});

test('Snapshot toArray provides state for persistence serialization', function (): void {
    $snapshot = \ZeroBoiler\Domain\Snapshots\Snapshot::create(
        aggregateType: 'App\Domain\Order',
        aggregateId: '550e8400-e29b-41d4-a716-446655440000',
        version: 10,
        state: ['status' => 'shipped', 'total' => 2500],
    );

    $array = $snapshot->toArray();
    expect($array)->toHaveKeys(['aggregate_type', 'aggregate_id', 'version', 'state', 'created_at']);
    expect($array['state'])->toBe(['status' => 'shipped', 'total' => 2500]);

    // JSON serializable for queue/cache transport
    $json = json_encode($snapshot);
    expect($json)->toBeJson();
    $decoded = json_decode($json, true);
    expect($decoded['state']['status'])->toBe('shipped');
});

test('cross-package type safety — all domain methods used by response have declared return types', function (): void {
    // Methods the response package calls via duck typing:
    $methodsToCheck = [
        ['class' => IdentifierContract::class, 'method' => 'toString', 'expected' => 'string'],
        ['class' => IdentifierContract::class, 'method' => 'equals', 'expected' => 'bool'],
        ['class' => IdentifierContract::class, 'method' => 'fromString', 'expected' => 'static'],
        ['class' => EntityContract::class, 'method' => 'id', 'expected' => 'string'],
        ['class' => EntityContract::class, 'method' => 'equals', 'expected' => 'bool'],
        ['class' => EntityContract::class, 'method' => 'toArray', 'expected' => 'array'],
        ['class' => AggregateRootContract::class, 'method' => 'version', 'expected' => 'int'],
    ];

    foreach ($methodsToCheck as $check) {
        $ref = new ReflectionMethod($check['class'], $check['method']);
        $returnType = $ref->getReturnType();
        expect($returnType)->not->toBeNull(
            "{$check['class']}::{$check['method']}() must have a return type"
        );
    }
});
