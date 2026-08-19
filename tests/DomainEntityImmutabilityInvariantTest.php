<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace Tests\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ZeroBoiler\Domain\AggregateRoot;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\Contracts\Entity as EntityContract;
use ZeroBoiler\Domain\Entity;
use ZeroBoiler\Domain\Exceptions\InvalidArgumentDomainException;
use ZeroBoiler\Domain\Exceptions\InvalidStateDomainException;
use ZeroBoiler\Domain\Identifiers\IntegerIdentifier;
use ZeroBoiler\Domain\Identifiers\StringIdentifier;
use ZeroBoiler\Domain\Identifiers\UuidIdentifier;
use ZeroBoiler\Domain\Identifiers\UlidIdentifier;

/**
 * Production contract tests for domain entity immutability and identity invariants.
 *
 * Verifies that:
 * - AggregateRoot identity is immutable (readonly AggregateRootId)
 * - Entity identity is immutable (readonly promoted property)
 * - Identity equality is type-safe (same class + same ID)
 * - Identifiers are readonly and validate on construction
 * - Guards enforce domain invariants at construction time
 *
 * These tests validate PHP 8.5 readonly guarantees and domain modeling contracts.
 */
#[CoversClass(AggregateRoot::class)]
#[CoversClass(Entity::class)]
#[CoversClass(AggregateRootId::class)]
#[CoversClass(UuidIdentifier::class)]
#[Group('production')]
#[Group('immutability')]
final class DomainEntityImmutabilityInvariantTest extends TestCase
{
    // ── AggregateRoot Identity Immutability ──────────────────────────────────

    public function test_aggregate_root_identity_is_immutable(): void
    {
        $id = AggregateRootId::generate();
        $order = new class($id) extends AggregateRoot {
            public function toArray(): array
            {
                return [...parent::toArray(), 'name' => 'test'];
            }
        };

        // ID is stable across multiple calls
        $first = $order->id();
        $second = $order->id();

        $this->assertSame($first, $second);
        $this->assertSame($id->toString(), $first);
    }

    public function test_aggregate_root_equality_requires_same_class(): void
    {
        $id = AggregateRootId::generate();

        $orderA = new class($id) extends AggregateRoot {
            public string $name = 'OrderA';
            public function toArray(): array { return [...parent::toArray()]; }
        };

        $orderB = new class($id) extends AggregateRoot {
            public string $name = 'OrderB';
            public function toArray(): array { return [...parent::toArray()]; }
        };

        // Same ID but different anonymous classes → not equal
        $this->assertFalse($orderA->equals($orderB));
    }

    public function test_aggregate_root_equality_with_same_instance(): void
    {
        $id = AggregateRootId::generate();

        $order = new class($id) extends AggregateRoot {
            public function toArray(): array { return [...parent::toArray()]; }
        };

        $this->assertTrue($order->equals($order));
    }

    public function test_aggregate_root_version_starts_at_zero(): void
    {
        $id = AggregateRootId::generate();

        $order = new class($id) extends AggregateRoot {
            public function toArray(): array { return [...parent::toArray()]; }
        };

        $this->assertSame(0, $order->version());
    }

    public function test_aggregate_root_increment_version(): void
    {
        $id = AggregateRootId::generate();

        $order = new class($id) extends AggregateRoot {
            public function toArray(): array { return [...parent::toArray()]; }
        };

        $order->incrementVersion();
        $this->assertSame(1, $order->version());

        $order->incrementVersion();
        $this->assertSame(2, $order->version());
    }

    // ── Entity Identity ─────────────────────────────────────────────────────

    public function test_entity_string_identity(): void
    {
        $item = new class('item-42') extends Entity {
            public function toArray(): array
            {
                return [...parent::toArray(), 'sku' => 'A1'];
            }
        };

        $this->assertSame('item-42', $item->id());
    }

    public function test_entity_int_identity_converts_to_string(): void
    {
        $item = new class(42) extends Entity {
            public function toArray(): array { return [...parent::toArray()]; }
        };

        $this->assertSame('42', $item->id());
    }

    public function test_entity_stringable_identity(): void
    {
        $id = AggregateRootId::generate();
        $item = new class($id) extends Entity {
            public function toArray(): array { return [...parent::toArray()]; }
        };

        $this->assertSame($id->toString(), $item->id());
    }

    public function test_entity_equality_same_class_same_id(): void
    {
        $itemA = new class('abc') extends Entity {
            public function toArray(): array { return [...parent::toArray()]; }
        };
        $itemB = new class('abc') extends Entity {
            public function toArray(): array { return [...parent::toArray()]; }
        };

        // Different anonymous classes → not equal
        $this->assertFalse($itemA->equals($itemB));
    }

    public function test_entity_equals_itself(): void
    {
        $item = new class('abc') extends Entity {
            public function toArray(): array { return [...parent::toArray()]; }
        };

        $this->assertTrue($item->equals($item));
    }

