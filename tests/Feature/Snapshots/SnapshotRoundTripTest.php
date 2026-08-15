<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Tests\Feature\Snapshots;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ZeroBoiler\Domain\Snapshots\Snapshot;

#[CoversClass(Snapshot::class)]
#[Group('snapshots')]
#[Group('production')]
final class SnapshotRoundTripTest extends TestCase
{
    // ─── Factory & Accessor Tests ─────────────────────────────────────────────

    public function test_create_factory_sets_auto_timestamp(): void
    {
        $before = new \DateTimeImmutable();
        $snapshot = Snapshot::create('App\\Order', 'ord-123', 10, ['status' => 'paid']);
        $after = new \DateTimeImmutable();

        self::assertSame('App\\Order', $snapshot->aggregateType);
        self::assertSame('ord-123', $snapshot->aggregateId);
        self::assertSame(10, $snapshot->version);
        self::assertSame(['status' => 'paid'], $snapshot->state);
        self::assertGreaterThanOrEqual($before, $snapshot->createdAt);
        self::assertLessThanOrEqual($after, $snapshot->createdAt);
    }

    public function test_constructor_accepts_explicit_timestamp(): void
    {
        $ts = new \DateTimeImmutable('2025-01-15T12:00:00+00:00');
        $snapshot = new Snapshot('App\\User', 'u-1', 1, ['name' => 'John'], $ts);

        self::assertSame($ts->format(\DateTimeImmutable::ATOM), $snapshot->createdAt->format(\DateTimeImmutable::ATOM));
    }

    // ─── toArray / fromArray Round-Trip ────────────────────────────────────────

    public function test_toArray_has_expected_keys(): void
    {
        $snapshot = Snapshot::create('App\\Order', 'ord-999', 50, ['key' => 'val']);

        $array = $snapshot->toArray();

        self::assertArrayHasKey('aggregate_type', $array);
        self::assertArrayHasKey('aggregate_id', $array);
        self::assertArrayHasKey('version', $array);
        self::assertArrayHasKey('state', $array);
        self::assertArrayHasKey('created_at', $array);
    }

    public function test_fromArray_reconstructs_identical_snapshot(): void
    {
        $original = Snapshot::create('App\\Order', 'ord-456', 25, ['status' => 'shipped']);
        $array = $original->toArray();
        $restored = Snapshot::fromArray($array);

        self::assertTrue($original->equals($restored));
    }

    public function test_fromArray_round_trip_preserves_nested_state(): void
    {
        $nested = [
            'items' => [
                ['id' => 1, 'name' => 'Widget'],
                ['id' => 2, 'name' => 'Gadget'],
            ],
            'metadata' => [
                'tags' => ['premium', 'featured'],
                'counts' => ['views' => 100, 'likes' => 42],
            ],
        ];

        $original = Snapshot::create('App\\Catalog', 'cat-1', 5, $nested);
        $restored = Snapshot::fromArray($original->toArray());

        self::assertSame($nested, $restored->state);
    }

