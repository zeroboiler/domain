<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Tests;

use PHPUnit\Framework\Attributes\Test;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\Identifiers\IntegerIdentifier;
use ZeroBoiler\Domain\Identifiers\StringIdentifier;
use ZeroBoiler\Domain\Snapshots\InMemorySnapshotStore;
use ZeroBoiler\Domain\Snapshots\Snapshot;
use ZeroBoiler\Domain\Tests\Fixtures\TestUuidIdentifier;
use ZeroBoiler\Domain\Tests\Fixtures\TestUlidIdentifier;

/**
 * Tests for JSON serialization of core domain value objects.
 */
final class DomainSerializationTest extends TestCase
{
    // ── AggregateRootId equality & serialization ───────────────────

    #[Test]
    public function aggregate_root_id_equality_works(): void
    {
        $id1 = AggregateRootId::generate();
        $id2 = AggregateRootId::fromString($id1->toString());
        $id3 = AggregateRootId::generate();

        $this->assertTrue($id1->equals($id2));
        $this->assertFalse($id1->equals($id3));
    }

    #[Test]
    public function aggregate_root_id_stringable(): void
    {
        $id = AggregateRootId::generate();
        $result = (string) $id;
        $this->assertSame($id->toString(), $result);
    }

    // ── IntegerIdentifier equality ─────────────────────────────────

    #[Test]
    public function integer_identifier_equality_works(): void
    {
        $id1 = IntegerIdentifier::from(42);
        $id2 = IntegerIdentifier::fromString('42');
        $id3 = IntegerIdentifier::from(43);

        $this->assertTrue($id1->equals($id2));
        $this->assertFalse($id1->equals($id3));
    }

    #[Test]
    public function integer_identifier_is_valid(): void
    {
        $this->assertTrue(IntegerIdentifier::isValid('42'));
        $this->assertTrue(IntegerIdentifier::isValid('0'));
        $this->assertTrue(IntegerIdentifier::isValid('-5'));
        $this->assertFalse(IntegerIdentifier::isValid('abc'));
        $this->assertFalse(IntegerIdentifier::isValid('12.5'));
        $this->assertFalse(IntegerIdentifier::isValid(''));
    }

    #[Test]
    public function integer_identifier_from_string_validates(): void
    {
        $id = IntegerIdentifier::fromString('42');
        $this->assertSame(42, $id->toInt());
        $this->assertSame('42', $id->toString());
    }

    // ── StringIdentifier equality ──────────────────────────────────

    #[Test]
    public function string_identifier_equality_works(): void
    {
        $id1 = StringIdentifier::from('hello');
        $id2 = StringIdentifier::from('hello');
        $id3 = StringIdentifier::from('world');

        $this->assertTrue($id1->equals($id2));
        $this->assertFalse($id1->equals($id3));
    }

    // ── Snapshot serialization ──────────────────────────────────────

    #[Test]
    public function snapshot_implements_json_serializable(): void
    {
        $snapshot = Snapshot::create(
            'App\\Domain\\Order',
            'order-uuid-123',
            5,
            ['status' => 'completed', 'total' => 99.99],
        );

        $this->assertInstanceOf(\JsonSerializable::class, $snapshot);
    }

    #[Test]
    public function snapshot_json_serializes_to_array(): void
    {
        $snapshot = Snapshot::create(
            'App\\Domain\\Order',
            'order-uuid-123',
            5,
            ['status' => 'completed', 'total' => 99.99],
        );

        $json = json_encode($snapshot);
        $decoded = json_decode($json, associative: true);

        $this->assertIsArray($decoded);
        $this->assertSame('App\\Domain\\Order', $decoded['aggregate_type']);
        $this->assertSame('order-uuid-123', $decoded['aggregate_id']);
        $this->assertSame(5, $decoded['version']);
        $this->assertSame(['status' => 'completed', 'total' => 99.99], $decoded['state']);
        $this->assertArrayHasKey('created_at', $decoded);
    }

    #[Test]
    public function snapshot_round_trip_from_array(): void
    {
        $original = Snapshot::create(
            'App\\Domain\\Order',
            'order-uuid-456',
            10,
            ['items' => 3],
        );

        $array = $original->toArray();
        $restored = Snapshot::fromArray($array);

        $this->assertSame($original->aggregateType, $restored->aggregateType);
        $this->assertSame($original->aggregateId, $restored->aggregateId);
        $this->assertSame($original->version, $restored->version);
        $this->assertSame($original->state, $restored->state);
        $this->assertSame($original->createdAt->format('c'), $restored->createdAt->format('c'));
    }

    // ── DomainEventCollection serialization ───────────────────────

    #[Test]
    public function domain_event_collection_implements_json_serializable(): void
    {
        $collection = new \ZeroBoiler\Domain\DomainEventCollection([]);
        $this->assertInstanceOf(\JsonSerializable::class, $collection);
    }

    #[Test]
    public function domain_event_collection_empty_serializes(): void
    {
        $collection = new \ZeroBoiler\Domain\DomainEventCollection([]);
        $json = json_encode($collection);
        $decoded = json_decode($json, associative: true);

        $this->assertIsArray($decoded);
        $this->assertEmpty($decoded);
    }

    #[Test]
    public function domain_event_collection_count_and_iteration(): void
    {
        $collection = new \ZeroBoiler\Domain\DomainEventCollection([]);

        $this->assertCount(0, $collection);
        $this->assertTrue($collection->isEmpty());
        $this->assertSame([], $collection->all());
    }

    // ── InMemorySnapshotStore ──────────────────────────────────────

    #[Test]
    public function in_memory_snapshot_store_save_and_load(): void
    {
        $store = new InMemorySnapshotStore();
        $snapshot = Snapshot::create('App\\Order', 'id-1', 1, ['x' => 1]);

        $store->save($snapshot);
        $loaded = $store->load('App\\Order', 'id-1');

        $this->assertNotNull($loaded);
        $this->assertSame($snapshot->version, $loaded->version);
        $this->assertSame($snapshot->state, $loaded->state);
    }

    #[Test]
    public function in_memory_snapshot_store_load_nonexistent(): void
    {
        $store = new InMemorySnapshotStore();
        $loaded = $store->load('App\\Order', 'nonexistent');

        $this->assertNull($loaded);
    }

    #[Test]
    public function in_memory_snapshot_store_stats(): void
    {
        $store = new InMemorySnapshotStore();

        $store->save(Snapshot::create('App\\Order', 'id-1', 1, []));
        $store->save(Snapshot::create('App\\Order', 'id-2', 2, []));
        $store->save(Snapshot::create('App\\User', 'id-1', 1, []));

        $stats = $store->stats();

        $this->assertSame(3, $stats['total']);
        $this->assertSame(2, $stats['by_type']['App\\Order']);
        $this->assertSame(1, $stats['by_type']['App\\User']);
    }

    #[Test]
    public function in_memory_snapshot_store_overwrites_latest(): void
    {
        $store = new InMemorySnapshotStore();

        $store->save(Snapshot::create('App\\Order', 'id-1', 1, ['v' => 1]));
        $store->save(Snapshot::create('App\\Order', 'id-1', 2, ['v' => 2]));

        $loaded = $store->load('App\\Order', 'id-1');
        $this->assertNotNull($loaded);
        $this->assertSame(2, $loaded->version);
        $this->assertSame(['v' => 2], $loaded->state);
    }
}