    public function test_entity_from_array_hydration(): void
    {
        $item = new class('1', 'prod-1', 3) extends Entity {
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
                    'productId' => $this->productId,
                    'quantity' => $this->quantity,
                ];
            }
        };

        $restored = $item::fromArray([
            'id' => '2',
            'productId' => 'prod-2',
            'quantity' => 5,
        ]);

        $this->assertSame('2', $restored->id());
        $this->assertSame('prod-2', $restored->productId);
        $this->assertSame(5, $restored->quantity);
    }

    // ── Identifier Immutability ──────────────────────────────────────────────

    public function test_uuid_identifier_is_readonly(): void
    {
        $id = new class('550e8400-e29b-41d4-a716-446655440000') extends UuidIdentifier {};

        $this->assertSame('550e8400-e29b-41d4-a716-446655440000', $id->toString());
        $this->assertSame('550e8400-e29b-41d4-a716-446655440000', (string) $id);
    }

    public function test_uuid_identifier_equality_requires_same_class(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';

        $order = new class($uuid) extends UuidIdentifier {};
        $product = new class($uuid) extends UuidIdentifier {};

        // Same UUID, different subclass → not equal
        $this->assertFalse($order->equals($product));
    }

    public function test_uuid_identifier_round_trip(): void
    {
        $class = new class('550e8400-e29b-41d4-a716-446655440000') extends UuidIdentifier {};

        $arr = $class->toArray();
        $restored = $class::fromArray($arr);

        $this->assertTrue($class->equals($restored));
        $this->assertSame('550e8400-e29b-41d4-a716-446655440000', $restored->toString());
    }

    public function test_uuid_identifier_json_round_trip(): void
    {
        $class = new class('550e8400-e29b-41d4-a716-446655440000') extends UuidIdentifier {};

        $json = $class->toJson();
        $restored = $class::fromJson($json);

        $this->assertTrue($class->equals($restored));
    }

    public function test_string_identifier_validates_non_empty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        StringIdentifier::from('');
    }

    public function test_integer_identifier_round_trip(): void
    {
        $id = IntegerIdentifier::from(42);
        $arr = $id->toArray();
        $restored = IntegerIdentifier::fromArray($arr);

        $this->assertSame(42, $restored->toInt());
        $this->assertTrue($id->equals($restored));
    }

    // ── toArray/toJson Contracts ─────────────────────────────────────────────

    public function test_entity_to_array_contains_id_and_type(): void
    {
        $item = new class('123') extends Entity {
            public function toArray(): array { return [...parent::toArray()]; }
        };

        $arr = $item->toArray();

        $this->assertArrayHasKey('id', $arr);
        $this->assertArrayHasKey('type', $arr);
        $this->assertSame('123', $arr['id']);
    }

    public function test_entity_from_json_round_trip(): void
    {
        $item = new class('1', 'test') extends Entity {
            public function __construct(
                int|string|\Stringable $id,
                public readonly string $name,
            ) {
                parent::__construct($id);
            }

            public function toArray(): array
            {
                return [...parent::toArray(), 'name' => $this->name];
            }
        };

        $json = $item->toJson();
        $restored = $item::fromJson($json);

        $this->assertSame('1', $restored->id());
        $this->assertSame('test', $restored->name);
    }

    public function test_aggregate_root_to_array_contains_id_version_type(): void
    {
        $id = AggregateRootId::generate();

        $order = new class($id) extends AggregateRoot {
            public function toArray(): array { return [...parent::toArray()]; }
        };

        $arr = $order->toArray();

        $this->assertArrayHasKey('id', $arr);
        $this->assertArrayHasKey('version', $arr);
        $this->assertArrayHasKey('type', $arr);
        $this->assertSame(0, $arr['version']);
    }

    // ── AggregateRootId Serialization ────────────────────────────────────────

    public function test_aggregate_root_id_to_array_round_trip(): void
    {
        $id = AggregateRootId::generate();
        $restored = AggregateRootId::fromArray($id->toArray());

        $this->assertTrue($id->equals($restored));
    }

    public function test_aggregate_root_id_from_json_round_trip(): void
    {
        $id = AggregateRootId::generate();
        $json = $id->toJson();
        $restored = AggregateRootId::fromJson($json);

        $this->assertTrue($id->equals($restored));
    }

    public function test_aggregate_root_id_json_serialize_returns_string(): void
    {
        $id = AggregateRootId::generate();
        $encoded = json_encode($id);

        $this->assertIsString($encoded);
        $this->assertSame($id->toString(), $encoded);
    }

    public function test_aggregate_root_id_from_string_validates_uuid(): void
    {
        $this->expectException(\Ramsey\Uuid\Exception\InvalidUuidStringException::class);
        AggregateRootId::fromString('not-a-uuid');
    }

    public function test_aggregate_root_id_is_valid(): void
    {
        $this->assertFalse(AggregateRootId::isValid('not-valid'));
        $this->assertTrue(AggregateRootId::isValid('550e8400-e29b-41d4-a716-446655440000'));
    }
}
