<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Tests\Unit\Identifiers;

use PHPUnit\Framework\TestCase;
use ZeroBoiler\Domain\Identifiers\IntegerIdentifier;
use ZeroBoiler\Domain\Identifiers\StringIdentifier;
use ZeroBoiler\Domain\Identifiers\UuidIdentifier;

/**
 * @covers \ZeroBoiler\Domain\Identifiers\UuidIdentifier
 * @covers \ZeroBoiler\Domain\Identifiers\StringIdentifier
 * @covers \ZeroBoiler\Domain\Identifiers\IntegerIdentifier
 */
final class IdentifierTest extends TestCase
{
    private const UUID = '550e8400-e29b-41d4-a716-446655440000';

    // ── UuidIdentifier (abstract — use test subclass) ────────────────

    /** Concrete subclass for testing the abstract UuidIdentifier. */
    private static function newUuidId(string $value): UuidIdentifier
    {
        return new class ($value) extends UuidIdentifier {};
    }

    private static function uuidIdClass(): string
    {
        return (new class ('00000000-0000-0000-0000-000000000000') extends UuidIdentifier {})::class;
    }

    public function test_uuid_from_string_creates_valid_id(): void
    {
        $id = self::newUuidId(self::UUID);

        self::assertSame(self::UUID, $id->value);
        self::assertSame(self::UUID, $id->toString());
        self::assertSame(self::UUID, (string) $id);
    }

    public function test_uuid_generate_creates_valid_uuid_v4(): void
    {
        $id = self::uuidIdClass()::generate();

        self::assertTrue(UuidIdentifier::isValid($id->value));
    }

    public function test_uuid_generate_creates_unique_values(): void
    {
        $cls = self::uuidIdClass();

        $a = $cls::generate();
        $b = $cls::generate();

        self::assertNotSame($a->value, $b->value);
    }

    public function test_uuid_from_string_throws_on_invalid(): void
    {
        $this->expectException(\Ramsey\Uuid\Exception\InvalidUuidStringException::class);

        self::newUuidId('not-a-uuid');
    }

    public function test_uuid_equals_same_class_same_value(): void
    {
        $a = self::newUuidId(self::UUID);
        $b = self::newUuidId(self::UUID);

        self::assertTrue($a->equals($b));
    }

    public function test_uuid_equals_different_value(): void
    {
        $a = self::newUuidId(self::UUID);
        $b = self::newUuidId('660e8400-e29b-41d4-a716-446655440001');

        self::assertFalse($a->equals($b));
    }

    public function test_uuid_equals_different_concrete_class(): void
    {
        $classA = (new class ('00000000-0000-0000-0000-000000000000') extends UuidIdentifier {})::class;
        $classB = (new class ('00000000-0000-0000-0000-000000000000') extends UuidIdentifier {})::class;

        $a = new $classA(self::UUID);
        $b = new $classB(self::UUID);

        // Different concrete classes — should NOT be equal
        self::assertFalse($a->equals($b));
    }

    public function test_uuid_to_array_roundtrip(): void
    {
        $id = self::newUuidId(self::UUID);
        $cls = self::uuidIdClass();
        $restored = $cls::fromArray($id->toArray());

        self::assertTrue($id->equals($restored));
    }

    public function test_uuid_to_array_structure(): void
    {
        $id = self::newUuidId(self::UUID);

        self::assertSame(['uuid' => self::UUID], $id->toArray());
    }

    public function test_uuid_from_json(): void
    {
        $json = json_encode(['uuid' => self::UUID], flags: JSON_THROW_ON_ERROR);
        $id = self::uuidIdClass()::fromJson($json);

        self::assertSame(self::UUID, $id->value);
    }

    public function test_uuid_json_serialize_returns_string(): void
    {
        $id = self::newUuidId(self::UUID);

        self::assertSame(self::UUID, $id->jsonSerialize());
    }

    public function test_uuid_is_valid(): void
    {
        self::assertTrue(UuidIdentifier::isValid(self::UUID));
        self::assertFalse(UuidIdentifier::isValid('not-a-uuid'));
        self::assertFalse(UuidIdentifier::isValid(''));
    }

    public function test_uuid_to_uuid_returns_ramsey_instance(): void
    {
        $id = self::newUuidId(self::UUID);

        self::assertInstanceOf(\Ramsey\Uuid\UuidInterface::class, $id->toUuid());
        self::assertSame(self::UUID, $id->toUuid()->toString());
    }

    // ── String Identifier (final) ───────────────────────────────────

    public function test_string_identifier_value(): void
    {
        $id = new StringIdentifier('order-123');

        self::assertSame('order-123', $id->value);
        self::assertSame('order-123', (string) $id);
    }

    public function test_string_identifier_empty_throws(): void
    {
        $this->expectException(\ValueError::class);

        new StringIdentifier('');
    }

    public function test_string_identifier_equals(): void
    {
        $a = new StringIdentifier('order-123');
        $b = new StringIdentifier('order-123');

        self::assertTrue($a->equals($b));
    }

    public function test_string_identifier_not_equals(): void
    {
        $a = new StringIdentifier('order-123');
        $b = new StringIdentifier('order-456');

        self::assertFalse($a->equals($b));
    }

    // ── Integer Identifier (final) ──────────────────────────────────

    public function test_integer_identifier_value(): void
    {
        $id = new IntegerIdentifier(42);

        self::assertSame(42, $id->value);
        self::assertSame('42', (string) $id);
    }

    public function test_integer_identifier_equals(): void
    {
        $a = new IntegerIdentifier(42);
        $b = new IntegerIdentifier(42);

        self::assertTrue($a->equals($b));
    }

    public function test_integer_identifier_not_equals(): void
    {
        $a = new IntegerIdentifier(42);
        $b = new IntegerIdentifier(43);

        self::assertFalse($a->equals($b));
    }
}
