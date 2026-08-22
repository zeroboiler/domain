<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace Tests\Domain\Core;

use ZeroBoiler\Domain\AggregateRoot;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\Entity;
use ZeroBoiler\Domain\ValueObject;
use ZeroBoiler\Events\Domain\DomainEvent;
use PHPUnit\Framework\TestCase;

/**
 * Tests for production-ready toArray() consistency, class_basename usage,
 * and type naming across AggregateRoot, Entity, and ValueObject hierarchies.
 *
 * @covers \ZeroBoiler\Domain\AggregateRoot::toArray
 * @covers \ZeroBoiler\Domain\Entity::toArray
 */
final class ArraySerializationConsistencyTest extends TestCase
{
    // ─────────────────────────────────────────────────────────
    // SECTION 1: AggregateRoot toArray uses class_basename
    // ─────────────────────────────────────────────────────────

    public function test_aggregate_root_toArray_type_uses_class_basename(): void
    {
        $order = new TestOrder(AggregateRootId::generate());

        $array = $order->toArray();

        $this->assertArrayHasKey('id', $array);
        $this->assertArrayHasKey('version', $array);
        $this->assertArrayHasKey('type', $array);
        $this->assertSame('TestOrder', $array['type']);
        $this->assertStringContainsString('-', $array['id']); // UUID format
        $this->assertSame(0, $array['version']);
    }

    public function test_aggregate_root_type_changes_with_subclass(): void
    {
        $order = new TestOrder(AggregateRootId::generate());
        $invoice = new TestInvoice(AggregateRootId::generate());

        $this->assertSame('TestOrder', $order->toArray()['type']);
        $this->assertSame('TestInvoice', $invoice->toArray()['type']);
    }

    public function test_aggregate_root_toArray_after_version_increment(): void
    {
        $order = new TestOrder(AggregateRootId::generate());
        $order->incrementVersion();

        $array = $order->toArray();

        $this->assertSame(1, $array['version']);
    }

    public function test_aggregate_root_subclass_can_override_toArray(): void
    {
        $order = new TestOrderWithOverride(AggregateRootId::generate());

        $array = $order->toArray();

        // Subclass should include parent fields + its own
        $this->assertArrayHasKey('id', $array);
        $this->assertArrayHasKey('version', $array);
        $this->assertArrayHasKey('type', $array);
        $this->assertArrayHasKey('status', $array);
        $this->assertSame('TestOrderWithOverride', $array['type']);
    }

    // ─────────────────────────────────────────────────────────
    // SECTION 2: Entity toArray consistency
    // ─────────────────────────────────────────────────────────

    public function test_entity_toArray_uses_class_basename(): void
    {
        $item = new TestEntity('item-123');

        $array = $item->toArray();

        $this->assertArrayHasKey('id', $array);
        $this->assertArrayHasKey('type', $array);
        $this->assertSame('item-123', $array['id']);
        $this->assertSame('TestEntity', $array['type']);
    }

    public function test_entity_toArray_with_int_id(): void
    {
        $entity = new TestEntity(42);

        $array = $entity->toArray();

        $this->assertSame('42', $array['id']);
        $this->assertSame('TestEntity', $array['type']);
    }

    public function test_entity_subclass_can_extend_toArray(): void
    {
        $item = new TestEntityWithFields('item-123', 'Widget', 5);

        $array = $item->toArray();

        $this->assertArrayHasKey('id', $array);
        $this->assertArrayHasKey('type', $array);
        $this->assertArrayHasKey('product_name', $array);
        $this->assertArrayHasKey('quantity', $array);
        $this->assertSame('Widget', $array['product_name']);
        $this->assertSame(5, $array['quantity']);
    }

    // ─────────────────────────────────────────────────────────
    // SECTION 3: Cross-hierarchy consistency
    // ─────────────────────────────────────────────────────────

    public function test_toArray_always_includes_id_and_type(): void
    {
        $aggregate = new TestOrder(AggregateRootId::generate());
        $entity = new TestEntity('ent-1');

        $aggArray = $aggregate->toArray();
        $entArray = $entity->toArray();

        // Both should have id and type
        $this->assertArrayHasKey('id', $aggArray);
        $this->assertArrayHasKey('type', $aggArray);
        $this->assertArrayHasKey('id', $entArray);
        $this->assertArrayHasKey('type', $entArray);

        // Type should be short class name (no namespace)
        $this->assertStringNotContainsString('\\', $aggArray['type']);
        $this->assertStringNotContainsString('\\', $entArray['type']);

        // AggregateRoot additionally has version
        $this->assertArrayHasKey('version', $aggArray);
    }

    public function test_toArray_id_is_always_string(): void
    {
        $aggregate = new TestOrder(AggregateRootId::generate());
        $entity = new TestEntity(42);

        $aggId = $aggregate->toArray()['id'];
        $entId = $entity->toArray()['id'];

        $this->assertIsString($aggId);
        $this->assertIsString($entId);
    }
}

// ─────────────────────────────────────────────────────────
// Test doubles
// ─────────────────────────────────────────────────────────

final class TestOrder extends AggregateRoot
{
    public function place(): void
    {
        $this->apply(DomainEvent::occur('order.placed', [
            'id' => $this->id(),
        ]));
    }

    protected function applyOrderPlaced(DomainEvent $event): void {}
}

final class TestInvoice extends AggregateRoot
{
    public function create(): void
    {
        $this->apply(DomainEvent::occur('invoice.created', [
            'id' => $this->id(),
        ]));
    }

    protected function applyInvoiceCreated(DomainEvent $event): void {}
}

final class TestOrderWithOverride extends AggregateRoot
{
    public string $status = 'pending';

    public function toArray(): array
    {
        return [
            ...parent::toArray(),
            'status' => $this->status,
        ];
    }

    protected function applyOrderPlaced(DomainEvent $event): void {}
}

final class TestEntity extends Entity
{
}

final class TestEntityWithFields extends Entity
{
    public function __construct(
        int|string|\Stringable $id,
        public readonly string $productName,
        public readonly int $quantity,
    ) {
        parent::__construct($id);
    }

    public function toArray(): array
    {
        return [
            ...parent::toArray(),
            'product_name' => $this->productName,
            'quantity' => $this->quantity,
        ];
    }
}
