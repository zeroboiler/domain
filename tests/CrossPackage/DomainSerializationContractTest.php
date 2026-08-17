<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Tests\CrossPackage;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ZeroBoiler\Domain\Contracts\Identifier;
use ZeroBoiler\Domain\Contracts\Entity;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\Identifiers\UuidIdentifier;
use ZeroBoiler\Domain\Identifiers\UlidIdentifier;
use ZeroBoiler\Domain\Identifiers\StringIdentifier;
use ZeroBoiler\Domain\Identifiers\IntegerIdentifier;

/**
 * Tests for cross-package serialization contract guarantees.
 *
 * These tests verify that domain objects can be reliably serialized and
 * deserialized by the response package's duck-typed transformers without
 * hard dependency — a core architectural contract.
 *
 * @see \ZeroBoiler\Response\Concerns\ExtractsDomainId
 * @see \ZeroBoiler\Response\Transformers\DomainTransformer
 */
#[Group('cross-package')]
#[Group('serialization')]
#[CoversClass(AggregateRootId::class)]
#[CoversClass(UuidIdentifier::class)]
#[CoversClass(UlidIdentifier::class)]
#[CoversClass(StringIdentifier::class)]
#[CoversClass(IntegerIdentifier::class)]
final class DomainSerializationContractTest extends TestCase
{
    // ─── AggregateRootId ──────────────────────────────────────

    public function testAggregateRootIdToArrayReturnsIdAndType(): void
    {
        $id = AggregateRootId::generate();
        $array = $id->toArray();

        $this->assertArrayHasKey('id', $array);
        $this->assertArrayHasKey('type', $array);
        $this->assertSame($id->toString(), $array['id']);
        $this->assertSame('AggregateRootId', $array['type']);
    }

    public function testAggregateRootIdRoundTripViaArray(): void
    {
        $original = AggregateRootId::generate();
        $restored = AggregateRootId::fromArray($original->toArray());

        $this->assertTrue($original->equals($restored));
        $this->assertSame($original->toString(), $restored->toString());
    }

    public function testAggregateRootIdRoundTripViaJson(): void
    {
        $original = AggregateRootId::generate();
        $json = $original->toJson();
        $restored = AggregateRootId::fromJson($json);

        $this->assertTrue($original->equals($restored));
    }

    public function testAggregateRootIdJsonSerializable(): void
    {
        $id = AggregateRootId::fromString('550e8400-e29b-41d4-a716-446655440000');
        $encoded = json_encode($id);

        $this->assertIsString($encoded);
        $decoded = json_decode($encoded, true);
        $this->assertArrayHasKey('id', $decoded);
        $this->assertArrayHasKey('type', $decoded);
    }

    public function testAggregateRootIdFromExistingString(): void
    {
        $uuidString = '550e8400-e29b-41d4-a716-446655440000';
        $id = AggregateRootId::fromString($uuidString);

        $this->assertSame($uuidString, $id->toString());
    }

    // ─── UuidIdentifier ────────────────────────────────────────

    public function testUuidIdentifierContract(): void
    {
        $id = UuidIdentifier::fromString('6ba7b810-9dad-11d1-80b4-00c04fd430c8');
        $this->assertInstanceOf(Identifier::class, $id);
        $this->assertInstanceOf(\Stringable::class, $id);
        $this->assertInstanceOf(\JsonSerializable::class, $id);
    }

    public function testUuidIdentifierRoundTripViaArray(): void
    {
        $original = UuidIdentifier::fromString('6ba7b810-9dad-11d1-80b4-00c04fd430c8');
        $restored = UuidIdentifier::fromArray($original->toArray());

        $this->assertTrue($original->equals($restored));
    }

    public function testUuidIdentifierRoundTripViaJson(): void
    {
        $original = UuidIdentifier::fromString('6ba7b810-9dad-11d1-80b4-00c04fd430c8');
        $json = $original->toJson();
        $restored = UuidIdentifier::fromJson($json);

        $this->assertTrue($original->equals($restored));
    }

    public function testUuidIdentifierEquality(): void
    {
        $uuid = '6ba7b810-9dad-11d1-80b4-00c04fd430c8';
        $a = UuidIdentifier::fromString($uuid);
        $b = UuidIdentifier::fromString($uuid);

        $this->assertTrue($a->equals($b));

        $c = UuidIdentifier::fromString('6ba7b811-9dad-11d1-80b4-00c04fd430c8');
        $this->assertFalse($a->equals($c));
    }

    // ─── UlidIdentifier ────────────────────────────────────────

    public function testUlidIdentifierContract(): void
    {
        $id = UlidIdentifier::fromString('01H9K5Z9Z9Z9Z9Z9Z9Z9Z9Z9Z');
        $this->assertInstanceOf(Identifier::class, $id);
    }

    public function testUlidIdentifierRoundTripViaArray(): void
    {
        $original = UlidIdentifier::fromString('01H9K5Z9Z9Z9Z9Z9Z9Z9Z9Z9Z');
        $restored = UlidIdentifier::fromArray($original->toArray());

        $this->assertTrue($original->equals($restored));
    }

    public function testUlidIdentifierRoundTripViaJson(): void
    {
        $original = UlidIdentifier::fromString('01H9K5Z9Z9Z9Z9Z9Z9Z9Z9Z9Z');
        $json = $original->toJson();
        $restored = UlidIdentifier::fromJson($json);

        $this->assertTrue($original->equals($restored));
    }

