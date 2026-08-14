<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Tests;

use PHPUnit\Framework\TestCase;
use ZeroBoiler\Domain\AggregateRoot;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Events\Domain\DomainEvent;

/**
 * Tests for AggregateRoot::toJson() and toArray() serialization.
 *
 * @covers \ZeroBoiler\Domain\AggregateRoot
 */
final class AggregateRootToJsonTest extends TestCase
{
    private static function createOrderAggregate(AggregateRootId $id): AggregateRoot
    {
        // Use a concrete anonymous-like subclass pattern
        return new class($id) extends AggregateRoot {
            public string $status = 'pending';
            public float $total = 0.0;

            public function toArray(): array
            {
                return [
                    ...parent::toArray(),
                    'status' => $this->status,
                    'total' => $this->total,
                ];
            }
        };
    }

    public function test_to_array_returns_id_version_and_type(): void
    {
        $id = AggregateRootId::generate();
        $order = self::createOrderAggregate($id);

        $array = $order->toArray();

        $this->assertSame($id->toString(), $array['id']);
        $this->assertSame(0, $array['version']);
        $this->assertSame('class@anonymous', $array['type']);
        $this->assertSame('pending', $array['status']);
        $this->assertSame(0.0, $array['total']);
    }

    public function test_to_json_returns_valid_json(): void
    {
        $id = AggregateRootId::generate();
        $order = self::createOrderAggregate($id);

        $json = $order->toJson();

        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        $this->assertIsArray($decoded);
        $this->assertSame($id->toString(), $decoded['id']);
        $this->assertSame(0, $decoded['version']);
        $this->assertSame('pending', $decoded['status']);
    }

    public function test_to_json_after_version_increment(): void
    {
        $id = AggregateRootId::generate();
        $order = self::createOrderAggregate($id);

        $order->apply(DomainEvent::occur('order.placed', ['id' => $id->toString()]));
        $order->incrementVersion();

        $json = $order->toJson();
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $decoded['version']);
    }

    public function test_to_json_with_custom_options(): void
    {
        $id = AggregateRootId::generate();
        $order = self::createOrderAggregate($id);

        $json = $order->toJson(JSON_PRETTY_PRINT);

        $this->assertStringContainsString("\n", $json);
    }
}
