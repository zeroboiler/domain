<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Tests;

use ZeroBoiler\Domain\AggregateRoot;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\Contracts\Repository;
use ZeroBoiler\Domain\Contracts\UnitOfWork;
use ZeroBoiler\Domain\DomainEventCollection;
use ZeroBoiler\Domain\Entity;
use ZeroBoiler\Domain\Exceptions\AggregateNotFoundException;
use ZeroBoiler\Domain\Exceptions\ConflictDomainException;
use ZeroBoiler\Domain\Exceptions\DomainException;
use ZeroBoiler\Domain\Exceptions\InvalidArgumentDomainException;
use ZeroBoiler\Domain\Exceptions\InvalidStateDomainException;
use ZeroBoiler\Domain\Exceptions\NotFoundDomainException;
use ZeroBoiler\Domain\Exceptions\OptimisticLockException;
use ZeroBoiler\Domain\Identifiers\IntegerIdentifier;
use ZeroBoiler\Domain\Identifiers\StringIdentifier;
use ZeroBoiler\Domain\Identifiers\UuidIdentifier;
use ZeroBoiler\Domain\InMemoryUnitOfWork;
use ZeroBoiler\Domain\Snapshots\Snapshot;
use ZeroBoiler\Domain\Snapshots\SnapshotPolicy;
use ZeroBoiler\Domain\ValueObject;
use ZeroBoiler\Events\Domain\DomainEvent;

/**
 * Final production bridge tests for domain → response integration.
 *
 * Tests the complete lifecycle of domain entities, events, snapshots,
 * and exception handling with full type safety and immutability.
 *
 * Covers:
 * - AggregateRoot full lifecycle (create, mutate, pull events, version)
 * - Entity equality and identity semantics
 * - Identifier round-trip serialization (UUID, ULID, String, Integer)
 * - DomainException hierarchy with RFC 9457 error arrays
 * - DomainEventCollection functional operations
 * - Unit of Work transactional boundaries
 * - Snapshot round-trip serde
 * - ValueObject structural equality
 */
final class DomainResponseFinalBridgeTest extends TestCase
{
    // ─── AggregateRoot Lifecycle ───────────────────────────────────────

    public function test_aggregate_root_create_and_mutate(): void
    {
        $id = AggregateRootId::generate();
        $order = TestBridgeOrder::create($id);

        expect($order->id())->toBe($id->toString());
        expect($order->version())->toBe(1);
        expect($order->status)->toBe('pending');

        $order->pay(99.99);

        expect($order->status)->toBe('paid');
        expect($order->total)->toBe(99.99);
        expect($order->version())->toBe(2);
    }

    public function test_aggregate_root_pull_domain_events(): void
    {
        $id = AggregateRootId::generate();
        $order = TestBridgeOrder::create($id);
        $order->pay(50.00);

        $events = $order->pullDomainEvents();

        expect($events)->toBeInstanceOf(DomainEventCollection::class);
        expect($events->count())->toBe(2);
        expect($order->hasUncommittedEvents())->toBeFalse();

        // Events are in chronological order
        expect($events->first()->eventType)->toBe('order.placed');
        expect($events->last()->eventType)->toBe('order.paid');
    }

    public function test_aggregate_root_peek_does_not_consume_events(): void
    {
        $id = AggregateRootId::generate();
        $order = TestBridgeOrder::create($id);

        $peeked = $order->peekDomainEvents();
        expect($peeked->count())->toBe(1);
        expect($order->hasUncommittedEvents())->toBeTrue();

        $pulled = $order->pullDomainEvents();
        expect($pulled->count())->toBe(1);
        expect($order->hasUncommittedEvents())->toBeFalse();
    }

    public function test_aggregate_root_equality(): void
    {
        $id = AggregateRootId::generate();
        $order1 = new TestBridgeOrder($id);
        $order2 = new TestBridgeOrder($id);
        $order3 = new TestBridgeOrder(AggregateRootId::generate());

        expect($order1->equals($order2))->toBeTrue();
        expect($order1->equals($order3))->toBeFalse();
    }

    public function test_aggregate_root_to_array(): void
    {
        $id = AggregateRootId::generate();
        $order = TestBridgeOrder::create($id);
        $order->pay(25.00);

        $array = $order->toArray();

        expect($array)->toHaveKeys(['id', 'version', 'type']);
        expect($array['version'])->toBe(2);
        expect($array['type'])->toBe('TestBridgeOrder');
    }

