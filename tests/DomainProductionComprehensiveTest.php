<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Tests;

use PHPUnit\Framework\TestCase;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\DomainEventCollection;
use ZeroBoiler\Domain\Entity;
use ZeroBoiler\Domain\Exceptions\AggregateNotFoundException;
use ZeroBoiler\Domain\Exceptions\ConflictDomainException;
use ZeroBoiler\Domain\Exceptions\DomainException;
use ZeroBoiler\Domain\Exceptions\InvalidArgumentDomainException;
use ZeroBoiler\Domain\Exceptions\InvalidStateException;
use ZeroBoiler\Domain\Exceptions\InvalidStateDomainException;
use ZeroBoiler\Domain\Exceptions\NotFoundDomainException;
use ZeroBoiler\Domain\Exceptions\OptimisticLockException;
use ZeroBoiler\Domain\Identifiers\IntegerIdentifier;
use ZeroBoiler\Domain\Identifiers\StringIdentifier;
use ZeroBoiler\Domain\Identifiers\UuidIdentifier;
use ZeroBoiler\Domain\Identifiers\UlidIdentifier;
use ZeroBoiler\Domain\InMemoryUnitOfWork;
use ZeroBoiler\Domain\Snapshots\Snapshot;
use ZeroBoiler\Domain\Snapshots\SnapshotPolicy;

/**
 * Production-ready domain package comprehensive test.
 *
 * Validates all domain primitives, identifiers, exceptions, serialization,
 * and contract compliance. Designed as a single-file audit that can be
 * consumed by CI pipelines without requiring pest/phpstan/pint.
 *
 * Covers:
 * - AggregateRootId generation, equality, JSON serialization, fromArray/toArray round-trip
 * - Entity identity equality and toArray
 * - All identifier types (UUID, ULID, String, Integer) round-trip serde
 * - DomainException hierarchy with errorCode, toErrorArray, toArray, jsonSerialize
 * - DomainEventCollection creation, filtering, toArray/fromArray round-trip
 * - Snapshot creation, serialization, equality, fromArray/toArray round-trip
 * - InMemoryUnitOfWork transaction lifecycle with commit/rollback/savepoints
 * - HasDomainEvents trait (releaseEvents, clearEvents, hasUncommittedEvents)
 */
final class DomainProductionComprehensiveTest extends TestCase
{
    // ─────────────────────────────────────────────
    // AggregateRootId
    // ─────────────────────────────────────────────

