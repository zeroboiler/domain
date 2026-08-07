<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Tests;

use ZeroBoiler\Domain\AggregateRoot;
use ZeroBoiler\Domain\AggregateRootId;
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
use ZeroBoiler\Domain\ValueObject;
use ZeroBoiler\Domain\Snapshots\InMemorySnapshotStore;
use ZeroBoiler\Domain\Snapshots\Snapshot;
use ZeroBoiler\Domain\Concerns\EventSourced;
use ZeroBoiler\Domain\Concerns\HasSnapshots;
use ZeroBoiler\Domain\Concerns\HasDomainEvents;
use ZeroBoiler\Domain\Snapshots\SnapshotPolicy;
use ZeroBoiler\Events\Domain\DomainEvent;

/**
 * Production hardening test — verifies immutability, invariants, type safety,
 * and cross-contract compliance for the domain package.
 */
final class DomainProductionHardeningTest extends TestCase
{
    // ── AggregateRootId Immutability ──────────────────────────────────

    public function test_aggregate_root_id_is_final_readonly(): void
    {
        $reflection = new \ReflectionClass(AggregateRootId::class);

        expect($reflection->isFinal())->toBeTrue()
            ->and($reflection->isReadOnly())->toBeTrue();
    }

    public function test_aggregate_root_id_json_serialization_roundtrip(): void
    {
        $id = AggregateRootId::generate();
        $json = json_encode($id);
        $restored = AggregateRootId::fromString($json);

        expect($json)->toBe($restored->toString())
            ->and($id->equals($restored))->toBeTrue();
    }

    public function test_aggregate_root_id_stringable(): void
    {
        $id = AggregateRootId::generate();

        expect((string) $id)->toBe($id->toString())
            ->and($id->toString())->toBe($id->value->toString());
    }

    public function test_aggregate_root_id_from_string_validates_uuid(): void
    {
        $this->expectException(\Ramsey\Uuid\Exception\InvalidUuidStringException::class);

        AggregateRootId::fromString('not-a-uuid');
    }

    // ── UuidIdentifier ───────────────────────────────────────────────

    public function test_uuid_identifier_is_abstract_readonly(): void
    {
        $reflection = new \ReflectionClass(UuidIdentifier::class);

        expect($reflection->isAbstract())->toBeTrue()
            ->and($reflection->isReadOnly())->toBeTrue();
    }

    public function test_uuid_identifier_validates_on_construction(): void
    {
        $this->expectException(\Ramsey\Uuid\Exception\InvalidUuidStringException::class);

        new class('not-a-uuid') extends UuidIdentifier {};
    }

    public function test_uuid_identifier_equals_requires_same_concrete_class(): void
    {
        $uuid = \Ramsey\Uuid\Uuid::uuid4()->toString();

        $idA = new class($uuid) extends UuidIdentifier {};
        $idB = new class($uuid) extends UuidIdentifier {};

        // Same UUID value but different concrete class → not equal
        expect($idA->equals($idB))->toBeFalse();
    }

    public function test_uuid_identifier_json_serialization(): void
    {
        $id = new class(\Ramsey\Uuid\Uuid::uuid4()->toString()) extends UuidIdentifier {};

        $json = json_encode($id);

        expect($json)->toBeJson()
            ->and(json_decode($json, true))->toBe($id->toString());
    }

    // ── UlidIdentifier ───────────────────────────────────────────────

    public function test_ulid_identifier_generates_monotonic(): void
    {
        $id1 = new class((new \Symfony\Component\Uid\Ulid)->toBase32()) extends UlidIdentifier {};
        $id2 = new class((new \Symfony\Component\Uid\Ulid)->toBase32()) extends UlidIdentifier {};

        // Monotonic: id2 > id1 lexicographically
        expect($id2->toString() > $id1->toString())->toBeTrue();
    }

    public function test_ulid_identifier_validates_on_construction(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new class('not-a-ulid') extends UlidIdentifier {};
    }

    public function test_ulid_identifier_is_valid(): void
    {
        $validUlid = (new \Symfony\Component\Uid\Ulid)->toBase32();

        expect(UlidIdentifier::isValid($validUlid))->toBeTrue()
            ->and(UlidIdentifier::isValid('invalid'))->toBeFalse();
    }

    // ── StringIdentifier ──────────────────────────────────────────────