    // ─── AggregateRootId Serialization ─────────────────────────────────

    public function test_aggregate_root_id_round_trip(): void
    {
        $id = AggregateRootId::generate();
        $restored = AggregateRootId::fromArray($id->toArray());

        expect($id->equals($restored))->toBeTrue();
    }

    public function test_aggregate_root_id_json_serialization(): void
    {
        $id = AggregateRootId::generate();
        $json = json_encode($id);

        expect($json)->toBeJson();
        expect(json_decode($json, true))->toBe($id->toString());
    }

    public function test_aggregate_root_id_accepts_id_key_in_from_array(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $id = AggregateRootId::fromArray(['id' => $uuid]);

        expect($id->toString())->toBe($uuid);
    }

    // ─── Entity ───────────────────────────────────────────────────────

    public function test_entity_identity_equality(): void
    {
        $item1 = new TestBridgeEntity('item-1', 'SKU-001', 5, 9.99);
        $item2 = new TestBridgeEntity('item-1', 'SKU-999', 10, 19.99);
        $item3 = new TestBridgeEntity('item-2', 'SKU-001', 5, 9.99);

        // Same ID, different properties → equal (identity-based)
        expect($item1->equals($item2))->toBeTrue();
        // Different ID, same properties → not equal
        expect($item1->equals($item3))->toBeFalse();
    }

    public function test_entity_to_array(): void
    {
        $item = new TestBridgeEntity('42', 'SKU-001', 3, 29.99);
        $array = $item->toArray();

        expect($array)->toHaveKeys(['id', 'type']);
        expect($array['id'])->toBe('42');
        expect($array['type'])->toBe('TestBridgeEntity');
    }

    public function test_entity_with_stringable_id(): void
    {
        $id = StringIdentifier::from('slug-123');
        $item = new TestBridgeEntity($id, 'X', 1, 1.0);

        expect($item->id())->toBe('slug-123');
    }

    public function test_entity_with_integer_id(): void
    {
        $item = new TestBridgeEntity(42, 'Y', 1, 1.0);

        expect($item->id())->toBe('42');
    }

    // ─── Identifiers ──────────────────────────────────────────────────

    public function test_uuid_identifier_round_trip(): void
    {
        $id = TestBridgeOrderId::generate();
        $restored = TestBridgeOrderId::fromArray($id->toArray());

        expect($id->equals($restored))->toBeTrue();
        expect($id->toString())->toBe($restored->toString());
    }

    public function test_uuid_identifier_cross_class_inequality(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $id1 = TestBridgeOrderId::fromString($uuid);
        $id2 = TestBridgeProductId::fromString($uuid);

        // Same UUID value but different class → not equal
        expect($id1->equals($id2))->toBeFalse();
    }

    public function test_string_identifier_validation(): void
    {
        expect(StringIdentifier::isValid('valid'))->toBeTrue();
        expect(StringIdentifier::isValid(''))->toBeFalse();
    }

    public function test_integer_identifier_from_string(): void
    {
        $id = IntegerIdentifier::fromString('42');
        expect($id->toInt())->toBe(42);
        expect($id->toString())->toBe('42');
    }

    // ─── DomainEventCollection ─────────────────────────────────────────

    public function test_event_collection_filter_and_map(): void
    {
        $e1 = DomainEvent::occur('order.placed', ['id' => '1']);
        $e2 = DomainEvent::occur('order.paid', ['id' => '1']);
        $e3 = DomainEvent::occur('order.placed', ['id' => '2']);

        $collection = new DomainEventCollection([$e1, $e2, $e3]);

        $placed = $collection->filter(
            fn (DomainEvent $e): bool => $e->eventType === 'order.placed',
        );

        expect($placed->count())->toBe(2);

        $types = $collection->map(
            fn (DomainEvent $e, int $i): string => $e->eventType,
        );

        expect($types)->toBe(['order.placed', 'order.paid', 'order.placed']);
    }

