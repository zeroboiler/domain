<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace Tests\Domain;

use PHPUnit\Framework\TestCase;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\Contracts\Identifier as IdentifierContract;
use ZeroBoiler\Domain\Identifiers\IntegerIdentifier;
use ZeroBoiler\Domain\Identifiers\StringIdentifier;
use ZeroBoiler\Domain\Identifiers\UuidIdentifier;
use ZeroBoiler\Domain\Identifiers\UlidIdentifier;

/**
 * Comprehensive contract validation for all identifier types.
 *
 * Validates cross-type behavior: equality, immutability, serialization,
 * JSON serialization, round-trip consistency, and interface compliance.
 *
 * @since 1.55.0
 */
final class IdentifierContractComprehensiveTest extends TestCase
{
    // ─── AggregateRootId ────────────────────────────────────────

    public function testAggregateRootIdIsFinalReadonly(): void
    {
        $reflection = new \ReflectionClass(AggregateRootId::class);

        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
    }

    public function testAggregateRootIdImplementsStringableAndJsonSerializable(): void
    {
        $id = AggregateRootId::generate();

        $this->assertInstanceOf(\Stringable::class, $id);
        $this->assertInstanceOf(\JsonSerializable::class, $id);
    }

    public function testAggregateRootIdGenerateCreatesValidUuid(): void
    {
        $id = AggregateRootId::generate();

        $this->assertTrue(AggregateRootId::isValid($id->toString()));
        $this->assertTrue($id->value->getVersion() === 4);
    }

    public function testAggregateRootIdFromStringRoundTrip(): void
    {
        $original = AggregateRootId::generate();
        $restored = AggregateRootId::fromString($original->toString());

        $this->assertTrue($original->equals($restored));
        $this->assertSame($original->toString(), $restored->toString());
    }

    public function testAggregateRootIdArrayRoundTrip(): void
    {
        $original = AggregateRootId::generate();
        $restored = AggregateRootId::fromArray($original->toArray());

        $this->assertTrue($original->equals($restored));
        $this->assertSame($original->toArray(), $restored->toArray());
    }

    public function testAggregateRootIdArrayRoundTripWithIdKey(): void
    {
        $original = AggregateRootId::generate();
        $restored = AggregateRootId::fromArray(['id' => $original->toString()]);

        $this->assertTrue($original->equals($restored));
    }

    public function testAggregateRootIdJsonSerializeReturnsString(): void
    {
        $id = AggregateRootId::generate();
        $json = json_encode($id);

        $this->assertIsString($json);
        $this->assertSame('"' . $id->toString() . '"', $json);
    }

    public function testAggregateRootIdToStringConsistency(): void
    {
        $id = AggregateRootId::generate();

        $this->assertSame($id->toString(), (string) $id);
        $this->assertSame($id->toString(), $id->__toString());
    }

    public function testAggregateRootIdEqualitySameObject(): void
    {
        $id = AggregateRootId::generate();

        $this->assertTrue($id->equals($id));
    }

    public function testAggregateRootIdEqualityDifferentValues(): void
    {
        $id1 = AggregateRootId::generate();
        $id2 = AggregateRootId::generate();

        $this->assertFalse($id1->equals($id2));
    }

    public function testAggregateRootIdFromArrayThrowsOnMissingKeys(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        AggregateRootId::fromArray(['foo' => 'bar']);
    }

    public function testAggregateRootIdFromArrayThrowsOnInvalidType(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        AggregateRootId::fromArray(['uuid' => 12345]);
    }

    public function testAggregateRootIdInvalidUuidDetection(): void
    {
        $this->assertFalse(AggregateRootId::isValid('not-a-uuid'));
        $this->assertFalse(AggregateRootId::isValid(''));
        $this->assertTrue(AggregateRootId::isValid('550e8400-e29b-41d4-a716-446655440000'));
    }

    // ─── UuidIdentifier ───────────────────────────────────────