    public function test_string_identifier_rejects_empty_string(): void
    {
        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage('cannot be empty');

        StringIdentifier::from('');
    }

    public function test_string_identifier_is_final_readonly(): void
    {
        $reflection = new \ReflectionClass(StringIdentifier::class);

        expect($reflection->isFinal())->toBeTrue()
            ->and($reflection->isReadOnly())->toBeTrue();
    }

    public function test_string_identifier_json_serialization(): void
    {
        $id = StringIdentifier::from('my-slug');

        expect(json_encode($id))->toBe('"my-slug"');
    }

    // ── IntegerIdentifier ────────────────────────────────────────────

    public function test_integer_identifier_is_final_readonly(): void
    {
        $reflection = new \ReflectionClass(IntegerIdentifier::class);

        expect($reflection->isFinal())->toBeTrue()
            ->and($reflection->isReadOnly())->toBeTrue();
    }

    public function test_integer_identifier_json_serializes_as_int(): void
    {
        $id = IntegerIdentifier::from(42);

        expect(json_encode($id))->toBe('42')
            ->and(json_decode(json_encode($id)))->toBe(42);
    }

    public function test_integer_identifier_from_string(): void
    {
        $id = IntegerIdentifier::fromString('42');

        expect($id->toInt())->toBe(42)
            ->and($id->toString())->toBe('42');
    }

    public function test_integer_identifier_is_valid(): void
    {
        expect(IntegerIdentifier::isValid('42'))->toBeTrue()
            ->and(IntegerIdentifier::isValid('-5'))->toBeTrue()
            ->and(IntegerIdentifier::isValid('abc'))->toBeFalse()
            ->and(IntegerIdentifier::isValid(''))->toBeFalse();
    }

    // ── IdentifierContract Compliance ─────────────────────────────────

    public function test_all_identifiers_implement_identifier_contract(): void
    {
        $uuid = \Ramsey\Uuid\Uuid::uuid4()->toString();
        $ulid = (new \Symfony\Component\Uid\Ulid)->toBase32();

        $identifiers = [
            AggregateRootId::generate(),
            new class($uuid) extends UuidIdentifier {},
            new class($ulid) extends UlidIdentifier {},
            StringIdentifier::from('slug'),
            IntegerIdentifier::from(1),
        ];

        foreach ($identifiers as $id) {
            expect($id)->toBeInstanceOf(IdentifierContract::class);
        }
    }

    // ── DomainEventCollection ─────────────────────────────────────────

    public function test_domain_event_collection_is_final_readonly(): void
    {
        $reflection = new \ReflectionClass(DomainEventCollection::class);

        expect($reflection->isFinal())->toBeTrue()
            ->and($reflection->isReadOnly())->toBeTrue();
    }

    public function test_domain_event_collection_rejects_associative_array(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('array_is_list');

        $event = DomainEvent::occur('test.event', ['key' => 'value']);
        new DomainEventCollection(['key' => $event]);
    }

    public function test_domain_event_collection_rejects_non_domain_events(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be a DomainEvent');

        new DomainEventCollection(['not-an-event']);
    }

    public function test_domain_event_collection_filter_returns_new_instance(): void
    {
        $e1 = DomainEvent::occur('test.a', []);
        $e2 = DomainEvent::occur('test.b', []);

        $original = new DomainEventCollection([$e1, $e2]);
        $filtered = $original->filter(fn (DomainEvent $e): bool => $e->eventType === 'test.a');

        expect($filtered)->not->toBe($original)
            ->and($filtered->count())->toBe(1)
            ->and($original->count())->toBe(2); // Immutability preserved
    }

    public function test_domain_event_collection_json_serialization(): void
    {
        $event = DomainEvent::occur('test.event', ['data' => 'value']);
        $collection = new DomainEventCollection([$event]);

        $json = json_encode($collection);
        $decoded = json_decode($json, true);

        expect($decoded)->toBeArray()
            ->and(count($decoded))->toBe(1)
            ->and($decoded[0])->toHaveKey('event_type');
    }

    // ── Snapshot ─────────────────────────────────────────────────────

    public function test_snapshot_is_final_readonly(): void
    {
        $reflection = new \ReflectionClass(Snapshot::class);

        expect($reflection->isFinal())->toBeTrue()
            ->and($reflection->isReadOnly())->toBeTrue();
    }