    public function test_event_collection_merge(): void
    {
        $c1 = new DomainEventCollection([
            DomainEvent::occur('a', []),
        ]);
        $c2 = new DomainEventCollection([
            DomainEvent::occur('b', []),
        ]);

        $merged = $c1->merge($c2);

        expect($merged->count())->toBe(2);
    }

    public function test_event_collection_json_serialization(): void
    {
        $collection = new DomainEventCollection([
            DomainEvent::occur('test.event', ['key' => 'value']),
        ]);

        $json = json_encode($collection);
        expect($json)->toBeJson();

        $decoded = json_decode($json, true);
        expect($decoded[0])->toHaveKey('eventType');
    }

    public function test_event_collection_get_first_last(): void
    {
        $e1 = DomainEvent::occur('first', []);
        $e2 = DomainEvent::occur('second', []);
        $collection = new DomainEventCollection([$e1, $e2]);

        expect($collection->first()->eventType)->toBe('first');
        expect($collection->last()->eventType)->toBe('second');
    }

    // ─── DomainException Hierarchy ───────────────────────────────────

    public function test_domain_exception_error_codes(): void
    {
        expect(InvalidStateDomainException::because('test')->errorCode())->toBe('INVALID_STATE');
        expect(InvalidArgumentDomainException::because('test')->errorCode())->toBe('INVALID_ARGUMENT');
        expect(NotFoundDomainException::because('test')->errorCode())->toBe('NOT_FOUND');
        expect(ConflictDomainException::because('test')->errorCode())->toBe('CONFLICT');
        expect(OptimisticLockException::for('id', 5, 3)->errorCode())->toBe('OPTIMISTIC_LOCK');
        expect(AggregateNotFoundException::for('Order', 'id')->errorCode())->toBe('AGGREGATE_NOT_FOUND');
    }

    public function test_domain_exception_to_error_array(): void
    {
        $e = InvalidStateDomainException::because('Order must be pending.');
        $array = $e->toErrorArray();

        expect($array)->toHaveKeys(['title', 'detail', 'code']);
        expect($array['title'])->toBe('InvalidStateDomainException');
        expect($array['code'])->toBe('INVALID_STATE');
    }

    public function test_domain_exception_json_serialization(): void
    {
        $e = NotFoundDomainException::forAggregate('Order', '123');
        $json = json_encode($e);

        expect($json)->toBeJson();
        $decoded = json_decode($json, true);
        expect($decoded['code'])->toBe('NOT_FOUND');
    }

    public function test_domain_exception_custom_error_code(): void
    {
        $e = InvalidStateDomainException::because('test', 'CUSTOM_CODE');
        expect($e->errorCode())->toBe('CUSTOM_CODE');
    }

    public function test_optimistic_lock_exception_message(): void
    {
        $e = OptimisticLockException::for('order-123', expectedVersion: 5, actualVersion: 3);
        $message = $e->getMessage();

        expect($message)->toContain('order-123');
        expect($message)->toContain('expected version 5');
        expect($message)->toContain('current version 3');
    }

    public function test_not_found_exception_for_aggregate(): void
    {
        $e = NotFoundDomainException::forAggregate('App\\Domain\\Order', 'uuid-here');
        $message = $e->getMessage();

        expect($message)->toContain('App\\Domain\\Order');
        expect($message)->toContain('uuid-here');
    }

    // ─── Unit of Work ─────────────────────────────────────────────────

    public function test_unit_of_work_run_success(): void
    {
        $uow = new InMemoryUnitOfWork;
        $id = AggregateRootId::generate();
        $order = TestBridgeOrder::create($id);

        $result = $uow->run(function () use ($uow, $order): TestBridgeOrder {
            $uow->track($order);
            $order->pay(100.00);

            return $order;
        });

        expect($result->status)->toBe('paid');
        expect($result->total)->toBe(100.00);
    }

    public function test_unit_of_work_run_rollback(): void
    {
        $uow = new InMemoryUnitOfWork;
        $id = AggregateRootId::generate();
        $order = TestBridgeOrder::create($id);

        $originalStatus = $order->status;

        try {
            $uow->run(function () use ($uow, $order): mixed {
                $uow->track($order);
                $order->pay(100.00);

                throw new \RuntimeException('Force rollback');
            });
        } catch (\RuntimeException) {
            // Expected
        }

        // After rollback, aggregate state is restored
        expect($order->status)->toBe($originalStatus);
    }

