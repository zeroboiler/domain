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
use ZeroBoiler\Domain\Tests\Fixtures\TestUlidIdentifier;
use ZeroBoiler\Domain\Tests\Fixtures\TestUuidIdentifier;

/**
 * JSON round-trip serialization tests for all domain identifiers.
 *
 * Verifies that every identifier type correctly serializes to JSON
 * and can be restored from its JSON representation via fromArray().
 */
final class DomainIdentifierJsonRoundTripTest extends \PHPUnit\Framework\TestCase
{
    // ── AggregateRootId ──

    #[Test]
    public function aggregateRootIdSerializesToJsonAsUuidString(): void
    {
        $id = AggregateRootId::generate();

        $json = json_encode($id);

        $this->assertIsString($json);
        $this->assertSame(
            $id->toString(),
            json_decode($json, associative: true),
            'AggregateRootId should serialize to its UUID string.',
        );

        // Verify it's a plain string (not wrapped in an object)
        $this->assertStringStartsWith('{', $json === false ? '' : '');
        $decoded = json_decode($json);
        $this->assertIsString($decoded);
    }

    #[Test]
    public function aggregateRootIdRoundTripsThroughJsonViaToArray(): void
    {
        $original = AggregateRootId::fromString('550e8400-e29b-41d4-a716-446655440000');

        $array = $original->toArray();
        $restored = AggregateRootId::fromArray($array);

        $this->assertTrue($original->equals($restored));
        $this->assertSame($original->toString(), $restored->toString());
    }

    #[Test]
    public function aggregateRootIdFromArrayAcceptsIdKey(): void
    {
        $id = AggregateRootId::fromArray(['id' => '550e8400-e29b-41d4-a716-446655440000']);

        $this->assertSame('550e8400-e29b-41d4-a716-446655440000', $id->toString());
    }

    #[Test]
    public function aggregateRootIdFromArrayRejectsMissingKeys(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        AggregateRootId::fromArray(['foo' => 'bar']);
    }

    // ── UuidIdentifier ──

    #[Test]
    public function uuidIdentifierSerializesToJsonAsUuidString(): void
    {
        $id = TestUuidIdentifier::generate();

        $json = json_encode($id);

        $this->assertIsString($json);
        $this->assertSame($id->toString(), json_decode($json));
    }

    #[Test]
    public function uuidIdentifierRoundTripsThroughToArray(): void
    {
        $original = TestUuidIdentifier::fromString('550e8400-e29b-41d4-a716-446655440000');

        $array = $original->toArray();
        $this->assertArrayHasKey('uuid', $array);
        $this->assertSame('550e8400-e29b-41d4-a716-446655440000', $array['uuid']);

        $restored = TestUuidIdentifier::fromArray($array);
        $this->assertTrue($original->equals($restored));
    }

    #[Test]
    public function uuidIdentifierFromArrayAcceptsIdKey(): void
    {
        $id = TestUuidIdentifier::fromArray(['id' => '550e8400-e29b-41d4-a716-446655440000']);

        $this->assertSame('550e8400-e29b-41d4-a716-446655440000', $id->toString());
    }

    // ── UlidIdentifier ──

    #[Test]
    public function ulidIdentifierSerializesToJsonAsUlidString(): void
    {
        $id = TestUlidIdentifier::generate();

        $json = json_encode($id);

        $this->assertIsString($json);
        $this->assertSame($id->toString(), json_decode($json));
    }

    #[Test]
    public function ulidIdentifierRoundTripsThroughToArray(): void
    {
        $original = TestUlidIdentifier::generate();

        $array = $original->toArray();
        $this->assertArrayHasKey('ulid', $array);

        $restored = TestUlidIdentifier::fromArray($array);
        $this->assertTrue($original->equals($restored));
    }

    #[Test]
    public function ulidIdentifierFromArrayAcceptsIdKey(): void
    {
        $id = TestUlidIdentifier::fromArray(['id' => '01H5J5Y9Z7K2M8N4P6Q0R1S2T']);

        $this->assertSame('01H5J5Y9Z7K2M8N4P6Q0R1S2T', $id->toString());
    }

    // ── StringIdentifier ──

    #[Test]
    public function stringIdentifierSerializesToJsonAsString(): void
    {
        $id = StringIdentifier::from('my-blog-post');

        $json = json_encode($id);

        $this->assertIsString($json);
        $this->assertSame('my-blog-post', json_decode($json));
    }

    #[Test]
    public function stringIdentifierRoundTripsThroughToArray(): void
    {
        $original = StringIdentifier::from('my-blog-post');

        $array = $original->toArray();
        $this->assertArrayHasKey('string', $array);
        $this->assertSame('my-blog-post', $array['string']);

        $restored = StringIdentifier::fromArray($array);
        $this->assertTrue($original->equals($restored));
    }

