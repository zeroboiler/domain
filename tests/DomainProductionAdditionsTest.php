<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Tests;

use PHPUnit\Framework\TestCase;
use ZeroBoiler\Domain\AggregateRoot;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\Contracts\Identifier as IdentifierContract;
use ZeroBoiler\Domain\DomainEventCollection;
use ZeroBoiler\Domain\Entity;
use ZeroBoiler\Domain\Identifiers\IntegerIdentifier;
use ZeroBoiler\Domain\Identifiers\StringIdentifier;
use ZeroBoiler\Domain\Identifiers\UuidIdentifier;
use ZeroBoiler\Domain\Identifiers\UlidIdentifier;
use ZeroBoiler\Domain\InMemoryUnitOfWork;
use ZeroBoiler\Domain\SnapshottingRepository;
use ZeroBoiler\Domain\Snapshots\InMemorySnapshotStore;
use ZeroBoiler\Domain\Snapshots\Snapshot;
use ZeroBoiler\Domain\Snapshots\SnapshotPolicy;
use ZeroBoiler\Domain\Tests\Fixtures\TestUlidIdentifier;
use ZeroBoiler\Domain\Tests\Fixtures\TestUuidIdentifier;
use ZeroBoiler\Domain\ValueObject;
use ZeroBoiler\Events\Domain\DomainEvent;

/**
 * Tests for new production-ready additions: Snapshot::equals(),
 * UuidIdentifier::isValid(), identifier parity checks, and edge cases.
 */
final class DomainProductionAdditionsTest extends TestCase
{
    // ── Snapshot::equals() ──────────────────────────────────────────

    public function testSnapshotEqualsSameContent(): void
    {
        $snapshotA = Snapshot::create('Order', '550e8400-e29b-41d4-a716-446655440000', 5, ['status' => 'paid']);
        $snapshotB = Snapshot::fromArray($snapshotA->toArray());

        $this->assertTrue($snapshotA->equals($snapshotB));
    }

    public function testSnapshotNotEqualsDifferentType(): void
    {
        $a = Snapshot::create('Order', 'id-1', 1, ['status' => 'pending']);
        $b = Snapshot::create('Invoice', 'id-1', 1, ['status' => 'pending']);

        $this->assertFalse($a->equals($b));
    }

    public function testSnapshotNotEqualsDifferentId(): void
    {
        $a = Snapshot::create('Order', 'id-1', 1, ['status' => 'pending']);
        $b = Snapshot::create('Order', 'id-2', 1, ['status' => 'pending']);

        $this->assertFalse($a->equals($b));
    }

    public function testSnapshotNotEqualsDifferentVersion(): void
    {
        $a = Snapshot::create('Order', 'id-1', 1, ['status' => 'pending']);
        $b = Snapshot::create('Order', 'id-1', 2, ['status' => 'pending']);

        $this->assertFalse($a->equals($b));
    }

    public function testSnapshotNotEqualsDifferentState(): void
    {
        $a = Snapshot::create('Order', 'id-1', 1, ['status' => 'pending']);
        $b = Snapshot::create('Order', 'id-1', 1, ['status' => 'paid']);

        $this->assertFalse($a->equals($b));
    }

    public function testSnapshotEqualsAfterFullRoundTrip(): void
    {
        $original = Snapshot::create(
            'App\\Domain\\Order',
            '550e8400-e29b-41d4-a716-446655440000',
            42,
            ['status' => 'shipped', 'total' => 99.99, 'items' => ['a', 'b']],
        );

        $json = json_encode($original);
        $restored = Snapshot::fromArray(json_decode($json, true));

        $this->assertTrue($original->equals($restored));
        $this->assertSame($original->aggregateType, $restored->aggregateType);
        $this->assertSame($original->aggregateId, $restored->aggregateId);
        $this->assertSame($original->version, $restored->version);
        $this->assertSame($original->state, $restored->state);
    }

    // ── UuidIdentifier::isValid() ───────────────────────────────────

    public function testUuidIdentifierIsValidWithValidUuid(): void
    {
        $this->assertTrue(TestUuidIdentifier::isValid('550e8400-e29b-41d4-a716-446655440000'));
    }

    public function testUuidIdentifierIsValidWithInvalidString(): void
    {
        $this->assertFalse(TestUuidIdentifier::isValid('not-a-uuid'));
    }

