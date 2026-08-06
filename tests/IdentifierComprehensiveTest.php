<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace Tests\Domain;

use ZeroBoiler\Domain\AggregateRoot;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\Contracts\Identifier;
use ZeroBoiler\Domain\Identifiers\IntegerIdentifier;
use ZeroBoiler\Domain\Identifiers\StringIdentifier;
use ZeroBoiler\Domain\Identifiers\UuidIdentifier;
use ZeroBoiler\Domain\Identifiers\UlidIdentifier;
use ZeroBoiler\Events\Domain\DomainEvent;

/**
 * Comprehensive domain identifier serialization and cross-type tests.
 *
 * Verifies all identifier types implement IdentifierContract, JsonSerializable,
 * and \Stringable with correct behavior.
 *
 * @see Identifier
 * @see UuidIdentifier
 * @see UlidIdentifier
 * @see StringIdentifier
 * @see IntegerIdentifier
 */
final class IdentifierComprehensiveTest extends \PHPUnit\Framework\TestCase
{
    // ─── UuidIdentifier ──────────────────────────────────────────────

    public function testUuidIdentifierImplementsContracts(): void
    {
        $this->assertContainsEquals(
            Identifier::class,
            class_implements(TestUuidId::class),
        );
        $this->assertContainsEquals(
            \JsonSerializable::class,
            class_implements(TestUuidId::class),
        );
        $this->assertContainsEquals(
            \Stringable::class,
            class_implements(TestUuidId::class),
        );
    }

    public function testUuidIdentifierGenerateCreatesValidInstance(): void
    {
        $id = TestUuidId::generate();

        $this->assertInstanceOf(TestUuidId::class, $id);
        $this->assertNotEmpty($id->toString());
        $this->assertNotEmpty((string) $id);
    }

    public function testUuidIdentifierFromStringParsesCorrectly(): void
    {
        $original = TestUuidId::generate();
        $parsed = TestUuidId::fromString($original->toString());

        $this->assertTrue($original->equals($parsed));
        $this->assertSame($original->toString(), $parsed->toString());
    }

    public function testUuidIdentifierEqualityIsTypeSafe(): void
    {
        $id1 = TestUuidId::generate();
        $id2 = TestUuidId::fromString($id1->toString());
        $id3 = TestUuidId::generate();

        $this->assertTrue($id1->equals($id2));
        $this->assertFalse($id1->equals($id3));
    }

    public function testUuidIdentifierEqualityRejectsDifferentSubtype(): void
    {
        $orderId = TestUuidId::generate();
        $productId = TestProductId::generate();

        $this->assertFalse($orderId->equals($productId));
    }

    public function testUuidIdentifierJsonSerializeReturnsString(): void
    {
        $id = TestUuidId::generate();
        $json = json_encode($id);

        $this->assertIsString($json);
        $this->assertSame('"' . $id->toString() . '"', $json);
    }

    public function testUuidIdentifierInArrayContext(): void
    {
        $id = TestUuidId::generate();
        $data = ['order_id' => $id];

        $this->assertSame($id->toString(), $data['order_id']);
        $this->assertSame('"' . $id->toString() . '"', json_encode($data['order_id']));
    }

    // ─── UlidIdentifier ───────────────────────────────────────────────

    public function testUlidIdentifierImplementsContracts(): void
    {
        $this->assertContainsEquals(
            Identifier::class,
            class_implements(TestUlidId::class),
        );
        $this->assertContainsEquals(
            \JsonSerializable::class,
            class_implements(TestUlidId::class),
        );
    }

    public function testUlidIdentifierGenerateCreatesValidInstance(): void
    {
        $id = TestUlidId::generate();

        $this->assertInstanceOf(TestUlidId::class, $id);
        $this->assertNotEmpty($id->toString());
        $this->assertSame(26, strlen($id->toString())); // ULID is 26 chars
    }

    public function testUlidIdentifierIsValid(): void
    {
        $id = TestUlidId::generate();
        $this->assertTrue(TestUlidId::isValid($id->toString()));
        $this->assertFalse(TestUlidId::isValid('invalid'));
    }

    // ─── StringIdentifier ────────────────────────────────────────────

    public function testStringIdentifierImplementsContracts(): void
    {
        $this->assertContainsEquals(
            Identifier::class,
            class_implements(StringIdentifier::class),
        );
    }

    public function testStringIdentifierRejectsEmptyString(): void
    {
        $this->expectException(\ValueError::class);
        StringIdentifier::from('');
    }

    public function testStringIdentifierFromCreatesInstance(): void
    {
        $slug = StringIdentifier::from('my-blog-post');

        $this->assertSame('my-blog-post', $slug->toString());
        $this->assertSame('my-blog-post', (string) $slug);
    }

    public function testStringIdentifierEquality(): void
    {
        $a = StringIdentifier::from('hello');
        $b = StringIdentifier::from('hello');
        $c = StringIdentifier::from('world');

        $this->assertTrue($a->equals($b));
        $this->assertFalse($a->equals($c));
    }

    public function testStringIdentifierJsonSerialize(): void
    {
        $slug = StringIdentifier::from('my-post');
        $this->assertSame('"my-post"', json_encode($slug));
    }

    // ─── IntegerIdentifier ─────────────────────────────────────────

    public function testIntegerIdentifierImplementsContracts(): void
    {
        $this->assertContainsEquals(
            Identifier::class,
            class_implements(IntegerIdentifier::class),
        );
    }

    public function testIntegerIdentifierFromCreatesInstance(): void
    {
        $id = IntegerIdentifier::from(42);

        $this->assertSame(42, $id->toInt());
        $this->assertSame('42', $id->toString());
        $this->assertSame('42', (string) $id);
    }

    public function testIntegerIdentifierEquality(): void
    {
        $a = IntegerIdentifier::from(42);
        $b = IntegerIdentifier::from(42);
        $c = IntegerIdentifier::from(99);

        $this->assertTrue($a->equals($b));
        $this->assertFalse($a->equals($c));
    }

    public function testIntegerIdentifierJsonSerializeReturnsInt(): void
    {
        $id = IntegerIdentifier::from(42);
        $this->assertSame('42', json_encode($id)); // int serializes as number
    }

    public function testIntegerIdentifierIsValid(): void
    {
        $this->assertTrue(IntegerIdentifier::isValid('42'));
        $this->assertTrue(IntegerIdentifier::isValid('0'));
        $this->assertTrue(IntegerIdentifier::isValid('-5'));
        $this->assertFalse(IntegerIdentifier::isValid('abc'));
    }

    // ─── Cross-type serialization ───────────────────────────────────

    public function testAllIdentifiersSerializeCleanlyInApiResponse(): void
    {
        $data = [
            'uuid_id' => TestUuidId::generate(),
            'ulid_id' => TestUlidId::generate(),
            'slug' => StringIdentifier::from('my-post'),
            'seq' => IntegerIdentifier::from(42),
        ];

        $json = json_encode($data);
        $this->assertIsString($json);
        $this->assertNotEmpty($json);

        // Verify all keys are present in JSON
        $decoded = json_decode($json, true);
        $this->assertArrayHasKey('uuid_id', $decoded);
        $this->assertArrayHasKey('ulid_id', $decoded);
        $this->assertArrayHasKey('slug', $decoded);
        $this->assertArrayHasKey('seq', $decoded);
    }
}

// ─── Test Fixtures ───────────────────────────────────────────────────

final readonly class TestUuidId extends UuidIdentifier {}

final readonly class TestProductId extends UuidIdentifier {}

final readonly class TestUlidId extends UlidIdentifier {}
