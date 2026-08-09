<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace Tests\Domain;

use PHPUnit\Framework\TestCase;

/**
 * Usage examples and integration patterns for the domain package.
 *
 * Demonstrates idiomatic ZeroBoiler\Domain usage with concrete examples
 * for each major concept: AggregateRoot, Entity, ValueObject, Identifiers,
 * Domain Events, Unit of Work, Snapshots, and Exceptions.
 *
 * These tests serve as living documentation — they compile but require
 * the actual package to be installed via Composer to execute.
 *
 * @since 1.43.0
 *
 * @see \ZeroBoiler\Domain\AggregateRoot
 * @see \ZeroBoiler\Domain\Entity
 * @see \ZeroBoiler\Domain\ValueObject
 * @see \ZeroBoiler\Domain\AggregateRootId
 * @see \ZeroBoiler\Domain\DomainEventCollection
 * @see \ZeroBoiler\Domain\InMemoryUnitOfWork
 * @see \ZeroBoiler\Domain\Snapshots\Snapshot
 * @see \ZeroBoiler\Domain\Exceptions\DomainException
 */
final class DomainUsageExamplesTest extends TestCase
{
    // ── AggregateRootId Usage ─────────────────────────────────────────────────

    /**
     * Demonstrates AggregateRootId generation, string conversion, and equality.
     */
    public function test_aggregate_root_id_usage(): void
    {
        // Generate a new UUID v4 identifier
        $id1 = \ZeroBoiler\Domain\AggregateRootId::generate();
        $id2 = \ZeroBoiler\Domain\AggregateRootId::generate();

        // IDs should be unique
        $this->assertFalse($id1->equals($id2), 'Two generated IDs must not be equal.');

        // Same ID recreated from string should be equal
        $id1Copy = \ZeroBoiler\Domain\AggregateRootId::fromString($id1->toString());
        $this->assertTrue($id1->equals($id1Copy), 'ID from same string must be equal.');

        // toString() returns the raw UUID string
        $this->assertIsString($id1->toString());
        $this->assertSame($id1->toString(), (string) $id1);

        // JSON serialization returns the UUID string
        $this->assertSame($id1->toString(), $id1->jsonSerialize());

        // fromArray / toArray round-trip
        $restored = \ZeroBoiler\Domain\AggregateRootId::fromArray($id1->toArray());
        $this->assertTrue($id1->equals($restored));
    }

    // ── Domain Exception Usage ─────────────────────────────────────────────────

    /**
     * Demonstrates the various domain exception types and their factory methods.
     */
    public function test_domain_exception_usage(): void
    {
        // InvalidArgumentDomainException — for input validation failures
        $e = \ZeroBoiler\Domain\Exceptions\InvalidArgumentDomainException::because('Quantity must be positive.');
        $this->assertSame('INVALID_ARGUMENT', $e->errorCode());
        $this->assertSame('Quantity must be positive.', $e->getMessage());

        // Custom error code override
        $e2 = \ZeroBoiler\Domain\Exceptions\InvalidArgumentDomainException::because('Email invalid.', 'INVALID_EMAIL');
        $this->assertSame('INVALID_EMAIL', $e2->errorCode());

        // NotFoundDomainException — for missing aggregates
        $e3 = \ZeroBoiler\Domain\Exceptions\NotFoundDomainException::because('User not found.');
        $this->assertSame('NOT_FOUND', $e3->errorCode());

        // NotFoundDomainException::forAggregate — structured message
        $e4 = \ZeroBoiler\Domain\Exceptions\NotFoundDomainException::forAggregate('Order', '550e8400');
        $this->assertStringContainsString('Order', $e4->getMessage());
        $this->assertStringContainsString('550e8400', $e4->getMessage());

        // InvalidStateDomainException — for business rule violations
        $e5 = \ZeroBoiler\Domain\Exceptions\InvalidStateDomainException::because('Order must be pending to pay.');
        $this->assertSame('INVALID_STATE', $e5->errorCode());

        // OptimisticLockException — for concurrent modification
        $e6 = \ZeroBoiler\Domain\Exceptions\OptimisticLockException::for(
            aggregateId: 'order-123',
            expectedVersion: 5,
            actualVersion: 3,
        );
        $this->assertSame('OPTIMISTIC_LOCK', $e6->errorCode());
        $this->assertStringContainsString('expected version 5', $e6->getMessage());
        $this->assertStringContainsString('current version 3', $e6->getMessage());

        // ConflictDomainException — for write-write conflicts
        $e7 = \ZeroBoiler\Domain\Exceptions\ConflictDomainException::because('Concurrent modification.');
        $this->assertSame('CONFLICT', $e7->errorCode());

        // AggregateNotFoundException — structured aggregate lookup failure
        $e8 = \ZeroBoiler\Domain\Exceptions\AggregateNotFoundException::for('App\\Domain\\Order', 'abc');
        $this->assertSame('AGGREGATE_NOT_FOUND', $e8->errorCode());

        // InvalidAggregateRootException — type validation failure
        $obj = new \stdClass;
        $e9 = \ZeroBoiler\Domain\Exceptions\InvalidAggregateRootException::notAnAggregate($obj);
        $this->assertSame('INVALID_AGGREGATE_ROOT', $e9->errorCode());

        // All domain exceptions implement JsonSerializable
        foreach ([$e, $e3, $e5, $e6, $e7, $e8, $e9] as $ex) {
            $this->assertInstanceOf('JsonSerializable', $ex);
            $arr = $ex->jsonSerialize();
            $this->assertArrayHasKey('error', $arr);
            $this->assertArrayHasKey('code', $arr);
            $this->assertArrayHasKey('detail', $arr);
            $this->assertArrayHasKey('title', $arr);
        }

        // toErrorArray() provides RFC 9457-compatible structure
        $errorArray = $e5->toErrorArray();
        $this->assertSame('InvalidStateDomainException', $errorArray['title']);
        $this->assertSame('Order must be pending to pay.', $errorArray['detail']);
        $this->assertSame('INVALID_STATE', $errorArray['code']);
    }

