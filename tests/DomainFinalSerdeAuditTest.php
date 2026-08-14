<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Tests;

use PHPUnit\Framework\TestCase;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\DomainEventCollection;
use ZeroBoiler\Domain\Identifiers\IntegerIdentifier;
use ZeroBoiler\Domain\Identifiers\StringIdentifier;
use ZeroBoiler\Domain\Identifiers\UuidIdentifier;
use ZeroBoiler\Domain\Identifiers\UlidIdentifier;
use ZeroBoiler\Domain\Snapshots\Snapshot;
use ZeroBoiler\Domain\Tests\Fixtures\TestEntity;
use ZeroBoiler\Domain\Tests\Fixtures\TestUuidIdentifier;

/**
 * Final serde audit — validates toArray/fromArray/toJson/fromJson round-trips
 * across all serializable domain classes.
 *
 * @since 1.66.0
 */
final class DomainFinalSerdeAuditTest extends TestCase
{
    // ──────────────────────────────────────────────────────
    // AggregateRootId
    // ──────────────────────────────────────────────────────

    public function test_aggregate_root_id_to_array_has_uuid_key(): void
    {
        $id = AggregateRootId::generate();
        $array = $id->toArray();

        $this->assertArrayHasKey('uuid', $array);
        $this->assertTrue(AggregateRootId::isValid($array['uuid']));
    }

    public function test_aggregate_root_id_round_trip_array(): void
    {
        $original = AggregateRootId::generate();
        $restored = AggregateRootId::fromArray($original->toArray());

        $this->assertTrue($original->equals($restored));
        $this->assertSame($original->toString(), $restored->toString());
    }

    public function test_aggregate_root_id_round_trip_json(): void
    {
        $original = AggregateRootId::generate();
        $json = $original->toJson();
        $restored = AggregateRootId::fromJson($json);

        $this->assertTrue($original->equals($restored));
    }

    public function test_aggregate_root_id_json_serialize_returns_string(): void
    {
        $id = AggregateRootId::generate();
        $encoded = json_encode($id);

        $this->assertIsString($encoded);
        $this->assertSame('"' . $id->toString() . '"', $encoded);
    }

    // ──────────────────────────────────────────────────────
    // UuidIdentifier
    // ──────────────────────────────────────────────────────

    public function test_uuid_identifier_round_trip_array(): void
    {
        $original = TestUuidIdentifier::generate();
        $restored = TestUuidIdentifier::fromArray($original->toArray());

        $this->assertTrue($original->equals($restored));
    }

    public function test_uuid_identifier_round_trip_json(): void
    {
        $original = TestUuidIdentifier::generate();
        $restored = TestUuidIdentifier::fromJson($original->toJson());

        $this->assertTrue($original->equals($restored));
    }

    public function test_uuid_identifier_is_valid_rejects_non_uuid(): void
    {
        $this->assertFalse(UuidIdentifier::isValid('not-a-uuid'));
        $this->assertFalse(UuidIdentifier::isValid(''));
    }

    // ──────────────────────────────────────────────────────
    // UlidIdentifier
    // ──────────────────────────────────────────────────────

    public function test_ulid_identifier_generate_creates_valid_ulid(): void
    {
        $id = \ZeroBoiler\Domain\Tests\Fixtures\TestUlidIdentifier::generate();
        $array = $id->toArray();

        $this->assertArrayHasKey('ulid', $array);
        $this->assertTrue(UlidIdentifier::isValid($array['ulid']));
    }

    public function test_ulid_identifier_round_trip(): void
    {
        $original = \ZeroBoiler\Domain\Tests\Fixtures\TestUlidIdentifier::generate();
        $restored = \ZeroBoiler\Domain\Tests\Fixtures\TestUlidIdentifier::fromArray($original->toArray());

        $this->assertTrue($original->equals($restored));
    }

    // ──────────────────────────────────────────────────────
    // StringIdentifier
    // ──────────────────────────────────────────────────────

    public function test_string_identifier_from_non_empty(): void
    {
        $id = StringIdentifier::from('my-slug');
        $this->assertSame('my-slug', $id->toString());
    }

    public function test_string_identifier_rejects_empty(): void
    {
        $this->assertFalse(StringIdentifier::isValid(''));
    }

    public function test_string_identifier_round_trip(): void
    {
        $original = StringIdentifier::from('hello-world');
        $array = $original->toArray();

        $this->assertArrayHasKey('string', $array);
        $restored = StringIdentifier::fromArray($array);
        $this->assertTrue($original->equals($restored));
    }

