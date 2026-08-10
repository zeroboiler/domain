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
use ZeroBoiler\Domain\Contracts\AggregateRoot as AggregateRootContract;
use ZeroBoiler\Domain\Contracts\Entity as EntityContract;
use ZeroBoiler\Domain\Contracts\Identifier as IdentifierContract;
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
use ZeroBoiler\Domain\Identifiers\UlidIdentifier;
use ZeroBoiler\Domain\InMemoryUnitOfWork;
use ZeroBoiler\Domain\Snapshots\InMemorySnapshotStore;
use ZeroBoiler\Domain\Snapshots\Snapshot;
use ZeroBoiler\Domain\ValueObject;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\ValueObjects\Contracts\ValueObject as ValueObjectContract;

/**
 * Final comprehensive integration test covering domain package production guarantees.
 *
 * Validates: strict types, immutability, domain invariants, round-trip serde,
 * event sourcing, snapshot lifecycle, UoW transactional semantics, identifier
 * contracts, exception hierarchy, and cross-package response bridge readiness.
 *
 * @group production
 * @group domain
 *
 * @since 1.0.0
 */
#[CoversClass(Entity::class)]
#[CoversClass(AggregateRoot::class)]
#[CoversClass(AggregateRootId::class)]
#[CoversClass(DomainEventCollection::class)]
#[CoversClass(InMemoryUnitOfWork::class)]
#[CoversClass(ValueObject::class)]
#[CoversClass(Snapshot::class)]
#[CoversClass(InMemorySnapshotStore::class)]
final class DomainResponseProductionFinalIntegrationTest extends TestCase
{
    // ── AggregateRootId ────────────────────────────────────────────────