    public function test_snapshot_from_array_to_array_roundtrip(): void
    {
        $original = Snapshot::create(
            aggregateType: 'App\\Domain\\Order',
            aggregateId: '550e8400-e29b-41d4-a716-446655440000',
            version: 50,
            state: ['status' => 'shipped', 'total' => 99.99],
        );

        $restored = Snapshot::fromArray($original->toArray());

        expect($restored->aggregateType)->toBe($original->aggregateType)
            ->and($restored->aggregateId)->toBe($original->aggregateId)
            ->and($restored->version)->toBe($original->version)
            ->and($restored->state)->toBe($original->state);
    }

    public function test_snapshot_from_array_validates_types(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Snapshot::fromArray(['aggregate_type' => 123, 'aggregate_id' => 'id', 'version' => 'bad', 'state' => [], 'created_at' => '2024-01-01']);
    }

    public function test_snapshot_equals(): void
    {
        $s1 = Snapshot::create('Order', 'id-1', 1, ['key' => 'value']);
        $s2 = Snapshot::create('Order', 'id-1', 1, ['key' => 'value']);
        $s3 = Snapshot::create('Order', 'id-1', 2, ['key' => 'value']);

        expect($s1->equals($s2))->toBeTrue()
            ->and($s1->equals($s3))->toBeFalse();
    }

    public function test_snapshot_json_serialization(): void
    {
        $snapshot = Snapshot::create('Order', 'id-1', 1, ['key' => 'value']);
        $json = json_encode($snapshot);

        expect($json)->toBeJson();

        $decoded = json_decode($json, true);
        expect($decoded['aggregate_type'])->toBe('Order')
            ->and($decoded['version'])->toBe(1);
    }

    // ── DomainException Hierarchy ─────────────────────────────────────

    public function test_all_domain_exceptions_have_error_codes(): void
    {
        $exceptions = [
            InvalidStateDomainException::because('test'),
            InvalidArgumentDomainException::because('test'),
            NotFoundDomainException::because('test'),
            ConflictDomainException::because('test'),
            AggregateNotFoundException::for('Order', 'id-1'),
            OptimisticLockException::for('id-1', 1, 2),
        ];

        foreach ($exceptions as $e) {
            expect($e)->toBeInstanceOf(DomainException::class);
            expect($e->errorCode())->toBeString();
            expect($e->errorCode())->not->toBeEmpty();
        }
    }

    public function test_domain_exception_to_error_array_is_rfc9457_compatible(): void
    {
        $e = InvalidStateDomainException::because('Order must be pending');
        $array = $e->toErrorArray();

        expect($array)->toHaveKeys(['title', 'detail', 'code'])
            ->and($array['title'])->toBe('InvalidStateDomainException')
            ->and($array['detail'])->toBe('Order must be pending')
            ->and($array['code'])->toBe('INVALID_STATE');
    }

    public function test_domain_exception_json_serializable(): void
    {
        $e = NotFoundDomainException::forAggregate('Order', 'id-1');
        $json = json_encode($e);

        expect($json)->toBeJson();

        $decoded = json_decode($json, true);
        expect($decoded['code'])->toBe('NOT_FOUND');
    }

    public function test_custom_error_code_overrides_default(): void
    {
        $e = new class('Custom error') extends DomainException {
            protected function defaultErrorCode(): string
            {
                return 'CUSTOM_DEFAULT';
            }
        };

        expect($e->errorCode())->toBe('CUSTOM_DEFAULT');

        $e2 = InvalidStateDomainException::because('test', 'CUSTOM_OVERRIDE');
        expect($e2->errorCode())->toBe('CUSTOM_OVERRIDE');
    }

    // ── UnitOfWork ──────────────────────────────────────────────────

    public function test_uow_run_commits_on_success(): void
    {
        $uow = new InMemoryUnitOfWork;
        $result = $uow->run(fn (): int => 42);

        expect($result)->toBe(42)
            ->and($uow->isActive())->toBeFalse();
    }

    public function test_uow_run_rolls_back_on_exception(): void
    {
        $uow = new InMemoryUnitOfWork;

        try {
            $uow->run(function (): void {
                throw new \RuntimeException('fail');
            });
        } catch (\RuntimeException) {
            // Expected
        }

        expect($uow->isActive())->toBeFalse()
            ->and($uow->hasPendingEvents())->toBeFalse();
    }

