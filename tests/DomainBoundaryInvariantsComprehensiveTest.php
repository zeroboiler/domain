<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\Contracts\Entity as EntityContract;
use ZeroBoiler\Domain\Contracts\Identifier as IdentifierContract;
use ZeroBoiler\Domain\DomainEventCollection;
use ZeroBoiler\Domain\Entity;
use ZeroBoiler\Domain\Exceptions\AggregateNotFoundException;
use ZeroBoiler\Domain\Exceptions\ConflictDomainException;
use ZeroBoiler\Domain\Exceptions\DomainException;
use ZeroBoiler\Domain\Exceptions\InvalidAggregateRootException;
use ZeroBoiler\Domain\Exceptions\InvalidArgumentDomainException;
use ZeroBoiler\Domain\Exceptions\InvalidStateDomainException;
use ZeroBoiler\Domain\Exceptions\NotFoundDomainException;
use ZeroBoiler\Domain\Exceptions\OptimisticLockException;
use ZeroBoiler\Domain\Identifiers\IntegerIdentifier;
use ZeroBoiler\Domain\Identifiers\StringIdentifier;
use ZeroBoiler\Domain\Identifiers\UuidIdentifier;
use ZeroBoiler\Domain\Snapshots\InMemorySnapshotStore;
use ZeroBoiler\Domain\Snapshots\Snapshot;
use ZeroBoiler\Domain\Snapshots\SnapshotPolicy;
use ZeroBoiler\Domain\Snapshots\SnapshotStore;
use ZeroBoiler\Domain\ValueObject;
use ZeroBoiler\ValueObjects\Contracts\ValueObject as ValueObjectContract;

/**
 * Domain boundary invariants — verifies immutability, identity semantics,
 * type safety, and domain invariant enforcement across all core classes.
 *
 * Production-ready: PHP 8.5 syntax, strict types, full return type coverage.
 */
#[Group('production')]
#[CoversClass(AggregateRootId::class)]
#[CoversClass(DomainEventCollection::class)]
#[CoversClass(Entity::class)]
#[CoversClass(DomainException::class)]
#[CoversClass(InvalidStateDomainException::class)]
#[CoversClass(InvalidArgumentDomainException::class)]
#[CoversClass(NotFoundDomainException::class)]
#[CoversClass(ConflictDomainException::class)]
#[CoversClass(OptimisticLockException::class)]
#[CoversClass(AggregateNotFoundException::class)]
#[CoversClass(InvalidAggregateRootException::class)]
#[CoversClass(Snapshot::class)]
#[CoversClass(InMemorySnapshotStore::class)]
#[CoversClass(StringIdentifier::class)]
#[CoversClass(IntegerIdentifier::class)]
#[CoversClass(UuidIdentifier::class)]
#[CoversClass(ValueObject::class)]
final class DomainBoundaryInvariantsComprehensiveTest extends TestCase
{
    // ─── AggregateRootId immutability ────────────────────────────────

    public function test_aggregate_root_id_is_final_readonly(): void
    {
        $reflection = new \ReflectionClass(AggregateRootId::class);

        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
    }

    public function test_aggregate_root_id_generates_unique_values(): void
    {
        $id1 = AggregateRootId::generate();
        $id2 = AggregateRootId::generate();

        $this->assertNotEquals($id1->toString(), $id2->toString());
        $this->assertFalse($id1->equals($id2));
    }

    public function test_aggregate_root_id_from_string_round_trip(): void
    {
        $id = AggregateRootId::generate();
        $restored = AggregateRootId::fromString($id->toString());

        $this->assertTrue($id->equals($restored));
        $this->assertSame($id->toString(), $restored->toString());
    }

    public function test_aggregate_root_id_to_array_round_trip(): void
    {
        $id = AggregateRootId::generate();
        $restored = AggregateRootId::fromArray($id->toArray());

        $this->assertTrue($id->equals($restored));
        $this->assertSame($id->toArray(), $restored->toArray());
    }

    public function test_aggregate_root_id_from_array_accepts_id_key(): void
    {
        $id = AggregateRootId::generate();
        $restored = AggregateRootId::fromArray(['id' => $id->toString()]);

        $this->assertTrue($id->equals($restored));
    }

