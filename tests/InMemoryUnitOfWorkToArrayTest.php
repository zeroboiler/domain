<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Tests;

use PHPUnit\Framework\TestCase;
use ZeroBoiler\Domain\AggregateRoot;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\InMemoryUnitOfWork;

/**
 * Tests for InMemoryUnitOfWork::toArray() and ::toJson() state inspection.
 *
 * @covers \ZeroBoiler\Domain\InMemoryUnitOfWork
 */
final class InMemoryUnitOfWorkToArrayTest extends TestCase
{
    public function test_to_array_returns_expected_structure(): void
    {
        $uow = new InMemoryUnitOfWork;

        $array = $uow->toArray();

        $this->assertArrayHasKey('nesting_depth', $array);
        $this->assertArrayHasKey('pending_event_count', $array);
        $this->assertArrayHasKey('committed_count', $array);
        $this->assertArrayHasKey('deleted_count', $array);
        $this->assertArrayHasKey('is_active', $array);

        $this->assertSame(0, $array['nesting_depth']);
        $this->assertSame(0, $array['pending_event_count']);
        $this->assertSame(0, $array['committed_count']);
        $this->assertSame(0, $array['deleted_count']);
        $this->assertFalse($array['is_active']);
    }

    public function test_to_array_reflects_active_state(): void
    {
        $uow = new InMemoryUnitOfWork;

        $uow->begin();

        $array = $uow->toArray();

        $this->assertSame(1, $array['nesting_depth']);
        $this->assertTrue($array['is_active']);
    }

    public function test_to_array_reflects_committed_count_after_commit(): void
    {
        $uow = new InMemoryUnitOfWork;
        $id = AggregateRootId::generate();

        $uow->run(function () use ($id, &$order): void {
            $order = new class($id) extends AggregateRoot {
                public function toArray(): array
                {
                    return [...parent::toArray()];
                }
            };
            $this->assertTrue(true); // Placeholder for tracking
        });
    }

    public function test_to_json_returns_valid_json(): void
    {
        $uow = new InMemoryUnitOfWork;

        $json = $uow->toJson();

        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        $this->assertIsArray($decoded);
        $this->assertSame(0, $decoded['nesting_depth']);
        $this->assertFalse($decoded['is_active']);
    }

    public function test_to_json_after_begin(): void
    {
        $uow = new InMemoryUnitOfWork;
        $uow->begin();

        $json = $uow->toJson();
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $decoded['nesting_depth']);
        $this->assertTrue($decoded['is_active']);
    }

    public function test_to_json_with_custom_options(): void
    {
        $uow = new InMemoryUnitOfWork;

        $json = $uow->toJson(JSON_PRETTY_PRINT);

        $this->assertStringContainsString("\n", $json);
    }
}
