<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace Tests\Domain;

use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\InMemoryUnitOfWork;
use ZeroBoiler\Domain\Tests\Fixtures\TestAggregate;

/**
 * Verify InMemoryUnitOfWork implements JsonSerializable for ecosystem consistency.
 *
 * All domain objects (Entity, AggregateRoot, ValueObject, identifiers,
 * DomainException, DomainEventCollection) implement JsonSerializable.
 * InMemoryUnitOfWork should follow the same pattern for consistent
 * serialization across logging, debugging, and monitoring pipelines.
 */
final class InMemoryUnitOfWorkJsonSerializableTest extends \PHPUnit\Framework\TestCase
{
    public function test_uow_implements_json_serializable(): void
    {
        $uow = new InMemoryUnitOfWork;

        $this->assertInstanceOf(\JsonSerializable::class, $uow);
    }

    public function test_json_serialize_returns_array(): void
    {
        $uow = new InMemoryUnitOfWork;

        $result = $uow->jsonSerialize();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('nesting_depth', $result);
        $this->assertArrayHasKey('pending_event_count', $result);
        $this->assertArrayHasKey('committed_count', $result);
        $this->assertArrayHasKey('deleted_count', $result);
        $this->assertArrayHasKey('is_active', $result);
    }

    public function test_json_encode_works_directly(): void
    {
        $uow = new InMemoryUnitOfWork;

        $json = json_encode($uow);

        $this->assertIsString($json);
        $this->assertStringContainsString('"nesting_depth":0', $json);
        $this->assertStringContainsString('"is_active":false', $json);
    }

    public function test_json_serialize_matches_to_array(): void
    {
        $uow = new InMemoryUnitOfWork;

        $this->assertSame($uow->toArray(), $uow->jsonSerialize());
    }

    public function test_json_serialize_reflects_active_transaction(): void
    {
        $uow = new InMemoryUnitOfWork;
        $uow->begin();

        $result = $uow->jsonSerialize();

        $this->assertSame(1, $result['nesting_depth']);
        $this->assertTrue($result['is_active']);

        $uow->rollback();

        $after = $uow->jsonSerialize();
        $this->assertSame(0, $after['nesting_depth']);
        $this->assertFalse($after['is_active']);
    }

    public function test_to_json_matches_json_encode(): void
    {
        $uow = new InMemoryUnitOfWork;
        $uow->begin();

        $this->assertSame(
            json_encode($uow, JSON_UNESCAPED_UNICODE),
            $uow->toJson(),
        );
    }

    public function test_json_serialize_round_trip_after_run(): void
    {
        $uow = new InMemoryUnitOfWork;

        $id = AggregateRootId::generate();
        $uow->run(function () use ($uow, $id): void {
            $aggregate = TestAggregate::create($id);
            $uow->track($aggregate);
            $aggregate->addItem('sku-1', 2);
        });

        $result = $uow->jsonSerialize();

        $this->assertSame(0, $result['nesting_depth']);
        $this->assertFalse($result['is_active']);
        $this->assertSame(1, $result['committed_count']);
        $this->assertSame(0, $result['deleted_count']);
        $this->assertSame(0, $result['pending_event_count']);
    }
}