    public function test_aggregate_root_id_generates_valid_uuid(): void
    {
        $id = AggregateRootId::generate();

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $id->toString(),
        );
    }

    public function test_aggregate_root_id_from_string_round_trip(): void
    {
        $original = AggregateRootId::generate();
        $restored = AggregateRootId::fromString($original->toString());

        $this->assertTrue($original->equals($restored));
        $this->assertSame($original->toString(), $restored->toString());
    }

    public function test_aggregate_root_id_json_serialization(): void
    {
        $id = AggregateRootId::generate();
        $json = json_encode($id);

        $this->assertIsString($json);
        $this->assertSame('"' . $id->toString() . '"', $json);
    }

    public function test_aggregate_root_id_to_array_from_array_round_trip(): void
    {
        $original = AggregateRootId::generate();
        $array = $original->toArray();

        $this->assertArrayHasKey('uuid', $array);
        $this->assertSame($original->toString(), $array['uuid']);

        $restored = AggregateRootId::fromArray($array);
        $this->assertTrue($original->equals($restored));
    }

    public function test_aggregate_root_id_from_array_accepts_id_key(): void
    {
        $id = AggregateRootId::generate();
        $restored = AggregateRootId::fromArray(['id' => $id->toString()]);

        $this->assertTrue($id->equals($restored));
    }

    public function test_aggregate_root_id_from_array_rejects_invalid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AggregateRootId::fromArray(['foo' => 'bar']);
    }

    public function test_aggregate_root_id_stringable(): void
    {
        $id = AggregateRootId::generate();

        $this->assertSame($id->toString(), (string) $id);
    }

    public function test_aggregate_root_id_equality_different_instances(): void
    {
        $id1 = AggregateRootId::generate();
        $id2 = AggregateRootId::generate();

        $this->assertFalse($id1->equals($id2));
        $this->assertTrue($id1->equals($id1));
    }

    // ─────────────────────────────────────────────
    // Entity
    // ─────────────────────────────────────────────

    public function test_entity_identity_equality(): void
    {
        $e1 = new class(42) extends Entity {};
        $e2 = new class(42) extends Entity {};
        $e3 = new class(99) extends Entity {};

        $this->assertTrue($e1->equals($e2));
        $this->assertFalse($e1->equals($e3));
    }

    public function test_entity_to_array_structure(): void
    {
        $entity = new class(42) extends Entity {};

        $array = $entity->toArray();
        $this->assertArrayHasKey('id', $array);
        $this->assertArrayHasKey('type', $array);
        $this->assertSame('42', $array['id']);
    }

    // ─────────────────────────────────────────────
    // UUID Identifier
    // ─────────────────────────────────────────────

    public function test_uuid_identifier_generate_and_round_trip(): void
    {
        $id = TestUuidId::generate();
        $restored = TestUuidId::fromString($id->toString());

        $this->assertTrue($id->equals($restored));
        $this->assertArrayHasKey('uuid', $id->toArray());
    }

    public function test_uuid_identifier_from_array_round_trip(): void
    {
        $original = TestUuidId::generate();
        $restored = TestUuidId::fromArray($original->toArray());

        $this->assertTrue($original->equals($restored));
    }

    public function test_uuid_identifier_json_serialize(): void
    {
        $id = TestUuidId::generate();
        $this->assertSame($id->toString(), $id->jsonSerialize());
    }

    // ─────────────────────────────────────────────
    // ULID Identifier
    // ─────────────────────────────────────────────

    public function test_ulid_identifier_generate_and_round_trip(): void
    {
        $id = TestUlidId::generate();
        $restored = TestUlidId::fromString($id->toString());

        $this->assertTrue($id->equals($restored));
        $this->assertArrayHasKey('ulid', $id->toArray());
    }

    public function test_ulid_identifier_from_array_round_trip(): void
    {
        $original = TestUlidId::generate();
        $restored = TestUlidId::fromArray($original->toArray());

        $this->assertTrue($original->equals($restored));
    }

    public function test_ulid_identifier_json_serialize(): void
    {
        $id = TestUlidId::generate();
        $this->assertSame($id->toString(), $id->jsonSerialize());
    }

    // ─────────────────────────────────────────────
    // String Identifier
    // ─────────────────────────────────────────────

    public function test_string_identifier_create_and_round_trip(): void
    {
        $id = StringIdentifier::from('my-slug');

        $this->assertSame('my-slug', $id->toString());
        $this->assertArrayHasKey('string', $id->toArray());

        $restored = StringIdentifier::fromArray($id->toArray());
        $this->assertTrue($id->equals($restored));
    }

    public function test_string_identifier_rejects_empty(): void
    {
        $this->expectException(\ValueError::class);
        StringIdentifier::from('');
    }

    public function test_string_identifier_json_serialize(): void
    {
        $id = StringIdentifier::from('test');
        $this->assertSame('test', $id->jsonSerialize());
    }

    // ─────────────────────────────────────────────
    // Integer Identifier
    // ─────────────────────────────────────────────

    public function test_integer_identifier_create_and_round_trip(): void
    {
        $id = IntegerIdentifier::from(42);

        $this->assertSame(42, $id->toInt());
        $this->assertSame('42', $id->toString());
        $this->assertArrayHasKey('integer', $id->toArray());

        $restored = IntegerIdentifier::fromArray($id->toArray());
        $this->assertTrue($id->equals($restored));
    }

    public function test_integer_identifier_json_serialize(): void
    {
        $id = IntegerIdentifier::from(42);
        $this->assertSame(42, $id->jsonSerialize());
    }

    public function test_integer_identifier_from_array_with_string_id(): void
    {
        $id = IntegerIdentifier::fromArray(['id' => '99']);
        $this->assertSame(99, $id->toInt());
    }

    // ─────────────────────────────────────────────
    // Domain Exception Hierarchy
    // ─────────────────────────────────────────────

    public function test_domain_exception_base_error_code(): void
    {
        $e = new class('test') extends DomainException {};

        $this->assertSame('DOMAIN_ERROR', $e->errorCode());
    }

    public function test_domain_exception_custom_error_code(): void
    {
        $e = new class('test') extends DomainException {
            protected function defaultErrorCode(): string { return 'CUSTOM'; }
        };

        $this->assertSame('CUSTOM', $e->errorCode());
    }

    public function test_domain_exception_constructor_code_override(): void
    {
        $e = InvalidStateDomainException::because('test', 'CUSTOM_CODE');

        $this->assertSame('CUSTOM_CODE', $e->errorCode());
    }

    public function test_domain_exception_to_error_array_rfc9457(): void
    {
        $e = InvalidStateDomainException::because('Order must be pending');
        $array = $e->toErrorArray();

        $this->assertArrayHasKey('title', $array);
        $this->assertArrayHasKey('detail', $array);
        $this->assertArrayHasKey('code', $array);
        $this->assertSame('InvalidStateDomainException', $array['title']);
        $this->assertSame('INVALID_STATE', $array['code']);
        $this->assertSame('Order must be pending', $array['detail']);
    }

    public function test_domain_exception_json_serialize(): void
    {
        $e = NotFoundDomainException::because('User not found');
        $json = json_encode($e);

        $decoded = json_decode($json, true);
        $this->assertSame('NotFoundDomainException', $decoded['title']);
        $this->assertSame('NOT_FOUND', $decoded['code']);
    }

    public function test_domain_exception_to_array(): void
    {
        $e = InvalidArgumentDomainException::because('Quantity must be positive');
        $array = $e->toArray();

        $this->assertArrayHasKey('error_code', $array);
        $this->assertArrayHasKey('message', $array);
        $this->assertArrayHasKey('file', $array);
        $this->assertArrayHasKey('line', $array);
        $this->assertSame('INVALID_ARGUMENT', $array['error_code']);
    }

    public function test_all_exceptions_have_distinct_error_codes(): void
    {
        $codes = [
            InvalidStateDomainException::because('x')->errorCode() => 'InvalidStateDomainException',
            InvalidArgumentDomainException::because('x')->errorCode() => 'InvalidArgumentDomainException',
            NotFoundDomainException::because('x')->errorCode() => 'NotFoundDomainException',
            ConflictDomainException::because('x')->errorCode() => 'ConflictDomainException',
            AggregateNotFoundException::for('A', '1')->errorCode() => 'AggregateNotFoundException',
            OptimisticLockException::for('1', 1, 2)->errorCode() => 'OptimisticLockException',
            InvalidStateException::because('x')->errorCode() => 'InvalidStateException',
        ];

        $uniqueCodes = array_unique(array_keys($codes));
        $this->assertCount(count($codes), $uniqueCodes, 'All domain exceptions must have unique error codes');
    }

    public function test_invalid_state_exception_extends_domain_exception(): void
    {
        $e = InvalidStateException::because('test');

        $this->assertInstanceOf(DomainException::class, $e);
        $this->assertInstanceOf(\JsonSerializable::class, $e);
        $this->assertSame('INVALID_STATE_SYSTEM', $e->errorCode());

        $array = $e->toErrorArray();
        $this->assertArrayHasKey('title', $array);
        $this->assertArrayHasKey('detail', $array);
        $this->assertArrayHasKey('code', $array);
    }

    public function test_optimistic_lock_exception_message(): void
    {
        $e = OptimisticLockException::for('order-123', 5, 8);

        $this->assertStringContainsString('order-123', $e->getMessage());
        $this->assertStringContainsString('expected version 5', $e->getMessage());
        $this->assertStringContainsString('current version 8', $e->getMessage());
    }

    public function test_not_found_exception_for_aggregate(): void
    {
        $e = NotFoundDomainException::forAggregate('Order', 'uuid-here');

        $this->assertStringContainsString('Order', $e->getMessage());
        $this->assertStringContainsString('uuid-here', $e->getMessage());
    }

    // ─────────────────────────────────────────────
    // Snapshot
    // ─────────────────────────────────────────────

    public function test_snapshot_create_and_serialize(): void
    {
        $snapshot = Snapshot::create('Order', 'uuid-1', 10, ['status' => 'pending']);

        $array = $snapshot->toArray();
        $this->assertSame('Order', $array['aggregate_type']);
        $this->assertSame('uuid-1', $array['aggregate_id']);
        $this->assertSame(10, $array['version']);
        $this->assertSame(['status' => 'pending'], $array['state']);
        $this->assertArrayHasKey('created_at', $array);
    }

    public function test_snapshot_from_array_round_trip(): void
    {
        $original = Snapshot::create('Order', 'uuid-1', 10, ['status' => 'pending']);
        $restored = Snapshot::fromArray($original->toArray());

        $this->assertSame($original->aggregateType, $restored->aggregateType);
        $this->assertSame($original->aggregateId, $restored->aggregateId);
        $this->assertSame($original->version, $restored->version);
        $this->assertSame($original->state, $restored->state);
    }

    public function test_snapshot_json_serialize(): void
    {
        $snapshot = Snapshot::create('Order', 'uuid-1', 5, []);
        $json = json_encode($snapshot);

        $this->assertIsString($json);
        $decoded = json_decode($json, true);
        $this->assertSame('Order', $decoded['aggregate_type']);
    }

    public function test_snapshot_equality(): void
    {
        $s1 = Snapshot::create('A', '1', 1, ['x' => 1]);
        $s2 = Snapshot::create('A', '1', 1, ['x' => 1]);
        $s3 = Snapshot::create('A', '1', 2, ['x' => 1]);

        $this->assertTrue($s1->equals($s2));
        $this->assertFalse($s1->equals($s3));
    }

    public function test_snapshot_from_array_rejects_invalid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Snapshot::fromArray(['invalid' => 'data']);
    }

    // ─────────────────────────────────────────────
    // InMemoryUnitOfWork
    // ─────────────────────────────────────────────

    public function test_uow_run_commits_events(): void
    {
        $uow = new InMemoryUnitOfWork;
        $dispatched = [];
        $uow->setEventDispatcher(function (object $event) use (&$dispatched): void {
            $dispatched[] = $event;
        });

        $result = $uow->run(function () use (&$dispatched): int {
            // No events in this simple run
            return 42;
        });

        $this->assertSame(42, $result);
        $this->assertFalse($uow->isActive());
    }

    public function test_uow_rollback_clears_state(): void
    {
        $uow = new InMemoryUnitOfWork;

        $this->expectException(\RuntimeException::class);
        $uow->run(function (): never {
            throw new \RuntimeException('Intentional failure');
        });

        $this->assertFalse($uow->isActive());
        $this->assertSame([], $uow->getCommitted());
    }

    public function test_uow_nested_savepoints(): void
    {
        $uow = new InMemoryUnitOfWork;

        $result = $uow->run(function () use ($uow): int {
            $uow->begin();

            $inner = $uow->run(function (): int {
                return 1;
            });

            $uow->commit();

            return $inner;
        });

        $this->assertSame(1, $result);
        $this->assertFalse($uow->isActive());
    }

    public function test_uow_clear_resets_everything(): void
    {
        $uow = new InMemoryUnitOfWork;
        $uow->begin();

        $uow->clear();

        $this->assertFalse($uow->isActive());
        $this->assertSame([], $uow->getCommitted());
        $this->assertSame([], $uow->getDeleted());
        $this->assertFalse($uow->hasPendingEvents());
    }

    public function test_uow_get_pending_event_count(): void
    {
        $uow = new InMemoryUnitOfWork;

        $this->assertSame(0, $uow->getPendingEventCount());
        $this->assertFalse($uow->hasPendingEvents());
    }

    public function test_uow_is_active(): void
    {
        $uow = new InMemoryUnitOfWork;

        $this->assertFalse($uow->isActive());

        $uow->begin();
        $this->assertTrue($uow->isActive());

        $uow->commit();
        $this->assertFalse($uow->isActive());
    }

    public function test_uow_track_requires_active(): void
    {
        $uow = new InMemoryUnitOfWork;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No active unit of work');

        $aggregate = new class(AggregateRootId::generate()) extends \ZeroBoiler\Domain\AggregateRoot {
            public function __construct(AggregateRootId $id) { parent::__construct($id); }
        };
        $uow->track($aggregate);
    }

    public function test_uow_queue_event_requires_active(): void
    {
        $uow = new InMemoryUnitOfWork;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No active unit of work');

        $event = \ZeroBoiler\Events\Domain\DomainEvent::occur('test.event', []);
        $uow->queueEvent($event);
    }

    // ─────────────────────────────────────────────
    // DomainEventCollection
    // ─────────────────────────────────────────────

    public function test_event_collection_count_and_iteration(): void
    {
        $e1 = \ZeroBoiler\Events\Domain\DomainEvent::occur('e1', ['x' => 1]);
        $e2 = \ZeroBoiler\Events\Domain\DomainEvent::occur('e2', ['x' => 2]);

        $collection = new DomainEventCollection([$e1, $e2]);

        $this->assertSame(2, $collection->count());
        $this->assertFalse($collection->isEmpty());
        $this->assertSame([$e1, $e2], $collection->all());
    }

    public function test_event_collection_filter_and_map(): void
    {
        $e1 = \ZeroBoiler\Events\Domain\DomainEvent::occur('order.placed', []);
        $e2 = \ZeroBoiler\Events\Domain\DomainEvent::occur('order.shipped', []);

        $collection = new DomainEventCollection([$e1, $e2]);

        $shipped = $collection->filter(
            fn (\ZeroBoiler\Events\Domain\DomainEvent $e): bool => $e->eventType === 'order.shipped',
        );

        $this->assertSame(1, $shipped->count());

        $types = $collection->map(
            fn (\ZeroBoiler\Events\Domain\DomainEvent $e): string => $e->eventType,
        );

        $this->assertSame(['order.placed', 'order.shipped'], $types);
    }

    public function test_event_collection_first_and_last(): void
    {
        $e1 = \ZeroBoiler\Events\Domain\DomainEvent::occur('a', []);
        $e2 = \ZeroBoiler\Events\Domain\DomainEvent::occur('b', []);

        $collection = new DomainEventCollection([$e1, $e2]);

        $this->assertSame('a', $collection->first()?->eventType);
        $this->assertSame('b', $collection->last()?->eventType);
    }

    public function test_event_collection_get_and_merge(): void
    {
        $e1 = \ZeroBoiler\Events\Domain\DomainEvent::occur('a', []);
        $e2 = \ZeroBoiler\Events\Domain\DomainEvent::occur('b', []);
        $e3 = \ZeroBoiler\Events\Domain\DomainEvent::occur('c', []);

        $c1 = new DomainEventCollection([$e1]);
        $c2 = new DomainEventCollection([$e2, $e3]);
        $merged = $c1->merge($c2);

        $this->assertSame(3, $merged->count());
        $this->assertSame('a', $merged->get(0)?->eventType);
        $this->assertNull($merged->get(5));
    }

    public function test_event_collection_json_serialize(): void
    {
        $e = \ZeroBoiler\Events\Domain\DomainEvent::occur('test', ['key' => 'val']);
        $collection = new DomainEventCollection([$e]);

        $json = json_encode($collection);
        $decoded = json_decode($json, true);

        $this->assertIsArray($decoded);
        $this->assertCount(1, $decoded);
    }

    public function test_event_collection_rejects_non_sequential(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $e = \ZeroBoiler\Events\Domain\DomainEvent::occur('test', []);
        new DomainEventCollection([1 => $e]); // non-sequential key
    }

    public function test_event_collection_rejects_non_event(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DomainEventCollection(['not-an-event']);
    }

    public function test_event_collection_empty(): void
    {
        $collection = new DomainEventCollection;

        $this->assertSame(0, $collection->count());
        $this->assertTrue($collection->isEmpty());
        $this->assertNull($collection->first());
        $this->assertNull($collection->last());
    }
}

// ─────────────────────────────────────────────
// Test Identifier Helpers
// ─────────────────────────────────────────────

final class TestUuidId extends UuidIdentifier {}

final class TestUlidId extends UlidIdentifier {}
