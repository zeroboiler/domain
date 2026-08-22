<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Tests\CrossPackage;

use PHPUnit\Framework\TestCase;
use ZeroBoiler\Domain\AggregateRoot;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\Contracts\Entity;
use ZeroBoiler\Domain\Contracts\Identifier;
use ZeroBoiler\Domain\DomainEventCollection;
use ZeroBoiler\Domain\Entity as BaseEntity;
use ZeroBoiler\Domain\Exceptions\{
    AggregateNotFoundException,
    ConflictDomainException,
    DomainException,
    InvalidArgumentDomainException,
    InvalidStateDomainException,
    NotFoundDomainException,
    OptimisticLockException,
};
use ZeroBoiler\Domain\Identifiers\{IntegerIdentifier, StringIdentifier, UuidIdentifier, UlidIdentifier};
use ZeroBoiler\Domain\InMemoryUnitOfWork;
use ZeroBoiler\Domain\Snapshots\{InMemorySnapshotStore, Snapshot, SnapshottingRepository};
use ZeroBoiler\Events\Domain\DomainEvent;

/**
 * Final production contract test for the domain package.
 *
 * Comprehensive verification of all domain building blocks and their
 * public API contracts that the response package consumes:
 *
 * 1. AggregateRoot — identity, versioning, events, toArray
 * 2. Entity — flexible ID types, equality, serialization
 * 3. Identifiers — round-trip, equality, Stringable, JsonSerializable
 * 4. Domain exceptions — RFC 9457, errorCode, httpStatus, serialization
 * 5. DomainEventCollection — functional ops, serialization
 * 6. Snapshots — create, equality, serialization
 * 7. UnitOfWork — transactional boundaries, event queuing
 * 8. Guards — domain invariant assertions
 *
 * @see \ZeroBoiler\Response\Concerns\ExtractsDomainId
 * @see \ZeroBoiler\Response\Transformers\DomainResponseFactory
 *
 * @since 1.78.0
 */
final class DomainProductionContractFinalTest extends TestCase
{
    // ─────────────────────────────────────────────────────────
    // 1. AggregateRoot — Identity, Versioning, Events
    // ─────────────────────────────────────────────────────────

    public function test_aggregate_root_generates_uuid_identity(): void
    {
        $id = AggregateRootId::generate();
        $root = ContractTestAggregate::create($id);

        $this->assertSame($id->toString(), $root->id());
        $this->assertSame($id, $root->aggregateId());
    }

    public function test_aggregate_root_version_increments_on_apply(): void
    {
        $root = ContractTestAggregate::create(AggregateRootId::generate());
        $this->assertSame(1, $root->version());

        $root->doSomething();
        $this->assertSame(2, $root->version());

        $root->doSomething();
        $this->assertSame(3, $root->version());
    }

    public function test_aggregate_root_pulls_domain_events(): void
    {
        $root = ContractTestAggregate::create(AggregateRootId::generate());
        $root->doSomething();

        $events = $root->pullDomainEvents();
        $this->assertInstanceOf(DomainEventCollection::class, $events);
        $this->assertSame(2, $events->count());

        // After pull, events are cleared
        $this->assertSame(0, $root->pullDomainEvents()->count());
    }

    public function test_aggregate_root_peek_does_not_clear_events(): void
    {
        $root = ContractTestAggregate::create(AggregateRootId::generate());

        $peeked = $root->peekDomainEvents();
        $pulled = $root->pullDomainEvents();

        $this->assertSame($peeked->count(), $pulled->count());
        $this->assertSame(1, $peeked->count());
    }

    public function test_aggregate_root_to_array_structure(): void
    {
        $root = ContractTestAggregate::create(AggregateRootId::generate());
        $root->doSomething();

        $array = $root->toArray();

        $this->assertArrayHasKey('id', $array);
        $this->assertArrayHasKey('version', $array);
        $this->assertArrayHasKey('type', $array);
        $this->assertSame('ContractTestAggregate', $array['type']);
        $this->assertSame(2, $array['version']);
    }

    public function test_aggregate_root_equality_is_identity_based(): void
    {
        $id = AggregateRootId::generate();
        $root1 = ContractTestAggregate::create($id);
        $root2 = ContractTestAggregate::create($id);

        $this->assertTrue($root1->equals($root2));

        $root3 = ContractTestAggregate::create(AggregateRootId::generate());
        $this->assertFalse($root1->equals($root3));
    }

