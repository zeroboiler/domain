<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Tests\Unit\Snapshots;

use PHPUnit\Framework\TestCase;
use ZeroBoiler\Domain\Snapshots\Snapshot;

/**
 * @covers \ZeroBoiler\Domain\Snapshots\Snapshot
 */
final class SnapshotTest extends TestCase
{
    private Snapshot $snapshot;

    protected function setUp(): void
    {
        $this->snapshot = Snapshot::create(
            aggregateType: 'App\\Domain\\Order',
            aggregateId: 'order-123',
            version: 42,
            state: ['status' => 'shipped', 'total' => 2999],
        );
    }

    // ── Immutability & Construction ───────────────────────────────────

    public function test_create_returns_immutable_instance(): void
    {
        $s = $this->snapshot;

        self::assertInstanceOf(Snapshot::class, $s);
        self::assertSame('App\Domain\Order', $s->aggregateType);
        self::assertSame('order-123', $s->aggregateId);
        self::assertSame(42, $s->version);
        self::assertSame(['status' => 'shipped', 'total' => 2999], $s->state);
        self::assertInstanceOf(\DateTimeImmutable::class, $s->createdAt);
    }

    public function test_readonly_class_cannot_be_mutated(): void
    {
        $reflection = new \ReflectionClass(Snapshot::class);

        self::assertTrue($reflection->isReadOnly());
    }

    // ── Serialization: toArray ────────────────────────────────────────

    public function test_toArray_has_expected_keys(): void
    {
        $array = $this->snapshot->toArray();

        self::assertArrayHasKey('aggregate_type', $array);
        self::assertArrayHasKey('aggregate_id', $array);
        self::assertArrayHasKey('version', $array);
        self::assertArrayHasKey('state', $array);
        self::assertArrayHasKey('created_at', $array);
    }

    public function test_toArray_preserves_values(): void
    {
        $array = $this->snapshot->toArray();

        self::assertSame('App\Domain\Order', $array['aggregate_type']);
        self::assertSame('order-123', $array['aggregate_id']);
        self::assertSame(42, $array['version']);
        self::assertSame(['status' => 'shipped', 'total' => 2999], $array['state']);
    }

    // ── Deserialization: fromArray ───────────────────────────────────

    public function test_fromArray_restores_snapshot(): void
    {
        $restored = Snapshot::fromArray($this->snapshot->toArray());

        self::assertTrue($this->snapshot->equals($restored));
        self::assertSame($this->snapshot->aggregateType, $restored->aggregateType);
        self::assertSame($this->snapshot->aggregateId, $restored->aggregateId);
        self::assertSame($this->snapshot->version, $restored->version);
        self::assertSame($this->snapshot->state, $restored->state);
    }

    public function test_fromArray_throws_on_invalid_types(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Snapshot::fromArray([
            'aggregate_type' => 123, // should be string
            'aggregate_id' => 'id',
            'version' => 1,
            'state' => [],
            'created_at' => '2025-01-01T00:00:00+00:00',
        ]);
    }

    public function test_fromArray_throws_on_missing_keys(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Snapshot::fromArray([]);
    }

    // ── JSON Round-trip ──────────────────────────────────────────────

    public function test_jsonSerialize_returns_array(): void
    {
        $json = json_encode($this->snapshot);

        self::assertIsString($json);
        self::assertNotEmpty($json);

        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertSame('App\Domain\Order', $decoded['aggregate_type']);
    }

    public function test_fromJson_restores_snapshot(): void
    {
        $json = json_encode($this->snapshot, flags: JSON_THROW_ON_ERROR);
        $restored = Snapshot::fromJson($json);

        self::assertTrue($this->snapshot->equals($restored));
    }

    public function test_fromJson_throws_on_invalid_json(): void
    {
        $this->expectException(\JsonException::class);

        Snapshot::fromJson('not-valid-json');
    }

    public function test_fromJson_throws_on_non_object_json(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Snapshot::fromJson('"just-a-string"');
    }

    // ── Equality ─────────────────────────────────────────────────────

    public function test_equals_same_snapshot(): void
    {
        $copy = Snapshot::create(
            aggregateType: 'App\\Domain\\Order',
            aggregateId: 'order-123',
            version: 42,
            state: ['status' => 'shipped', 'total' => 2999],
        );

        self::assertTrue($this->snapshot->equals($copy));
    }

    public function test_equals_different_type(): void
    {
        $other = Snapshot::create(
            aggregateType: 'App\\Domain\\Invoice',
            aggregateId: 'order-123',
            version: 42,
            state: ['status' => 'shipped', 'total' => 2999],
        );

        self::assertFalse($this->snapshot->equals($other));
    }

    public function test_equals_different_version(): void
    {
        $other = Snapshot::create(
            aggregateType: 'App\\Domain\\Order',
            aggregateId: 'order-123',
            version: 43,
            state: ['status' => 'shipped', 'total' => 2999],
        );

        self::assertFalse($this->snapshot->equals($other));
    }

    public function test_equals_different_state(): void
    {
        $other = Snapshot::create(
            aggregateType: 'App\\Domain\\Order',
            aggregateId: 'order-123',
            version: 42,
            state: ['status' => 'cancelled', 'total' => 2999],
        );

        self::assertFalse($this->snapshot->equals($other));
    }

    // ── Native PHP Serialize ─────────────────────────────────────────

    public function test_php_serialize_roundtrip(): void
    {
        $serialized = serialize($this->snapshot);
        $restored = unserialize($serialized);

        self::assertInstanceOf(Snapshot::class, $restored);
        self::assertTrue($this->snapshot->equals($restored));
    }
}
