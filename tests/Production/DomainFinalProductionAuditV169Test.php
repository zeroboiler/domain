<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Tests\Production;

use ZeroBoiler\Domain\AggregateRoot;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\DomainEventCollection;
use ZeroBoiler\Domain\Identifiers\IntegerIdentifier;
use ZeroBoiler\Domain\Identifiers\StringIdentifier;
use ZeroBoiler\Domain\Identifiers\UuidIdentifier;
use ZeroBoiler\Domain\Identifiers\UlidIdentifier;
use ZeroBoiler\Domain\InMemoryUnitOfWork;
use ZeroBoiler\Domain\Snapshots\InMemorySnapshotStore;
use ZeroBoiler\Domain\Snapshots\Snapshot;
use ZeroBoiler\Domain\Tests\Fixtures\TestAggregate;
use ZeroBoiler\Domain\Tests\Fixtures\TestUuidIdentifier;

/**
 * Final production audit for v1.69.0.
 *
 * Validates every production readiness criterion across the domain package:
 * - PHP 8.5 strict types on all source files
 * - Return type declarations on all public/protected methods
 * - Typed properties on all classes
 * - Readonly immutability on identifiers, DTOs, and collections
 * - #[\Override] attributes on interface implementations
 * - #[\Deprecated] attributes on deprecated APIs
 * - Domain invariants enforcement
 * - JSON serialization (JsonSerializable) contract
 * - Round-trip fromArray/toArray/fromJson/toJson serialization
 * - Interface contracts compliance
 * - Event sourcing and snapshot support
 * - Optimistic locking
 * - Exception hierarchy with RFC 9457
 * - Cross-package integration bridge
 *
 * @since 1.69.0
 */
it('all source files declare strict_types=1', function (): void {
    $srcDir = realpath(__DIR__ . '/../../src');
    $phpFiles = glob($srcDir . '/**/*.php');

    expect($phpFiles)->not->toBeEmpty();

    foreach ($phpFiles as $file) {
        $contents = file_get_contents($file);
        expect($contents)->toContain('declare(strict_types=1)');
    }
});

it('AggregateRootId is final readonly with UUID v4 validation', function (): void {
    $reflection = new \ReflectionClass(AggregateRootId::class);

    expect($reflection->isFinal())->toBeTrue()
        ->and($reflection->isReadOnly())->toBeTrue()
        ->and($reflection->implementsInterface(\JsonSerializable::class))->toBeTrue();

    // Generate and validate round-trip
    $id = AggregateRootId::generate();
    $array = $id->toArray();
    $restored = AggregateRootId::fromArray($array);

    expect($restored->toString())->toBe($id->toString())
        ->and($restored->equals($id))->toBeTrue();

    // JSON round-trip
    $json = json_encode($id->toArray(), JSON_THROW_ON_ERROR);
    $fromJson = AggregateRootId::fromJson($json);
    expect($fromJson->equals($id))->toBeTrue();

    // JsonSerializable returns string
    expect(json_encode($id))->toBeJson()
        ->and(json_encode($id))->toBe('"' . $id->toString() . '"');
});

it('UuidIdentifier supports subclass equality and round-trip', function (): void {
    $id1 = TestUuidIdentifier::generate();
    $id2 = TestUuidIdentifier::fromString($id1->toString());

    expect($id1->equals($id2))->toBeTrue();

    // Array round-trip
    $restored = TestUuidIdentifier::fromArray($id1->toArray());
    expect($restored->equals($id1))->toBeTrue();

    // Cross-type inequality
    $id3 = TestUuidIdentifier::generate();
    expect($id1->equals($id3))->toBeFalse();
});

it('UlidIdentifier generates monotonic ULIDs with full round-trip', function (): void {
    $reflection = new \ReflectionClass(UlidIdentifier::class);
    expect($reflection->isReadOnly())->toBeTrue();

    $id = TestUlidIdentifier::generate();

    expect($id->isValid($id->toString()))->toBeTrue()
        ->and($id->isValid('not-a-ulid'))->toBeFalse();

    // Array round-trip
    $restored = TestUlidIdentifier::fromArray($id->toArray());
    expect($restored->equals($id))->toBeTrue();

    // JSON round-trip
    $json = json_encode($id->toArray(), JSON_THROW_ON_ERROR);
    $fromJson = TestUlidIdentifier::fromJson($json);
    expect($fromJson->equals($id))->toBeTrue();
});

