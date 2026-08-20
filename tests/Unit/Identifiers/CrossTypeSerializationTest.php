<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace Tests\Unit\Identifiers;

use Tests\TestCase;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\Identifiers\IntegerIdentifier;
use ZeroBoiler\Domain\Identifiers\StringIdentifier;
use ZeroBoiler\Domain\Identifiers\UlidIdentifier;
use ZeroBoiler\Domain\Identifiers\UuidIdentifier;

/**
 * Cross-type identifier serialization and equality contract tests.
 *
 * Verifies that:
 * 1. Each identifier type serializes to/from array and JSON correctly
 * 2. Different identifier types are NEVER equal (even with same value)
 * 3. Same-type identifiers with same value ARE equal
 * 4. fromArray/toArray round-trip is lossless for all types
 * 5. fromJson/toJson round-trip is lossless for all types
 * 6. json_encode() produces correct output for each type
 * 7. __toString() produces correct output for each type
 * 8. AggregateRootId has its own distinct serialization format
 *
 * @since 1.54.0
 */
class CrossTypeSerializationTest extends TestCase
{
    // ---------------------------------------------------------------
    // UuidIdentifier
    // ---------------------------------------------------------------

    public function testUuidIdentifierGenerateCreatesValidUuid(): void
    {
        $id = TestUuidId::generate();

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $id->toString(),
        );
        $this->assertTrue(UuidIdentifier::isValid($id->toString()));
    }

    public function testUuidIdentifierFromArrayRoundTrip(): void
    {
        $original = TestUuidId::generate();
        $restored = TestUuidId::fromArray($original->toArray());

        $this->assertTrue($original->equals($restored));
        $this->assertSame($original->toArray(), $restored->toArray());
    }

    public function testUuidIdentifierFromArrayAcceptsIdKey(): void
    {
        $original = TestUuidId::generate();
        $restored = TestUuidId::fromArray(['id' => $original->toString()]);

        $this->assertTrue($original->equals($restored));
    }

    public function testUuidIdentifierFromJsonRoundTrip(): void
    {
        $original = TestUuidId::generate();
        $restored = TestUuidId::fromJson($original->toJson());

        $this->assertTrue($original->equals($restored));
    }

    public function testUuidIdentifierJsonSerializeReturnsString(): void
    {
        $id = TestUuidId::generate();

        $this->assertSame($id->toString(), $id->jsonSerialize());
    }

    public function testUuidIdentifierToStringReturnsString(): void
    {
        $id = TestUuidId::generate();

        $this->assertSame($id->toString(), (string) $id);
    }

    public function testUuidIdentifierToArrayHasKeyUuid(): void
    {
        $id = TestUuidId::generate();
        $array = $id->toArray();

        $this->assertArrayHasKey('uuid', $array);
        $this->assertSame($id->toString(), $array['uuid']);
    }

    // ---------------------------------------------------------------
    // UlidIdentifier
    // ---------------------------------------------------------------

    public function testUlidIdentifierGenerateCreatesValidUlid(): void
    {
        $id = TestUlidId::generate();

        $this->assertTrue(UlidIdentifier::isValid($id->toString()));
        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', $id->toString());
    }

    public function testUlidIdentifierFromArrayRoundTrip(): void
    {
        $original = TestUlidId::generate();
        $restored = TestUlidId::fromArray($original->toArray());

        $this->assertTrue($original->equals($restored));
        $this->assertSame($original->toArray(), $restored->toArray());
    }

    public function testUlidIdentifierFromArrayAcceptsIdKey(): void
    {
        $original = TestUlidId::generate();
        $restored = TestUlidId::fromArray(['id' => $original->toString()]);

        $this->assertTrue($original->equals($restored));
    }

    public function testUlidIdentifierFromJsonRoundTrip(): void
    {
        $original = TestUlidId::generate();
        $restored = TestUlidId::fromJson($original->toJson());

        $this->assertTrue($original->equals($restored));
    }

    public function testUlidIdentifierToArrayHasKeyUlid(): void
    {
        $id = TestUlidId::generate();
        $array = $id->toArray();

        $this->assertArrayHasKey('ulid', $array);
        $this->assertSame($id->toString(), $array['ulid']);
    }

    // ---------------------------------------------------------------
    // StringIdentifier
    // ---------------------------------------------------------------

    public function testStringIdentifierFromArrayRoundTrip(): void
    {
        $original = StringIdentifier::from('my-slug-123');
        $restored = StringIdentifier::fromArray($original->toArray());

        $this->assertTrue($original->equals($restored));
        $this->assertSame($original->toArray(), $restored->toArray());
    }

    public function testStringIdentifierRejectsEmptyString(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        StringIdentifier::from('');
    }

    public function testStringIdentifierFromJsonRoundTrip(): void
    {
        $original = StringIdentifier::from('hello-world');
        $restored = StringIdentifier::fromJson($original->toJson());

        $this->assertTrue($original->equals($restored));
    }

    // ---------------------------------------------------------------
    // IntegerIdentifier
    // ---------------------------------------------------------------

    public function testIntegerIdentifierFromArrayRoundTrip(): void
    {
        $original = IntegerIdentifier::from(42);
        $restored = IntegerIdentifier::fromArray($original->toArray());

        $this->assertTrue($original->equals($restored));
        $this->assertSame(42, $restored->toInt());
    }

    public function testIntegerIdentifierRejectsZero(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        IntegerIdentifier::from(0);
    }

    public function testIntegerIdentifierRejectsNegative(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        IntegerIdentifier::from(-1);
    }

    public function testIntegerIdentifierFromJsonRoundTrip(): void
    {
        $original = IntegerIdentifier::from(99);
        $restored = IntegerIdentifier::fromJson($original->toJson());

        $this->assertTrue($original->equals($restored));
    }

    // ---------------------------------------------------------------
    // AggregateRootId
    // ---------------------------------------------------------------

    public function testAggregateRootIdGenerateCreatesValidUuid(): void
    {
        $id = AggregateRootId::generate();

        $this->assertTrue(AggregateRootId::isValid($id->toString()));
    }

    public function testAggregateRootIdFromArrayRoundTrip(): void
    {
        $original = AggregateRootId::generate();
        $restored = AggregateRootId::fromArray($original->toArray());

        $this->assertTrue($original->equals($restored));
    }

    public function testAggregateRootIdFromArrayAcceptsIdKey(): void
    {
        $original = AggregateRootId::generate();
        $restored = AggregateRootId::fromArray(['id' => $original->toString()]);

        $this->assertTrue($original->equals($restored));
    }

    public function testAggregateRootIdFromJsonRoundTrip(): void
    {
        $original = AggregateRootId::generate();
        $restored = AggregateRootId::fromJson($original->toJson());

        $this->assertTrue($original->equals($restored));
    }

    public function testAggregateRootIdToArrayHasKeyUuid(): void
    {
        $id = AggregateRootId::generate();
        $array = $id->toArray();

        $this->assertArrayHasKey('uuid', $array);
    }

    public function testAggregateRootIdJsonSerializeReturnsString(): void
    {
        $id = AggregateRootId::generate();

        $this->assertSame($id->toString(), $id->jsonSerialize());
    }

    // ---------------------------------------------------------------
    // Cross-type inequality
    // ---------------------------------------------------------------

    public function testDifferentIdentifierTypesAreNeverEqual(): void
    {
        $uuidStr = '550e8400-e29b-41d4-a716-446655440000';

        $uuidId = TestUuidId::fromString($uuidStr);
        $altUuidId = TestAltUuidId::fromString($uuidStr);

        // Same UUID value, different subclass → NOT equal
        $this->assertFalse($uuidId->equals($altUuidId));
    }

    public function testSameIdentifierTypeWithSameValueAreEqual(): void
    {
        $id1 = TestUuidId::fromString('550e8400-e29b-41d4-a716-446655440000');
        $id2 = TestUuidId::fromString('550e8400-e29b-41d4-a716-446655440000');

        $this->assertTrue($id1->equals($id2));
    }

    public function testSameIdentifierTypeWithDifferentValueAreNotEqual(): void
    {
        $id1 = TestUuidId::generate();
        $id2 = TestUuidId::generate();

        $this->assertFalse($id1->equals($id2));
    }

    // ---------------------------------------------------------------
    // Invalid input rejection
    // ---------------------------------------------------------------

    public function testUuidIdentifierRejectsInvalidString(): void
    {
        $this->expectException(\Ramsey\Uuid\Exception\InvalidUuidStringException::class);

        TestUuidId::fromString('not-a-uuid');
    }

    public function testUlidIdentifierRejectsInvalidString(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        TestUlidId::fromString('not-a-ulid');
    }

    public function testUuidIdentifierFromArrayRejectsMissingKeys(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        TestUuidId::fromArray(['foo' => 'bar']);
    }

    public function testUlidIdentifierFromArrayRejectsMissingKeys(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        TestUlidId::fromArray(['foo' => 'bar']);
    }

    public function testAggregateRootIdFromArrayRejectsInvalidData(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        AggregateRootId::fromArray(['uuid' => 123]);
    }

    // ---------------------------------------------------------------
    // JsonSerializable output type
    // ---------------------------------------------------------------

    public function testAllIdentifiersJsonEncodeProduceScalarOrArray(): void
    {
        $uuidId = TestUuidId::generate();
        $ulidId = TestUlidId::generate();
        $strId = StringIdentifier::from('test');
        $intId = IntegerIdentifier::from(1);
        $arId = AggregateRootId::generate();

        // jsonSerialize on identifiers returns string (not array)
        $this->assertIsString($uuidId->jsonSerialize());
        $this->assertIsString($ulidId->jsonSerialize());
        $this->assertIsString($strId->jsonSerialize());
        $this->assertIsString($intId->jsonSerialize());
        $this->assertIsString($arId->jsonSerialize());
    }
}

// Test fixtures

class TestUuidId extends UuidIdentifier {}

class TestAltUuidId extends UuidIdentifier {}

class TestUlidId extends UlidIdentifier {}