    public function test_unit_of_work_manual_begin_commit(): void
    {
        $uow = new InMemoryUnitOfWork;

        expect($uow->isActive())->toBeFalse();

        $uow->begin();
        expect($uow->isActive())->toBeTrue();

        $uow->commit();
        expect($uow->isActive())->toBeFalse();
    }

    public function test_unit_of_work_manual_rollback(): void
    {
        $uow = new InMemoryUnitOfWork;
        $uow->begin();
        $uow->queueEvent(DomainEvent::occur('test', []));
        expect($uow->hasPendingEvents())->toBeTrue();

        $uow->rollback();
        expect($uow->hasPendingEvents())->toBeFalse();
        expect($uow->isActive())->toBeFalse();
    }

    public function test_unit_of_work_track_and_commit(): void
    {
        $uow = new InMemoryUnitOfWork;
        $id = AggregateRootId::generate();
        $order = TestBridgeOrder::create($id);
        $order->pullDomainEvents(); // Clear initial events

        $uow->begin();
        $uow->track($order);
        $order->pay(50.00);
        $uow->commit();

        expect($uow->getCommitted())->toHaveKey($id->toString());
    }

    // ─── Snapshots ─────────────────────────────────────────────────────

    public function test_snapshot_round_trip(): void
    {
        $snapshot = Snapshot::create(
            aggregateType: 'App\\Domain\\Order',
            aggregateId: 'uuid-here',
            version: 42,
            state: ['status' => 'paid', 'total' => 99.99],
        );

        $array = $snapshot->toArray();
        expect($array)->toHaveKeys(['aggregate_type', 'aggregate_id', 'version', 'state', 'created_at']);

        $restored = Snapshot::fromArray($array);
        expect($snapshot->equals($restored))->toBeTrue();
    }

    public function test_snapshot_json_serialization(): void
    {
        $snapshot = Snapshot::create('Order', 'id-1', 1, ['key' => 'val']);
        $json = json_encode($snapshot);

        expect($json)->toBeJson();
    }

    public function test_snapshot_equality(): void
    {
        $s1 = Snapshot::create('Order', 'id-1', 5, ['x' => 1]);
        $s2 = Snapshot::create('Order', 'id-1', 5, ['x' => 1]);
        $s3 = Snapshot::create('Order', 'id-1', 6, ['x' => 1]);

        expect($s1->equals($s2))->toBeTrue();
        expect($s1->equals($s3))->toBeFalse();
    }

    public function test_snapshot_from_array_validates_types(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Snapshot::fromArray([
            'aggregate_type' => 123, // Should be string
            'aggregate_id' => 'id',
            'version' => 1,
            'state' => [],
            'created_at' => '2024-01-01T00:00:00+00:00',
        ]);
    }

    // ─── Value Object ─────────────────────────────────────────────────

    public function test_value_object_equality(): void
    {
        $a1 = TestBridgeAddress::fromArray(['street' => '123 Main', 'city' => 'NYC', 'country' => 'US']);
        $a2 = TestBridgeAddress::fromArray(['street' => '123 Main', 'city' => 'NYC', 'country' => 'US']);
        $a3 = TestBridgeAddress::fromArray(['street' => '456 Oak', 'city' => 'LA', 'country' => 'US']);

        expect($a1->equals($a2))->toBeTrue();
        expect($a1->equals($a3))->toBeFalse();
    }

    public function test_value_object_to_array(): void
    {
        $vo = TestBridgeAddress::fromArray(['street' => '1st', 'city' => 'NYC', 'country' => 'US']);

        expect($vo->toArray())->toBe([
            'street' => '1st',
            'city' => 'NYC',
            'country' => 'US',
        ]);
    }

    // ─── Cross-cutting: Immutability & Type Safety ───────────────────

    public function test_aggregate_root_id_is_immutable(): void
    {
        $this->expectException(\Error::class);

        $id = AggregateRootId::generate();
        $id->value = ' tampered'; // Readonly property
    }

    public function test_snapshot_is_immutable(): void
    {
        $this->expectException(\Error::class);

        $s = Snapshot::create('T', '1', 1, []);
        $s->version = 999; // Readonly property
    }

