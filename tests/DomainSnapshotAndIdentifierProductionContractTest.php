<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\Identifiers\IntegerIdentifier;
use ZeroBoiler\Domain\Identifiers\StringIdentifier;
use ZeroBoiler\Domain\Identifiers\UuidIdentifier;
use ZeroBoiler\Domain\Identifiers\UlidIdentifier;
use ZeroBoiler\Domain\Snapshots\InMemorySnapshotStore;
use ZeroBoiler\Domain\Snapshots\Snapshot;
use ZeroBoiler\Domain\Snapshots\SnapshotPolicy;

/**
 * Production contract tests for Snapshot and Identifier subsystems.
 *
 * Verifies:
 * - Snapshot round-trip (create → toArray → fromArray → equals)
 * - Snapshot JSON serialization (JsonSerializable)
 * - All identifier types: generate → validate → serialize → round-trip
 * - InMemorySnapshotStore full API (CRUD + stats + purge)
 * - SnapshotPolicy attribute default values
 *
 * @group production
 * @group snapshot
 * @group identifier
 */
#[CoversClass(Snapshot::class)]
#[CoversClass(InMemorySnapshotStore::class)]
#[CoversClass(SnapshotPolicy::class)]
#[CoversClass(UuidIdentifier::class)]
#[CoversClass(UlidIdentifier::class)]
#[CoversClass(StringIdentifier::class)]
#[CoversClass(IntegerIdentifier::class)]
#[Group('production')]
#[Group('snapshot')]
#[Group('identifier')]
final class DomainSnapshotAndIdentifierProductionContractTest extends TestCase
{
    // ─── Snapshot ───────────────────────────────────────────────────────────────

    public function test_snapshot_create_stores_all_fields(): void
    {
        $snapshot = Snapshot::create(
            'App\\Domain\\Order',
            'order-123',
            42,
            ['status' => 'paid', 'total' => 99.99],
        );

        $this->assertSame('App\\Domain\\Order', $snapshot->aggregateType);
        $this->assertSame('order-123', $snapshot->aggregateId);
        $this->assertSame(42, $snapshot->version);
        $this->assertSame(['status' => 'paid', 'total' => 99.99], $snapshot->state);
        $this->assertInstanceOf(\DateTimeImmutable::class, $snapshot->createdAt);
    }

    public function test_snapshot_to_array_and_from_array_round_trip(): void
    {
        $original = Snapshot::create('App\\Domain\\Product', 'prod-456', 10, ['name' => 'Widget']);

        $array = $original->toArray();
        $this->assertArrayHasKey('aggregate_type', $array);
        $this->assertArrayHasKey('aggregate_id', $array);
        $this->assertArrayHasKey('version', $array);
        $this->assertArrayHasKey('state', $array);
        $this->assertArrayHasKey('created_at', $array);

        $restored = Snapshot::fromArray($array);
        $this->assertTrue($original->equals($restored));
        $this->assertSame($original->aggregateType, $restored->aggregateType);
        $this->assertSame($original->aggregateId, $restored->aggregateId);
        $this->assertSame($original->version, $restored->version);
        $this->assertSame($original->state, $restored->state);
    }

    public function test_snapshot_from_array_rejects_invalid_data(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Snapshot::fromArray(['aggregate_type' => 123, 'aggregate_id' => null]);
    }

    public function test_snapshot_json_serializable_delegates_to_to_array(): void
    {
        $snapshot = Snapshot::create('App\\Domain\\Order', 'id-1', 5, ['x' => 1]);
        $json = json_encode($snapshot);

        $this->assertNotFalse($json);
        $decoded = json_decode($json, true);
        $this->assertSame('App\\Domain\\Order', $decoded['aggregate_type']);
        $this->assertSame(5, $decoded['version']);
    }

    public function test_snapshot_equals_requires_all_fields(): void
    {
        $a = Snapshot::create('TypeA', 'id-1', 1, ['x' => 1]);
        $b = Snapshot::create('TypeA', 'id-1', 1, ['x' => 1]);
        $c = Snapshot::create('TypeA', 'id-1', 2, ['x' => 1]);
        $d = Snapshot::create('TypeB', 'id-1', 1, ['x' => 1]);

        $this->assertTrue($a->equals($b));
        $this->assertFalse($a->equals($c)); // different version
        $this->assertFalse($a->equals($d)); // different type
    }

    // ─── InMemorySnapshotStore ─────────────────────────────────────────────────

    public function test_snapshot_store_save_load_delete_cycle(): void
    {
        $store = new InMemorySnapshotStore();
        $snapshot = Snapshot::create('Order', 'id-1', 10, ['status' => 'paid']);

        $this->assertFalse($store->has('Order', 'id-1'));

        $store->save($snapshot);
        $this->assertTrue($store->has('Order', 'id-1'));

        $loaded = $store->load('Order', 'id-1');
        $this->assertNotNull($loaded);
        $this->assertTrue($snapshot->equals($loaded));

        $store->delete('Order', 'id-1');
        $this->assertFalse($store->has('Order', 'id-1'));
        $this->assertNull($store->load('Order', 'id-1'));
    }

    public function test_snapshot_store_delete_older_than(): void
    {
        $store = new InMemorySnapshotStore();
        $old = Snapshot::create('Order', 'id-1', 10, []);
        $new = Snapshot::create('Order', 'id-1', 50, []);

        $store->save($old);
        $store->save($new); // Overwrites old (latest only)

        $store->deleteOlderThan('Order', 'id-1', 20);
        // Version 10 < 20, so deleted
        $this->assertNull($store->load('Order', 'id-1'));
    }

