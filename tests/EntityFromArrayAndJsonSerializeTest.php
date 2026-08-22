<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace Tests\Domain\Entity;

use PHPUnit\Framework\TestCase;
use ZeroBoiler\Domain\Entity;
use ZeroBoiler\Domain\Identifiers\UuidIdentifier;
use ZeroBoiler\Domain\Identifiers\IntegerIdentifier;
use ZeroBoiler\Domain\Identifiers\StringIdentifier;

/**
 * Tests for Entity::fromArray() round-trip hydration and JsonSerializable support.
 *
 * Validates that entities can be:
 * 1. Constructed via fromArray() with reflection-based hydration
 * 2. Serialized to array via toArray() and back via fromArray()
 * 3. Serialized to JSON via jsonSerialize() / json_encode()
 * 4. Round-tripped with typed identifiers (Stringable, int, string)
 *
 * @covers \ZeroBoiler\Domain\Entity::fromArray
 * @covers \ZeroBoiler\Domain\Entity::jsonSerialize
 *
 * @since 2.9.0
 */
final class EntityFromArrayAndJsonSerializeTest extends TestCase
{
    // ──────────────────────────────────────────────
    // Test fixture: simple entity with mixed ID types
    // ──────────────────────────────────────────────

    private function newOrderItem(array $args): TestOrderItem
    {
        return TestOrderItem::fromArray($args);
    }

    // ── fromArray() basic hydration ────────────────

    public function testFromArrayWithStringId(): void
    {
        $item = $this->newOrderItem([
            'id' => 'item-42',
            'productId' => 'prod-100',
            'quantity' => 5,
        ]);

        self::assertSame('item-42', $item->id());
        self::assertSame('prod-100', $item->productId);
        self::assertSame(5, $item->quantity);
        self::assertSame('TestOrderItem', $item->toArray()['type']);
    }

    public function testFromArrayWithIntegerId(): void
    {
        $item = $this->newOrderItem([
            'id' => 99,
            'productId' => 'prod-200',
            'quantity' => 10,
        ]);

        self::assertSame('99', $item->id()); // id() always returns string
        self::assertSame('prod-200', $item->productId);
    }

    public function testFromArrayWithStringableId(): void
    {
        $uuid = TestOrderId::generate();
        $item = $this->newOrderItem([
            'id' => $uuid,
            'productId' => 'prod-300',
            'quantity' => 3,
        ]);

        self::assertSame($uuid->toString(), $item->id());
        self::assertSame('prod-300', $item->productId);
    }

    // ── fromArray() ignores extra keys ───────────

    public function testFromArrayIgnoresExtraKeys(): void
    {
        $item = $this->newOrderItem([
            'id' => 'item-1',
            'productId' => 'prod-1',
            'quantity' => 1,
            'extra_field' => 'should be ignored',
            'another_extra' => 42,
        ]);

        self::assertSame('item-1', $item->id());
        self::assertSame('prod-1', $item->productId);
        self::assertSame(1, $item->quantity);
    }

    // ── fromArray() uses default for optional params ──

    public function testFromArrayUsesDefaultForOptionalParams(): void
    {
        $item = TestSimpleEntity::fromArray([
            'id' => 'simple-1',
        ]);

        self::assertSame('simple-1', $item->id());
        self::assertSame('default-name', $item->name);
    }

    // ── fromArray() throws on missing required param ──

    public function testFromArrayThrowsOnMissingRequiredParameter(): void
    {
        $this->expectException(\ArgumentCountError::class);
        $this->expectExceptionMessage('Missing required parameter "productId"');

        $this->newOrderItem([
            'id' => 'item-1',
            // 'productId' missing — should throw
            'quantity' => 1,
        ]);
    }

    // ── toArray() / fromArray() round-trip ──────────

    public function testToArrayFromArrayRoundTrip(): void
    {
        $original = $this->newOrderItem([
            'id' => 'item-99',
            'productId' => 'prod-500',
            'quantity' => 7,
        ]);

        $array = $original->toArray();
        $restored = TestOrderItem::fromArray($array);

        self::assertSame($original->id(), $restored->id());
        self::assertSame($original->productId, $restored->productId);
        self::assertSame($original->quantity, $restored->quantity);
        self::assertTrue($original->equals($restored));
    }