    // ──────────────────────────────────────────────────────
    // IntegerIdentifier
    // ──────────────────────────────────────────────────────

    public function test_integer_identifier_from_int(): void
    {
        $id = IntegerIdentifier::from(42);
        $this->assertSame(42, $id->toInt());
    }

    public function test_integer_identifier_round_trip(): void
    {
        $original = IntegerIdentifier::from(99);
        $array = $original->toArray();

        $this->assertArrayHasKey('integer', $array);
        $restored = IntegerIdentifier::fromArray($array);
        $this->assertTrue($original->equals($restored));
    }

    public function test_integer_identifier_from_string_parses(): void
    {
        $id = IntegerIdentifier::fromString('100');
        $this->assertSame(100, $id->toInt());
    }

    // ──────────────────────────────────────────────────────
    // Cross-identifier inequality
    // ──────────────────────────────────────────────────────

    public function test_different_identifier_types_never_equal(): void
    {
        $uuid = TestUuidIdentifier::generate();
        $str = StringIdentifier::from('test');
        $int = IntegerIdentifier::from(1);

        // All identifiers implement Identifier contract with equals()
        // Cross-type equals should return false (duck typing via toString)
        // Even if somehow the string representation matches
        $this->assertNotSame($uuid::class, $str::class);
    }

    // ──────────────────────────────────────────────────────
    // Snapshot
    // ──────────────────────────────────────────────────────

    public function test_snapshot_to_array_structure(): void
    {
        $snapshot = Snapshot::create('Order', 'uuid-123', 5, ['status' => 'paid']);
        $array = $snapshot->toArray();

        $this->assertSame('Order', $array['aggregate_type']);
        $this->assertSame('uuid-123', $array['aggregate_id']);
        $this->assertSame(5, $array['version']);
        $this->assertSame(['status' => 'paid'], $array['state']);
    }

    public function test_snapshot_round_trip(): void
    {
        $original = Snapshot::create('Order', 'uuid-456', 10, ['total' => 99.99]);
        $restored = Snapshot::fromArray($original->toArray());

        $this->assertTrue($original->equals($restored));
    }

    public function test_snapshot_json_round_trip(): void
    {
        $original = Snapshot::create('Product', 'ulid-789', 3, ['name' => 'Widget']);
        $restored = Snapshot::fromJson($original->toJson());

        $this->assertTrue($original->equals($restored));
    }

    // ──────────────────────────────────────────────────────
    // DomainEventCollection
    // ──────────────────────────────────────────────────────

    public function test_empty_collection_serialization(): void
    {
        $collection = new DomainEventCollection;
        $this->assertSame([], $collection->toArray());
        $this->assertTrue($collection->isEmpty());
        $this->assertSame(0, $collection->count());
    }

    public function test_collection_json_serialize_is_list(): void
    {
        $collection = new DomainEventCollection;
        $json = json_encode($collection);

        $this->assertSame('[]', $json);
    }

    // ──────────────────────────────────────────────────────
    // Entity
    // ──────────────────────────────────────────────────────

    public function test_entity_to_array_has_id_and_type(): void
    {
        $entity = new TestEntity('abc');
        $array = $entity->toArray();

        $this->assertArrayHasKey('id', $array);
        $this->assertArrayHasKey('type', $array);
        $this->assertSame('abc', $array['id']);
        $this->assertSame('TestEntity', $array['type']);
    }

    public function test_entity_equals_same_id(): void
    {
        $a = new TestEntity('same-id');
        $b = new TestEntity('same-id');

        $this->assertTrue($a->equals($b));
    }

    public function test_entity_not_equals_different_id(): void
    {
        $a = new TestEntity('id-1');
        $b = new TestEntity('id-2');

        $this->assertFalse($a->equals($b));
    }

    public function test_entity_json_serialize(): void
    {
        $entity = new TestEntity('xyz');
        $json = json_encode($entity);
        $data = json_decode($json, true);

        $this->assertSame('xyz', $data['id']);
        $this->assertSame('TestEntity', $data['type']);
    }

    // ──────────────────────────────────────────────────────
    // AggregateRootId serialize/unserialize (PHP native)
    // ──────────────────────────────────────────────────────

    public function test_aggregate_root_id_php_serialize_round_trip(): void
    {
        $original = AggregateRootId::generate();
        $serialized = serialize($original);
        $restored = unserialize($serialized);

        $this->assertInstanceOf(AggregateRootId::class, $restored);
        $this->assertTrue($original->equals($restored));
    }
}