    // ── InvalidStateException (Non-Domain) Usage ─────────────────────────────

    public function test_invalid_state_exception_is_system_level(): void
    {
        $e = \ZeroBoiler\Domain\Exceptions\InvalidStateException::because('Config is invalid.');
        $this->assertSame('Config is invalid.', $e->getMessage());

        // Does NOT extend DomainException
        $this->assertInstanceOf('Exception', $e);
        $this->assertNotInstanceOf(
            'ZeroBoiler\Domain\Exceptions\DomainException',
            $e,
            'InvalidStateException must NOT extend DomainException.',
        );
    }

    // ── DomainEventCollection Usage ───────────────────────────────────────────

    public function test_domain_event_collection_usage(): void
    {
        // Create from domain event objects (using stdClass as mock events)
        $event1 = new \stdClass;
        $event1->type = 'OrderCreated';
        $event1->payload = ['orderId' => '123'];

        $event2 = new \stdClass;
        $event2->type = 'OrderPaid';
        $event2->payload = ['amount' => 100];

        $collection = \ZeroBoiler\Domain\DomainEventCollection::fromArray([$event1, $event2]);

        $this->assertCount(2, $collection);
        $this->assertFalse($collection->isEmpty());
        $this->assertSame($event1, $collection->first());
        $this->assertSame($event2, $collection->last());

        // Get by index
        $this->assertSame($event1, $collection->get(0));
        $this->assertSame($event2, $collection->get(1));

        // Filter
        $created = $collection->filter(fn ($e) => $e->type === 'OrderCreated');
        $this->assertCount(1, $created);

        // Map
        $types = $collection->map(fn ($e) => $e->type);
        $this->assertSame(['OrderCreated', 'OrderPaid'], $types->all());

        // toArray for serialization
        $arr = $collection->toArray();
        $this->assertIsArray($arr);
        $this->assertCount(2, $arr);

        // Merge two collections
        $event3 = new \stdClass;
        $event3->type = 'OrderShipped';
        $merged = $collection->merge(\ZeroBoiler\Domain\DomainEventCollection::fromArray([$event3]));
        $this->assertCount(3, $merged);

        // Empty collection
        $empty = \ZeroBoiler\Domain\DomainEventCollection::empty();
        $this->assertTrue($empty->isEmpty());
        $this->assertCount(0, $empty);
    }

    // ── Snapshot & SnapshotPolicy Usage ───────────────────────────────────────

    public function test_snapshot_and_policy_usage(): void
    {
        // Create a snapshot policy (snapshot every 5 events)
        $policy = \ZeroBoiler\Domain\Snapshots\SnapshotPolicy::every(5);
        $this->assertTrue($policy->shouldSnapshot(5, 5));
        $this->assertFalse($policy->shouldSnapshot(3, 5));
        $this->assertTrue($policy->shouldSnapshot(10, 5));

        // Create a snapshot
        $snapshot = new \ZeroBoiler\Domain\Snapshots\Snapshot(
            aggregateType: 'App\\Domain\\Order',
            aggregateId: 'order-123',
            version: 5,
            state: ['status' => 'paid', 'total' => 1000],
            createdAt: new \DateTimeImmutable('2026-08-09T12:00:00Z'),
        );

        $this->assertSame('App\\Domain\\Order', $snapshot->aggregateType);
        $this->assertSame('order-123', $snapshot->aggregateId);
        $this->assertSame(5, $snapshot->version);
        $this->assertSame(['status' => 'paid', 'total' => 1000], $snapshot->state);
        $this->assertTrue($snapshot->isSerializable());
        $this->assertSame(5, $snapshot->sequence());

        // Serialization
        $arr = $snapshot->toArray();
        $restored = \ZeroBoiler\Domain\Snapshots\Snapshot::fromArray($arr);
        $this->assertSame($snapshot->aggregateId, $restored->aggregateId);
        $this->assertSame($snapshot->version, $restored->version);
    }

