<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace Tests\Domain;

use PHPUnit\Framework\TestCase;
use ZeroBoiler\Domain\Identifiers\UuidIdentifier;
use ZeroBoiler\Domain\Identifiers\UlidIdentifier;
use ZeroBoiler\Domain\Identifiers\StringIdentifier;
use ZeroBoiler\Domain\Identifiers\IntegerIdentifier;

/**
 * Tests for cross-identifier type inequality and immutability.
 *
 * Verifies that different identifier types are never equal to each other
 * and that all identifiers are truly immutable.
 */
final class IdentifierCrossTypeInequalityTest extends TestCase
{
    // ---- Cross-type inequality ----

    public function test_uuid_and_ulid_identifiers_are_never_equal(): void
    {
        $uuid = $this->createUuidId('550e8400-e29b-41d4-a716-446655440000');
        $ulid = $this->createUlidId('01H5J5K2Z8X7Y9W0');

        // Both are IdentifierContract implementations but different types
        $this->assertFalse($uuid->equals($ulid));
        $this->assertFalse($ulid->equals($uuid));
    }

    public function test_uuid_and_string_identifiers_are_never_equal(): void
    {
        $uuid = $this->createUuidId('550e8400-e29b-41d4-a716-446655440000');
        $string = StringIdentifier::from('550e8400-e29b-41d4-a716-446655440000');

        $this->assertFalse($uuid->equals($string));
        $this->assertFalse($string->equals($uuid));
    }

    public function test_uuid_and_integer_identifiers_are_never_equal(): void
    {
        $uuid = $this->createUuidId('550e8400-e29b-41d4-a716-446655440000');
        $int = IntegerIdentifier::from(42);

        $this->assertFalse($uuid->equals($int));
        $this->assertFalse($int->equals($uuid));
    }

    public function test_string_and_integer_identifiers_are_never_equal(): void
    {
        $string = StringIdentifier::from('42');
        $int = IntegerIdentifier::from(42);

        $this->assertFalse($string->equals($int));
        $this->assertFalse($int->equals($string));
    }

    // ---- Same-type equality ----

    public function test_same_uuid_identifiers_are_equal(): void
    {
        $a = $this->createUuidId('550e8400-e29b-41d4-a716-446655440000');
        $b = $this->createUuidId('550e8400-e29b-41d4-a716-446655440000');

        $this->assertTrue($a->equals($b));
    }

    public function test_different_uuid_identifiers_are_not_equal(): void
    {
        $a = $this->createUuidId('550e8400-e29b-41d4-a716-446655440000');
        $b = $this->createUuidId('550e8400-e29b-41d4-a716-446655440001');

        $this->assertFalse($a->equals($b));
    }

    public function test_same_string_identifiers_are_equal(): void
    {
        $a = StringIdentifier::from('my-slug');
        $b = StringIdentifier::from('my-slug');

        $this->assertTrue($a->equals($b));
    }

    public function test_same_integer_identifiers_are_equal(): void
    {
        $a = IntegerIdentifier::from(42);
        $b = IntegerIdentifier::from(42);

        $this->assertTrue($a->equals($b));
    }

    // ---- Immutability ----

    public function test_uuid_identifier_value_is_readonly(): void
    {
        $id = $this->createUuidId('550e8400-e29b-41d4-a716-446655440000');

        $this->assertSame('550e8400-e29b-41d4-a716-446655440000', $id->value);
        $this->assertSame('550e8400-e29b-41d4-a716-446655440000', $id->toString());
        $this->assertSame('550e8400-e29b-41d4-a716-446655440000', (string) $id);
    }

    public function test_ulid_identifier_value_is_readonly(): void
    {
        $id = $this->createUlidId('01H5J5K2Z8X7Y9W0V6T4R3Q');

        $this->assertSame('01H5J5K2Z8X7Y9W0V6T4R3Q', $id->value);
        $this->assertSame('01H5J5K2Z8X7Y9W0V6T4R3Q', $id->toString());
    }

    public function test_string_identifier_value_is_readonly(): void
    {
        $id = StringIdentifier::from('my-slug');

        $this->assertSame('my-slug', $id->value);
        $this->assertSame('my-slug', $id->toString());
    }

    public function test_integer_identifier_value_is_readonly(): void
    {
        $id = IntegerIdentifier::from(42);

        $this->assertSame(42, $id->value);
        $this->assertSame(42, $id->toInt());
        $this->assertSame('42', $id->toString());
    }

    // ---- Validation ----

    public function test_string_identifier_rejects_empty_string(): void
    {
        $this->expectException(\ValueError::class);
        StringIdentifier::from('');
    }

    public function test_string_identifier_is_valid_returns_false_for_empty(): void
    {
        $this->assertFalse(StringIdentifier::isValid(''));
    }

    public function test_string_identifier_is_valid_returns_true_for_non_empty(): void
    {
        $this->assertTrue(StringIdentifier::isValid('hello'));
    }

    public function test_integer_identifier_is_valid_returns_false_for_non_numeric(): void
    {
        $this->assertFalse(IntegerIdentifier::isValid('abc'));
    }