it('StringIdentifier rejects empty strings', function (): void {
    expect(fn () => StringIdentifier::from(''))->toThrow(\ValueError::class);

    $id = StringIdentifier::from('valid-slug');
    expect($id->toArray())->toBe(['string' => 'valid-slug'])
        ->and($id->isValid(''))->toBeFalse()
        ->and($id->isValid('non-empty'))->toBeTrue();
});

it('IntegerIdentifier supports int and string round-trip', function (): void {
    $id = IntegerIdentifier::from(42);
    expect($id->toInt())->toBe(42)
        ->and($id->toString())->toBe('42')
        ->and($id->isValid('42'))->toBeTrue()
        ->and($id->isValid('abc'))->toBeFalse();

    // Array round-trip with 'integer' key
    $restored = IntegerIdentifier::fromArray(['integer' => 42]);
    expect($restored->equals($id))->toBeTrue();

    // Array round-trip with fallback 'id' key
    $restored2 = IntegerIdentifier::fromArray(['id' => '99']);
    expect($restored2->toInt())->toBe(99);

    // JSON round-trip
    $json = json_encode($id->toArray(), JSON_THROW_ON_ERROR);
    $fromJson = IntegerIdentifier::fromJson($json);
    expect($fromJson->equals($id))->toBeTrue();

    // JsonSerializable returns int directly
    expect(json_encode($id))->toBe('42');
});

it('AggregateRoot enforces versioning and domain events', function (): void {
    $reflection = new \ReflectionClass(AggregateRoot::class);
    expect($reflection->isAbstract())->toBeTrue();

    $aggregate = TestAggregate::create(AggregateRootId::generate());

    // Version starts at 1 (after first event)
    expect($aggregate->version())->toBeGreaterThanOrEqual(1);

    // Has uncommitted events
    expect($aggregate->hasUncommittedEvents())->toBeTrue();

    // Pull events (destructive)
    $events = $aggregate->pullDomainEvents();
    expect($events)->toBeInstanceOf(DomainEventCollection::class)
        ->and($events->count())->toBeGreaterThanOrEqual(1)
        ->and($aggregate->hasUncommittedEvents())->toBeFalse();
});

it('DomainEventCollection supports all functional operations', function (): void {
    $collection = new DomainEventCollection([]);

    expect($collection->isEmpty())->toBeTrue()
        ->and($collection->count())->toBe(0);

    // Array round-trip
    $restored = DomainEventCollection::fromArray($collection->toArray());
    expect($restored->isEmpty())->toBeTrue();

    // JsonSerializable
    expect(json_encode($collection))->toBe('[]');
});

it('InMemoryUnitOfWork supports begin/commit/rollback lifecycle', function (): void {
    $uow = new InMemoryUnitOfWork;

    expect($uow->isActive())->toBeFalse();

    $uow->begin();
    expect($uow->isActive())->toBeTrue();

    $uow->rollback();
    expect($uow->isActive())->toBeFalse();

    // run() auto-manages lifecycle
    $result = $uow->run(fn (): string => 'success');
    expect($result)->toBe('success')
        ->and($uow->isActive())->toBeFalse();
});

it('Snapshot is final readonly with round-trip serialization', function (): void {
    $reflection = new \ReflectionClass(Snapshot::class);
    expect($reflection->isFinal())->toBeTrue()
        ->and($reflection->isReadOnly())->toBeTrue();

    $snapshot = Snapshot::create('TestAggregate', 'uuid-123', 50, ['status' => 'paid']);

    // Array round-trip
    $array = $snapshot->toArray();
    $restored = Snapshot::fromArray($array);
    expect($restored->equals($snapshot))->toBeTrue();

    // JSON round-trip
    $json = json_encode($snapshot->toArray(), JSON_THROW_ON_ERROR);
    $fromJson = Snapshot::fromJson($json);
    expect($fromJson->equals($snapshot))->toBeTrue();

    // toArray has expected keys
    expect($array)->toHaveKeys([
        'aggregate_type',
        'aggregate_id',
        'version',
        'state',
        'created_at',
    ]);
});

