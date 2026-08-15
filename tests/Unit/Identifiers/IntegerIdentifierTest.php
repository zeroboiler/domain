<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Tests\Unit\Identifiers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ZeroBoiler\Domain\Identifiers\IntegerIdentifier;

#[CoversClass(IntegerIdentifier::class)]
#[Group('unit')]
#[Group('identifiers')]
final class IntegerIdentifierTest extends TestCase
{
    public function testFromInt(): void
    {
        $id = IntegerIdentifier::from(42);

        $this->assertSame(42, $id->toInt());
        $this->assertSame('42', $id->toString());
    }

    public function testFromStringParsesNumeric(): void
    {
        $id = IntegerIdentifier::fromString('100');

        $this->assertSame(100, $id->toInt());
    }

    public function testFromStringRejectsNonNumeric(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('integer');

        IntegerIdentifier::fromString('not-a-number');
    }

    public function testEquality(): void
    {
        $a = IntegerIdentifier::from(42);
        $b = IntegerIdentifier::from(42);

        $this->assertTrue($a->equals($b));
    }

    public function testInequality(): void
    {
        $a = IntegerIdentifier::from(42);
        $b = IntegerIdentifier::from(43);

        $this->assertFalse($a->equals($b));
    }

    public function testToArrayRoundTrip(): void
    {
        $id = IntegerIdentifier::from(999);
        $restored = IntegerIdentifier::fromArray($id->toArray());

        $this->assertTrue($id->equals($restored));
    }

    public function testToJsonRoundTrip(): void
    {
        $id = IntegerIdentifier::from(999);
        $json = $id->toJson();
        $restored = IntegerIdentifier::fromJson($json);

        $this->assertTrue($id->equals($restored));
    }

    public function testPhpSerializeRoundTrip(): void
    {
        $id = IntegerIdentifier::from(123);
        $restored = unserialize(serialize($id));

        $this->assertInstanceOf(IntegerIdentifier::class, $restored);
        $this->assertTrue($id->equals($restored));
    }

    public function testJsonSerializeReturnsArray(): void
    {
        $id = IntegerIdentifier::from(42);
        $data = $id->jsonSerialize();

        $this->assertIsArray($data);
        $this->assertArrayHasKey('value', $data);
        $this->assertSame(42, $data['value']);
    }
}
