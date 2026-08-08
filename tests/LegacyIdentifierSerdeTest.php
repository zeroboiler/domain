<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Tests;

use PHPUnit\Framework\TestCase;
use ZeroBoiler\Domain\Identifiers\Identifier;

/**
 * Tests for legacy Identifier toArray()/fromArray() round-trip serialization.
 *
 * Ensures the deprecated Identifier base class maintains serde parity
 * with the modern identifier types (UuidIdentifier, UlidIdentifier, etc.).
 *
 * @internal
 */
final class LegacyIdentifierSerdeTest extends TestCase
{
    /** @test */
    public function toArrayReturnsValueArrayWithString(): void
    {
        $id = ConcreteIdentifier::fromString('550e8400-e29b-41d4-a716-446655440000');

        $result = $id->toArray();

        $this->assertArrayHasKey('value', $result);
        $this->assertSame($id->toString(), $result['value']);
    }

    /** @test */
    public function fromArrayRestoresIdentifierFromGenerated(): void
    {
        $original = new ConcreteIdentifier; // generates UUID v4

        $restored = ConcreteIdentifier::fromArray($original->toArray());

        $this->assertSame($original->toString(), $restored->toString());
    }

    /** @test */
    public function fromArrayRejectsMissingValueKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid identifier data');

        ConcreteIdentifier::fromArray(['foo' => 'bar']);
    }

    /** @test */
    public function fromArrayRejectsNonStringValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid identifier data');

        ConcreteIdentifier::fromArray(['value' => 123]);
    }

    /** @test */
    public function fromArrayRejectsInvalidUuid(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ConcreteIdentifier::fromArray(['value' => 'not-a-uuid']);
    }

    /** @test */
    public function toArrayFromArrayRoundTripPreservesClass(): void
    {
        $original = ConcreteIdentifier::fromString('550e8400-e29b-41d4-a716-446655440000');
        $restored = ConcreteIdentifier::fromArray($original->toArray());

        $this->assertInstanceOf(ConcreteIdentifier::class, $restored);
        $this->assertSame('550e8400-e29b-41d4-a716-446655440000', $restored->toString());
        $this->assertTrue($original->equals($restored));
    }

    /** @test */
    public function toArrayIsConsistentWithJsonSerializeUsingNew(): void
    {
        $id = new ConcreteIdentifier; // generates UUID v4

        $this->assertSame($id->toString(), $id->toArray()['value']);
        $this->assertSame($id->jsonSerialize(), $id->toArray()['value']);
    }

    /** @test */
    public function toArrayFromArrayEmptyArrayRejects(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ConcreteIdentifier::fromArray([]);
    }
}

/** @internal Concrete test implementation of the legacy Identifier. */
final class ConcreteIdentifier extends Identifier {}
