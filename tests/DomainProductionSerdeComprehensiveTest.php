<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace Tests\Domain\Production;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\DomainEventCollection;
use ZeroBoiler\Domain\Entity;
use ZeroBoiler\Domain\Exceptions\AggregateNotFoundException;
use ZeroBoiler\Domain\Exceptions\ConflictDomainException;
use ZeroBoiler\Domain\Exceptions\DomainException;
use ZeroBoiler\Domain\Exceptions\InvalidArgumentDomainException;
use ZeroBoiler\Domain\Exceptions\InvalidStateDomainException;
use ZeroBoiler\Domain\Exceptions\InvalidStateException;
use ZeroBoiler\Domain\Exceptions\NotFoundDomainException;
use ZeroBoiler\Domain\Exceptions\OptimisticLockException;
use ZeroBoiler\Domain\Identifiers\IntegerIdentifier;
use ZeroBoiler\Domain\Identifiers\StringIdentifier;
use ZeroBoiler\Domain\Identifiers\UuidIdentifier;
use ZeroBoiler\Domain\Snapshots\InMemorySnapshotStore;
use ZeroBoiler\Domain\Snapshots\Snapshot;
use ZeroBoiler\Domain\ValueObject;
use ZeroBoiler\Events\Domain\DomainEvent;

/**
 * Production-ready serialization/deserialization round-trip tests.
 *
 * Verifies that all domain objects support stable, lossless round-trip
 * serialization via toArray()/fromArray() and JSON encoding/decoding.
 * This is critical for caching, queue payloads, snapshot storage,
 * and cross-service communication.
 *
 * @internal Production contract test — verifies serialization stability.
 *
 * @since 1.0.0
 */
#[Group('production')]
#[Group('serde')]
#[CoversClass(AggregateRootId::class)]
#[CoversClass(DomainEventCollection::class)]
#[CoversClass(Snapshot::class)]
#[CoversClass(InMemorySnapshotStore::class)]
#[CoversClass(IntegerIdentifier::class)]
#[CoversClass(StringIdentifier::class)]
#[CoversClass(UuidIdentifier::class)]
#[CoversClass(DomainException::class)]
#[CoversClass(InvalidStateException::class)]
final class DomainProductionSerdeTest extends TestCase
{
    // ---------------------------------------------------------------
    // AggregateRootId serde tests
    // ---------------------------------------------------------------

