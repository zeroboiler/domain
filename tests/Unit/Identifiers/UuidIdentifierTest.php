<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Tests\Unit\Identifiers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ZeroBoiler\Domain\Identifiers\UuidIdentifier;

#[CoversClass(UuidIdentifier::class)]
#[Group('unit')]
#[Group('identifiers')]
final class UuidIdentifierTest extends TestCase
{
    private static ?string $generatedUuid = null;

    // ─── Generation ─────────────────────────────────────────────

    public function testGenerateCreatesValidUuidV4(): void
    {
        $id = UuidIdentifier::generate();

        $this->assertInstanceOf(UuidIdentifier::class, $id);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $id->toString(),
        );

        self::$generatedUuid = $id->toString();
    }

    public function testGenerateProducesUniqueValues(): void
    {
        $ids = array_map(fn () => UuidIdentifier::generate()->toString(), range(1, 100));

        $this->assertCount(100, array_unique($ids), 'Generated UUIDs must be unique');
    }

    // ─── fromString ──────────────────────────────────────────────

    public function testFromStringWithValidUuid(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $id = UuidIdentifier::fromString($uuid);

        $this->assertSame($uuid, $id->toString());
    }

    public function testFromStringRejectsInvalidUuid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid UUID');

        UuidIdentifier::fromString('not-a-uuid');
    }

    public function testFromStringRejectsEmptyString(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        UuidIdentifier::fromString('');
    }

    // ─── Equality ─────────────────────────────────────────────────

    public function testEqualityWithSameValue(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $a = UuidIdentifier::fromString($uuid);
        $b = UuidIdentifier::fromString($uuid);

        $this->assertTrue($a->equals($b));
    }

    public function testEqualityWithDifferentValue(): void
    {
        $a = UuidIdentifier::fromString('550e8400-e29b-41d4-a716-446655440000');
        $b = UuidIdentifier::fromString('660e8400-e29b-41d4-a716-446655440000');

        $this->assertFalse($a->equals($b));
    }

    public function testEqualsWithDifferentType(): void
    {
        $a = UuidIdentifier::fromString('550e8400-e29b-41d4-a716-446655440000');
        $b = new class ('550e8400-e29b-41d4-a716-446655440000') extends UuidIdentifier {};

        $this->assertTrue($a->equals($b));
    }

    // ─── Serialization ──────────────────────────────────────────

    public function testToArrayRoundTrip(): void
    {
        $id = UuidIdentifier::fromString('550e8400-e29b-41d4-a716-446655440000');
        $restored = UuidIdentifier::fromArray($id->toArray());

        $this->assertTrue($id->equals($restored));
    }

    public function testToJsonRoundTrip(): void
    {
        $id = UuidIdentifier::generate();
        $json = $id->toJson();
        $restored = UuidIdentifier::fromJson($json);

        $this->assertTrue($id->equals($restored));
    }

    public function testJsonSerializeReturnsArray(): void
    {
        $id = UuidIdentifier::generate();
        $data = $id->jsonSerialize();

        $this->assertIsArray($data);
        $this->assertArrayHasKey('uuid', $data);
    }

    public function testJsonEncodeReturnsString(): void
    {
        $id = UuidIdentifier::generate();

        $this->assertIsString(json_encode($id));
        $this->assertStringContainsString('"uuid":', json_encode($id));
    }

    // ─── PHP serialize/unserialize ───────────────────────────────

    public function testPhpSerializeRoundTrip(): void
    {
        $id = UuidIdentifier::generate();
        $serialized = serialize($id);
        $restored = unserialize($serialized);

        $this->assertInstanceOf(UuidIdentifier::class, $restored);
        $this->assertTrue($id->equals($restored));
    }

    // ─── String casting ──────────────────────────────────────────

    public function testToStringMatchesGetString(): void
    {
        $id = UuidIdentifier::generate();

        $this->assertSame($id->toString(), (string) $id);
    }
}