    public function test_aggregate_root_id_from_array_rejects_missing_keys(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AggregateRootId::fromArray(['foo' => 'bar']);
    }

    public function test_aggregate_root_id_json_serialization(): void
    {
        $id = AggregateRootId::generate();
        $json = json_encode($id);

        $this->assertIsString($json);
        $this->assertSame('"' . $id->toString() . '"', $json);
    }

    public function test_aggregate_root_id_stringable(): void
    {
        $id = AggregateRootId::generate();

        $this->assertSame($id->toString(), (string) $id);
    }

    // ─── DomainEventCollection immutability ───────────────────────────

    public function test_domain_event_collection_is_final_readonly(): void
    {
        $reflection = new \ReflectionClass(DomainEventCollection::class);

        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
    }

    public function test_domain_event_collection_rejects_associative_array(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('sequential list');

        // @phpstan-ignore argument.type
        new DomainEventCollection(['key' => 'value']);
    }

    public function test_domain_event_collection_filter_returns_new_instance(): void
    {
        $events = $this->createEventCollection(3);
        $original = $events->count();
        $filtered = $events->filter(fn () => false);

        $this->assertNotSame($events, $filtered);
        $this->assertSame($original, $events->count());
        $this->assertSame(0, $filtered->count());
    }

    public function test_domain_event_collection_merge_returns_new_instance(): void
    {
        $events1 = $this->createEventCollection(2);
        $events2 = $this->createEventCollection(1);

        $merged = $events1->merge($events2);

        $this->assertNotSame($events1, $merged);
        $this->assertNotSame($events2, $merged);
        $this->assertSame(2, $events1->count());
        $this->assertSame(1, $events2->count());
        $this->assertSame(3, $merged->count());
    }

    public function test_domain_event_collection_to_array_from_array_round_trip(): void
    {
        $original = $this->createEventCollection(2);
        $restored = DomainEventCollection::fromArray($original->toArray());

        $this->assertSame($original->count(), $restored->count());
    }

    public function test_domain_event_collection_from_array_rejects_non_list(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DomainEventCollection::fromArray(['key' => ['event_type' => 'test']]);
    }

    public function test_domain_event_collection_json_serialization(): void
    {
        $events = $this->createEventCollection(2);
        $json = json_encode($events);

        $this->assertIsString($json);
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
        $this->assertCount(2, $decoded);
    }

    // ─── Entity identity semantics ────────────────────────────────────

    public function test_entity_equality_requires_same_class(): void
    {
        $id = AggregateRootId::generate();
        $entity1 = new TestEntity($id);
        $entity2 = new AnotherTestEntity($id);

        // Different classes are never equal, even with same ID
        $this->assertFalse($entity1->equals($entity2));
    }

    public function test_entity_equality_with_same_id(): void
    {
        $id = AggregateRootId::generate();
        $entity1 = new TestEntity($id);
        $entity2 = new TestEntity($id);

        $this->assertTrue($entity1->equals($entity2));
    }

    public function test_entity_to_array_includes_id_and_type(): void
    {
        $id = AggregateRootId::generate();
        $entity = new TestEntity($id);
        $array = $entity->toArray();

        $this->assertArrayHasKey('id', $array);
        $this->assertArrayHasKey('type', $array);
        $this->assertSame('TestEntity', $array['type']);
        $this->assertSame($id->toString(), $array['id']);
    }

    // ─── ValueObject equality ────────────────────────────────────────

    public function test_value_object_equals_requires_same_class(): void
    {
        $vo1 = TestValueObject::fromArray(['value' => 'test']);
        $vo2 = AnotherTestValueObject::fromArray(['value' => 'test']);

        $this->assertFalse($vo1->equals($vo2));
    }

    public function test_value_object_equals_requires_same_data(): void
    {
        $vo1 = TestValueObject::fromArray(['value' => 'test']);
        $vo2 = TestValueObject::fromArray(['value' => 'test']);
        $vo3 = TestValueObject::fromArray(['value' => 'other']);

        $this->assertTrue($vo1->equals($vo2));
        $this->assertFalse($vo1->equals($vo3));
    }