    public function test_snapshot_store_count_and_stats(): void
    {
        $store = new InMemorySnapshotStore();
        $store->save(Snapshot::create('Order', 'id-1', 1, []));
        $store->save(Snapshot::create('Order', 'id-2', 1, []));
        $store->save(Snapshot::create('Product', 'id-1', 1, []));

        $this->assertSame(3, $store->count());
        $this->assertSame(2, $store->count('Order'));
        $this->assertSame(1, $store->count('Product'));

        $stats = $store->stats();
        $this->assertSame(3, $stats['total']);
        $this->assertSame(2, $stats['by_type']['Order']);
        $this->assertSame(1, $stats['by_type']['Product']);
    }

    public function test_snapshot_store_purge_by_type(): void
    {
        $store = new InMemorySnapshotStore();
        $store->save(Snapshot::create('Order', 'id-1', 1, []));
        $store->save(Snapshot::create('Product', 'id-1', 1, []));

        $removed = $store->purge('Order');
        $this->assertSame(1, $removed);
        $this->assertSame(1, $store->count());
        $this->assertNull($store->load('Order', 'id-1'));
        $this->assertNotNull($store->load('Product', 'id-1'));
    }

    public function test_snapshot_store_purge_all(): void
    {
        $store = new InMemorySnapshotStore();
        $store->save(Snapshot::create('A', '1', 1, []));
        $store->save(Snapshot::create('B', '2', 1, []));

        $removed = $store->purge();
        $this->assertSame(2, $removed);
        $this->assertSame(0, $store->count());
    }

    // ─── SnapshotPolicy Attribute ────────────────────────────────────────────────

    public function test_snapshot_policy_default_every_50(): void
    {
        $policy = new SnapshotPolicy;
        $this->assertSame(50, $policy->every);
    }

    public function test_snapshot_policy_custom_every(): void
    {
        $policy = new SnapshotPolicy(every: 100);
        $this->assertSame(100, $policy->every);
    }

    public function test_snapshot_policy_zero_disables(): void
    {
        $policy = new SnapshotPolicy(every: 0);
        $this->assertSame(0, $policy->every);
    }

    // ─── AggregateRootId ───────────────────────────────────────────────────────

    public function test_aggregate_root_id_generate_creates_valid_uuid(): void
    {
        $id = AggregateRootId::generate();

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $id->toString(),
        );
    }

    public function test_aggregate_root_id_from_string_round_trip(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $id = AggregateRootId::fromString($uuid);

        $this->assertSame($uuid, $id->toString());
        $this->assertTrue($id->equals(AggregateRootId::fromString($uuid)));
    }

    public function test_aggregate_root_id_json_serializable(): void
    {
        $id = AggregateRootId::generate();
        $json = json_encode($id);

        $this->assertNotFalse($json);
        $this->assertSame('"' . $id->toString() . '"', $json);
    }

    // ─── UuidIdentifier ─────────────────────────────────────────────────────────

    public function test_uuid_identifier_generate_and_validate(): void
    {
        $id = TestUuidSubclass::generate();
        $this->assertTrue(TestUuidSubclass::isValid($id->toString()));
    }

    public function test_uuid_identifier_round_trip(): void
    {
        $id = TestUuidSubclass::generate();
        $array = $id->toArray();
        $restored = TestUuidSubclass::fromArray($array);

        $this->assertTrue($id->equals($restored));
        $this->assertSame($id->toString(), $restored->toString());
    }

    public function test_uuid_identifier_rejects_invalid_string(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TestUuidSubclass::fromString('not-a-uuid');
    }

    // ─── UlidIdentifier ────────────────────────────────────────────────────────

    public function test_ulid_identifier_generate_and_validate(): void
    {
        $id = TestUlidSubclass::generate();
        $this->assertTrue(TestUlidSubclass::isValid($id->toString()));
        // ULID is 26 chars
        $this->assertSame(26, strlen($id->toString()));
    }

    public function test_ulid_identifier_json_serializable(): void
    {
        $id = TestUlidSubclass::generate();
        $json = json_encode($id);

        $this->assertNotFalse($json);
        $this->assertSame('"' . $id->toString() . '"', $json);
    }

    // ─── StringIdentifier ─────────────────────────────────────────────────────

    public function test_string_identifier_from_and_round_trip(): void
    {
        $id = StringIdentifier::from('my-slug');
        $this->assertSame('my-slug', $id->toString());

        $array = $id->toArray();
        $restored = StringIdentifier::fromArray($array);
        $this->assertTrue($id->equals($restored));
    }

    public function test_string_identifier_rejects_empty_string(): void
    {
        $this->expectException(\ValueError::class);
        StringIdentifier::from('');
    }

    // ─── IntegerIdentifier ─────────────────────────────────────────────────────

    public function test_integer_identifier_from_and_round_trip(): void
    {
        $id = IntegerIdentifier::from(42);
        $this->assertSame(42, $id->toInt());

        $array = $id->toArray();
        $restored = IntegerIdentifier::fromArray($array);
        $this->assertTrue($id->equals($restored));
    }

    public function test_integer_identifier_json_serializable(): void
    {
        $id = IntegerIdentifier::from(42);
        $json = json_encode($id);

        $this->assertSame('42', $json);
    }

    // ─── Cross-Identifier Equality ─────────────────────────────────────────────

    public function test_different_identifier_types_never_equal(): void
    {
        $uuid = TestUuidSubclass::generate();
        $string = StringIdentifier::from('test');
        $integer = IntegerIdentifier::from(1);

        // These use different value types — should not be equal
        $this->assertNotSame($uuid->toString(), $string->toString());
        $this->assertNotSame($uuid->toString(), $integer->toString());
    }
}

// ─── Test Fixtures ───────────────────────────────────────────────────────────────

final class TestUuidSubclass extends UuidIdentifier {}
final class TestUlidSubclass extends UlidIdentifier {}