    public function test_aggregate_root_json_serialization(): void
    {
        $root = ContractTestAggregate::create(AggregateRootId::generate());

        $json = $root->toJson();
        $decoded = json_decode($json, true);

        $this->assertSame($root->id(), $decoded['id']);
        $this->assertSame(1, $decoded['version']);
    }

    // ─────────────────────────────────────────────────────────
    // 2. Entity — Flexible ID Types
    // ─────────────────────────────────────────────────────────

    public function test_entity_with_int_id(): void
    {
        $entity = new ContractTestEntity(42);
        $this->assertSame('42', $entity->id());
    }

    public function test_entity_with_string_id(): void
    {
        $entity = new ContractTestEntity('slug-abc');
        $this->assertSame('slug-abc', $entity->id());
    }

    public function test_entity_with_stringable_id(): void
    {
        $id = UuidIdentifier::generate();
        $entity = new ContractTestEntity($id);
        $this->assertSame($id->toString(), $entity->id());
    }

    public function test_entity_equality_same_class_same_id(): void
    {
        $e1 = new ContractTestEntity(1);
        $e2 = new ContractTestEntity(1);
        $e3 = new ContractTestEntity(2);

        $this->assertTrue($e1->equals($e2));
        $this->assertFalse($e1->equals($e3));
    }

    public function test_entity_from_array_round_trip(): void
    {
        $original = new ContractTestEntity('e-1', 'Test Name');
        $array = $original->toArray();
        $restored = ContractTestEntity::fromArray($array);

        $this->assertSame($original->id(), $restored->id());
        $this->assertSame($original->name, $restored->name);
    }

    public function test_entity_from_json_round_trip(): void
    {
        $original = new ContractTestEntity('e-1', 'Test Name');
        $json = $original->toJson();
        $restored = ContractTestEntity::fromJson($json);

        $this->assertSame($original->id(), $restored->id());
    }

    public function test_entity_implements_json_serializable(): void
    {
        $entity = new ContractTestEntity(1);
        $this->assertInstanceOf(\JsonSerializable::class, $entity);

        $encoded = json_encode($entity);
        $this->assertIsString($encoded);
    }

    public function test_entity_has_uncommitted_events_returns_bool(): void
    {
        $entity = new ContractTestEntity(1);
        $this->assertIsBool($entity->hasUncommittedEvents());
    }

    // ─────────────────────────────────────────────────────────
    // 3. Identifiers — Round-Trip & Equality
    // ─────────────────────────────────────────────────────────

    public function test_uuid_identifier_round_trip(): void
    {
        $id = ContractTestUuid::generate();
        $restored = ContractTestUuid::fromArray($id->toArray());

        $this->assertTrue($id->equals($restored));
        $this->assertSame($id->toString(), $restored->toString());
    }

    public function test_uuid_identifier_json_round_trip(): void
    {
        $id = ContractTestUuid::generate();
        $restored = ContractTestUuid::fromJson($id->toJson());

        $this->assertTrue($id->equals($restored));
    }

    public function test_ulid_identifier_round_trip(): void
    {
        $id = ContractTestUlid::generate();
        $restored = ContractTestUlid::fromArray($id->toArray());

        $this->assertTrue($id->equals($restored));
    }

    public function test_string_identifier_round_trip(): void
    {
        $id = StringIdentifier::from('my-slug');
        $restored = StringIdentifier::fromArray($id->toArray());

        $this->assertTrue($id->equals($restored));
    }

    public function test_integer_identifier_round_trip(): void
    {
        $id = IntegerIdentifier::from(42);
        $restored = IntegerIdentifier::fromArray($id->toArray());

        $this->assertTrue($id->equals($restored));
        $this->assertSame(42, $restored->toInt());
    }

    public function test_identifiers_are_stringable(): void
    {
        $uuid = ContractTestUuid::generate();
        $ulid = ContractTestUlid::generate();
        $str = StringIdentifier::from('slug');
        $int = IntegerIdentifier::from(1);
        $rootId = AggregateRootId::generate();

        $this->assertSame($uuid->toString(), (string) $uuid);
        $this->assertSame($ulid->toString(), (string) $ulid);
        $this->assertSame('slug', (string) $str);
        $this->assertSame('1', (string) $int);
        $this->assertSame($rootId->toString(), (string) $rootId);
    }

    public function test_identifiers_are_json_serializable(): void
    {
        $uuid = ContractTestUuid::generate();
        $str = StringIdentifier::from('slug');

        $this->assertIsString(json_encode($uuid));
        $this->assertIsString(json_encode($str));
    }

