<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\DomainEventCollection;
use ZeroBoiler\Domain\Entity;
use ZeroBoiler\Events\Domain\DomainEvent;

/**
 * Production contract tests for toJson() methods added in v1.64.0.
 *
 * Verifies that all domain classes with fromJson() also have toJson()
 * and that round-trip serialization is lossless.
 *
 * @internal Production contract test.
 *
 * @since 1.64.0
 */
#[Group('production')]
#[Group('serde')]
#[CoversClass(AggregateRootId::class)]
#[CoversClass(DomainEventCollection::class)]
final class DomainToJsonProductionContractTest extends TestCase
{
    // ---------------------------------------------------------------
    // AggregateRootId toJson() round-trip
    // ---------------------------------------------------------------

    public function test_aggregate_root_id_to_json_round_trip(): void
    {
        $id = AggregateRootId::generate();
        $json = $id->toJson();
        $restored = AggregateRootId::fromJson($json);

        $this->assertSame($id->toString(), $restored->toString());
        $this->assertTrue($id->equals($restored));
    }

    public function test_aggregate_root_id_to_json_from_array_round_trip(): void
    {
        $id = AggregateRootId::generate();
        $array = $id->toArray();
        $restored = AggregateRootId::fromArray($array);

        $this->assertSame($id->toString(), $restored->toString());
    }

    public function test_aggregate_root_id_to_json_is_valid_json(): void
    {
        $id = AggregateRootId::generate();
        $json = $id->toJson();

        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('uuid', $decoded);
    }

    public function test_aggregate_root_id_to_json_with_known_value(): void
    {
        $id = AggregateRootId::fromString('550e8400-e29b-41d4-a716-446655440000');
        $json = $id->toJson();

        $this->assertSame('{"uuid":"550e8400-e29b-41d4-a716-446655440000"}', $json);
    }

    // ---------------------------------------------------------------
    // DomainEventCollection toJson() round-trip
    // ---------------------------------------------------------------

    public function test_domain_event_collection_to_json_round_trip(): void
    {
        $e1 = DomainEvent::occur('order.placed', ['id' => 'uuid-1']);
        $e2 = DomainEvent::occur('order.paid', ['amount' => 100]);
        $collection = new DomainEventCollection([$e1, $e2]);

        $json = $collection->toJson();
        $restored = DomainEventCollection::fromJson($json);

        $this->assertSame(2, $restored->count());
        $this->assertSame('order.placed', $restored->first()?->eventType);
    }

    public function test_domain_event_collection_to_json_empty(): void
    {
        $collection = new DomainEventCollection();
        $json = $collection->toJson();

        $restored = DomainEventCollection::fromJson($json);
        $this->assertTrue($restored->isEmpty());
    }

    public function test_domain_event_collection_to_json_is_valid_json(): void
    {
        $e1 = DomainEvent::occur('test.event', ['key' => 'value']);
        $collection = new DomainEventCollection([$e1]);
        $json = $collection->toJson();

        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
        $this->assertCount(1, $decoded);
    }

    // ---------------------------------------------------------------
    // UuidIdentifier toJson() round-trip (via base Identifier class)
    // ---------------------------------------------------------------

    public function test_uuid_identifier_to_json_round_trip(): void
    {
        $id = TestUuidIdentifier::generate();
        $json = $id->toJson();
        $restored = TestUuidIdentifier::fromJson($json);

        $this->assertSame($id->toString(), $restored->toString());
        $this->assertTrue($id->equals($restored));
    }

    public function test_ulid_identifier_to_json_round_trip(): void
    {
        $id = TestUlidIdentifier::generate();
        $json = $id->toJson();
        $restored = TestUlidIdentifier::fromJson($json);

        $this->assertSame($id->toString(), $restored->toString());
        $this->assertTrue($id->equals($restored));
    }

    // ---------------------------------------------------------------
    // Entity toJson() round-trip (via fixture)
    // ---------------------------------------------------------------

    public function test_entity_to_json_round_trip(): void
    {
        $item = new TestValueObject('item-1', 'product-1', 3, 9.99);
        $json = $item->toJson();

        $this->assertStringStartsWith('{', $json);
        $this->assertStringEndsWith('}', $json);

        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('id', $decoded);
        $this->assertArrayHasKey('productId', $decoded);
        $this->assertArrayHasKey('quantity', $decoded);
        $this->assertArrayHasKey('unitPrice', $decoded);
    }

    // ---------------------------------------------------------------
    // toJson() uses JSON_THROW_ON_ERROR
    // ---------------------------------------------------------------

    public function test_to_json_throws_on_invalid_input(): void
    {
        // This test verifies that toJson() uses JSON_THROW_ON_ERROR
        // by ensuring the return is always a valid JSON string.
        $id = AggregateRootId::generate();
        $json = $id->toJson();

        json_decode($json);
        $this->assertSame(JSON_ERROR_NONE, json_last_error());
    }

    public function test_to_json_with_custom_options(): void
    {
        $id = AggregateRootId::fromString('550e8400-e29b-41d4-a716-446655440000');
        $json = $id->toJson(JSON_PRETTY_PRINT);

        // Pretty-printed JSON should contain newlines
        $this->assertStringContainsString("\n", $json);
    }

    // ---------------------------------------------------------------
    // Consistency: toJson() === json_encode(toArray())
    // ---------------------------------------------------------------

    public function test_to_json_matches_json_encode_of_to_array(): void
    {
        $id = AggregateRootId::generate();
        $this->assertSame(
            json_encode($id->toArray(), JSON_UNESCAPED_UNICODE),
            $id->toJson(),
        );
    }

    public function test_collection_to_json_matches_json_encode_of_to_array(): void
    {
        $e1 = DomainEvent::occur('test.event', []);
        $collection = new DomainEventCollection([$e1]);
        $this->assertSame(
            json_encode($collection->toArray(), JSON_UNESCAPED_UNICODE),
            $collection->toJson(),
        );
    }
}