    public function test_integer_identifier_is_valid_returns_true_for_positive(): void
    {
        $this->assertTrue(IntegerIdentifier::isValid('42'));
    }

    public function test_integer_identifier_is_valid_returns_true_for_negative(): void
    {
        $this->assertTrue(IntegerIdentifier::isValid('-5'));
    }

    public function test_integer_identifier_is_valid_returns_true_for_zero(): void
    {
        $this->assertTrue(IntegerIdentifier::isValid('0'));
    }

    // ---- Serialization consistency ----

    public function test_all_identifiers_support_json_serialization(): void
    {
        $uuid = $this->createUuidId('550e8400-e29b-41d4-a716-446655440000');
        $ulid = $this->createUlidId('01H5J5K2Z8X7Y9W0V6T4R3Q');
        $string = StringIdentifier::from('my-slug');
        $integer = IntegerIdentifier::from(42);

        $this->assertIsString(json_encode($uuid));
        $this->assertIsString(json_encode($ulid));
        $this->assertIsString(json_encode($string));
        $this->assertIsString(json_encode($integer));
    }

    public function test_integer_json_serialize_returns_int_not_string(): void
    {
        $id = IntegerIdentifier::from(42);
        $json = json_encode($id);

        $this->assertSame('42', $json); // json_encode of int 42 gives "42"
        $this->assertSame(42, $id->jsonSerialize());
    }

    // ---- fromArray round-trip ----

    public function test_uuid_identifier_round_trip(): void
    {
        $original = $this->createUuidId('550e8400-e29b-41d4-a716-446655440000');
        $restored = $this->createUuidIdClass()::fromArray($original->toArray());

        $this->assertTrue($original->equals($restored));
    }

    public function test_ulid_identifier_round_trip(): void
    {
        $original = $this->createUlidId('01H5J5K2Z8X7Y9W0V6T4R3Q');
        $restored = $this->createUlidIdClass()::fromArray($original->toArray());

        $this->assertTrue($original->equals($restored));
    }

    public function test_string_identifier_round_trip(): void
    {
        $original = StringIdentifier::from('my-slug');
        $restored = StringIdentifier::fromArray($original->toArray());

        $this->assertTrue($original->equals($restored));
    }

    public function test_integer_identifier_round_trip(): void
    {
        $original = IntegerIdentifier::from(42);
        $restored = IntegerIdentifier::fromArray($original->toArray());

        $this->assertTrue($original->equals($restored));
    }

    // ---- fromArray with id key fallback ----

    public function test_uuid_from_array_accepts_id_key(): void
    {
        $restored = $this->createUuidIdClass()::fromArray(['id' => '550e8400-e29b-41d4-a716-446655440000']);

        $this->assertSame('550e8400-e29b-41d4-a716-446655440000', $restored->toString());
    }

    public function test_ulid_from_array_accepts_id_key(): void
    {
        $restored = $this->createUlidIdClass()::fromArray(['id' => '01H5J5K2Z8X7Y9W0V6T4R3Q']);

        $this->assertSame('01H5J5K2Z8X7Y9W0V6T4R3Q', $restored->toString());
    }

    public function test_string_from_array_accepts_id_key(): void
    {
        $restored = StringIdentifier::fromArray(['id' => 'my-slug']);

        $this->assertSame('my-slug', $restored->toString());
    }

    public function test_integer_from_array_accepts_id_as_string_key(): void
    {
        $restored = IntegerIdentifier::fromArray(['id' => '42']);

        $this->assertSame(42, $restored->toInt());
    }

    // ---- fromArray validation ----

    public function test_uuid_from_array_rejects_non_string(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->createUuidIdClass()::fromArray(['uuid' => 123]);
    }

    public function test_string_from_array_rejects_non_string(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        StringIdentifier::fromArray(['string' => 123]);
    }

    public function test_integer_from_array_rejects_non_int(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        IntegerIdentifier::fromArray(['integer' => 'abc']);
    }

    // ---- fromJson round-trip ----

    public function test_uuid_from_json_round_trip(): void
    {
        $original = $this->createUuidId('550e8400-e29b-41d4-a716-446655440000');
        $restored = $this->createUuidIdClass()::fromJson(json_encode($original->toArray()));

        $this->assertTrue($original->equals($restored));
    }

    public function test_integer_from_json_round_trip(): void
    {
        $original = IntegerIdentifier::from(42);
        $restored = IntegerIdentifier::fromJson(json_encode($original->toArray()));

        $this->assertTrue($original->equals($restored));
    }

    // ---- Helper methods to create abstract class instances ----

    private function createUuidId(string $uuid): UuidIdentifier
    {
        return new class($uuid) extends UuidIdentifier {};
    }

    private function createUuidIdClass(): string
    {
        return get_class(new class('00000000-0000-0000-0000-000000000000') extends UuidIdentifier {});
    }

    private function createUlidId(string $ulid): UlidIdentifier
    {
        return new class($ulid) extends UlidIdentifier {};
    }

    private function createUlidIdClass(): string
    {
        return get_class(new class('01H5J5K2Z8X7Y9W0V6T4R3Q') extends UlidIdentifier {});
    }
}