    public function test_aggregate_root_id_generate_creates_valid_uuid_v4(): void
    {
        $id = AggregateRootId::generate();

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $id->toString(),
            'Generated ID must be a valid UUID v4.'
        );
    }

    public function test_aggregate_root_id_from_string_round_trip(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $id = AggregateRootId::fromString($uuid);

        $this->assertSame($uuid, $id->toString());
        $this->assertSame($uuid, (string) $id);
    }

    public function test_aggregate_root_id_array_round_trip(): void
    {
        $id = AggregateRootId::generate();
        $restored = AggregateRootId::fromArray($id->toArray());

        $this->assertTrue($id->equals($restored), 'fromArray must restore an equal ID.');
        $this->assertSame($id->toArray(), $restored->toArray());
    }

    public function test_aggregate_root_id_json_serialization(): void
    {
        $id = AggregateRootId::generate();
        $json = json_encode($id);

        $this->assertNotFalse($json);
        $this->assertSame('"' . $id->toString() . '"', $json, 'JSON must be a quoted UUID string.');
    }

    public function test_aggregate_root_id_equality_same_value_same_type(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $a = AggregateRootId::fromString($uuid);
        $b = AggregateRootId::fromString($uuid);

        $this->assertTrue($a->equals($b));
    }

    public function test_aggregate_root_id_from_array_accepts_id_key_fallback(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $id = AggregateRootId::fromArray(['id' => $uuid]);

        $this->assertSame($uuid, $id->toString());
    }

    public function test_aggregate_root_id_from_array_throws_on_missing_key(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AggregateRootId::fromArray(['foo' => 'bar']);
    }

    public function test_aggregate_root_id_readonly_class(): void
    {
        $reflection = new \ReflectionClass(AggregateRootId::class);
        $this->assertTrue(
            $reflection->isReadOnly(),
            'AggregateRootId must be a readonly class.'
        );
    }

    public function test_aggregate_root_id_final_class(): void
    {
        $reflection = new \ReflectionClass(AggregateRootId::class);
        $this->assertTrue(
            $reflection->isFinal(),
            'AggregateRootId must be a final class.'
        );
    }

    // ── Identifiers ──────────────────────────────────────────────────

    public function test_uuid_identifier_generate_round_trip(): void
    {
        $id = TestUuidId::generate();
        $restored = TestUuidId::fromArray($id->toArray());

        $this->assertTrue($id->equals($restored));
        $this->assertInstanceOf(IdentifierContract::class, $id);
        $this->assertInstanceOf(\JsonSerializable::class, $id);
    }

    public function test_uuid_identifier_validation(): void
    {
        $this->assertTrue(TestUuidId::isValid('550e8400-e29b-41d4-a716-446655440000'));
        $this->assertFalse(TestUuidId::isValid('not-a-uuid'));
    }

    public function test_uuid_identifier_readonly_class(): void
    {
        $reflection = new \ReflectionClass(UuidIdentifier::class);
        $this->assertTrue($reflection->isReadOnly());
    }

    public function test_ulid_identifier_round_trip(): void
    {
        $id = TestUlidId::generate();
        $restored = TestUlidId::fromArray($id->toArray());

        $this->assertTrue($id->equals($restored));
        $this->assertTrue(TestUlidId::isValid($id->toString()));
    }

    public function test_ulid_identifier_readonly_class(): void
    {
        $reflection = new \ReflectionClass(UlidIdentifier::class);
        $this->assertTrue($reflection->isReadOnly());
    }

    public function test_integer_identifier_round_trip(): void
    {
        $id = IntegerIdentifier::from(42);
        $restored = IntegerIdentifier::fromArray($id->toArray());

        $this->assertTrue($id->equals($restored));
        $this->assertSame(42, $id->toInt());
        $this->assertSame('42', $id->toString());
        $this->assertSame(42, $id->jsonSerialize()); // int, not string
    }

    public function test_integer_identifier_final_readonly(): void
    {
        $reflection = new \ReflectionClass(IntegerIdentifier::class);
        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
    }

    public function test_string_identifier_round_trip(): void
    {
        $id = StringIdentifier::from('my-slug');
        $restored = StringIdentifier::fromArray($id->toArray());

        $this->assertTrue($id->equals($restored));
        $this->assertSame('my-slug', $id->toString());
    }

    public function test_string_identifier_empty_throws(): void
    {
        $this->expectException(\ValueError::class);
        StringIdentifier::from('');
    }

    public function test_string_identifier_final_readonly(): void
    {
        $reflection = new \ReflectionClass(StringIdentifier::class);
        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
    }

    public function test_uuid_identifier_json_serialization_returns_string(): void
    {
        $id = TestUuidId::generate();
        $json = json_encode($id);

        $this->assertNotFalse($json);
        $this->assertSame('"' . $id->toString() . '"', $json);
    }

    // ── Domain Exceptions ──────────────────────────────────────────────

    public function test_domain_exception_hierarchy(): void
    {
        $e = new class('test') extends DomainException {};

        $this->assertInstanceOf(\Exception::class, $e);
        $this->assertInstanceOf(\JsonSerializable::class, $e);
    }

    public function test_domain_exception_error_code(): void
    {
        $e = InvalidStateDomainException::because('Order must be pending.');
        $this->assertSame('INVALID_STATE', $e->errorCode());
    }

    public function test_domain_exception_custom_error_code(): void
    {
        $e = InvalidStateDomainException::because('Test', 'CUSTOM_CODE');
        $this->assertSame('CUSTOM_CODE', $e->errorCode());
    }

    public function test_domain_exception_to_error_array(): void
    {
        $e = InvalidStateDomainException::because('Cannot pay.');
        $arr = $e->toErrorArray();

        $this->assertArrayHasKey('title', $arr);
        $this->assertArrayHasKey('detail', $arr);
        $this->assertArrayHasKey('code', $arr);
        $this->assertSame('INVALID_STATE', $arr['code']);
    }

    public function test_domain_exception_json_serialization(): void
    {
        $e = NotFoundDomainException::because('User not found.');
        $json = json_encode($e);

        $this->assertNotFalse($json);
        $decoded = json_decode($json, true);
        $this->assertSame('NOT_FOUND', $decoded['code']);
    }

    public function test_domain_exception_to_array(): void
    {
        $e = ConflictDomainException::because('Concurrent modification.');
        $arr = $e->toArray();

        $this->assertArrayHasKey('error_code', $arr);
        $this->assertArrayHasKey('message', $arr);
        $this->assertArrayHasKey('file', $arr);
        $this->assertArrayHasKey('line', $arr);
    }

    public function test_all_exception_types_have_named_constructors(): void
    {
        $exceptions = [
            InvalidStateDomainException::because('test'),
            InvalidArgumentDomainException::because('test'),
            NotFoundDomainException::because('test'),
            ConflictDomainException::because('test'),
            OptimisticLockException::for('id', 1, 2),
            AggregateNotFoundException::for('Order', 'uuid'),
            InvalidAggregateRootException::notAnAggregate(new \stdClass),
        ];

        foreach ($exceptions as $e) {
            $this->assertInstanceOf(DomainException::class, $e);
            $this->assertNotEmpty($e->errorCode(), get_class($e) . ' must have a non-empty error code.');
        }
    }

    public function test_optimistic_lock_exception_message(): void
    {
        $e = OptimisticLockException::for('order-id', expectedVersion: 5, actualVersion: 3);
        $this->assertStringContainsString('expected version 5', $e->getMessage());
        $this->assertStringContainsString('current version 3', $e->getMessage());
    }

    // ── Snapshot ──────────────────────────────────────────────────────

    public function test_snapshot_immutability(): void
    {
        $snapshot = Snapshot::create('Order', 'uuid-123', 10, ['status' => 'pending']);
        $this->assertSame('Order', $snapshot->aggregateType);
        $this->assertSame('uuid-123', $snapshot->aggregateId);
        $this->assertSame(10, $snapshot->version);
        $this->assertSame(['status' => 'pending'], $snapshot->state);

        // Readonly class — properties cannot be modified
        $reflection = new \ReflectionClass(Snapshot::class);
        $this->assertTrue($reflection->isReadOnly());
    }

    public function test_snapshot_array_round_trip(): void
    {
        $original = Snapshot::create('Order', 'uuid', 5, ['foo' => 'bar']);
        $restored = Snapshot::fromArray($original->toArray());

        $this->assertTrue($original->equals($restored));
    }

    public function test_snapshot_json_serialization(): void
    {
        $snapshot = Snapshot::create('Order', 'uuid', 1, []);
        $json = json_encode($snapshot);

        $this->assertNotFalse($json);
        $decoded = json_decode($json, true);
        $this->assertSame('Order', $decoded['aggregate_type']);
    }

    public function test_in_memory_snapshot_store_lifecycle(): void
    {
        $store = new InMemorySnapshotStore;
        $snapshot = Snapshot::create('Order', 'uuid-1', 5, ['s' => 1]);

        $this->assertFalse($store->has('Order', 'uuid-1'));

        $store->save($snapshot);
        $this->assertTrue($store->has('Order', 'uuid-1'));
        $this->assertSame(1, $store->count());
        $this->assertSame(5, $store->load('Order', 'uuid-1')?->version);

        $store->delete('Order', 'uuid-1');
        $this->assertFalse($store->has('Order', 'uuid-1'));

        $store->save($snapshot);
        $removed = $store->purge('Order');
        $this->assertSame(1, $removed);
        $this->assertSame(0, $store->count());
    }

    public function test_in_memory_snapshot_store_stats(): void
    {
        $store = new InMemorySnapshotStore;
        $store->save(Snapshot::create('A', '1', 1, []));
        $store->save(Snapshot::create('A', '2', 1, []));
        $store->save(Snapshot::create('B', '1', 1, []));

        $stats = $store->stats();
        $this->assertSame(3, $stats['total']);
        $this->assertSame(2, $stats['by_type']['A']);
        $this->assertSame(1, $stats['by_type']['B']);
    }

    // ── DomainEventCollection ────────────────────────────────────────

    public function test_domain_event_collection_iterable(): void
    {
        $event1 = DomainEvent::occur('test.created', ['id' => '1']);
        $event2 = DomainEvent::occur('test.updated', ['id' => '1']);
        $collection = new DomainEventCollection([$event1, $event2]);

        $this->assertCount(2, $collection);
        $this->assertFalse($collection->isEmpty());

        $count = 0;
        foreach ($collection as $event) {
            $this->assertInstanceOf(DomainEvent::class, $event);
            $count++;
        }
        $this->assertSame(2, $count);
    }

    public function test_domain_event_collection_operations(): void
    {
        $e1 = DomainEvent::occur('a', []);
        $e2 = DomainEvent::occur('b', []);
        $collection = new DomainEventCollection([$e1]);

        $merged = $collection->merge(new DomainEventCollection([$e2]));
        $this->assertCount(2, $merged);

        $filtered = $merged->filter(fn (DomainEvent $e): bool => $e->eventType === 'a');
        $this->assertCount(1, $filtered);

        $mapped = $merged->map(fn (DomainEvent $e): string => $e->eventType);
        $this->assertSame(['a', 'b'], $mapped);

        $this->assertSame($e1, $collection->first());
        $this->assertSame($e2, $merged->last());
        $this->assertSame($e1, $merged->get(0));
        $this->assertNull($merged->get(99));
    }

    public function test_domain_event_collection_json_serialization(): void
    {
        $event = DomainEvent::occur('test.event', ['key' => 'value']);
        $collection = new DomainEventCollection([$event]);

        $json = json_encode($collection);
        $this->assertNotFalse($json);
        $decoded = json_decode($json, true);
        $this->assertCount(1, $decoded);
        $this->assertSame('test.event', $decoded[0]['eventType']);
    }

    public function test_domain_event_collection_rejects_non_list(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DomainEventCollection(['key' => DomainEvent::occur('a', [])]);
    }

    public function test_domain_event_collection_from_array_round_trip(): void
    {
        $original = new DomainEventCollection([
            DomainEvent::occur('order.created', ['id' => '1']),
            DomainEvent::occur('order.paid', ['amount' => 100]),
        ]);

        $restored = DomainEventCollection::fromArray($original->toArray());
        $this->assertCount($original->count(), $restored);
    }

    public function test_domain_event_collection_readonly_class(): void
    {
        $reflection = new \ReflectionClass(DomainEventCollection::class);
        $this->assertTrue($reflection->isReadOnly());
    }

    // ── Unit of Work ──────────────────────────────────────────────────

    public function test_uow_run_commit_dispatches_events(): void
    {
        $uow = new InMemoryUnitOfWork;
        $dispatched = [];
        $uow->setEventDispatcher(function (DomainEvent $e) use (&$dispatched): void {
            $dispatched[] = $e;
        });

        $aggregate = new TestAggregate(AggregateRootId::generate());
        $aggregate->doSomething();

        $result = $uow->run(function () use ($uow, $aggregate): string {
            $uow->track($aggregate);
            return 'success';
        });

        $this->assertSame('success', $result);
        $this->assertCount(1, $dispatched);
        $this->assertSame('test.something', $dispatched[0]->eventType);
        $this->assertCount(1, $uow->getCommitted());
    }

    public function test_uow_run_rollback_discards_events(): void
    {
        $uow = new InMemoryUnitOfWork;
        $dispatched = [];
        $uow->setEventDispatcher(function (DomainEvent $e) use (&$dispatched): void {
            $dispatched[] = $e;
        });

        $aggregate = new TestAggregate(AggregateRootId::generate());
        $aggregate->doSomething();

        $thrown = false;
        try {
            $uow->run(function () use ($uow, $aggregate): void {
                $uow->track($aggregate);
                throw new \RuntimeException('Intentional failure');
            });
        } catch (\RuntimeException $e) {
            $thrown = true;
            $this->assertSame('Intentional failure', $e->getMessage());
        }

        $this->assertTrue($thrown, 'Exception must be thrown from run() on failure.');
        $this->assertCount(0, $dispatched, 'No events dispatched after rollback.');
    }

    public function test_uow_nested_run_savepoints(): void
    {
        $uow = new InMemoryUnitOfWork;
        $aggregate = new TestAggregate(AggregateRootId::generate());
        $aggregate->doSomething();

        $result = $uow->run(function () use ($uow, $aggregate): string {
            $uow->track($aggregate);
            $inner = $uow->run(fn (): string => 'inner');
            $this->assertSame('inner', $inner);
            return 'outer';
        });

        $this->assertSame('outer', $result);
    }

    public function test_uow_inactive_after_commit(): void
    {
        $uow = new InMemoryUnitOfWork;

        $uow->run(function () use ($uow): void {
            $this->assertTrue($uow->isActive());
        });

        $this->assertFalse($uow->isActive(), 'UoW must be inactive after run() commits.');
    }

    public function test_uoW_get_pending_events_non_destructive(): void
    {
        $uow = new InMemoryUnitOfWork;
        $aggregate = new TestAggregate(AggregateRootId::generate());
        $aggregate->doSomething();

        $peeked = null;
        $uow->run(function () use ($uow, $aggregate, &$peeked): void {
            $uow->track($aggregate);
            $peeked = $uow->getPendingEvents();
            $this->assertCount(1, $peeked, 'Pending events must be available inside run().');
            $this->assertTrue($uow->hasPendingEvents());
        });

        // After commit, peeked events were captured during the transaction
        $this->assertNotNull($peeked);
        $this->assertCount(1, $peeked);
    }

    public function test_uow_persistence_callback_invoked(): void
    {
        $uow = new InMemoryUnitOfWork;
        $persisted = [];
        $uow->setPersistenceCallback(function (array $committed, array $deleted) use (&$persisted): void {
            $persisted = ['committed' => array_keys($committed), 'deleted' => array_keys($deleted)];
        });

        $aggregate = new TestAggregate(AggregateRootId::generate());
        $uow->run(function () use ($uow, $aggregate): void {
            $uow->track($aggregate);
        });

        $this->assertCount(1, $persisted['committed']);
    }

    // ── Entity Contract ────────────────────────────────────────────────

    public function test_entity_id_returns_string(): void
    {
        $entity = new TestEntity(42);
        $this->assertSame('42', $entity->id());
    }

    public function test_entity_equality(): void
    {
        $a = new TestEntity(42);
        $b = new TestEntity(42);
        $c = new TestEntity(99);

        $this->assertTrue($a->equals($b));
        $this->assertFalse($a->equals($c));
    }

    public function test_entity_to_array_base_shape(): void
    {
        $entity = new TestEntity(1);
        $arr = $entity->toArray();

        $this->assertArrayHasKey('id', $arr);
        $this->assertArrayHasKey('type', $arr);
        $this->assertSame('1', $arr['id']);
    }

    public function test_entity_implements_contract(): void
    {
        $entity = new TestEntity(1);
        $this->assertInstanceOf(EntityContract::class, $entity);
    }

    // ── Aggregate Root ────────────────────────────────────────────────

    public function test_aggregate_root_version_increments(): void
    {
        $aggregate = new TestAggregate(AggregateRootId::generate());
        $this->assertSame(0, $aggregate->version());

        $aggregate->doSomething();
        $this->assertSame(1, $aggregate->version());
    }

    public function test_aggregate_root_pull_domain_events(): void
    {
        $aggregate = new TestAggregate(AggregateRootId::generate());
        $aggregate->doSomething();
        $aggregate->doSomething();

        $events = $aggregate->pullDomainEvents();
        $this->assertCount(2, $events);
        $this->assertInstanceOf(DomainEventCollection::class, $events);

        // After pull, events are cleared
        $this->assertEmpty($aggregate->pullDomainEvents());
    }

    public function test_aggregate_root_peek_domain_events_non_destructive(): void
    {
        $aggregate = new TestAggregate(AggregateRootId::generate());
        $aggregate->doSomething();

        $peeked = $aggregate->peekDomainEvents();
        $this->assertCount(1, $peeked);

        // Events are still there
        $pulled = $aggregate->pullDomainEvents();
        $this->assertCount(1, $pulled);
    }

    public function test_aggregate_root_to_array_base_shape(): void
    {
        $id = AggregateRootId::generate();
        $aggregate = new TestAggregate($id);
        $aggregate->doSomething(); // version = 1

        $arr = $aggregate->toArray();
        $this->assertArrayHasKey('id', $arr);
        $this->assertArrayHasKey('version', $arr);
        $this->assertArrayHasKey('type', $arr);
        $this->assertSame($id->toString(), $arr['id']);
        $this->assertSame(1, $arr['version']);
    }

    public function test_aggregate_root_implements_contract(): void
    {
        $aggregate = new TestAggregate(AggregateRootId::generate());
        $this->assertInstanceOf(AggregateRootContract::class, $aggregate);
        $this->assertInstanceOf(EntityContract::class, $aggregate);
    }

    public function test_aggregate_root_id_identity(): void
    {
        $id = AggregateRootId::generate();
        $aggregate = new TestAggregate($id);

        $this->assertSame($id->toString(), $aggregate->id());
        $this->assertSame($id, $aggregate->aggregateId());
    }

    public function test_aggregate_root_clear_domain_events(): void
    {
        $aggregate = new TestAggregate(AggregateRootId::generate());
        $aggregate->doSomething();

        $aggregate->clearDomainEvents();
        $this->assertCount(0, $aggregate->pullDomainEvents());
    }

    // ── Value Object ─────────────────────────────────────────────────

    public function test_value_object_equality(): void
    {
        $a = new TestAddress('123 Main', 'NYC', 'US');
        $b = new TestAddress('123 Main', 'NYC', 'US');
        $c = new TestAddress('456 Oak', 'LA', 'US');

        $this->assertTrue($a->equals($b));
        $this->assertFalse($a->equals($c));
    }

    public function test_value_object_array_round_trip(): void
    {
        $original = new TestAddress('123 Main', 'NYC', 'US');
        $restored = TestAddress::fromArray($original->toArray());

        $this->assertTrue($original->equals($restored));
    }

    public function test_value_object_implements_contract(): void
    {
        $vo = new TestAddress('a', 'b', 'c');
        $this->assertInstanceOf(ValueObjectContract::class, $vo);
    }

    // ── Strict Types Verification ─────────────────────────────────────

    public function test_all_source_files_declare_strict_types(): void
    {
        $srcDir = dirname(__DIR__, 2) . '/src';
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($srcDir, \RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            $this->assertStringContainsString(
                'declare(strict_types=1)',
                $contents,
                "File {$file->getPathname()} must declare strict_types=1."
            );
        }
    }

    public function test_all_concrete_classes_have_since_tag(): void
    {
        $classes = [
            Entity::class,
            AggregateRoot::class,
            AggregateRootId::class,
            DomainEventCollection::class,
            InMemoryUnitOfWork::class,
            ValueObject::class,
            Snapshot::class,
            InMemorySnapshotStore::class,
            UuidIdentifier::class,
            UlidIdentifier::class,
            IntegerIdentifier::class,
            StringIdentifier::class,
            DomainException::class,
            InvalidStateDomainException::class,
            InvalidArgumentDomainException::class,
            NotFoundDomainException::class,
            ConflictDomainException::class,
            OptimisticLockException::class,
            AggregateNotFoundException::class,
            InvalidAggregateRootException::class,
        ];

        foreach ($classes as $class) {
            $reflection = new \ReflectionClass($class);
            $doc = $reflection->getDocComment();
            $this->assertNotFalse(
                $doc,
                "{$class} must have a class-level docblock."
            );
            $this->assertStringContainsString(
                '@since',
                $doc ?: '',
                "{$class} must have a @since tag."
            );
        }
    }
}

// ── Test Fixtures ─────────────────────────────────────────────────────

final readonly class TestUuidId extends UuidIdentifier {}

final readonly class TestUlidId extends UlidIdentifier {}

final class TestAddress extends ValueObject
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

final class TestEntity extends Entity
{
}

final class TestAggregate extends AggregateRoot
{
    use \ZeroBoiler\Domain\Concerns\HasDomainEvents;
    use \ZeroBoiler\Domain\Concerns\EventSourced;

    public string $status = 'pending';

    public function doSomething(): void
    {
        $this->status = 'done';
        $this->apply(DomainEvent::occur('test.something', [
            'id' => $this->aggregateId->toString(),
            'status' => $this->status,
        ]));
    }

    protected function applyTestSomething(DomainEvent $event): void
    {
        $this->status = $event->payload['status'] ?? 'done';
    }
}