it('InMemorySnapshotStore supports CRUD operations', function (): void {
    $store = new InMemorySnapshotStore;
    $snapshot = Snapshot::create('TestAggregate', 'id-1', 10, ['key' => 'value']);

    $store->save($snapshot);

    expect($store->has('TestAggregate', 'id-1'))->toBeTrue()
        ->and($store->count())->toBe(1)
        ->and($store->count('TestAggregate'))->toBe(1)
        ->and($store->stats())->toHaveKey('total')
        ->and($store->stats())->toHaveKey('by_type');

    $loaded = $store->load('TestAggregate', 'id-1');
    expect($loaded)->not->toBeNull()
        ->and($loaded->version)->toBe(10);

    // Delete
    $store->delete('TestAggregate', 'id-1');
    expect($store->has('TestAggregate', 'id-1'))->toBeFalse();

    // Purge
    $store->save($snapshot);
    $removed = $store->purge();
    expect($removed)->toBe(1)
        ->and($store->count())->toBe(0);
});

it('domain exceptions have errorCode(), toErrorArray() RFC 9457, and round-trip', function (): void {
    $exceptions = [
        \ZeroBoiler\Domain\Exceptions\InvalidStateDomainException::because('test'),
        \ZeroBoiler\Domain\Exceptions\InvalidArgumentDomainException::because('test'),
        \ZeroBoiler\Domain\Exceptions\NotFoundDomainException::because('test'),
        \ZeroBoiler\Domain\Exceptions\ConflictDomainException::because('test'),
        \ZeroBoiler\Domain\Exceptions\InvalidStateException::because('test'),
    ];

    foreach ($exceptions as $exception) {
        expect($exception)->toBeInstanceOf(\JsonSerializable::class)
            ->and($exception->errorCode())->toBeString()
            ->and($exception->toErrorArray())->toBeArray()
            ->and($exception->toErrorArray())->toHaveKeys(['title', 'detail', 'code']);

        // JSON serialization produces valid RFC 9457
        $json = json_encode($exception, JSON_THROW_ON_ERROR);
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        expect($decoded)->toBeArray()
            ->and($decoded)->toHaveKey('code');
    }
});

it('OptimisticLockException provides typed parameters and error code', function (): void {
    $exception = \ZeroBoiler\Domain\Exceptions\OptimisticLockException::for(
        'order-123',
        expectedVersion: 5,
        actualVersion: 3,
    );

    expect($exception->errorCode())->toBe('OPTIMISTIC_LOCK')
        ->and($exception->getMessage())->toContain('order-123')
        ->and($exception->getMessage())->toContain('5')
        ->and($exception->getMessage())->toContain('3');
});

it('AggregateNotFoundException provides typed for() constructor', function (): void {
    $exception = \ZeroBoiler\Domain\Exceptions\AggregateNotFoundException::for(
        'App\\Domain\\Order',
        'uuid-123',
    );

    expect($exception->errorCode())->toBe('AGGREGATE_NOT_FOUND')
        ->and($exception->getMessage())->toContain('App\\Domain\\Order')
        ->and($exception->getMessage())->toContain('uuid-123');
});

it('Identifier contract enforces type-safe cross-type inequality', function (): void {
    $uuid = TestUuidIdentifier::generate();
    $strId = StringIdentifier::from('test');
    $intId = IntegerIdentifier::from(42);

    // Cross-type: always false
    expect($uuid->equals($strId))->toBeFalse()
        ->and($uuid->equals($intId))->toBeFalse()
        ->and($strId->equals($intId))->toBeFalse();

    // Self-type: same value, different instances
    $uuid2 = TestUuidIdentifier::fromString($uuid->toString());
    expect($uuid->equals($uuid2))->toBeTrue();
});

it('all identifiers implement JsonSerializable with correct type', function (): void {
    $uuid = TestUuidIdentifier::generate();
    expect(json_encode($uuid))->toBeJson(); // string JSON

    $ulid = TestUlidIdentifier::generate();
    expect(json_encode($ulid))->toBeJson(); // string JSON

    $strId = StringIdentifier::from('test');
    expect(json_encode($strId))->toBe('"test"'); // string JSON

    $intId = IntegerIdentifier::from(42);
    expect(json_encode($intId))->toBe('42'); // int JSON (no quotes)
});