    public function test_identifiers_implement_contract(): void
    {
        $identifiers = [
            ContractTestUuid::generate(),
            ContractTestUlid::generate(),
            StringIdentifier::from('test'),
            IntegerIdentifier::from(1),
        ];

        foreach ($identifiers as $id) {
            $this->assertInstanceOf(Identifier::class, $id);
            $this->assertInstanceOf(\Stringable::class, $id);
            $this->assertInstanceOf(\JsonSerializable::class, $id);
        }
    }

    public function test_cross_type_identifiers_never_equal(): void
    {
        $uuid = ContractTestUuid::generate();
        $ulid = ContractTestUlid::generate();
        $str = StringIdentifier::from('test');
        $int = IntegerIdentifier::from(1);

        $this->assertFalse($uuid->equals($ulid));
        $this->assertFalse($uuid->equals($str));
        $this->assertFalse($uuid->equals($int));
    }

    public function test_aggregate_root_id_round_trip(): void
    {
        $id = AggregateRootId::generate();
        $restored = AggregateRootId::fromArray($id->toArray());
        $this->assertTrue($id->equals($restored));

        $fromJson = AggregateRootId::fromJson($id->toJson());
        $this->assertTrue($id->equals($fromJson));
    }

    // ─────────────────────────────────────────────────────────
    // 4. Domain Exceptions — RFC 9457 Contract
    // ─────────────────────────────────────────────────────────

    public function test_all_exceptions_provide_error_code(): void
    {
        $exceptions = [
            InvalidStateDomainException::because('state'),
            InvalidArgumentDomainException::because('arg'),
            NotFoundDomainException::because('not found'),
            ConflictDomainException::because('conflict'),
            OptimisticLockException::for('x', 1, 2),
            AggregateNotFoundException::for('Order', 'id-1'),
        ];

        foreach ($exceptions as $e) {
            $this->assertIsString($e->errorCode(), get_class($e) . '::errorCode()');
            $this->assertNotEmpty($e->errorCode(), get_class($e) . '::errorCode()');
        }
    }

    public function test_all_exceptions_provide_http_status(): void
    {
        $expected = [
            InvalidStateDomainException::class => 422,
            InvalidArgumentDomainException::class => 422,
            NotFoundDomainException::class => 404,
            ConflictDomainException::class => 409,
            AggregateNotFoundException::class => 404,
        ];

        foreach ($expected as $class => $status) {
            $e = $class::because('test');
            $this->assertSame($status, $e->httpStatus(), "{$class}::httpStatus()");
        }

        $lock = OptimisticLockException::for('x', 1, 2);
        $this->assertSame(409, $lock->httpStatus());
    }

    public function test_all_exceptions_produce_rfc9457_error_arrays(): void
    {
        $exceptions = [
            InvalidStateDomainException::because('state'),
            InvalidArgumentDomainException::because('arg', 'CODE'),
            NotFoundDomainException::forAggregate('Order', 'id-1'),
            ConflictDomainException::because('conflict'),
            OptimisticLockException::for('order-1', 5, 3),
        ];

        foreach ($exceptions as $e) {
            $arr = $e->toErrorArray();
            $this->assertArrayHasKey('title', $arr, get_class($e));
            $this->assertArrayHasKey('detail', $arr, get_class($e));
            $this->assertArrayHasKey('code', $arr, get_class($e));
            $this->assertArrayHasKey('status', $arr, get_class($e));
            $this->assertIsInt($arr['status'], get_class($e));
        }
    }

    public function test_exceptions_are_json_serializable(): void
    {
        $e = InvalidStateDomainException::because('Test', 'CUSTOM');

        $json = $e->toJson();
        $decoded = json_decode($json, true);

        $this->assertSame('InvalidStateDomainException', $decoded['title']);
        $this->assertSame('Test', $decoded['detail']);
        $this->assertSame('CUSTOM', $decoded['code']);
        $this->assertSame(422, $decoded['status']);
    }

    public function test_exception_from_json_round_trip(): void
    {
        $original = NotFoundDomainException::forAggregate('Order', 'uuid-1');
        $json = $original->toJson();
        $restored = NotFoundDomainException::fromJson($json, NotFoundDomainException::class);

        $this->assertSame($original->getMessage(), $restored->getMessage());
        $this->assertSame($original->errorCode(), $restored->errorCode());
    }

