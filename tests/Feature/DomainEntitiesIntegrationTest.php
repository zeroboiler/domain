<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Tests\Feature;

use PHPUnit\Framework\TestCase;
use ZeroBoiler\Domain\Contracts\Identifier;
use ZeroBoiler\Domain\DomainEventCollection;
use ZeroBoiler\Domain\Identifiers\IntegerIdentifier;
use ZeroBoiler\Domain\Identifiers\StringIdentifier;
use ZeroBoiler\Domain\Identifiers\UuidIdentifier;
use ZeroBoiler\Domain\Identifiers\UlidIdentifier;

/**
 * Production integration tests for domain identifiers and event collection.
 *
 * Verifies round-trip serialization, immutability, equality semantics,
 * and domain invariant enforcement across all identifier types.
 *
 * @covers \ZeroBoiler\Domain\Identifiers\UuidIdentifier
 * @covers \ZeroBoiler\Domain\Identifiers\UlidIdentifier
 * @covers \ZeroBoiler\Domain\Identifiers\StringIdentifier
 * @covers \ZeroBoiler\Domain\Identifiers\IntegerIdentifier
 * @covers \ZeroBoiler\Domain\DomainEventCollection
 */
final class DomainEntitiesIntegrationTest extends TestCase
{
    // -------------------------------------------------------
    // UuidIdentifier round-trip serialization
    // -------------------------------------------------------

    public function test_uuid_identifier_from_string_roundtrip(): void
    {
        $uuidString = '550e8400-e29b-41d4-a716-446655440000';
        $id1 = TestUuidId::fromString($uuidString);
        $id2 = TestUuidId::fromString($uuidString);

        self::assertTrue($id1->equals($id2));
        self::assertSame($uuidString, $id1->toString());
        self::assertSame($uuidString, (string) $id1);
    }

    public function test_uuid_identifier_generates_unique(): void
    {
        $id1 = TestUuidId::generate();
        $id2 = TestUuidId::generate();

        self::assertNotSame($id1->toString(), $id2->toString());
        self::assertFalse($id1->equals($id2));
    }

    public function test_uuid_identifier_json_serialization_roundtrip(): void
    {
        $id = TestUuidId::fromString('550e8400-e29b-41d4-a716-446655440000');
        $json = json_encode($id);
        self::assertIsString($json);
        self::assertSame('"550e8400-e29b-41d4-a716-446655440000"', $json);

        $restored = TestUuidId::fromJson($json);
        self::assertTrue($id->equals($restored));
    }

    public function test_uuid_identifier_array_serialization_roundtrip(): void
    {
        $id = TestUuidId::fromString('550e8400-e29b-41d4-a716-446655440000');
        $array = $id->toArray();
        self::assertArrayHasKey('value', $array);

        $restored = TestUuidId::fromArray($array);
        self::assertTrue($id->equals($restored));
    }

    // -------------------------------------------------------
    // UlidIdentifier round-trip serialization
    // -------------------------------------------------------

    public function test_ulid_identifier_from_string_roundtrip(): void
    {
        $ulid = '01HXY2KQNCXGJHTP5Z3F8KSAWR';
        $id1 = TestUlidId::fromString($ulid);
        $id2 = TestUlidId::fromString($ulid);

        self::assertTrue($id1->equals($id2));
        self::assertSame($ulid, $id1->toString());
    }

    public function test_ulid_identifier_json_serialization_roundtrip(): void
    {
        $id = TestUlidId::fromString('01HXY2KQNCXGJHTP5Z3F8KSAWR');
        $json = json_encode($id);
        self::assertSame('"01HXY2KQNCXGJHTP5Z3F8KSAWR"', $json);

        $restored = TestUlidId::fromJson($json);
        self::assertTrue($id->equals($restored));
    }

    public function test_ulid_identifier_array_serialization_roundtrip(): void
    {
        $id = TestUlidId::fromString('01HXY2KQNCXGJHTP5Z3F8KSAWR');
        $array = $id->toArray();
        self::assertArrayHasKey('value', $array);

        $restored = TestUlidId::fromArray($array);
        self::assertTrue($id->equals($restored));
    }

    // -------------------------------------------------------
    // StringIdentifier (final — use directly, not subclassed)
    // -------------------------------------------------------

    public function test_string_identifier_from_string_roundtrip(): void
    {
        $id1 = StringIdentifier::from('order-123');
        $id2 = StringIdentifier::from('order-123');
        $id3 = StringIdentifier::from('order-456');

        self::assertTrue($id1->equals($id2));
        self::assertFalse($id1->equals($id3));
        self::assertSame('order-123', $id1->toString());
        self::assertSame('order-123', (string) $id1);
    }