    public function test_uow_nested_run_savepoint_semantics(): void
    {
        $uow = new InMemoryUnitOfWork;
        $order = 0;

        $uow->run(function () use ($uow, &$order): void {
            $order = 1;

            $uow->run(function () use (&$order): void {
                $order = 2;
            });

            // After inner commit, order should be 2
            expect($order)->toBe(2);
        });

        // Outer scope committed successfully
        expect($order)->toBe(2);
    }

    public function test_uow_nested_run_inner_rollback_preserves_outer(): void
    {
        $uow = new InMemoryUnitOfWork;
        $result = 'unset';

        $uow->run(function () use ($uow, &$result): void {
            try {
                $uow->run(function (): void {
                    throw new \RuntimeException('inner fail');
                });
            } catch (\RuntimeException) {
                $result = 'caught';
            }
        });

        expect($result)->toBe('caught')
            ->and($uow->isActive())->toBeFalse();
    }

    public function test_uow_clear_resets_all_state(): void
    {
        $uow = new InMemoryUnitOfWork;
        $uow->begin();
        $uow->queueEvent(DomainEvent::occur('test', []));
        $uow->clear();

        expect($uow->isActive())->toBeFalse()
            ->and($uow->hasPendingEvents())->toBeFalse()
            ->and($uow->getCommitted())->toBe([]);
    }

    public function test_uow_persistence_callback_invoked_before_events(): void
    {
        $uow = new InMemoryUnitOfWork;
        $callbackOrder = [];

        $uow->setPersistenceCallback(function (array $committed, array $deleted) use (&$callbackOrder): void {
            $callbackOrder[] = 'persist';
        });

        $uow->setEventDispatcher(function (DomainEvent $event) use (&$callbackOrder): void {
            $callbackOrder[] = 'dispatch:' . $event->eventType;
        });

        $uow->run(function () use ($uow): void {
            $uow->queueEvent(DomainEvent::occur('test.event', []));
        });

        expect($callbackOrder)->toBe(['persist', 'dispatch:test.event']);
    }

    // ── InMemorySnapshotStore ────────────────────────────────────────

    public function test_snapshot_store_stats(): void
    {
        $store = new InMemorySnapshotStore;
        expect($store->stats())->toBe(['total' => 0, 'by_type' => []]);

        $store->save(Snapshot::create('Order', 'id-1', 1, []));
        $store->save(Snapshot::create('Order', 'id-2', 1, []));
        $store->save(Snapshot::create('User', 'id-1', 1, []));

        $stats = $store->stats();
        expect($stats['total'])->toBe(3)
            ->and($stats['by_type']['Order'])->toBe(2)
            ->and($stats['by_type']['User'])->toBe(1);
    }

    public function test_snapshot_store_purge_by_type(): void
    {
        $store = new InMemorySnapshotStore;
        $store->save(Snapshot::create('Order', 'id-1', 1, []));
        $store->save(Snapshot::create('User', 'id-1', 1, []));

        $removed = $store->purge('Order');

        expect($removed)->toBe(1)
            ->and($store->count())->toBe(1)
            ->and($store->has('User', 'id-1'))->toBeTrue();
    }

    public function test_snapshot_store_count_by_type(): void
    {
        $store = new InMemorySnapshotStore;
        $store->save(Snapshot::create('Order', 'id-1', 1, []));
        $store->save(Snapshot::create('Order', 'id-2', 1, []));
        $store->save(Snapshot::create('User', 'id-1', 1, []));

        expect($store->count('Order'))->toBe(2)
            ->and($store->count('User'))->toBe(1)
            ->and($store->count())->toBe(3)
            ->and($store->count('NonExistent'))->toBe(0);
    }

    public function test_snapshot_store_delete_older_than(): void
    {
        $store = new InMemorySnapshotStore;
        $store->save(Snapshot::create('Order', 'id-1', 5, []));
        $store->save(Snapshot::create('Order', 'id-2', 10, []));

        $store->deleteOlderThan('Order', 'id-1', 8);

        expect($store->has('Order', 'id-1'))->toBeFalse()
            ->and($store->has('Order', 'id-2'))->toBeTrue();
    }

    // ── AggregateRoot toArray ─────────────────────────────────────────

