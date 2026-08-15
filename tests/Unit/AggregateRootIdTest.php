<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ZeroBoiler\Domain\AggregateRootId;

#[CoversClass(AggregateRootId::class)]
#[Group('unit')]
#[Group('identifiers')]
final class AggregateRootIdTest extends TestCase
{
    public function testGenerateCreatesUuidV4(): void
    {
        $id = AggregateRootId::generate();

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $id->toString(),
        );
    }

    public function testFromStringWithValidUuid(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $id = AggregateRootId::fromString($uuid);

        $this->assertSame($uuid, $id->toString());
    }

    public function testFromStringRejectsInvalidUuid(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        AggregateRootId::fromString('invalid');
    }

    public function testEquality(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $a = AggregateRootId::fromString($uuid);
        $b = AggregateRootId::fromString($uuid);

        $this->assertTrue($a->equals($b));
    }

    public function testInequality(): void
    {
        $a = AggregateRootId::fromString('550e8400-e29b-41d4-a716-446655440000');
        $b = AggregateRootId::fromString('660e8400-e29b-41d4-a716-446655440000');

        $this->assertFalse($a->equals($b));
    }

    public function testToArrayRoundTrip(): void
    {
        $id = AggregateRootId::generate();
        $restored = AggregateRootId::fromArray($id->toArray());

        $this->assertTrue($id->equals($restored));
    }

    public function testToJsonRoundTrip(): void
    {
        $id = AggregateRootId::generate();
        $json = $id->toJson();
        $restored = AggregateRootId::fromJson($json);

        $this->assertTrue($id->equals($restored));
    }

    public function testJsonSerializeReturnsArray(): void
    {
        $id = AggregateRootId::generate();
        $data = $id->jsonSerialize();

        $this->assertIsArray($data);
        $this->assertArrayHasKey('uuid', $data);
    }

    public function testPhpSerializeRoundTrip(): void
    {
        $id = AggregateRootId::generate();
        $restored = unserialize(serialize($id));

        $this->assertInstanceOf(AggregateRootId::class, $restored);
        $this->assertTrue($id->equals($restored));
    }

    public function testIsFinalAndReadonly(): void
    {
        $reflection = new \ReflectionClass(AggregateRootId::class);

        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
    }
}