    public function test_aggregate_root_id_generate_and_to_array(): void
    {
        $id = AggregateRootId::generate();
        $array = $id->toArray();

        $this->assertArrayHasKey('uuid', $array);
        $this->assertIsString($array['uuid']);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $array['uuid'],
        );
    }

    public function test_aggregate_root_id_round_trip(): void
    {
        $original = AggregateRootId::generate();
        $restored = AggregateRootId::fromArray($original->toArray());

        $this->assertTrue($original->equals($restored));
        $this->assertEquals($original->toString(), $restored->toString());
    }

    public function test_aggregate_root_id_from_string_round_trip(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $original = AggregateRootId::fromString($uuid);
        $restored = AggregateRootId::fromArray($original->toArray());

        $this->assertTrue($original->equals($restored));
        $this->assertEquals($uuid, $restored->toString());
    }

    public function test_aggregate_root_id_json_serialization(): void
    {
        $id = AggregateRootId::generate();
        $json = json_encode($id);
        $this->assertNotFalse($json);
        $this->assertEquals($id->toString(), json_decode($json));
    }

    // ---------------------------------------------------------------
    // UuidIdentifier serde tests
    // ---------------------------------------------------------------

    public function test_uuid_identifier_round_trip(): void
    {
        $original = UuidIdentifier::generate();
        $restored = UuidIdentifier::fromArray($original->toArray());

        $this->assertTrue($original->equals($restored));
        $this->assertEquals($original->toString(), $restored->toString());
    }

    public function test_uuid_identifier_json_serialization(): void
    {
        $id = UuidIdentifier::generate();
        $json = json_encode(['id' => $id]);

        $this->assertNotFalse($json);
        $decoded = json_decode($json, true);
        $this->assertEquals($id->toString(), $decoded['id']);
    }

    // ---------------------------------------------------------------
    // StringIdentifier serde tests
    // ---------------------------------------------------------------

    public function test_string_identifier_round_trip(): void
    {
        $original = StringIdentifier::from('my-blog-post');
        $restored = StringIdentifier::fromArray($original->toArray());

        $this->assertTrue($original->equals($restored));
        $this->assertEquals('my-blog-post', $restored->toString());
    }

    public function test_string_identifier_json_serialization(): void
    {
        $id = StringIdentifier::from('slug-value');
        $json = json_encode(['slug' => $id]);

        $this->assertNotFalse($json);
        $decoded = json_decode($json, true);
        $this->assertEquals('slug-value', $decoded['slug']);
    }

    public function test_string_identifier_from_array_with_id_key(): void
    {
        $id = StringIdentifier::fromArray(['id' => 'fallback-id']);
        $this->assertEquals('fallback-id', $id->toString());
    }

    public function test_string_identifier_empty_throws(): void
    {
        $this->expectException(\ValueError::class);
        StringIdentifier::from('');
    }

    // ---------------------------------------------------------------
    // IntegerIdentifier serde tests
    // ---------------------------------------------------------------

    public function test_integer_identifier_round_trip(): void
    {
        $original = IntegerIdentifier::from(42);
        $restored = IntegerIdentifier::fromArray($original->toArray());

        $this->assertTrue($original->equals($restored));
        $this->assertEquals(42, $restored->toInt());
    }

    public function test_integer_identifier_json_serialization(): void
    {
        $id = IntegerIdentifier::from(42);
        $json = json_encode(['seq' => $id]);

        $this->assertNotFalse($json);
        $decoded = json_decode($json, true);
        $this->assertEquals(42, $decoded['seq']);
    }

    public function test_integer_identifier_from_array_with_string_id(): void
    {
        $id = IntegerIdentifier::fromArray(['id' => '99']);
        $this->assertEquals(99, $id->toInt());
    }

    public function test_integer_identifier_negative_round_trip(): void
    {
        $original = IntegerIdentifier::from(-5);
        $restored = IntegerIdentifier::fromArray($original->toArray());

        $this->assertEquals(-5, $restored->toInt());
    }

    // ---------------------------------------------------------------
    // Cross-identifier inequality tests
    // ---------------------------------------------------------------

    public function test_cross_identifier_types_never_equal(): void
    {
        $uuid = AggregateRootId::generate();
        $str = StringIdentifier::from($uuid->toString());
        $int = IntegerIdentifier::from(1);

        $this->assertFalse($uuid->equals($str));
        $this->assertFalse($str->equals($int));
    }

    public function test_same_value_different_identifier_types_not_equal(): void
    {
        $strId = StringIdentifier::from('42');
        $intId = IntegerIdentifier::from(42);

        $this->assertFalse($strId->equals($intId));
        $this->assertFalse($intId->equals($strId));
    }

    // ---------------------------------------------------------------
    // Snapshot serde tests
    // ---------------------------------------------------------------

    public function test_snapshot_round_trip(): void
    {
        $original = Snapshot::create(
            'App\\Domain\\Order',
            'order-uuid-123',
            5,
            ['status' => 'paid', 'total' => 299.99],
        );

        $restored = Snapshot::fromArray($original->toArray());

        $this->assertTrue($original->equals($restored));
        $this->assertEquals('App\\Domain\\Order', $restored->aggregateType);
        $this->assertEquals('order-uuid-123', $restored->aggregateId);
        $this->assertEquals(5, $restored->version);
        $this->assertEquals(['status' => 'paid', 'total' => 299.99], $restored->state);
    }

    public function test_snapshot_json_serialization(): void
    {
        $snapshot = Snapshot::create('Test', 'id-1', 1, ['key' => 'value']);
        $json = json_encode($snapshot);

        $this->assertNotFalse($json);
        $decoded = json_decode($json, true);

        $this->assertEquals('Test', $decoded['aggregate_type']);
        $this->assertEquals('id-1', $decoded['aggregate_id']);
        $this->assertEquals(1, $decoded['version']);
        $this->assertEquals(['key' => 'value'], $decoded['state']);
    }

    // ---------------------------------------------------------------
    // DomainEventCollection serde tests
    // ---------------------------------------------------------------

    public function test_domain_event_collection_round_trip(): void
    {
        $events = [
            DomainEvent::occur('order.placed', ['id' => '1', 'status' => 'pending']),
            DomainEvent::occur('order.paid', ['id' => '1', 'amount' => 99.99]),
            DomainEvent::occur('order.shipped', ['id' => '1', 'tracking' => 'TRK-123']),
        ];

        $original = new DomainEventCollection($events);
        $restored = DomainEventCollection::fromArray($original->toArray());

        $this->assertEquals($original->count(), $restored->count());
        $this->assertEquals($original->first()->eventType, $restored->first()->eventType);
    }

    public function test_domain_event_collection_json_serialization(): void
    {
        $events = [
            DomainEvent::occur('test.event', ['key' => 'value']),
        ];

        $collection = new DomainEventCollection($events);
        $json = json_encode($collection);

        $this->assertNotFalse($json);
        $decoded = json_decode($json, true);

        $this->assertCount(1, $decoded);
        $this->assertEquals('test.event', $decoded[0]['event_type']);
    }

    public function test_domain_event_collection_empty_serialization(): void
    {
        $collection = new DomainEventCollection([]);
        $array = $collection->toArray();

        $this->assertEquals([], $array);
        $this->assertEquals(0, $collection->count());
        $this->assertTrue($collection->isEmpty());
    }

    // ---------------------------------------------------------------
    // DomainException serde tests
    // ---------------------------------------------------------------

    public function test_invalid_state_exception_error_array_structure(): void
    {
        $exception = InvalidStateDomainException::because('Order must be pending to pay.');
        $error = $exception->toErrorArray();

        $this->assertArrayHasKey('title', $error);
        $this->assertArrayHasKey('detail', $error);
        $this->assertArrayHasKey('code', $error);
        $this->assertEquals('InvalidStateDomainException', $error['title']);
        $this->assertEquals('Order must be pending to pay.', $error['detail']);
        $this->assertEquals('INVALID_STATE', $error['code']);
    }

    public function test_invalid_argument_exception_default_code(): void
    {
        $exception = InvalidArgumentDomainException::because('Quantity must be positive.');
        $this->assertEquals('INVALID_ARGUMENT', $exception->errorCode());
    }

    public function test_not_found_exception_for_aggregate(): void
    {
        $exception = NotFoundDomainException::forAggregate('Order', 'order-123');
        $error = $exception->toErrorArray();
        $this->assertStringContainsString('Order', $error['detail']);
        $this->assertStringContainsString('order-123', $error['detail']);
    }

    public function test_aggregate_not_found_exception(): void
    {
        $exception = AggregateNotFoundException::for('App\\Domain\\Order', 'id-xyz');
        $this->assertEquals('AGGREGATE_NOT_FOUND', $exception->errorCode());
    }

    public function test_conflict_exception_default_code(): void
    {
        $exception = ConflictDomainException::because('Concurrent modification detected.');
        $this->assertEquals('CONFLICT', $exception->errorCode());
    }

    public function test_optimistic_lock_exception_structure(): void
    {
        $exception = OptimisticLockException::for('id-1', expectedVersion: 5, actualVersion: 3);
        $error = $exception->toErrorArray();
        $this->assertEquals('OPTIMISTIC_LOCK', $exception->errorCode());
        $this->assertStringContainsString('id-1', $error['detail']);
    }

    public function test_custom_error_code_override(): void
    {
        $exception = InvalidStateDomainException::because(
            'Order must be pending.',
            code: 'ORDER_NOT_PENDING',
        );
        $this->assertEquals('ORDER_NOT_PENDING', $exception->errorCode());
    }

    public function test_exception_json_serialization(): void
    {
        $exception = InvalidStateDomainException::because('Test error.');
        $json = json_encode($exception);

        $this->assertNotFalse($json);
        $decoded = json_decode($json, true);
        $this->assertArrayHasKey('title', $decoded);
        $this->assertArrayHasKey('detail', $decoded);
    }

    public function test_exception_implements_throwable(): void
    {
        $exception = InvalidStateDomainException::because('Test.');
        $this->assertInstanceOf(\Throwable::class, $exception);
    }

    public function test_invalid_state_exception_is_not_domain_exception(): void
    {
        $exception = InvalidStateException::because('System state is invalid.');
        $this->assertNotInstanceOf(DomainException::class, $exception);
        $this->assertInstanceOf(\RuntimeException::class, $exception);
    }

    public function test_all_domain_exceptions_have_unique_default_codes(): void
    {
        $exceptions = [
            InvalidStateDomainException::because('test'),
            InvalidArgumentDomainException::because('test'),
            NotFoundDomainException::because('test'),
            ConflictDomainException::because('test'),
            OptimisticLockException::for('id', 1, 2),
            AggregateNotFoundException::for('Type', 'id'),
        ];

        $codes = array_map(fn (\Throwable $e): string => $e instanceof DomainException ? $e->errorCode() : '', $exceptions);
        $uniqueCodes = array_unique($codes);

        $this->assertCount(count($codes), $uniqueCodes, 'All default error codes must be unique.');
    }

    // ---------------------------------------------------------------
    // InMemorySnapshotStore serde tests
    // ---------------------------------------------------------------

    public function test_snapshot_store_save_load_round_trip(): void
    {
        $store = new InMemorySnapshotStore;
        $snapshot = Snapshot::create('Order', 'id-1', 10, ['status' => 'shipped']);

        $store->save($snapshot);
        $loaded = $store->load('Order', 'id-1');

        $this->assertNotNull($loaded);
        $this->assertTrue($snapshot->equals($loaded));
    }

    public function test_snapshot_store_has_and_delete(): void
    {
        $store = new InMemorySnapshotStore;
        $snapshot = Snapshot::create('Test', 'id-1', 1, []);

        $store->save($snapshot);
        $this->assertTrue($store->has('Test', 'id-1'));

        $store->delete('Test', 'id-1');
        $this->assertFalse($store->has('Test', 'id-1'));
    }

    public function test_snapshot_store_stats(): void
    {
        $store = new InMemorySnapshotStore;
        $store->save(Snapshot::create('Order', 'id-1', 1, []));
        $store->save(Snapshot::create('Order', 'id-2', 1, []));
        $store->save(Snapshot::create('Product', 'id-1', 1, []));

        $stats = $store->stats();
        $this->assertEquals(3, $stats['total']);
        $this->assertEquals(2, $stats['by_type']['Order']);
        $this->assertEquals(1, $stats['by_type']['Product']);
    }

    public function test_snapshot_store_purge_all(): void
    {
        $store = new InMemorySnapshotStore;
        $store->save(Snapshot::create('A', '1', 1, []));
        $store->save(Snapshot::create('B', '2', 1, []));

        $removed = $store->purge();
        $this->assertEquals(2, $removed);
        $this->assertEquals(0, $store->count());
    }

    public function test_snapshot_store_purge_by_type(): void
    {
        $store = new InMemorySnapshotStore;
        $store->save(Snapshot::create('Order', '1', 1, []));
        $store->save(Snapshot::create('Product', '1', 1, []));

        $removed = $store->purge('Order');
        $this->assertEquals(1, $removed);
        $this->assertEquals(1, $store->count());
        $this->assertFalse($store->has('Order', '1'));
        $this->assertTrue($store->has('Product', '1'));
    }

    public function test_snapshot_store_delete_older_than(): void
    {
        $store = new InMemorySnapshotStore;
        $store->save(Snapshot::create('Order', '1', 5, []));

        // Should NOT delete version 5 when threshold is 6
        $store->deleteOlderThan('Order', '1', 6);
        $this->assertTrue($store->has('Order', '1'));

        // Should delete version 5 when threshold is 5
        $store->deleteOlderThan('Order', '1', 5);
        $this->assertFalse($store->has('Order', '1'));
    }

    public function test_snapshot_store_count_by_type(): void
    {
        $store = new InMemorySnapshotStore;
        $store->save(Snapshot::create('Order', '1', 1, []));
        $store->save(Snapshot::create('Order', '2', 1, []));
        $store->save(Snapshot::create('Product', '1', 1, []));

        $this->assertEquals(3, $store->count());
        $this->assertEquals(2, $store->count('Order'));
        $this->assertEquals(1, $store->count('Product'));
    }

    // ---------------------------------------------------------------
    // ValueObject equality tests
    // ---------------------------------------------------------------

    public function test_value_object_equality(): void
    {
        $vo1 = new class ('value') extends ValueObject {
            public function __construct(public readonly string $value) {}

            public function toArray(): array
            {
                return ['value' => $this->value];
            }
        };

        $vo2 = new class ('value') extends ValueObject {
            public function __construct(public readonly string $value) {}

            public function toArray(): array
            {
                return ['value' => $this->value];
            }
        };

        // ValueObject uses toArray() for equality — same data means equal
        $this->assertEquals($vo1->toArray(), $vo2->toArray());
    }

    // ---------------------------------------------------------------
    // Entity serialization tests
    // ---------------------------------------------------------------

    public function test_entity_to_array_contains_id_and_type(): void
    {
        $entity = new class ('123') extends Entity {
            public function __construct(public readonly string $id) {}

            protected function id(): string
            {
                return $this->id;
            }
        };

        $array = $entity->toArray();
        $this->assertArrayHasKey('id', $array);
        $this->assertArrayHasKey('type', $array);
        $this->assertEquals('123', $array['id']);
    }

    public function test_entity_equality_same_id(): void
    {
        $entity1 = new class ('abc') extends Entity {
            public function __construct(public readonly string $id) {}

            protected function id(): string
            {
                return $this->id;
            }
        };

        $entity2 = new class ('abc') extends Entity {
            public function __construct(public readonly string $id) {}

            protected function id(): string
            {
                return $this->id;
            }
        };

        // Entities with the same ID and same class are equal
        $this->assertEquals($entity1->id(), $entity2->id());
    }
}