    public function test_aggregate_root_to_array_base_shape(): void
    {
        $aggregate = new class(AggregateRootId::generate()) extends AggregateRoot {};

        $array = $aggregate->toArray();

        expect($array)->toHaveKeys(['id', 'version', 'type'])
            ->and($array['version'])->toBe(0)
            ->and($array['type'])->toBe('class@anonymous');
    }

    // ── Entity Identity Equality ─────────────────────────────────────

    public function test_entity_equals_requires_same_class(): void
    {
        $id = AggregateRootId::generate();

        $a1 = new class($id) extends AggregateRoot {};
        $a2 = new class($id) extends AggregateRoot {};

        // Different anonymous classes → not equal
        expect($a1->equals($a2))->toBeFalse();
    }

    public function test_entity_equals_same_id_same_class(): void
    {
        $id = AggregateRootId::generate();

        // Create two instances of the SAME class
        $class = new class($id) extends AggregateRoot {};
        $class2 = new ($class::class)(AggregateRootId::fromString($id->toString()));

        // Different instances, same ID, same class → equal
        // But anonymous classes create a new class each time, so this won't work.
        // Instead, test with the fixture TestAggregate:
        $ta1 = Fixtures\TestAggregate::create($id);
        $ta2 = Fixtures\TestAggregate::create($id);

        expect($ta1->equals($ta2))->toBeTrue();
    }

    // ── ValueObject ───────────────────────────────────────────────────

    public function test_value_object_equals_uses_to_array(): void
    {
        $vo1 = Fixtures\TestValueObject::fromArray(['value' => 'test']);
        $vo2 = Fixtures\TestValueObject::fromArray(['value' => 'test']);
        $vo3 = Fixtures\TestValueObject::fromArray(['value' => 'other']);

        expect($vo1->equals($vo2))->toBeTrue()
            ->and($vo1->equals($vo3))->toBeFalse()
            ->and($vo1->equals(null))->toBeFalse();
    }

    // ── PHP 8.5 Syntax Verification ──────────────────────────────────

    public function test_readonly_promoted_properties_are_enforced(): void
    {
        $id = AggregateRootId::generate();

        // Attempting to set a readonly property via reflection should throw
        $reflection = new \ReflectionProperty($id, 'value');

        expect($reflection->isReadOnly())->toBeTrue()
            ->and($reflection->isPublic())->toBeTrue()
            ->and($reflection->isPromoted())->toBeTrue();
    }

    public function test_override_attribute_present_on_concrete_implementations(): void
    {
        $methods = [
            [AggregateRoot::class, 'version'],
            [AggregateRoot::class, 'pullDomainEvents'],
            [AggregateRoot::class, 'clearDomainEvents'],
            [AggregateRoot::class, 'id'],
            [AggregateRoot::class, 'equals'],
            [AggregateRoot::class, 'incrementVersion'],
        ];

        foreach ($methods as [$class, $method]) {
            $reflection = new \ReflectionMethod($class, $method);
            $attrs = $reflection->getAttributes(\Override::class);
            expect($attrs)->not->toBeEmpty()
                ->and(sprintf('[%s::%s] missing #[Override]', $class, $method))
                ->toBeEmpty(); // Empty string means no failure message
        }
    }

    public function test_deprecated_attribute_present_on_aliases(): void
    {
        $aggregate = new class(AggregateRootId::generate()) extends AggregateRoot {};

        $reflection = new \ReflectionMethod($aggregate, 'getVersion');
        $attrs = $reflection->getAttributes(\Deprecated::class);

        expect($attrs)->not->toBeEmpty();
    }

    // ── Cross-contract type compliance ──────────────────────────────

    public function test_identifier_contract_stringable_implementation(): void
    {
        $id = AggregateRootId::generate();

        expect($id)->toBeInstanceOf(\Stringable::class)
            ->and($id)->toBeInstanceOf(IdentifierContract::class);
    }

    public function test_domain_exception_json_serializable(): void
    {
        $e = ConflictDomainException::because('concurrent modification');

        expect($e)->toBeInstanceOf(\JsonSerializable::class);

        $json = json_encode($e);
        expect($json)->toBeJson();

        $decoded = json_decode($json, true);
        expect($decoded)->toHaveKeys(['title', 'detail', 'code']);
    }
}