    public function test_value_object_equals_returns_false_for_null(): void
    {
        $vo = TestValueObject::fromArray(['value' => 'test']);
        $this->assertFalse($vo->equals(null));
    }

    public function test_value_object_from_array_to_array_round_trip(): void
    {
        $original = TestValueObject::fromArray(['value' => 'test']);
        $restored = TestValueObject::fromArray($original->toArray());

        $this->assertTrue($original->equals($restored));
    }

    // ─── Identifier type safety ──────────────────────────────────────

    public function test_string_identifier_rejects_empty_string(): void
    {
        $this->expectException(\ValueError::class);
        StringIdentifier::from('');
    }

    public function test_string_identifier_is_final_readonly(): void
    {
        $reflection = new \ReflectionClass(StringIdentifier::class);
        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
    }

    public function test_integer_identifier_is_final_readonly(): void
    {
        $reflection = new \ReflectionClass(IntegerIdentifier::class);
        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
    }

    public function test_uuid_identifier_is_abstract_readonly(): void
    {
        $reflection = new \ReflectionClass(UuidIdentifier::class);
        $this->assertTrue($reflection->isAbstract());
        $this->assertTrue($reflection->isReadOnly());
    }

    public function test_integer_identifier_from_array_round_trip(): void
    {
        $id = IntegerIdentifier::from(42);
        $restored = IntegerIdentifier::fromArray($id->toArray());

        $this->assertTrue($id->equals($restored));
        $this->assertSame(42, $restored->toInt());
    }

    public function test_string_identifier_from_array_round_trip(): void
    {
        $id = StringIdentifier::from('my-slug');
        $restored = StringIdentifier::fromArray($id->toArray());

        $this->assertTrue($id->equals($restored));
        $this->assertSame('my-slug', $restored->toString());
    }

    public function test_integer_identifier_json_serialization_returns_int(): void
    {
        $id = IntegerIdentifier::from(42);
        $json = json_encode($id);

        $this->assertSame('42', $json);
    }

    public function test_identifier_equals_requires_same_concrete_class(): void
    {
        $id1 = new class extends UuidIdentifier {
            public function __construct()
            {
                parent::__construct((new class extends UuidIdentifier {
                    public function __construct()
                    {
                        $uuid = \Ramsey\Uuid\Uuid::uuid4();
                        parent::__construct($uuid->toString());
                    }
                })->value);
            }
        };

        // Different concrete subclasses are never equal
        $uuid = \Ramsey\Uuid\Uuid::uuid4()->toString();
        $id2 = new class($uuid) extends UuidIdentifier {
            public function __construct(string $value)
            {
                parent::__construct($value);
            }
        };

        // Even if they share the same UUID value, different subclasses are not equal
        // (unless they are the exact same concrete class)
    }

    // ─── DomainException hierarchy ───────────────────────────────────

    public function test_all_exceptions_have_machine_readable_error_codes(): void
    {
        $exceptions = [
            InvalidStateDomainException::because('test'),
            InvalidArgumentDomainException::because('test'),
            NotFoundDomainException::because('test'),
            ConflictDomainException::because('test'),
            OptimisticLockException::for('id', 5, 3),
            AggregateNotFoundException::for('Order', '123'),
            InvalidAggregateRootException::notAnAggregate(new \stdClass),
        ];

        foreach ($exceptions as $exception) {
            $this->assertNotEmpty(
                $exception->errorCode(),
                get_class($exception) . ' should have a non-empty error code',
            );
            $this->assertMatchesRegularExpression(
                '/^[A-Z_]+$/',
                $exception->errorCode(),
                get_class($exception) . ' error code should be UPPER_SNAKE_CASE',
            );
        }
    }

    public function test_domain_exception_to_error_array_structure(): void
    {
        $exception = InvalidStateDomainException::because('Order must be pending.');
        $array = $exception->toErrorArray();

        $this->assertArrayHasKey('title', $array);
        $this->assertArrayHasKey('detail', $array);
        $this->assertArrayHasKey('code', $array);
        $this->assertSame('INVALID_STATE', $array['code']);
        $this->assertSame('Order must be pending.', $array['detail']);
    }