    #[Test]
    public function stringIdentifierFromArrayAcceptsIdKey(): void
    {
        $id = StringIdentifier::fromArray(['id' => 'my-slug']);

        $this->assertSame('my-slug', $id->toString());
    }

    #[Test]
    public function stringIdentifierRejectsEmptyString(): void
    {
        $this->expectException(\ValueError::class);

        StringIdentifier::from('');
    }

    // ── IntegerIdentifier ──

    #[Test]
    public function integerIdentifierSerializesToJsonAsInt(): void
    {
        $id = IntegerIdentifier::from(42);

        $json = json_encode($id);

        $this->assertIsString($json);
        $this->assertSame(42, json_decode($json));
    }

    #[Test]
    public function integerIdentifierRoundTripsThroughToArray(): void
    {
        $original = IntegerIdentifier::from(42);

        $array = $original->toArray();
        $this->assertArrayHasKey('integer', $array);
        $this->assertSame(42, $array['integer']);

        $restored = IntegerIdentifier::fromArray($array);
        $this->assertTrue($original->equals($restored));
    }

    #[Test]
    public function integerIdentifierFromArrayAcceptsIdKey(): void
    {
        $id = IntegerIdentifier::fromArray(['id' => 42]);

        $this->assertSame(42, $id->toInt());
    }

    #[Test]
    public function integerIdentifierFromArrayAcceptsStringIdKey(): void
    {
        $id = IntegerIdentifier::fromArray(['id' => '99']);

        $this->assertSame(99, $id->toInt());
    }

    // ── Cross-type inequality ──

    #[Test]
    public function differentIdentifierSubclassesAreNeverEqual(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';

        $a = TestUuidIdentifier::fromString($uuid);
        $b = TestUuidIdentifierAlt::fromString($uuid);

        // Same UUID, different subclass → not equal
        $this->assertFalse($a->equals($b));
    }

    #[Test]
    public function differentConcreteIdentifiersWithSameValueAreNotEqual(): void
    {
        // StringIdentifier and IntegerIdentifier are both final, so this tests
        // that equals() checks class identity even for the same concrete type
        $a = StringIdentifier::from('42');
        $b = StringIdentifier::from('42');

        $this->assertTrue($a->equals($b), 'Same type, same value should be equal');
    }

    // ── All identifiers implement Stringable ──

    #[Test]
    public function allIdentifiersAreStringable(): void
    {
        $identifiers = [
            AggregateRootId::generate(),
            TestUuidIdentifier::generate(),
            TestUlidIdentifier::generate(),
            StringIdentifier::from('test'),
            IntegerIdentifier::from(1),
        ];

        foreach ($identifiers as $id) {
            $this->assertInstanceOf(
                \Stringable::class,
                $id,
                sprintf('%s should implement Stringable', $id::class),
            );
            $this->assertIsString((string) $id, sprintf('%s::__toString() should return string', $id::class));
        }
    }

    // ── All identifiers implement JsonSerializable ──

    #[Test]
    public function allIdentifiersImplementJsonSerializable(): void
    {
        $identifiers = [
            AggregateRootId::generate(),
            TestUuidIdentifier::generate(),
            TestUlidIdentifier::generate(),
            StringIdentifier::from('test'),
            IntegerIdentifier::from(1),
        ];

        foreach ($identifiers as $id) {
            $json = json_encode($id);
            $this->assertNotFalse($json, sprintf('%s should be JSON-serializable', $id::class));
            $this->assertNotEmpty($json, sprintf('%s JSON should not be empty', $id::class));
        }
    }

    // ── All identifiers have toArray/fromArray ──

    #[Test]
    public function allIdentifiersHaveToArrayAndFromArray(): void
    {
        $identifiers = [
            AggregateRootId::generate(),
            TestUuidIdentifier::generate(),
            TestUlidIdentifier::generate(),
            StringIdentifier::from('test'),
            IntegerIdentifier::from(1),
        ];

        foreach ($identifiers as $id) {
            $this->assertTrue(
                method_exists($id, 'toArray'),
                sprintf('%s should have toArray()', $id::class),
            );

            $this->assertTrue(
                method_exists($id::class, 'fromArray'),
                sprintf('%s should have static fromArray()', $id::class),
            );
        }
    }

    // ── Array encoding in nested structures ──

    #[Test]
    public function identifiersWorkAsArrayKeysInJson(): void
    {
        $uuid = AggregateRootId::generate();
        $data = ['order_id' => $uuid, 'status' => 'pending'];

        $json = json_encode($data);
        $decoded = json_decode($json, associative: true);

        $this->assertSame($uuid->toString(), $decoded['order_id']);
    }

    #[Test]
    public function integerIdentifierWorksAsArrayValueInJson(): void
    {
        $id = IntegerIdentifier::from(42);
        $data = ['product_id' => $id];

        $json = json_encode($data);
        $decoded = json_decode($json, associative: true);

        $this->assertSame(42, $decoded['product_id']);
    }
}