    public function test_exception_to_array_includes_debug_info(): void
    {
        $e = InvalidStateDomainException::because('test');
        $arr = $e->toArray();

        $this->assertArrayHasKey('error_code', $arr);
        $this->assertArrayHasKey('message', $arr);
        $this->assertArrayHasKey('file', $arr);
        $this->assertArrayHasKey('line', $arr);
    }

    // ─────────────────────────────────────────────────────────
    // 5. DomainEventCollection
    // ─────────────────────────────────────────────────────────

    public function test_event_collection_functional_operations(): void
    {
        $e1 = DomainEvent::occur('order.placed', ['id' => '1']);
        $e2 = DomainEvent::occur('order.item_added', ['product_id' => 'p1']);
        $e3 = DomainEvent::occur('order.paid', ['amount' => 100]);
        $e4 = DomainEvent::occur('order.placed', ['id' => '2']);

        $col = new DomainEventCollection([$e1, $e2, $e3, $e4]);

        $this->assertSame(4, $col->count());
        $this->assertFalse($col->isEmpty());
        $this->assertSame($e1, $col->first());
        $this->assertSame($e4, $col->last());
        $this->assertSame($e2, $col->get(1));
        $this->assertNull($col->get(99));

        // Functional ops
        $this->assertTrue($col->some(fn (DomainEvent $e): bool => $e->eventType === 'order.paid'));
        $this->assertTrue($col->none(fn (DomainEvent $e): bool => $e->eventType === 'order.shipped'));
        $this->assertSame(2, $col->countBy(fn (DomainEvent $e): bool => $e->eventType === 'order.placed'));
        $this->assertTrue($col->hasType('order.paid'));
        $this->assertSame(['order.placed', 'order.item_added', 'order.paid'], $col->types());

        $found = $col->find(fn (DomainEvent $e): bool => $e->eventType === 'order.paid');
        $this->assertSame(100, $found->payload['amount']);
    }

    public function test_event_collection_filter_returns_new_collection(): void
    {
        $e1 = DomainEvent::occur('a', []);
        $e2 = DomainEvent::occur('b', []);
        $e3 = DomainEvent::occur('a', []);

        $col = new DomainEventCollection([$e1, $e2, $e3]);
        $filtered = $col->filter(fn (DomainEvent $e): bool => $e->eventType === 'a');

        $this->assertSame(2, $filtered->count());
        $this->assertInstanceOf(DomainEventCollection::class, $filtered);
    }

    public function test_event_collection_merge(): void
    {
        $c1 = new DomainEventCollection([DomainEvent::occur('a', [])]);
        $c2 = new DomainEventCollection([DomainEvent::occur('b', [])]);

        $merged = $c1->merge($c2);
        $this->assertSame(2, $merged->count());
    }

    public function test_event_collection_each_is_chainable(): void
    {
        $col = new DomainEventCollection([DomainEvent::occur('a', [])]);

        $result = $col->each(function (DomainEvent $e): void {});
        $this->assertSame($col, $result);
    }

    public function test_event_collection_reduce(): void
    {
        $col = new DomainEventCollection([
            DomainEvent::occur('a', ['v' => 1]),
            DomainEvent::occur('a', ['v' => 2]),
        ]);

        $sum = $col->reduce(fn (int $c, DomainEvent $e): int => $c + $e->payload['v'], 0);
        $this->assertSame(3, $sum);
    }

    public function test_event_collection_json_serialization(): void
    {
        $col = new DomainEventCollection([DomainEvent::occur('test', ['key' => 'val'])]);
        $json = $col->toJson();
        $decoded = json_decode($json, true);

        $this->assertIsArray($decoded);
        $this->assertCount(1, $decoded);
    }

    // ─────────────────────────────────────────────────────────
    // 6. Snapshots
    // ─────────────────────────────────────────────────────────

    public function test_snapshot_create_and_equality(): void
    {
        $state = ['status' => 'pending', 'total' => 1999];
        $s1 = Snapshot::create('Order', 'uuid-1', 5, $state);
        $s2 = Snapshot::create('Order', 'uuid-1', 5, $state);
        $s3 = Snapshot::create('Order', 'uuid-1', 6, $state);

        $this->assertTrue($s1->equals($s2));
        $this->assertFalse($s1->equals($s3));
    }

    public function test_snapshot_round_trip(): void
    {
        $original = Snapshot::create('Order', 'uuid-1', 10, ['status' => 'shipped']);
        $restored = Snapshot::fromArray($original->toArray());

        $this->assertTrue($original->equals($restored));
        $this->assertSame('Order', $restored->aggregateType);
        $this->assertSame('uuid-1', $restored->aggregateId);
        $this->assertSame(10, $restored->version);
    }

