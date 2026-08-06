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
use ZeroBoiler\Domain\Tests\Fixtures\TestUuidIdentifier;
use ZeroBoiler\Domain\Tests\Fixtures\TestUlidIdentifier;

/**
 * Tests for JsonSerializable consistency across all identifier types.
 *
 * Ensures every identifier can be used in json_encode() for clean API responses.
 */
final class IdentifierSerializationTest extends TestCase
{
    // ── AggregateRootId ────────────────────────────────────────────

    #[Test]
    public function aggregate_root_id_implements_json_serializable(): void
    {
        $id = AggregateRootId::generate();
        $this->assertInstanceOf(\JsonSerializable::class, $id);
    }

    #[Test]
    public function aggregate_root_id_json_encodes_to_string(): void
    {
        $id = AggregateRootId::generate();
        $json = json_encode($id);
        $this->assertIsString($json);
        $this->assertStringStartsWith('"', $json);
        $this->assertStringEndsWith('"', $json);

        // Round-trip: decoded value matches toString()
        $decoded = json_decode($json, associative: true);
        $this->assertSame($id->toString(), $decoded);
    }

    #[Test]
    public function aggregate_root_id_serializes_cleanly_in_array(): void
    {
        $id = AggregateRootId::generate();
        $result = json_encode(['order_id' => $id]);
        $decoded = json_decode($result, associative: true);

        $this->assertArrayHasKey('order_id', $decoded);
        $this->assertSame($id->toString(), $decoded['order_id']);
    }

    #[Test]
    public function aggregate_root_id_from_string_serializes_correctly(): void
    {
        $uuidString = '550e8400-e29b-41d4-a716-446655440000';
        $id = AggregateRootId::fromString($uuidString);

        $this->assertSame('"' . $uuidString . '"', json_encode($id));
    }

    // ── UuidIdentifier ────────────────────────────────────────────

    #[Test]
    public function uuid_identifier_json_encodes_to_string(): void
    {
        $id = TestUuidIdentifier::generate();
        $json = json_encode($id);

        $this->assertIsString($json);
        $this->assertStringStartsWith('"', $json);
        $this->assertSame($id->toString(), json_decode($json));
    }

    // ── UlidIdentifier ──────────────────────────────────────────────

    #[Test]
    public function ulid_identifier_json_encodes_to_string(): void
    {
        $id = TestUlidIdentifier::generate();
        $json = json_encode($id);

        $this->assertIsString($json);
        $this->assertSame($id->toString(), json_decode($json));
    }

    // ── StringIdentifier ────────────────────────────────────────────

    #[Test]
    public function string_identifier_json_encodes_to_string(): void
    {
        $id = StringIdentifier::from('my-slug');
        $json = json_encode($id);

        $this->assertSame('"my-slug"', $json);
    }

    #[Test]
    public function string_identifier_serializes_in_nested_response(): void
    {
        $id = StringIdentifier::from('post-123');
        $response = ['slug' => $id, 'count' => 42];
        $json = json_encode($response);
        $decoded = json_decode($json, associative: true);

        $this->assertSame('post-123', $decoded['slug']);
        $this->assertSame(42, $decoded['count']);
    }

    // ── IntegerIdentifier ───────────────────────────────────────────

    #[Test]
    public function integer_identifier_implements_json_serializable(): void
    {
        $id = IntegerIdentifier::from(42);
        $this->assertInstanceOf(\JsonSerializable::class, $id);
    }

    #[Test]
    public function integer_identifier_json_encodes_to_integer(): void
    {
        $id = IntegerIdentifier::from(42);
        $json = json_encode($id);

        $this->assertSame('42', $json);
        $this->assertSame(42, json_decode($json));
    }

    #[Test]
    public function integer_identifier_serializes_cleanly_in_array(): void
    {
        $id = IntegerIdentifier::from(99);
        $result = json_encode(['user_id' => $id]);
        $decoded = json_decode($result, associative: true);

        $this->assertArrayHasKey('user_id', $decoded);
        $this->assertSame(99, $decoded['user_id']);
    }

    #[Test]
    public function integer_identifier_from_string_serializes_correctly(): void
    {
        $id = IntegerIdentifier::fromString('256');
        $json = json_encode($id);

        $this->assertSame('256', $json);
        $this->assertSame(256, json_decode($json));
    }

    #[Test]
    public function integer_identifier_zero_serializes_correctly(): void
    {
        $id = IntegerIdentifier::from(0);
        $json = json_encode($id);

        $this->assertSame('0', $json);
    }

    #[Test]
    public function integer_identifier_negative_serializes_correctly(): void
    {
        $id = IntegerIdentifier::from(-5);
        $json = json_encode($id);

        $this->assertSame('-5', $json);
    }

    // ── Cross-identifier consistency ────────────────────────────────

    #[Test]
    public function all_identifiers_are_json_serializable(): void
    {
        $identifiers = [
            'aggregate_root' => AggregateRootId::generate(),
            'uuid' => TestUuidIdentifier::generate(),
            'ulid' => TestUlidIdentifier::generate(),
            'string' => StringIdentifier::from('test'),
            'integer' => IntegerIdentifier::from(1),
        ];

        foreach ($identifiers as $type => $id) {
            $this->assertInstanceOf(
                \JsonSerializable::class,
                $id,
                "{$type} identifier must implement JsonSerializable",
            );

            // Must not throw
            $json = json_encode($id);
            $this->assertNotEmpty($json, "{$type} identifier must produce non-empty JSON");
            $this->assertNotNull(json_decode($json), "{$type} identifier must produce valid JSON");
        }
    }

    #[Test]
    public function mixed_identifiers_serialize_in_single_response(): void
    {
        $data = [
            'order_id' => AggregateRootId::generate(),
            'product_id' => TestUuidIdentifier::generate(),
            'category_id' => TestUlidIdentifier::generate(),
            'slug' => StringIdentifier::from('featured'),
            'sequence' => IntegerIdentifier::from(42),
        ];

        $json = json_encode($data);
        $decoded = json_decode($json, associative: true);

        $this->assertIsArray($decoded);
        $this->assertCount(5, $decoded);

        // Integer identifier must serialize as integer, not string
        $this->assertIsInt($decoded['sequence']);
        $this->assertSame(42, $decoded['sequence']);
    }
}