    // ─── StringIdentifier ──────────────────────────────────────

    public function testStringIdentifierContract(): void
    {
        $id = StringIdentifier::from('my-slug');
        $this->assertInstanceOf(Identifier::class, $id);
    }

    public function testStringIdentifierRoundTripViaArray(): void
    {
        $original = StringIdentifier::from('my-slug');
        $restored = StringIdentifier::fromArray($original->toArray());

        $this->assertTrue($original->equals($restored));
        $this->assertSame('my-slug', $restored->toString());
    }

    public function testStringIdentifierRoundTripViaJson(): void
    {
        $original = StringIdentifier::from('my-slug');
        $json = $original->toJson();
        $restored = StringIdentifier::fromJson($json);

        $this->assertTrue($original->equals($restored));
    }

    public function testStringIdentifierRejectsEmpty(): void
    {
        $this->expectException(\ValueError::class);
        StringIdentifier::from('');
    }

    // ─── IntegerIdentifier ─────────────────────────────────────

    public function testIntegerIdentifierContract(): void
    {
        $id = IntegerIdentifier::from(42);
        $this->assertInstanceOf(Identifier::class, $id);
    }

    public function testIntegerIdentifierRoundTripViaArray(): void
    {
        $original = IntegerIdentifier::from(42);
        $restored = IntegerIdentifier::fromArray($original->toArray());

        $this->assertTrue($original->equals($restored));
        $this->assertSame('42', $restored->toString());
    }

    public function testIntegerIdentifierRoundTripViaJson(): void
    {
        $original = IntegerIdentifier::from(42);
        $json = $original->toJson();
        $restored = IntegerIdentifier::fromJson($json);

        $this->assertTrue($original->equals($restored));
    }

    public function testIntegerIdentifierRejectsNegative(): void
    {
        $this->expectException(\ValueError::class);
        IntegerIdentifier::from(-1);
    }

    // ─── Cross-Identifier Equality ─────────────────────────────

    public function testDifferentIdentifierTypesAreNeverEqual(): void
    {
        $uuid = UuidIdentifier::fromString('6ba7b810-9dad-11d1-80b4-00c04fd430c8');
        $string = StringIdentifier::from('6ba7b810-9dad-11d1-80b4-00c04fd430c8');

        // Different concrete classes should never be equal
        $this->assertNotEquals($uuid->toString(), $string->toString());
    }

    // ─── Duck-Typing Contract (for ExtractsDomainId) ───────────

    public function testIdentifierToStringForResponseExtraction(): void
    {
        $id = UuidIdentifier::fromString('6ba7b810-9dad-11d1-80b4-00c04fd430c8');

        // Response package's ExtractsDomainId uses method_exists + toString()
        $this->assertTrue(method_exists($id, 'toString'));
        $this->assertTrue(method_exists($id, 'toArray'));
        $this->assertTrue(method_exists($id, 'fromArray'));
        $this->assertTrue(method_exists($id, 'fromJson'));
        $this->assertSame('6ba7b810-9dad-11d1-80b4-00c04fd430c8', $id->toString());
    }

    public function testAggregateRootIdToStringForResponseExtraction(): void
    {
        $id = AggregateRootId::fromString('550e8400-e29b-41d4-a716-446655440000');

        $this->assertTrue(method_exists($id, 'id'));
        $this->assertSame('550e8400-e29b-41d4-a716-446655440000', $id->id());
        $this->assertSame('550e8400-e29b-41d4-a716-446655440000', (string) $id);
    }

    // ─── Identifier as Stringable ───────────────────────────────

    public function testIdentifiersImplementStringable(): void
    {
        $uuid = UuidIdentifier::fromString('6ba7b810-9dad-11d1-80b4-00c04fd430c8');
        $ulid = UlidIdentifier::fromString('01H9K5Z9Z9Z9Z9Z9Z9Z9Z9Z9Z');
        $string = StringIdentifier::from('test');
        $integer = IntegerIdentifier::from(1);
        $rootId = AggregateRootId::fromString('550e8400-e29b-41d4-a716-446655440000');

        foreach ([$uuid, $ulid, $string, $integer, $rootId] as $id) {
            $this->assertInstanceOf(\Stringable::class, $id, get_class($id) . ' must implement Stringable');
            $cast = (string) $id;
            $this->assertNotEmpty($cast, get_class($id) . ' string cast must not be empty');
        }
    }

    // ─── toArray Structure Consistency ────────────────────────────

    public function testAllIdentifiersHaveValueInToArray(): void
    {
        $identifiers = [
            'uuid' => UuidIdentifier::fromString('6ba7b810-9dad-11d1-80b4-00c04fd430c8'),
            'ulid' => UlidIdentifier::fromString('01H9K5Z9Z9Z9Z9Z9Z9Z9Z9Z9Z'),
            'string' => StringIdentifier::from('test'),
            'integer' => IntegerIdentifier::from(1),
        ];

        foreach ($identifiers as $name => $id) {
            $array = $id->toArray();
            $this->assertArrayHasKey('value', $array, "{$name} toArray must have 'value' key");
            $this->assertNotEmpty($array['value'], "{$name} value must not be empty");
        }
    }
}