    public function testUuidIdentifierIsAbstractReadonly(): void
    {
        $reflection = new \ReflectionClass(UuidIdentifier::class);

        $this->assertTrue($reflection->isAbstract());
        $this->assertTrue($reflection->isReadOnly());
    }

    public function testUuidIdentifierSubclassEqualityAndRoundTrip(): void
    {
        $id1 = TestUuidId::generate();
        $id2 = TestUuidId::fromString($id1->toString());
        $id3 = TestUuidId::generate();

        $this->assertTrue($id1->equals($id2));
        $this->assertFalse($id1->equals($id3));

        // Array round-trip
        $restored = TestUuidId::fromArray($id1->toArray());
        $this->assertTrue($id1->equals($restored));

        // JSON
        $this->assertSame('"' . $id1->toString() . '"', json_encode($id1));
    }

    public function testUuidIdentifierCrossTypeInequality(): void
    {
        $orderId = TestUuidId::generate();
        $productId = AnotherUuidId::fromString($orderId->toString());

        // Same UUID value but different types → not equal
        $this->assertFalse($orderId->equals($productId));
    }

    public function testUuidIdentifierArrayRoundTripWithIdKey(): void
    {
        $original = TestUuidId::generate();
        $restored = TestUuidId::fromArray(['id' => $original->toString()]);

        $this->assertTrue($original->equals($restored));
    }

    public function testUuidIdentifierConstructorValidatesUuid(): void
    {
        $this->expectException(\Ramsey\Uuid\Exception\InvalidUuidStringException::class);

        new TestUuidId('not-a-valid-uuid');
    }

    public function testUuidIdentifierImplementsContracts(): void
    {
        $id = TestUuidId::generate();

        $this->assertInstanceOf(IdentifierContract::class, $id);
        $this->assertInstanceOf(\JsonSerializable::class, $id);
        $this->assertInstanceOf(\Stringable::class, $id);
    }

    public function testUuidIdentifierToUuidReturnsRamseyInstance(): void
    {
        $id = TestUuidId::generate();
        $ramsey = $id->toUuid();

        $this->assertInstanceOf(\Ramsey\Uuid\UuidInterface::class, $ramsey);
        $this->assertSame($id->toString(), $ramsey->toString());
    }

    // ─── UlidIdentifier ───────────────────────────────────────

    public function testUlidIdentifierIsAbstractReadonly(): void
    {
        $reflection = new \ReflectionClass(UlidIdentifier::class);

        $this->assertTrue($reflection->isAbstract());
        $this->assertTrue($reflection->isReadOnly());
    }

    public function testUlidIdentifierSubclassRoundTrip(): void
    {
        $id1 = TestUlidId::generate();
        $id2 = TestUlidId::fromString($id1->toString());

        $this->assertTrue($id1->equals($id2));

        // Array round-trip
        $restored = TestUlidId::fromArray($id1->toArray());
        $this->assertTrue($id1->equals($restored));

        // JSON
        $this->assertSame('"' . $id1->toString() . '"', json_encode($id1));
    }

    public function testUlidIdentifierArrayRoundTripWithIdKey(): void
    {
        $original = TestUlidId::generate();
        $restored = TestUlidId::fromArray(['id' => $original->toString()]);

        $this->assertTrue($original->equals($restored));
    }

    public function testUlidIdentifierValidatesOnConstruction(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new TestUlidId('not-a-valid-ulid');
    }

    public function testUlidIdentifierImplementsContracts(): void
    {
        $id = TestUlidId::generate();

        $this->assertInstanceOf(IdentifierContract::class, $id);
        $this->assertInstanceOf(\JsonSerializable::class, $id);
    }

    public function testUlidIdentifierToUlidReturnsSymfonyInstance(): void
    {
        $id = TestUlidId::generate();
        $ulid = $id->toUlid();

        $this->assertInstanceOf(\Symfony\Component\Uid\Ulid::class, $ulid);
        $this->assertSame($id->toString(), $ulid->toBase32());
    }

    // ─── StringIdentifier ──────────────────────────────────────

    public function testStringIdentifierIsFinalReadonly(): void
    {
        $reflection = new \ReflectionClass(StringIdentifier::class);

        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
    }