    #[DataProvider('invalidDataProvider')]
    public function test_fromArray_throws_on_invalid_data(array $data, string $expectedFragment): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedFragment);

        Snapshot::fromArray($data);
    }

    /**
     * @return array<string, array{data: array, expectedFragment: string}>
     */
    public static function invalidDataProvider(): array
    {
        return [
            'missing all keys' => [
                'data' => [],
                'expectedFragment' => 'Invalid snapshot data',
            ],
            'wrong aggregate_type type (int)' => [
                'data' => [
                    'aggregate_type' => 123,
                    'aggregate_id' => 'id',
                    'version' => 1,
                    'state' => [],
                    'created_at' => '2025-01-01T00:00:00+00:00',
                ],
                'expectedFragment' => 'expected string, string, int, array, string; got int, string, int, array, string',
            ],
            'wrong aggregate_id type (array)' => [
                'data' => [
                    'aggregate_type' => 'App\\Order',
                    'aggregate_id' => ['not', 'string'],
                    'version' => 1,
                    'state' => [],
                    'created_at' => '2025-01-01T00:00:00+00:00',
                ],
                'expectedFragment' => 'expected string, string, int, array, string; got string, array',
            ],
            'wrong version type (string)' => [
                'data' => [
                    'aggregate_type' => 'App\\Order',
                    'aggregate_id' => 'id',
                    'version' => 'not-int',
                    'state' => [],
                    'created_at' => '2025-01-01T00:00:00+00:00',
                ],
                'expectedFragment' => 'expected string, string, int, array, string; got string, string, string',
            ],
            'wrong state type (string)' => [
                'data' => [
                    'aggregate_type' => 'App\\Order',
                    'aggregate_id' => 'id',
                    'version' => 1,
                    'state' => 'not-array',
                    'created_at' => '2025-01-01T00:00:00+00:00',
                ],
                'expectedFragment' => 'expected string, string, int, array, string; got string, string, int, string',
            ],
            'wrong created_at type (int)' => [
                'data' => [
                    'aggregate_type' => 'App\\Order',
                    'aggregate_id' => 'id',
                    'version' => 1,
                    'state' => [],
                    'created_at' => 1234567890,
                ],
                'expectedFragment' => 'expected string, string, int, array, string; got string, string, int, array, int',
            ],
            'null values' => [
                'data' => [
                    'aggregate_type' => null,
                    'aggregate_id' => null,
                    'version' => null,
                    'state' => null,
                    'created_at' => null,
                ],
                'expectedFragment' => 'expected string, string, int, array, string; got null, null, null, null, null',
            ],
        ];
    }

    // ─── JSON Round-Trip ──────────────────────────────────────────────────────

    public function test_toJson_produces_valid_json(): void
    {
        $snapshot = Snapshot::create('App\\Order', 'ord-1', 5, ['total' => 99.99]);
        $json = $snapshot->toJson();

        self::assertJson($json);

        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('App\\Order', $decoded['aggregate_type']);
        self::assertSame('ord-1', $decoded['aggregate_id']);
        self::assertSame(5, $decoded['version']);
        self::assertSame(['total' => 99.99], $decoded['state']);
    }

    public function test_fromJson_reconstructs_identical_snapshot(): void
    {
        $original = Snapshot::create('App\\User', 'u-42', 100, ['email' => 'test@example.com']);
        $restored = Snapshot::fromJson($original->toJson());

        self::assertTrue($original->equals($restored));
    }

    public function test_fromJson_throws_on_invalid_json(): void
    {
        $this->expectException(\JsonException::class);

        Snapshot::fromJson('{not-valid-json}');
    }

    public function test_fromJson_throws_on_non_object_json(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('JSON must decode to an array/object.');

        Snapshot::fromJson('"just a string"');
    }

    public function test_fromJson_throws_on_array_json(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('JSON must decode to an array/object.');

        Snapshot::fromJson('[1, 2, 3]');
    }

    // ─── jsonSerialize ────────────────────────────────────────────────────────

    public function test_jsonSerialize_matches_toArray(): void
    {
        $snapshot = Snapshot::create('App\\Order', 'ord-1', 1, []);
        self::assertSame($snapshot->toArray(), $snapshot->jsonSerialize());
    }

    public function test_json_encode_uses_jsonSerialize(): void
    {
        $snapshot = Snapshot::create('App\\Order', 'ord-1', 1, ['status' => 'new']);

        $encoded = json_encode($snapshot, flags: JSON_THROW_ON_ERROR);
        $decoded = json_decode($encoded, true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('App\\Order', $decoded['aggregate_type']);
        self::assertSame('ord-1', $decoded['aggregate_id']);
        self::assertSame(['status' => 'new'], $decoded['state']);
    }

    // ─── Equality ──────────────────────────────────────────────────────────────

    public function test_equals_true_for_identical_snapshots(): void
    {
        $state = ['status' => 'paid', 'total' => 150.0];
        $a = Snapshot::create('App\\Order', 'ord-1', 10, $state);
        $b = Snapshot::create('App\\Order', 'ord-1', 10, $state);

        self::assertTrue($a->equals($b));
    }

    public function test_equals_false_for_different_type(): void
    {
        $a = Snapshot::create('App\\Order', 'id-1', 1, []);
        $b = Snapshot::create('App\\Invoice', 'id-1', 1, []);

        self::assertFalse($a->equals($b));
    }

    public function test_equals_false_for_different_id(): void
    {
        $a = Snapshot::create('App\\Order', 'id-1', 1, []);
        $b = Snapshot::create('App\\Order', 'id-2', 1, []);

        self::assertFalse($a->equals($b));
    }

    public function test_equals_false_for_different_version(): void
    {
        $a = Snapshot::create('App\\Order', 'id-1', 1, []);
        $b = Snapshot::create('App\\Order', 'id-1', 2, []);

        self::assertFalse($a->equals($b));
    }

    public function test_equals_false_for_different_state(): void
    {
        $a = Snapshot::create('App\\Order', 'id-1', 1, ['status' => 'paid']);
        $b = Snapshot::create('App\\Order', 'id-1', 1, ['status' => 'pending']);

        self::assertFalse($a->equals($b));
    }

    // ─── PHP Native Serialize (unserialize round-trip) ────────────────────────

    public function test_php_serialize_round_trip(): void
    {
        $original = Snapshot::create('App\\Order', 'ord-s1', 42, ['key' => 'value', 'nested' => ['a' => 1]]);

        /** @var Snapshot $restored */
        $restored = unserialize(serialize($original));

        self::assertInstanceOf(Snapshot::class, $restored);
        self::assertSame($original->aggregateType, $restored->aggregateType);
        self::assertSame($original->aggregateId, $restored->aggregateId);
        self::assertSame($original->version, $restored->version);
        self::assertSame($original->state, $restored->state);
    }

    // ─── Edge Cases ────────────────────────────────────────────────────────────

    public function test_empty_state(): void
    {
        $snapshot = Snapshot::create('App\\Order', 'id', 0, []);
        $restored = Snapshot::fromArray($snapshot->toArray());

        self::assertSame([], $restored->state);
    }

    public function test_version_zero(): void
    {
        $snapshot = Snapshot::create('App\\Order', 'id', 0, []);
        self::assertSame(0, $snapshot->version);
    }

    public function test_large_version_number(): void
    {
        $snapshot = Snapshot::create('App\\Order', 'id', PHP_INT_MAX, []);
        self::assertSame(PHP_INT_MAX, $snapshot->version);
    }

    public function test_state_with_special_characters(): void
    {
        $state = ['name' => 'O\'Brien "the Great"', 'emoji' => '🎉🚀', 'html' => '<p>Hello &amp; goodbye</p>'];
        $snapshot = Snapshot::create('App\\Order', 'id', 1, $state);
        $restored = Snapshot::fromJson($snapshot->toJson());

        self::assertSame($state, $restored->state);
    }

    public function test_created_at_preserves_timezone(): void
    {
        $ts = new \DateTimeImmutable('2025-06-15T14:30:00+03:00');
        $snapshot = new Snapshot('App\\Order', 'id', 1, [], $ts);
        $restored = Snapshot::fromArray($snapshot->toArray());

        self::assertSame($ts->getTimestamp(), $restored->createdAt->getTimestamp());
        self::assertSame('+03:00', $restored->createdAt->format('P'));
    }
}