    public function testUuidIdentifierIsValidWithEmptyString(): void
    {
        $this->assertFalse(TestUuidIdentifier::isValid(''));
    }

    public function testUuidIdentifierIsValidWithPartialUuid(): void
    {
        $this->assertFalse(TestUuidIdentifier::isValid('550e8400-e29b'));
    }

    // ── Identifier Parity: all identifiers have isValid() ───────────

    public function testAllIdentifierTypesHaveIsValidMethod(): void
    {
        $types = [UuidIdentifier::class, UlidIdentifier::class, StringIdentifier::class, IntegerIdentifier::class];

        foreach ($types as $type) {
            $this->assertTrue(
                method_exists($type, 'isValid'),
                "{$type}::isValid() must exist.",
            );
        }
    }

    public function testAllIdentifierTypesImplementIdentifierContract(): void
    {
        $types = [
            AggregateRootId::class,
            TestUuidIdentifier::class,
            TestUlidIdentifier::class,
            StringIdentifier::class,
            IntegerIdentifier::class,
        ];

        foreach ($types as $type) {
            $this->assertInstanceOf(
                IdentifierContract::class,
                $type::generate() ?? $type::from('test') ?? $type::from(1),
                "{$type} must implement IdentifierContract.",
            );
        }
    }

    // ── Identifier cross-type inequality ────────────────────────────

    public function testUuidAndUlidIdentifiersAreNotEqual(): void
    {
        $uuid = TestUuidIdentifier::generate();
        $ulid = TestUlidIdentifier::generate();

        // Both implement IdentifierContract but different concrete types
        $this->assertFalse($uuid->equals($ulid));
    }

    public function testStringAndIntegerIdentifiersAreNotEqual(): void
    {
        $str = StringIdentifier::from('42');
        $int = IntegerIdentifier::from(42);

        // Different concrete types — equals() checks instanceof
        $this->assertFalse($str->equals($int));
    }

    // ── Identifier JSON serialization consistency ────────────────────

    public function testIntegerIdentifierSerializesAsInteger(): void
    {
        $id = IntegerIdentifier::from(42);
        $encoded = json_encode($id);

        $this->assertSame('42', $encoded);
    }

    public function testStringIdentifierSerializesAsString(): void
    {
        $id = StringIdentifier::from('my-slug');
        $encoded = json_encode($id);

        $this->assertSame('"my-slug"', $encoded);
    }

    public function testUlidIdentifierSerializesAsString(): void
    {
        $id = TestUlidIdentifier::generate();
        $encoded = json_encode($id);

        // Should be a quoted string (ULID is base32 encoded)
        $this->assertMatchesRegularExpression('/^"[0-9A-HJKMNP-TV-Z]{26}"$/', $encoded);
    }

    // ── DomainEventCollection edge cases ────────────────────────────

    public function testDomainEventCollectionMergeWithEmpty(): void
    {
        $collection = new DomainEventCollection;
        $merged = $collection->merge([]);

        $this->assertTrue($merged->isEmpty());
    }

    public function testDomainEventCollectionMapPreservesIndex(): void
    {
        $events = [
            DomainEvent::occur('event.a', ['index' => 0]),
            DomainEvent::occur('event.b', ['index' => 1]),
            DomainEvent::occur('event.c', ['index' => 2]),
        ];

        $collection = new DomainEventCollection($events);
        $types = $collection->map(fn (DomainEvent $e, int $i): string => "{$e->eventType}:{$i}");

        $this->assertSame(['event.a:0', 'event.b:1', 'event.c:2'], $types);
    }

    public function testDomainEventCollectionFilterReturnsNewInstance(): void
    {
        $events = [
            DomainEvent::occur('order.placed', []),
            DomainEvent::occur('order.paid', []),
            DomainEvent::occur('order.placed', []),
        ];

        $collection = new DomainEventCollection($events);
        $placed = $collection->filter(fn (DomainEvent $e): bool => $e->eventType === 'order.placed');

        $this->assertSame(2, $placed->count());
        $this->assertSame(3, $collection->count()); // Original unchanged
        $this->assertNotSame($collection, $placed);
    }

    public function testDomainEventCollectionGetOutOfBoundsReturnsNull(): void
    {
        $collection = new DomainEventCollection([DomainEvent::occur('test', [])]);

        $this->assertNull($collection->get(1));
        $this->assertNull($collection->get(-1));
    }