    public function test_snapshot_json_round_trip(): void
    {
        $original = Snapshot::create('Order', 'uuid-1', 3, ['x' => 1]);
        $restored = Snapshot::fromJson($original->toJson());

        $this->assertTrue($original->equals($restored));
    }

    // ─────────────────────────────────────────────────────────
    // 7. UnitOfWork
    // ─────────────────────────────────────────────────────────

    public function test_uow_run_auto_commits(): void
    {
        $uow = new InMemoryUnitOfWork;
        $dispatched = [];
        $uow->setEventDispatcher(function (DomainEvent $e) use (&$dispatched): void {
            $dispatched[] = $e->eventType;
        });

        $result = $uow->run(function () use ($uow): string {
            $uow->queueEvent(DomainEvent::occur('test.event', []));

            return 'done';
        });

        $this->assertSame('done', $result);
        $this->assertSame(['test.event'], $dispatched);
        $this->assertFalse($uow->isActive());
    }

    public function test_uow_run_auto_rollbacks_on_exception(): void
    {
        $uow = new InMemoryUnitOfWork;
        $dispatched = [];
        $uow->setEventDispatcher(function (DomainEvent $e) use (&$dispatched): void {
            $dispatched[] = $e->eventType;
        });

        try {
            $uow->run(function () use ($uow): void {
                $uow->queueEvent(DomainEvent::occur('test.event', []));
                throw new \RuntimeException('fail');
            });
        } catch (\RuntimeException $e) {
            // Expected
        }

        // Events should NOT have been dispatched
        $this->assertSame([], $dispatched);
        $this->assertFalse($uow->isActive());
    }

    public function test_uow_commit_tracks_committed_aggregates(): void
    {
        $uow = new InMemoryUnitOfWork;
        $id = AggregateRootId::generate();
        $aggregate = ContractTestAggregate::create($id);

        $uow->begin();
        $uow->track($aggregate);
        $uow->commit();

        $committed = $uow->getCommitted();
        $this->assertCount(1, $committed);
    }

    public function test_uow_json_serializable(): void
    {
        $uow = new InMemoryUnitOfWork;
        $uow->begin();
        $uow->commit();

        $this->assertInstanceOf(\JsonSerializable::class, $uow);
        $array = $uow->toArray();
        $this->assertIsArray($array);
    }

    // ─────────────────────────────────────────────────────────
    // 8. Guards — Domain Invariant Assertions
    // ─────────────────────────────────────────────────────────

    public function test_guard_assert_not_empty_string_passes(): void
    {
        $entity = new class extends BaseEntity {
            use \ZeroBoiler\Domain\Concerns\Guards;
            public function __construct(int|string|\Stringable $id) { parent::__construct($id); }

            public function validate(string $value): void
            {
                $this->assertNotEmptyString($value, 'name');
            }
        };

        // Should not throw
        $entity->validate('valid');
        $this->assertTrue(true);
    }

    public function test_guard_assert_not_empty_string_throws(): void
    {
        $entity = new class extends BaseEntity {
            use \ZeroBoiler\Domain\Concerns\Guards;
            public function __construct(int|string|\Stringable $id) { parent::__construct($id); }

            public function validate(string $value): void
            {
                $this->assertNotEmptyString($value, 'name');
            }
        };

        $this->expectException(InvalidArgumentDomainException::class);
        $this->expectExceptionMessage('"name" must not be empty');
        $entity->validate('');
    }

    public function test_guard_assert_positive_integer_throws_on_zero(): void
    {
        $entity = new class extends BaseEntity {
            use \ZeroBoiler\Domain\Concerns\Guards;
            public function __construct(int|string|\Stringable $id) { parent::__construct($id); }

            public function validate(int $value): void
            {
                $this->assertPositiveInteger($value, 'qty');
            }
        };

        $this->expectException(InvalidArgumentDomainException::class);
        $entity->validate(0);
    }

    public function test_guard_assert_state_is_throws_on_mismatch(): void
    {
        $entity = new class extends BaseEntity {
            use \ZeroBoiler\Domain\Concerns\Guards;
            public function __construct(int|string|\Stringable $id) { parent::__construct($id); }

            public function check(): void
            {
                $this->assertStateIs('pending', 'shipped', 'pay');
            }
        };

        $this->expectException(InvalidStateDomainException::class);
        $this->expectExceptionMessage('Cannot pay');
        $entity->check();
    }

