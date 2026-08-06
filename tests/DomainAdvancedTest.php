<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Domain\AggregateRoot;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\Concerns\EventSourced;
use ZeroBoiler\Domain\Contracts\Identifier;
use ZeroBoiler\Domain\Identifiers\IntegerIdentifier;
use ZeroBoiler\Domain\Identifiers\StringIdentifier;
use ZeroBoiler\Domain\Identifiers\UuidIdentifier;
use ZeroBoiler\Domain\Identifiers\UlidIdentifier;
use ZeroBoiler\Domain\InMemoryUnitOfWork;
use ZeroBoiler\Domain\Tests\Fixtures\TestAggregate;
use ZeroBoiler\Domain\Tests\Fixtures\TestUuidIdentifier;
use ZeroBoiler\Domain\Tests\Fixtures\TestUlidIdentifier;
use ZeroBoiler\Domain\Tests\Fixtures\TestValueObject;
use ZeroBoiler\Domain\ValueObject;
use ZeroBoiler\Events\Domain\DomainEvent;

// ─── UuidIdentifier Edge Cases ───────────────────────────────────────

it('generates unique UUIDs across multiple calls', function (): void {
    $ids = array_map(fn (): string => TestUuidIdentifier::generate()->toString(), range(1, 100));

    expect(count(array_unique($ids)))->toBe(100);
});

it('fromString round-trips correctly', function (): void {
    $original = TestUuidIdentifier::generate();
    $string = $original->toString();
    $restored = TestUuidIdentifier::fromString($string);

    expect($restored->equals($original))->toBeTrue();
    expect($restored->toString())->toBe($string);
});

it('equals returns false for different identifiers', function (): void {
    $a = TestUuidIdentifier::generate();
    $b = TestUuidIdentifier::generate();

    expect($a->equals($b))->toBeFalse();
});

it('fromString throws on invalid UUID', function (): void {
    TestUuidIdentifier::fromString('not-a-uuid');
})->throws(InvalidArgumentException::class);

it('jsonSerialize returns string representation', function (): void {
    $id = TestUuidIdentifier::generate();
    $json = json_encode($id);

    expect($json)->toBeJson();
    expect(json_decode($json, true))->toBe($id->toString());
});

// ─── UlidIdentifier Edge Cases ────────────────────────────────────────

it('generates ULIDs in monotonic order', function (): void {
    $a = TestUlidIdentifier::generate();
    $b = TestUlidIdentifier::generate();

    // ULID string comparison should show $b > $a (monotonic)
    expect($b->toString() > $a->toString())->toBeTrue();
});

it('fromString round-trips for ULID', function (): void {
    $original = TestUlidIdentifier::generate();
    $string = $original->toString();
    $restored = TestUlidIdentifier::fromString($string);

    expect($restored->equals($original))->toBeTrue();
});

// ─── StringIdentifier Edge Cases ─────────────────────────────────────

it('rejects empty string', function (): void {
    StringIdentifier::from('');
})->throws(InvalidArgumentException::class);

it('accepts non-empty strings', function (): void {
    $id = StringIdentifier::from('my-slug-123');

    expect($id->toString())->toBe('my-slug-123');
    expect((string) $id)->toBe('my-slug-123');
});

// ─── IntegerIdentifier Edge Cases ─────────────────────────────────────

it('fromString parses numeric strings', function (): void {
    $id = IntegerIdentifier::fromString('42');

    expect($id->value)->toBe(42);
});

it('fromString throws on non-numeric string', function (): void {
    IntegerIdentifier::fromString('not-a-number');
})->throws(InvalidArgumentException::class);

// ─── AggregateRoot Domain Invariants ───────────────────────────────────

it('applies multiple events in sequence', function (): void {
    $aggregate = TestAggregate::create(AggregateRootId::generate());

    $aggregate->rename('First');
    $aggregate->rename('Second');

    $events = $aggregate->pullDomainEvents();

    // create() fires 1 event, then 2 renames
    expect($events->count())->toBe(3);
    expect($aggregate->hasUncommittedEvents())->toBeFalse();
});

it('version increments with each event', function (): void {
    $id = AggregateRootId::generate();
    $aggregate = TestAggregate::create($id);

    $aggregate->rename('Alice');

    expect($aggregate->version())->toBe(2); // 1 (create) + 1 rename
});

// ─── ValueObject Equality ─────────────────────────────────────────────

it('two value objects with same value are equal', function (): void {
    $a = TestValueObject::from('hello');
    $b = TestValueObject::from('hello');

    expect($a->equals($b))->toBeTrue();
});

it('two value objects with different value are not equal', function (): void {
    $a = TestValueObject::from('hello');
    $b = TestValueObject::from('world');

    expect($a->equals($b))->toBeFalse();
});

it('value object jsonSerialize returns array', function (): void {
    $vo = TestValueObject::from('hello');
    $json = json_encode($vo);

    expect($json)->toBeJson();
    expect(json_decode($json, true))->toBe(['value' => 'hello']);
});

// ─── UnitOfWork Persistence Callback ──────────────────────────────────

it('persistence callback receives committed and deleted aggregates', function (): void {
    $committed = [];
    $deleted = [];

    $uow = new InMemoryUnitOfWork;
    $uow->setPersistenceCallback(
        function (array $c, array $d) use (&$committed, &$deleted): void {
            $committed = $c;
            $deleted = $d;
        },
    );

    $id = AggregateRootId::generate();
    $aggregate = TestAggregate::create($id);

    $uow->begin();
    $uow->track($aggregate);
    $uow->commit();

    expect($committed)->toHaveCount(1);
    expect($committed[0]->id()->equals($id))->toBeTrue();
    expect($deleted)->toBeEmpty();
});