    public function test_identifier_readonly_classes(): void
    {
        $uuid = TestBridgeOrderId::generate();
        $str = StringIdentifier::from('test');
        $int = IntegerIdentifier::from(42);

        // All are readonly classes — assignment to property would fail
        expect($uuid->value)->toBeString();
        expect($str->value)->toBe('test');
        expect($int->value)->toBe(42);
    }

    public function test_in_memory_snapshot_store_lifecycle(): void
    {
        $store = new TestBridgeSnapshotStore;
        $snapshot = Snapshot::create('Order', 'id-1', 1, ['status' => 'new']);

        expect($store->has('Order', 'id-1'))->toBeFalse();

        $store->save($snapshot);
        expect($store->has('Order', 'id-1'))->toBeTrue();
        expect($store->count())->toBe(1);

        $loaded = $store->load('Order', 'id-1');
        expect($loaded)->not()->toBeNull();
        expect($loaded->version)->toBe(1);

        $stats = $store->stats();
        expect($stats['total'])->toBe(1);
        expect($stats['by_type'])->toHaveKey('Order');

        $store->delete('Order', 'id-1');
        expect($store->has('Order', 'id-1'))->toBeFalse();
    }

    public function test_in_memory_snapshot_store_purge(): void
    {
        $store = new TestBridgeSnapshotStore;
        $store->save(Snapshot::create('Order', '1', 1, []));
        $store->save(Snapshot::create('Order', '2', 1, []));
        $store->save(Snapshot::create('Product', '1', 1, []));

        $removed = $store->purge('Order');
        expect($removed)->toBe(2);
        expect($store->count())->toBe(1);
        expect($store->count('Product'))->toBe(1);
    }

    public function test_domain_event_collection_validates_list(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('sequential list');

        new DomainEventCollection(['key' => DomainEvent::occur('test', [])]);
    }

    public function test_domain_event_collection_validates_items(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be a DomainEvent');

        new DomainEventCollection(['not-an-event']);
    }
}

// ─── Test Fixtures ───────────────────────────────────────────────────

final class TestBridgeOrder extends AggregateRoot
{
    public string $status = 'pending';
    public float $total = 0.0;

    public static function create(AggregateRootId $id): self
    {
        $order = new self($id);
        $order->recordThat(
            DomainEvent::occur('order.placed', [
                'id' => $id->toString(),
                'status' => 'pending',
            ]),
        );
        $order->version++;

        return $order;
    }

    public function pay(float $amount): void
    {
        if ($this->status !== 'pending') {
            throw InvalidStateDomainException::because('Order must be pending to pay.');
        }
        if ($amount <= 0) {
            throw InvalidArgumentDomainException::because('Amount must be positive.');
        }

        $this->status = 'paid';
        $this->total = $amount;

        $this->recordThat(
            DomainEvent::occur('order.paid', [
                'id' => $this->id(),
                'amount' => $amount,
            ]),
        );
        $this->version++;
    }

    public function toArray(): array
    {
        return [
            ...parent::toArray(),
            'status' => $this->status,
            'total' => $this->total,
        ];
    }
}

final class TestBridgeEntity extends Entity
{
    public function __construct(
        int|string|\Stringable $id,
        public readonly string $sku,
        public int $quantity,
        public float $unitPrice,
    ) {
        parent::__construct($id);
    }

    public function toArray(): array
    {
        return [
            ...parent::toArray(),
            'sku' => $this->sku,
            'quantity' => $this->quantity,
            'unit_price' => $this->unitPrice,
            'subtotal' => $this->quantity * $this->unitPrice,
        ];
    }
}

final class TestBridgeOrderId extends UuidIdentifier {}

final class TestBridgeProductId extends UuidIdentifier {}

final class TestBridgeAddress extends ValueObject
{
    public function __construct(
        public readonly string $street,
        public readonly string $city,
        public readonly string $country,
    ) {}

    public static function fromArray(array $data): static
    {
        return new static(
            street: $data['street'],
            city: $data['city'],
            country: $data['country'],
        );
    }

    public function toArray(): array
    {
        return [
            'street' => $this->street,
            'city' => $this->city,
            'country' => $this->country,
        ];
    }
}

/**
 * @extends InMemorySnapshotStore
 */
final class TestBridgeSnapshotStore extends InMemorySnapshotStore {}