    public function test_guard_assert_range_throws_outside(): void
    {
        $entity = new class extends BaseEntity {
            use \ZeroBoiler\Domain\Concerns\Guards;
            public function __construct(int|string|\Stringable $id) { parent::__construct($id); }

            public function check(): void
            {
                $this->assertRange(150, 1, 100, 'amount');
            }
        };

        $this->expectException(InvalidArgumentDomainException::class);
        $entity->check();
    }

    public function test_guard_assert_in_throws_for_invalid_option(): void
    {
        $entity = new class extends BaseEntity {
            use \ZeroBoiler\Domain\Concerns\Guards;
            public function __construct(int|string|\Stringable $id) { parent::__construct($id); }

            public function check(): void
            {
                $this->assertIn(['USD', 'EUR'], 'GBP', 'currency');
            }
        };

        $this->expectException(InvalidArgumentDomainException::class);
        $this->expectExceptionMessage('INVALID_OPTION');
        $entity->check();
    }

    public function test_guard_assert_not_null_throws_on_null(): void
    {
        $entity = new class extends BaseEntity {
            use \ZeroBoiler\Domain\Concerns\Guards;
            public function __construct(int|string|\Stringable $id) { parent::__construct($id); }

            public function check(mixed $value): void
            {
                $this->assertNotNull($value, 'user');
            }
        };

        $this->expectException(InvalidArgumentDomainException::class);
        $this->expectExceptionMessage('NULL_VALUE');
        $entity->check(null);
    }

    public function test_guard_assert_found_throws_not_found_exception(): void
    {
        $entity = new class extends BaseEntity {
            use \ZeroBoiler\Domain\Concerns\Guards;
            public function __construct(int|string|\Stringable $id) { parent::__construct($id); }

            public function check(mixed $value): void
            {
                $this->assertFound($value, 'Order');
            }
        };

        $this->expectException(NotFoundDomainException::class);
        $entity->check(null);
    }

    // ─────────────────────────────────────────────────────────
    // 9. AggregateRootId Full Contract
    // ─────────────────────────────────────────────────────────

    public function test_aggregate_root_id_all_serialization_paths(): void
    {
        $id = AggregateRootId::generate();

        // toArray → fromArray
        $fromArr = AggregateRootId::fromArray($id->toArray());
        $this->assertTrue($id->equals($fromArr));

        // toJson → fromJson
        $fromJson = AggregateRootId::fromJson($id->toJson());
        $this->assertTrue($id->equals($fromJson));

        // jsonSerialize
        $jsonEncoded = json_encode($id);
        $this->assertSame('"' . $id->toString() . '"', $jsonEncoded);

        // Stringable
        $this->assertSame($id->toString(), (string) $id);

        // fromString
        $fromStr = AggregateRootId::fromString($id->toString());
        $this->assertTrue($id->equals($fromStr));

        // isValid
        $this->assertTrue(AggregateRootId::isValid($id->toString()));
        $this->assertFalse(AggregateRootId::isValid('not-a-uuid'));
    }
}

// ─────────────────────────────────────────────────────────────
// Test Fixtures
// ─────────────────────────────────────────────────────────────

/**
 * Test aggregate root for contract verification.
 */
final class ContractTestAggregate extends AggregateRoot
{
    use \ZeroBoiler\Domain\Concerns\EventSourced;

    public static function create(AggregateRootId $id): self
    {
        $aggregate = new self($id);
        $aggregate->apply(DomainEvent::occur('contract.created', ['name' => 'Test']));

        return $aggregate;
    }

    public function doSomething(): void
    {
        $this->apply(DomainEvent::occur('contract.something_done', []));
    }

    protected function applyContractCreated(DomainEvent $event): void {}
    protected function applyContractSomethingDone(DomainEvent $event): void {}
}

/**
 * Test entity for contract verification.
 */
final class ContractTestEntity extends Entity
{
    public function __construct(
        int|string|\Stringable $id,
        public ?string $name = null,
    ) {
        parent::__construct($id);
    }

    public function toArray(): array
    {
        return [
            ...parent::toArray(),
            'name' => $this->name,
        ];
    }
}

/**
 * Test UUID identifier.
 */
class ContractTestUuid extends UuidIdentifier {}

/**
 * Test ULID identifier.
 */
class ContractTestUlid extends UlidIdentifier {}