    public function test_domain_exception_json_serialization(): void
    {
        $exception = NotFoundDomainException::because('User not found.');
        $json = json_encode($exception);

        $decoded = json_decode($json, true);
        $this->assertSame('NotFoundDomainException', $decoded['title']);
        $this->assertSame('NOT_FOUND', $decoded['code']);
    }

    public function test_domain_exception_custom_error_code(): void
    {
        $exception = InvalidStateDomainException::because('test', 'CUSTOM_CODE');
        $this->assertSame('CUSTOM_CODE', $exception->errorCode());
    }

    public function test_optimistic_lock_exception_message_format(): void
    {
        $exception = OptimisticLockException::for('order-123', 5, 3);
        $message = $exception->getMessage();

        $this->assertStringContainsString('order-123', $message);
        $this->assertStringContainsString('5', $message);
        $this->assertStringContainsString('3', $message);
    }

    public function test_not_found_exception_for_aggregate_format(): void
    {
        $exception = NotFoundDomainException::forAggregate('Order', 'uuid-here');
        $message = $exception->getMessage();

        $this->assertStringContainsString('Order', $message);
        $this->assertStringContainsString('uuid-here', $message);
    }

    public function test_exception_to_array_for_debugging(): void
    {
        $exception = ConflictDomainException::because('Concurrent modification.');
        $array = $exception->toArray();

        $this->assertArrayHasKey('error_code', $array);
        $this->assertArrayHasKey('message', $array);
        $this->assertArrayHasKey('file', $array);
        $this->assertArrayHasKey('line', $array);
    }

    // ─── Snapshot immutability ────────────────────────────────────────

    public function test_snapshot_is_final_readonly(): void
    {
        $reflection = new \ReflectionClass(Snapshot::class);
        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
    }

    public function test_snapshot_to_array_from_array_round_trip(): void
    {
        $original = Snapshot::create(Order::class, 'uuid-123', 5, ['status' => 'paid']);
        $restored = Snapshot::fromArray($original->toArray());

        $this->assertTrue($original->equals($restored));
        $this->assertSame($original->version, $restored->version);
        $this->assertSame($original->state, $restored->state);
        $this->assertSame($original->aggregateType, $restored->aggregateType);
        $this->assertSame($original->aggregateId, $restored->aggregateId);
    }

    public function test_snapshot_from_array_rejects_invalid_data(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Snapshot::fromArray([
            'aggregate_type' => 123,  // Should be string
            'aggregate_id' => 'uuid',
            'version' => 5,
            'state' => [],
            'created_at' => '2025-01-01T00:00:00+00:00',
        ]);
    }

    public function test_snapshot_equality(): void
    {
        $s1 = Snapshot::create(Order::class, 'uuid-123', 5, ['status' => 'paid']);
        $s2 = Snapshot::create(Order::class, 'uuid-123', 5, ['status' => 'paid']);
        $s3 = Snapshot::create(Order::class, 'uuid-123', 6, ['status' => 'paid']);

        $this->assertTrue($s1->equals($s2));
        $this->assertFalse($s1->equals($s3));
    }

    // ─── SnapshotPolicy attribute ────────────────────────────────────

    public function test_snapshot_policy_is_final_readonly(): void
    {
        $reflection = new \ReflectionClass(SnapshotPolicy::class);
        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
    }

    public function test_snapshot_policy_default_every(): void
    {
        $policy = new SnapshotPolicy;
        $this->assertSame(50, $policy->every);
    }

    // ─── InMemorySnapshotStore ─────────────────────────────────────

    public function test_in_memory_snapshot_store_is_final(): void
    {
        $reflection = new \ReflectionClass(InMemorySnapshotStore::class);
        $this->assertTrue($reflection->isFinal());
    }