    public function test_string_identifier_rejects_empty_string(): void
    {
        $this->expectException(\ValueError::class);
        StringIdentifier::from('');
    }

    public function test_string_identifier_json_and_array_roundtrip(): void
    {
        $id = StringIdentifier::from('order-abc');
        $json = json_encode($id);
        $restored = StringIdentifier::fromJson($json);
        self::assertTrue($id->equals($restored));

        $array = $id->toArray();
        self::assertArrayHasKey('string', $array);
        self::assertSame('order-abc', $array['string']);
    }

    // -------------------------------------------------------
    // IntegerIdentifier (final — use directly)
    // -------------------------------------------------------

    public function test_integer_identifier_roundtrip(): void
    {
        $id1 = IntegerIdentifier::from(42);
        $id2 = IntegerIdentifier::from(42);
        $id3 = IntegerIdentifier::from(99);

        self::assertTrue($id1->equals($id2));
        self::assertFalse($id1->equals($id3));
        self::assertSame(42, $id1->value);
    }

    public function test_integer_identifier_json_and_array_roundtrip(): void
    {
        $id = IntegerIdentifier::from(42);
        $json = json_encode($id);
        $restored = IntegerIdentifier::fromJson($json);
        self::assertTrue($id->equals($restored));

        $array = $id->toArray();
        self::assertSame(42, $array['integer']);
    }

    public function test_integer_identifier_from_string(): void
    {
        $id = IntegerIdentifier::fromString('100');
        self::assertSame(100, $id->value);
        self::assertSame('100', $id->toString());
    }

    // -------------------------------------------------------
    // Cross-identifier inequality (different types never equal)
    // -------------------------------------------------------

    public function test_different_identifier_types_are_never_equal(): void
    {
        $uuidId = TestUuidId::fromString('550e8400-e29b-41d4-a716-446655440000');
        $ulidId = TestUlidId::fromString('550e8400-e29b-41d4-a716-446655440000');

        // Same string value, different types → never equal
        self::assertFalse($uuidId->equals($ulidId));
    }

    public function test_string_and_integer_identifiers_are_never_equal(): void
    {
        $strId = StringIdentifier::from('42');
        $intId = IntegerIdentifier::from(42);

        self::assertFalse($strId->equals($intId));
    }

    // -------------------------------------------------------
    // DomainEventCollection serialization
    // -------------------------------------------------------

    public function test_domain_event_collection_countable(): void
    {
        $collection = new DomainEventCollection;
        self::assertCount(0, $collection);

        // DomainEventCollection is readonly — new instances for each modification
        $collection = $collection->push($this->makeTestEvent('OrderCreated'));
        self::assertCount(1, $collection);

        $collection = $collection->push($this->makeTestEvent('OrderPaid'));
        self::assertCount(2, $collection);
    }

    public function test_domain_event_collection_iteration(): void
    {
        $collection = new DomainEventCollection([
            $this->makeTestEvent('a'),
            $this->makeTestEvent('b'),
        ]);

        $names = [];
        foreach ($collection as $event) {
            $names[] = $event['name'];
        }

        self::assertSame(['a', 'b'], $names);
    }

    public function test_domain_event_collection_json_roundtrip(): void
    {
        $collection = new DomainEventCollection([
            $this->makeTestEvent('ItemAdded'),
        ]);

        $json = json_encode($collection->toArray());
        self::assertIsString($json);

        $restored = DomainEventCollection::fromJson($json);
        self::assertCount(1, $restored);
    }

    public function test_domain_event_collection_to_json_serializable(): void
    {
        $collection = new DomainEventCollection([
            $this->makeTestEvent('X'),
        ]);

        $json = json_encode($collection);
        self::assertIsString($json);
        $data = json_decode($json, true);
        self::assertIsArray($data);
        self::assertCount(1, $data);
    }

    // -------------------------------------------------------
    // Helper: create a plain array event for DomainEventCollection
    // -------------------------------------------------------

    private function makeTestEvent(string $name): array
    {
        return [
            'name' => $name,
            'aggregate_id' => '550e8400-e29b-41d4-a716-446655440000',
            'aggregate_type' => 'TestOrder',
            'version' => 1,
            'occurred_at' => '2026-08-14T12:00:00+00:00',
            'payload' => ['test' => true],
        ];
    }
}

// ---------------------------------------------------------------
// Concrete test identifiers (must be readonly to extend abstract readonly)
// ---------------------------------------------------------------

final readonly class TestUuidId extends UuidIdentifier {}

final readonly class TestUlidId extends UlidIdentifier {}