    public function testRoundTripPreservesTypeField(): void
    {
        $item = $this->newOrderItem([
            'id' => 'item-1',
            'productId' => 'prod-1',
            'quantity' => 1,
        ]);

        $array = $item->toArray();

        self::assertArrayHasKey('id', $array);
        self::assertArrayHasKey('type', $array);
        self::assertSame('TestOrderItem', $array['type']);
    }

    // ── JsonSerializable ──────────────────────────

    public function testJsonSerializeReturnsArray(): void
    {
        $item = $this->newOrderItem([
            'id' => 'item-1',
            'productId' => 'prod-1',
            'quantity' => 3,
        ]);

        $result = $item->jsonSerialize();

        self::assertIsArray($result);
        self::assertSame('item-1', $result['id']);
        self::assertSame('TestOrderItem', $result['type']);
    }

    public function testJsonEncodeProducesValidJson(): void
    {
        $item = $this->newOrderItem([
            'id' => 'item-1',
            'productId' => 'prod-1',
            'quantity' => 3,
        ]);

        $json = json_encode($item);

        self::assertIsString($json);
        self::assertNotFalse($json);

        $decoded = json_decode($json, true);
        self::assertSame('item-1', $decoded['id']);
        self::assertSame('TestOrderItem', $decoded['type']);
    }

    public function testJsonEncodeRoundTrip(): void
    {
        $original = $this->newOrderItem([
            'id' => 'item-42',
            'productId' => 'prod-42',
            'quantity' => 42,
        ]);

        $json = json_encode($original);
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $restored = TestOrderItem::fromArray($decoded);

        self::assertSame($original->id(), $restored->id());
        self::assertSame($original->productId, $restored->productId);
        self::assertSame($original->quantity, $restored->quantity);
    }

    // ── Equality ─────────────────────────────────

    public function testEqualsReturnsTrueForSameIdAndClass(): void
    {
        $a = $this->newOrderItem(['id' => 'item-1', 'productId' => 'p1', 'quantity' => 1]);
        $b = $this->newOrderItem(['id' => 'item-1', 'productId' => 'p2', 'quantity' => 2]);

        self::assertTrue($a->equals($b));
    }

    public function testEqualsReturnsFalseForDifferentId(): void
    {
        $a = $this->newOrderItem(['id' => 'item-1', 'productId' => 'p1', 'quantity' => 1]);
        $b = $this->newOrderItem(['id' => 'item-2', 'productId' => 'p1', 'quantity' => 1]);

        self::assertFalse($a->equals($b));
    }

    public function testEqualsReturnsFalseForDifferentClass(): void
    {
        $item = $this->newOrderItem(['id' => 'item-1', 'productId' => 'p1', 'quantity' => 1]);
        $other = TestOtherEntity::fromArray(['id' => 'item-1']);

        self::assertFalse($item->equals($other));
    }

    // ── fromArray() with nested constructor ─────────

    public function testFromArrayWithIntegerIdentifier(): void
    {
        $entity = TestIdEntity::fromArray([
            'id' => 42,
            'name' => 'Test',
        ]);

        self::assertSame('42', $entity->id());
        self::assertSame('Test', $entity->name);
    }

    // ── fromArray() with no constructor ───────────

    public function testFromArrayWithNoConstructor(): void
    {
        $entity = TestNoConstructorEntity::fromArray(['extra' => 'data']);

        self::assertInstanceOf(TestNoConstructorEntity::class, $entity);
    }
}

// ──────────────────────────────────────────────────
// Fixtures
// ──────────────────────────────────────────────────

final class TestOrderId extends UuidIdentifier {}

final class TestOrderItem extends Entity
{
    public function __construct(
        int|string|\Stringable $id,
        public readonly string $productId,
        public readonly int $quantity,
    ) {
        parent::__construct($id);
    }

    public function toArray(): array
    {
        return [
            ...parent::toArray(),
            'product_id' => $this->productId,
            'quantity' => $this->quantity,
        ];
    }
}

final class TestSimpleEntity extends Entity
{
    public function __construct(
        int|string|\Stringable $id,
        public string $name = 'default-name',
    ) {
        parent::__construct($id);
    }
}

final class TestOtherEntity extends Entity {}

final class TestIdEntity extends Entity
{
    public function __construct(
        int|string|\Stringable $id,
        public readonly string $name,
    ) {
        parent::__construct($id);
    }
}

final class TestNoConstructorEntity extends Entity
{
    public function __construct()
    {
        // Deliberately no parent::__construct() call — testing edge case
    }
}