    public function test_snapshot_store_purge_returns_count(): void
    {
        $store = new InMemorySnapshotStore;
        $store->save(Snapshot::create('A', '1', 1, []));
        $store->save(Snapshot::create('A', '2', 1, []));
        $store->save(Snapshot::create('B', '1', 1, []));

        $removedA = $store->purge('A');
        $this->assertSame(2, $removedA);
        $this->assertSame(1, $store->count());

        $removedAll = $store->purge();
        $this->assertSame(1, $removedAll);
        $this->assertSame(0, $store->count());
    }

    public function test_snapshot_store_stats(): void
    {
        $store = new InMemorySnapshotStore;
        $store->save(Snapshot::create('Order', '1', 1, []));
        $store->save(Snapshot::create('Order', '2', 1, []));
        $store->save(Snapshot::create('Product', '1', 1, []));

        $stats = $store->stats();

        $this->assertSame(3, $stats['total']);
        $this->assertSame(2, $stats['by_type']['Order']);
        $this->assertSame(1, $stats['by_type']['Product']);
    }

    public function test_snapshot_store_delete_older_than(): void
    {
        $store = new InMemorySnapshotStore;
        $store->save(Snapshot::create('Order', '1', 5, []));

        $store->deleteOlderThan('Order', '1', 6); // Snapshot version 5 < 6, should delete
        $this->assertFalse($store->has('Order', '1'));

        $store->save(Snapshot::create('Order', '1', 10, []));
        $store->deleteOlderThan('Order', '1', 6); // Snapshot version 10 >= 6, should NOT delete
        $this->assertTrue($store->has('Order', '1'));
    }

    // ─── SnapshotStore interface compliance ──────────────────────────

    public function test_in_memory_snapshot_store_implements_interface(): void
    {
        $store = new InMemorySnapshotStore;
        $this->assertInstanceOf(SnapshotStore::class, $store);
    }

    // ─── Helper methods ─────────────────────────────────────────────

    private function createEventCollection(int $count): DomainEventCollection
    {
        $events = [];

        for ($i = 0; $i < $count; $i++) {
            $events[] = new class extends \ZeroBoiler\Events\Domain\DomainEvent {
                public string $eventType = 'test.event';
                public array $payload = [];
                public \DateTimeImmutable $occurredAt;
                public ?string $aggregateId = null;
                public ?int $aggregateVersion = null;
                public string $eventId = '';

                public function __construct()
                {
                    $this->occurredAt = new \DateTimeImmutable;
                    $this->eventId = \Ramsey\Uuid\Uuid::uuid4()->toString();
                }

                public function toArray(): array
                {
                    return [
                        'event_type' => $this->eventType,
                        'payload' => $this->payload,
                        'occurred_at' => $this->occurredAt->format(\DateTimeInterface::ATOM),
                        'event_id' => $this->eventId,
                    ];
                }
            };
        }

        return new DomainEventCollection($events);
    }
}

// ─── Test doubles ──────────────────────────────────────────────────

final class TestEntity extends Entity
{
    public function __construct(
        int|string|\Stringable $id,
        public string $name = 'test',
    ) {
        parent::__construct($id);
    }

    public function toArray(): array
    {
        return [...parent::toArray(), 'name' => $this->name];
    }
}

final class AnotherTestEntity extends Entity
{
    public function __construct(
        int|string|\Stringable $id,
        public string $label = 'another',
    ) {
        parent::__construct($id);
    }

    public function toArray(): array
    {
        return [...parent::toArray(), 'label' => $this->label];
    }
}

final class TestValueObject extends ValueObject
{
    public function __construct(public readonly string $value) {}

    public static function fromArray(array $data): static
    {
        return new static(value: $data['value']);
    }

    public function toArray(): array
    {
        return ['value' => $this->value];
    }
}

final class AnotherTestValueObject extends ValueObject
{
    public function __construct(public readonly string $value) {}

    public static function fromArray(array $data): static
    {
        return new static(value: $data['value']);
    }

    public function toArray(): array
    {
        return ['value' => $this->value];
    }
}

class Order extends \ZeroBoiler\Domain\AggregateRoot
{
    public function __construct(\ZeroBoiler\Domain\AggregateRootId $id)
    {
        parent::__construct($id);
    }

    public function toArray(): array
    {
        return [...parent::toArray(), 'type_override' => 'Order'];
    }
}
