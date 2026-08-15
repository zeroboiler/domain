<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Tests\Unit\Identifiers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ZeroBoiler\Domain\Identifiers\StringIdentifier;

#[CoversClass(StringIdentifier::class)]
#[Group('unit')]
#[Group('identifiers')]
final class StringIdentifierTest extends TestCase
{
    public function testFromStringWithValidValue(): void
    {
        $id = StringIdentifier::from('my-blog-post');

        $this->assertSame('my-blog-post', $id->toString());
    }

    public function testFromStringRejectsEmpty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('non-empty string');

        StringIdentifier::from('');
    }

    public function testFromStringRejectsWhitespaceOnly(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        StringIdentifier::from('   ');
    }

    public function testIsValid(): void
    {
        $this->assertTrue(StringIdentifier::isValid('hello'));
        $this->assertFalse(StringIdentifier::isValid(''));
    }

    public function testEquality(): void
    {
        $a = StringIdentifier::from('slug-a');
        $b = StringIdentifier::from('slug-a');

        $this->assertTrue($a->equals($b));
    }

    public function testInequality(): void
    {
        $a = StringIdentifier::from('slug-a');
        $b = StringIdentifier::from('slug-b');

        $this->assertFalse($a->equals($b));
    }

    public function testToArrayRoundTrip(): void
    {
        $id = StringIdentifier::from('test-slug');
        $restored = StringIdentifier::fromArray($id->toArray());

        $this->assertTrue($id->equals($restored));
    }

    public function testToJsonRoundTrip(): void
    {
        $id = StringIdentifier::from('test-slug');
        $json = $id->toJson();
        $restored = StringIdentifier::fromJson($json);

        $this->assertTrue($id->equals($restored));
    }

    public function testPhpSerializeRoundTrip(): void
    {
        $id = StringIdentifier::from('test-slug');
        $restored = unserialize(serialize($id));

        $this->assertInstanceOf(StringIdentifier::class, $restored);
        $this->assertTrue($id->equals($restored));
    }

    public function testJsonSerializeReturnsArray(): void
    {
        $id = StringIdentifier::from('test');
        $data = $id->jsonSerialize();

        $this->assertIsArray($data);
        $this->assertArrayHasKey('value', $data);
        $this->assertSame('test', $data['value']);
    }
}