    public function testStringIdentifierRejectsEmptyString(): void
    {
        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage('cannot be empty');

        StringIdentifier::from('');
    }

    public function testStringIdentifierRoundTrip(): void
    {
        $original = StringIdentifier::from('my-slug-123');
        $restored = StringIdentifier::fromString($original->toString());

        $this->assertTrue($original->equals($restored));

        // Array round-trip
        $arrayRestored = StringIdentifier::fromArray($original->toArray());
        $this->assertTrue($original->equals($arrayRestored));

        // JSON
        $this->assertSame('"my-slug-123"', json_encode($original));
    }

    public function testStringIdentifierArrayRoundTripWithIdKey(): void
    {
        $original = StringIdentifier::from('my-slug');
        $restored = StringIdentifier::fromArray(['id' => 'my-slug']);

        $this->assertTrue($original->equals($restored));
    }

    public function testStringIdentifierImplementsContracts(): void
    {
        $id = StringIdentifier::from('test');

        $this->assertInstanceOf(IdentifierContract::class, $id);
        $this->assertInstanceOf(\JsonSerializable::class, $id);
        $this->assertInstanceOf(\Stringable::class, $id);
    }

    public function testStringIdentifierIsValid(): void
    {
        $this->assertTrue(StringIdentifier::isValid('hello'));
        $this->assertFalse(StringIdentifier::isValid(''));
    }

    // ─── IntegerIdentifier ────────────────────────────────────

    public function testIntegerIdentifierIsFinalReadonly(): void
    {
        $reflection = new \ReflectionClass(IntegerIdentifier::class);

        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
    }

    public function testIntegerIdentifierRoundTrip(): void
    {
        $original = IntegerIdentifier::from(42);
        $restored = IntegerIdentifier::fromString((string) $original->toInt());

        $this->assertTrue($original->equals($restored));

        // Array round-trip
        $arrayRestored = IntegerIdentifier::fromArray($original->toArray());
        $this->assertTrue($original->equals($arrayRestored));

        // JSON
        $this->assertSame('42', json_encode($original));
    }

    public function testIntegerIdentifierJsonSerializeReturnsInt(): void
    {
        $id = IntegerIdentifier::from(42);

        $this->assertSame(42, $id->jsonSerialize());
    }

    public function testIntegerIdentifierArrayRoundTripWithIdKey(): void
    {
        $original = IntegerIdentifier::from(99);
        $restored = IntegerIdentifier::fromArray(['id' => 99]);

        $this->assertTrue($original->equals($restored));
    }

    public function testIntegerIdentifierArrayRoundTripWithStringId(): void
    {
        $original = IntegerIdentifier::from(100);
        $restored = IntegerIdentifier::fromArray(['id' => '100']);

        $this->assertTrue($original->equals($restored));
    }

    public function testIntegerIdentifierImplementsContracts(): void
    {
        $id = IntegerIdentifier::from(1);

        $this->assertInstanceOf(IdentifierContract::class, $id);
        $this->assertInstanceOf(\JsonSerializable::class, $id);
        $this->assertInstanceOf(\Stringable::class, $id);
    }

    public function testIntegerIdentifierIsValid(): void
    {
        $this->assertTrue(IntegerIdentifier::isValid('42'));
        $this->assertTrue(IntegerIdentifier::isValid('-1'));
        $this->assertFalse(IntegerIdentifier::isValid('abc'));
        $this->assertFalse(IntegerIdentifier::isValid(''));
    }

    public function testIntegerIdentifierFromArrayThrowsOnInvalidType(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        IntegerIdentifier::fromArray(['integer' => 'not-a-number']);
    }

    // ─── Cross-identifier contract validation ──────────────────

    /**
     * @test
     */
    public function all_identifiers_implement_identifier_contract(): void
    {
        $identifiers = [
            AggregateRootId::generate(),
            TestUuidId::generate(),
            TestUlidId::generate(),
            StringIdentifier::from('test'),
            IntegerIdentifier::from(1),
        ];

        foreach ($identifiers as $id) {
            $this->assertInstanceOf(
                IdentifierContract::class,
                $id,
                get_class($id) . ' must implement IdentifierContract',
            );
            $this->assertInstanceOf(
                \Stringable::class,
                $id,
                get_class($id) . ' must be Stringable',
            );
        }
    }