    // ── InMemorySnapshotStore Usage ───────────────────────────────────────────

    public function test_in_memory_snapshot_store_usage(): void
    {
        $store = new \ZeroBoiler\Domain\Snapshots\InMemorySnapshotStore();

        $snapshot = new \ZeroBoiler\Domain\Snapshots\Snapshot(
            aggregateType: 'App\\Domain\\Order',
            aggregateId: 'order-123',
            version: 5,
            state: ['status' => 'paid'],
            createdAt: new \DateTimeImmutable(),
        );

        // Save and load
        $store->save($snapshot);
        $loaded = $store->load('App\\Domain\\Order', 'order-123');
        $this->assertNotNull($loaded);
        $this->assertSame('order-123', $loaded->aggregateId);
        $this->assertSame(5, $loaded->version);

        // Has
        $this->assertTrue($store->has('App\\Domain\\Order', 'order-123'));
        $this->assertFalse($store->has('App\\Domain\\User', 'user-456'));

        // Count
        $this->assertSame(1, $store->count());
        $this->assertSame(1, $store->count('App\\Domain\\Order'));
        $this->assertSame(0, $store->count('App\\Domain\\User'));

        // Stats
        $stats = $store->stats();
        $this->assertSame(1, $stats['total']);
        $this->assertArrayHasKey('App\\Domain\\Order', $stats['by_type']);

        // Delete
        $store->delete('App\\Domain\\Order', 'order-123');
        $this->assertNull($store->load('App\\Domain\\Order', 'order-123'));

        // Purge
        $store->save($snapshot);
        $store->save(new \ZeroBoiler\Domain\Snapshots\Snapshot(
            aggregateType: 'App\\Domain\\User',
            aggregateId: 'user-456',
            version: 1,
            state: ['name' => 'Alice'],
            createdAt: new \DateTimeImmutable(),
        ));
        $removed = $store->purge('App\\Domain\\Order');
        $this->assertSame(1, $removed);
        $this->assertSame(1, $store->count());
    }

    // ── Custom Identifier Usage ───────────────────────────────────────────────

    public function test_string_identifier_usage(): void
    {
        // Custom identifier extending StringIdentifier
        $orderId = new class('order-123') extends \ZeroBoiler\Domain\Identifiers\StringIdentifier {};

        $this->assertSame('order-123', $orderId->toString());
        $this->assertSame('order-123', (string) $orderId);

        // fromString factory
        $copy = \ZeroBoiler\Domain\Identifiers\StringIdentifier::fromString('order-456');
        $this->assertSame('order-456', $copy->toString());
    }

    public function test_integer_identifier_usage(): void
    {
        $id = \ZeroBoiler\Domain\Identifiers\IntegerIdentifier::generate();
        $this->assertIsInt($id->value());
        $this->assertSame($id->value(), (int) (string) $id);

        // fromString
        $fromStr = \ZeroBoiler\Domain\Identifiers\IntegerIdentifier::fromString('42');
        $this->assertSame(42, $fromStr->value());

        // fromArray/toArray round-trip
        $restored = \ZeroBoiler\Domain\Identifiers\IntegerIdentifier::fromArray($id->toArray());
        $this->assertTrue($id->equals($restored));
    }

    // ── HasDomainEvents Trait Verification ──────────────────────────────────

    public function test_has_domain_events_trait_methods(): void
    {
        $ref = new \ReflectionClass('ZeroBoiler\Domain\Concerns\HasDomainEvents');

        $required = ['recordThat', 'pullDomainEvents', 'peekDomainEvents', 'clearDomainEvents', 'hasUncommittedEvents', 'domainEvents'];
        foreach ($required as $method) {
            $this->assertTrue(
                $ref->hasMethod($method),
                "HasDomainEvents trait must have {$method}() method.",
            );
        }
    }

    // ── HasSnapshots Trait Verification ──────────────────────────────────────

    public function test_has_snapshots_trait_methods(): void
    {
        $ref = new \ReflectionClass('ZeroBoiler\Domain\Concerns\HasSnapshots');

        $required = ['takeSnapshot', 'restoreFromSnapshot', 'lastSnapshotAt', 'snapshotCount', 'snapshotThreshold', 'lastSnapshotVersion'];
        foreach ($required as $method) {
            $this->assertTrue(
                $ref->hasMethod($method),
                "HasSnapshots trait must have {$method}() method.",
            );
        }
    }

    // ── EventSourced Trait Verification ───────────────────────────────────────

    public function test_event_sourced_trait_methods(): void
    {
        $ref = new \ReflectionClass('ZeroBoiler\Domain\Concerns\EventSourced');

        $required = ['applyThat', 'reconstituteFromEvents', 'getApplicableEvents', 'eventMap'];
        foreach ($required as $method) {
            $this->assertTrue(
                $ref->hasMethod($method),
                "EventSourced trait must have {$method}() method.",
            );
        }
    }
}