    public function testDomainEventCollectionMergeWithAnotherCollection(): void
    {
        $a = new DomainEventCollection([DomainEvent::occur('a', [])]);
        $b = new DomainEventCollection([DomainEvent::occur('b', [])]);

        $merged = $a->merge($b);

        $this->assertSame(2, $merged->count());
        $this->assertSame('a', $merged->first()->eventType);
        $this->assertSame('b', $merged->last()->eventType);
    }

    // ── InMemoryUnitOfWork clear() edge cases ───────────────────────

    public function testUnitOfWorkClearResetsAllState(): void
    {
        $uow = new InMemoryUnitOfWork;
        $uow->begin();
        $uow->queueEvent(DomainEvent::occur('test', []));
        $uow->commit();

        $this->assertSame(1, $uow->getPendingEventCount());

        $uow->clear();

        $this->assertFalse($uow->isActive());
        $this->assertSame(0, $uow->getPendingEventCount());
        $this->assertSame([], $uow->getCommitted());
        $this->assertSame([], $uow->getDeleted());
    }

    // ── Entity ID type coverage ─────────────────────────────────────

    public function testEntityWithIntId(): void
    {
        $entity = new class(42) extends Entity {};

        $this->assertSame('42', $entity->id());
    }

    public function testEntityWithStringId(): void
    {
        $entity = new class('abc-123') extends Entity {};

        $this->assertSame('abc-123', $entity->id());
    }

    public function testEntityWithStringableId(): void
    {
        $id = TestUuidIdentifier::generate();
        $entity = new class($id) extends Entity {};

        $this->assertSame($id->toString(), $entity->id());
    }

    public function testEntityEqualityWithSameIntId(): void
    {
        $a = new class(1) extends Entity {};
        $b = new class(1) extends Entity {};

        $this->assertTrue($a->equals($b));
    }

    public function testEntityInequalityWithDifferentType(): void
    {
        $a = new class(1) extends Entity {};
        $b = new class(1) extends Entity {};

        // Different anonymous classes — not equal
        $this->assertFalse($a->equals($b));
    }

    // ── ValueObject equality edge cases ─────────────────────────────

    public function testValueObjectSelfEquality(): void
    {
        $vo = new class('test') extends ValueObject {
            public function __construct(public readonly string $value) {}

            public static function fromArray(array $data): static
            {
                return new static($data['value']);
            }

            public function toArray(): array
            {
                return ['value' => $this->value];
            }
        };

        $this->assertTrue($vo->equals($vo));
    }

    public function testValueObjectNotEqualsNull(): void
    {
        $vo = new class('test') extends ValueObject {
            public function __construct(public readonly string $value) {}

            public static function fromArray(array $data): static
            {
                return new static($data['value']);
            }

            public function toArray(): array
            {
                return ['value' => $this->value];
            }
        };

        $this->assertFalse($vo->equals(null));
    }

    // ── AggregateRootId JSON in nested structures ────────────────────

    public function testAggregateRootIdInNestedArray(): void
    {
        $id = AggregateRootId::fromString('550e8400-e29b-41d4-a716-446655440000');
        $data = [
            'order' => ['id' => $id, 'status' => 'pending'],
            'meta' => ['created_by' => $id],
        ];

        $encoded = json_encode($data);
        $decoded = json_decode($encoded, true);

        $this->assertSame('550e8400-e29b-41d4-a716-446655440000', $decoded['order']['id']);
        $this->assertSame('550e8400-e29b-41d4-a716-446655440000', $decoded['meta']['created_by']);
    }

    // ── DomainException errorCode consistency ────────────────────────

    public function testDomainExceptionErrorCodePreservesCustomCode(): void
    {
        $exception = \ZeroBoiler\Domain\Exceptions\InvalidStateDomainException::because(
            'Order must be pending.',
            code: 'ORDER_NOT_PENDING',
        );

        $this->assertSame('ORDER_NOT_PENDING', $exception->errorCode());
    }

    public function testDomainExceptionErrorCodeFallsBackToDefault(): void
    {
        $exception = \ZeroBoiler\Domain\Exceptions\InvalidStateDomainException::because('Test');

        $this->assertSame('INVALID_STATE', $exception->errorCode());
    }
}