    /**
     * @test
     */
    public function all_identifiers_provide_toString_fromString_round_trip(): void
    {
        $identifiers = [
            AggregateRootId::generate(),
            TestUuidId::generate(),
            TestUlidId::generate(),
            StringIdentifier::from('slug-value'),
            IntegerIdentifier::from(77),
        ];

        foreach ($identifiers as $original) {
            $class = $original::class;
            $restored = $class::fromString($original->toString());

            $this->assertSame(
                $original->toString(),
                $restored->toString(),
                "{$class}::fromString(toString()) should round-trip",
            );
        }
    }

    /**
     * @test
     */
    public function all_identifiers_provide_toArray_fromArray_round_trip(): void
    {
        $identifiers = [
            AggregateRootId::generate(),
            TestUuidId::generate(),
            TestUlidId::generate(),
            StringIdentifier::from('slug'),
            IntegerIdentifier::from(55),
        ];

        foreach ($identifiers as $original) {
            $class = $original::class;
            $restored = $class::fromArray($original->toArray());

            $this->assertSame(
                $original->toArray(),
                $restored->toArray(),
                "{$class}::fromArray(toArray()) should round-trip",
            );
        }
    }

    /**
     * @test
     */
    public function all_identifiers_have_jsonSerialize_method(): void
    {
        $identifiers = [
            AggregateRootId::generate(),
            TestUuidId::generate(),
            TestUlidId::generate(),
            StringIdentifier::from('test'),
            IntegerIdentifier::from(1),
        ];

        foreach ($identifiers as $id) {
            $json = json_encode($id);
            $this->assertNotFalse($json, get_class($id) . '::jsonSerialize() should be JSON-encodable');
            $this->assertNotEmpty($json);
        }
    }

    /**
     * @test
     */
    public function all_identifiers_have_equals_method_returning_bool(): void
    {
        $identifiers = [
            AggregateRootId::generate(),
            TestUuidId::generate(),
            TestUlidId::generate(),
            StringIdentifier::from('test'),
            IntegerIdentifier::from(1),
        ];

        foreach ($identifiers as $id) {
            $this->assertTrue($id->equals($id), get_class($id) . '::equals(self) should return true');
        }
    }

    /**
     * @test
     */
    public function all_identifiers_have_toString_method(): void
    {
        $identifiers = [
            AggregateRootId::generate(),
            TestUuidId::generate(),
            TestUlidId::generate(),
            StringIdentifier::from('test'),
            IntegerIdentifier::from(1),
        ];

        foreach ($identifiers as $id) {
            $this->assertIsString($id->toString());
            $this->assertNotEmpty($id->toString());
        }
    }

    /**
     * @test
     */
    public function all_identifiers_have_isValid_static_method(): void
    {
        $classes = [
            AggregateRootId::class,
            UuidIdentifier::class,
            UlidIdentifier::class,
            StringIdentifier::class,
            IntegerIdentifier::class,
        ];

        foreach ($classes as $class) {
            $this->assertTrue(
                method_exists($class, 'isValid'),
                "{$class} must have a static isValid() method",
            );
        }
    }

    /**
     * @test
     */
    public function all_identifiers_have_generate_static_method(): void
    {
        $classes = [
            AggregateRootId::class,
            UuidIdentifier::class,
            UlidIdentifier::class,
        ];

        foreach ($classes as $class) {
            $this->assertTrue(
                method_exists($class, 'generate'),
                "{$class} must have a static generate() method",
            );
        }
    }
}

// ─── Test fixture identifiers ────────────────────────────────────

final readonly class TestUuidId extends UuidIdentifier {}

final readonly class AnotherUuidId extends UuidIdentifier {}

final readonly class TestUlidId extends UlidIdentifier {}
