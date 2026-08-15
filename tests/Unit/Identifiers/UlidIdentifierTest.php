<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Tests\Unit\Identifiers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ZeroBoiler\Domain\Identifiers\UlidIdentifier;

#[CoversClass(UlidIdentifier::class)]
#[Group('unit')]
#[Group('identifiers')]
final class UlidIdentifierTest extends TestCase
{
    public function testGenerateCreatesValidUlid(): void
    {
        $id = UlidIdentifier::generate();

        $this->assertInstanceOf(UlidIdentifier::class, $id);
        $this->assertMatchesRegularExpression(
            '/^[0-9A-HJKMNP-TV-Z]{26}$/',
            $id->toString(),
        );
    }

    public function testGenerateIsMonotonic(): void
    {
        $a = UlidIdentifier::generate();
        $b = UlidIdentifier::generate();

        $this->assertGreaterThan(
            $a->toString(),
            $b->toString(),
            'ULIDs should be monotonically increasing',
        );
    }

    public function testFromStringWithValidUlid(): void
    {
        $ulid = '01H5J8K6M2N4P7Q9R0S1T2U3V4';
        $id = UlidIdentifier::fromString($ulid);

        $this->assertSame($ulid, $id->toString());
    }

    public function testFromStringRejectsInvalidUlid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid ULID');

        UlidIdentifier::fromString('not-a-ulid!!!');
    }

    public function testEquality(): void
    {
        $ulid = '01H5J8K6M2N4P7Q9R0S1T2U3V4';
        $a = UlidIdentifier::fromString($ulid);
        $b = UlidIdentifier::fromString($ulid);

        $this->assertTrue($a->equals($b));
    }

    public function testInequality(): void
    {
        $a = UlidIdentifier::generate();
        $b = UlidIdentifier::generate();

        $this->assertFalse($a->equals($b));
    }

    public function testToArrayRoundTrip(): void
    {
        $id = UlidIdentifier::generate();
        $restored = UlidIdentifier::fromArray($id->toArray());

        $this->assertTrue($id->equals($restored));
    }

    public function testToJsonRoundTrip(): void
    {
        $id = UlidIdentifier::generate();
        $json = $id->toJson();
        $restored = UlidIdentifier::fromJson($json);

        $this->assertTrue($id->equals($restored));
    }

    public function testPhpSerializeRoundTrip(): void
    {
        $id = UlidIdentifier::generate();
        $restored = unserialize(serialize($id));

        $this->assertInstanceOf(UlidIdentifier::class, $restored);
        $this->assertTrue($id->equals($restored));
    }

    public function testToUlidReturnsSymfonyUlid(): void
    {
        $id = UlidIdentifier::generate();
        $symfonyUlid = $id->toUlid();

        $this->assertSame($id->toString(), $symfonyUlid->toString());
    }
}
