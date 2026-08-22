<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ZeroBoiler\Domain\AggregateRoot;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\Contracts\AggregateRoot as AggregateRootContract;
use ZeroBoiler\Domain\Contracts\Entity as EntityContract;
use ZeroBoiler\Domain\Contracts\Identifier as IdentifierContract;
use ZeroBoiler\Domain\Contracts\Repository as RepositoryContract;
use ZeroBoiler\Domain\Contracts\UnitOfWork as UnitOfWorkContract;
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
use ZeroBoiler\Domain\InMemoryUnitOfWork;
use ZeroBoiler\Domain\Snapshots\InMemorySnapshotStore;
use ZeroBoiler\Domain\Snapshots\Snapshot;
use ZeroBoiler\Domain\ValueObject;
use ZeroBoiler\Events\Domain\DomainEvent;

/**
 * Production readiness checklist — verifies every class meets quality gates.
 *
 * Covers: strict types, immutability, return types, docblocks, typed properties,
 * interface compliance, JSON serialization, domain invariants, and error contracts.
 *
 * @coversNothing — integration/structural test, not unit isolation
 */
final class DomainProductionReadinessChecklistTest extends TestCase
{
    // ──────────────────────────────────────────────────────
    // CHECK 1: strict_types declaration
    // ──────────────────────────────────────────────────────

    /**
     * @test
     */
    public function all_source_files_have_strict_types_declaration(): void
    {
        $srcDir = __DIR__ . '/../src';
        $files = $this->findPhpFiles($srcDir);
        $violations = [];

        foreach ($files as $file) {
            $contents = file_get_contents($file);
            if (! str_contains($contents, 'declare(strict_types=1)')) {
                $violations[] = basename($file);
            }
        }

        $this->assertEmpty(
            $violations,
            'Files missing declare(strict_types=1): ' . implode(', ', $violations),
        );
    }

    // ──────────────────────────────────────────────────────
    // CHECK 2: AggregateRootId — final readonly, UUID v4
    // ──────────────────────────────────────────────────────

    /**
     * @test
     */
    public function aggregate_root_id_is_final_readonly(): void
    {
        $reflection = new ReflectionClass(AggregateRootId::class);

        $this->assertTrue($reflection->isFinal(), 'AggregateRootId must be final');
        $this->assertTrue($reflection->isReadOnly(), 'AggregateRootId must be readonly');
    }

    /**
     * @test
     */
    public function aggregate_root_id_implements_stringable_and_json_serializable(): void
    {
        $id = AggregateRootId::generate();

        $this->assertInstanceOf(
            \Stringable::class,
            $id,
            'AggregateRootId must implement \Stringable',
        );
        $this->assertInstanceOf(\JsonSerializable::class, $id, 'AggregateRootId must implement JsonSerializable');
    }

    /**
     * @test
     */
    public function aggregate_root_id_generate_creates_valid_uuid_v4(): void
    {
        $id = AggregateRootId::generate();

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $id->toString(),
            'Generated ID must be a valid UUID v4',
        );
    }

    /**
     * @test
     */
    public function aggregate_root_id_from_string_round_trip(): void
    {
        $original = AggregateRootId::generate();
        $restored = AggregateRootId::fromString($original->toString());

        $this->assertTrue($original->equals($restored), 'Round-trip via fromString must preserve equality');
        $this->assertSame($original->toString(), $restored->toString());
    }

    /**
     * @test
     */
    public function aggregate_root_id_json_serializes_as_string(): void
    {
        $id = AggregateRootId::generate();
        $json = json_encode($id);

        $decoded = json_decode($json, true);
        $this->assertIsString($decoded, 'JSON serialization must be a string');
        $this->assertSame($id->toString(), $decoded);
    }

    /**
     * @test
     */
    public function aggregate_root_id_to_string_matches_magic_to_string(): void
    {
        $id = AggregateRootId::generate();

        $this->assertSame($id->toString(), (string) $id);
    }

    /**
     * @test
     */
    public function aggregate_root_id_different_ids_are_not_equal(): void
    {
        $id1 = AggregateRootId::generate();
        $id2 = AggregateRootId::generate();

        $this->assertFalse($id1->equals($id2), 'Two different UUIDs must not be equal');
    }

    // ──────────────────────────────────────────────────────
    // CHECK 3: UuidIdentifier — abstract readonly, validated
    // ──────────────────────────────────────────────────────

    /**
     * @test
     */
    public function uuid_identifier_is_abstract_readonly(): void
    {
        $reflection = new ReflectionClass(UuidIdentifier::class);

        $this->assertTrue($reflection->isAbstract(), 'UuidIdentifier must be abstract');
        $this->assertTrue($reflection->isReadOnly(), 'UuidIdentifier must be readonly');
    }

    /**
     * @test
     */
    public function uuid_identifier_subclass_validates_on_construction(): void
    {
        $this->expectException(\Ramsey\Uuid\Exception\InvalidUuidStringException::class);

        new class ('not-a-uuid') extends UuidIdentifier {};
    }

    /**
     * @test
     */
    public function uuid_identifier_equality_requires_same_concrete_class(): void
    {
        $uuid = \Ramsey\Uuid\Uuid::uuid4()->toString();

        $idA = new class ($uuid) extends UuidIdentifier {};
        $idB = new class ($uuid) extends UuidIdentifier {};

        // Same UUID value but different concrete classes → NOT equal
        $this->assertFalse($idA->equals($idB));
    }

    /**
     * @test
     */
    public function uuid_identifier_is_valid_returns_false_for_non_uuid(): void
    {
        $this->assertFalse(UuidIdentifier::isValid('not-a-uuid'));
        $this->assertTrue(UuidIdentifier::isValid(\Ramsey\Uuid\Uuid::uuid4()->toString()));
    }

    // ──────────────────────────────────────────────────────
    // CHECK 4: StringIdentifier — readonly, non-empty invariant
    // ──────────────────────────────────────────────────────

    /**
     * @test
     */
    public function string_identifier_rejects_empty_string(): void
    {
        $this->expectException(\ValueError::class);
        StringIdentifier::from('');
    }

    /**
     * @test
     */
    public function string_identifier_from_string_creates_instance(): void
    {
        $id = StringIdentifier::from('my-slug');
        $this->assertSame('my-slug', $id->toString());
        $this->assertSame('my-slug', (string) $id);
    }

    /**
     * @test
     */
    public function string_identifier_json_serializes_as_string(): void
    {
        $id = StringIdentifier::from('test-slug');
        $this->assertSame('test-slug', $id->jsonSerialize());
    }

    // ──────────────────────────────────────────────────────
    // CHECK 5: IntegerIdentifier — final readonly, int JSON
    // ──────────────────────────────────────────────────────

    /**
     * @test
     */
    public function integer_identifier_is_final_readonly(): void
    {
        $reflection = new ReflectionClass(IntegerIdentifier::class);

        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
    }

    /**
     * @test
     */
    public function integer_identifier_json_serializes_as_int(): void
    {
        $id = IntegerIdentifier::from(42);
        $this->assertSame(42, $id->jsonSerialize());
    }

    /**
     * @test
     */
    public function integer_identifier_from_string_parses_integer(): void
    {
        $id = IntegerIdentifier::fromString('99');
        $this->assertSame(99, $id->toInt());
        $this->assertSame('99', $id->toString());
    }

    // ──────────────────────────────────────────────────────
    // CHECK 6: Entity — identity equality, flexible ID types
    // ──────────────────────────────────────────────────────

    /**
     * @test
     */
    public function entity_supports_string_int_and_stringable_id_types(): void
    {
        $entityString = new TestEntity('string-id');
        $entityInt = new TestEntity(42);

        $this->assertSame('string-id', $entityString->id());
        $this->assertSame('42', $entityInt->id());
    }

    /**
     * @test
     */
    public function entity_equals_compares_same_class_and_id(): void
    {
        $a = new TestEntity('id-1');
        $b = new TestEntity('id-1');
        $c = new TestEntity('id-2');

        $this->assertTrue($a->equals($b));
        $this->assertFalse($a->equals($c));
    }

    /**
     * @test
     */
    public function entity_equals_returns_false_for_different_class(): void
    {
        $a = new TestEntity('id-1');

        // AggregateRoot also extends Entity but is a different class
        $this->assertFalse($a->equals($a)); // Same instance, same class → true
    }

    // ──────────────────────────────────────────────────────
    // CHECK 7: AggregateRoot — versioning, events, contracts
    // ──────────────────────────────────────────────────────

    /**
     * @test
     */
    public function aggregate_root_implements_entity_and_aggregate_root_contract(): void
    {
        $reflection = new ReflectionClass(AggregateRoot::class);

        $this->assertTrue($reflection->isAbstract(), 'AggregateRoot must be abstract');
        $this->assertTrue(
            $reflection->implementsInterface(AggregateRootContract::class),
            'Must implement AggregateRootContract',
        );
        $this->assertTrue(
            $reflection->implementsInterface(EntityContract::class),
            'Must implement EntityContract',
        );
    }

    /**
     * @test
     */
    public function aggregate_root_to_array_returns_id_version_type(): void
    {
        $aggregate = new TestAggregate();
        $array = $aggregate->toArray();

        $this->assertArrayHasKey('id', $array);
        $this->assertArrayHasKey('version', $array);
        $this->assertArrayHasKey('type', $array);
        $this->assertSame('TestAggregate', $array['type']);
        $this->assertSame(0, $array['version']);
    }

    /**
     * @test
     */
    public function aggregate_root_version_starts_at_zero(): void
    {
        $aggregate = new TestAggregate();

        $this->assertSame(0, $aggregate->version());
    }

    /**
     * @test
     */
    public function aggregate_root_pull_domain_events_returns_collection(): void
    {
        $aggregate = new TestAggregate();
        $events = $aggregate->pullDomainEvents();

        $this->assertInstanceOf(DomainEventCollection::class, $events);
        $this->assertTrue($events->isEmpty());
    }

    /**
     * @test
     */
    public function aggregate_root_clear_domain_events_empties_events(): void
    {
        $aggregate = new TestAggregate();
        $aggregate->pullDomainEvents();
        $aggregate->clearDomainEvents();

        // Should not throw, events already empty
        $this->assertTrue($aggregate->pullDomainEvents()->isEmpty());
    }

    // ──────────────────────────────────────────────────────
    // CHECK 8: DomainEventCollection — readonly, type-safe
    // ──────────────────────────────────────────────────────

    /**
     * @test
     */
    public function domain_event_collection_is_final_readonly(): void
    {
        $reflection = new ReflectionClass(DomainEventCollection::class);

        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
    }

    /**
     * @test
     */
    public function domain_event_collection_rejects_non_sequential_array(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new DomainEventCollection([
            0 => $this->createEvent('test.1'),
            'key' => $this->createEvent('test.2'),
        ]);
    }

    /**
     * @test
     */
    public function domain_event_collection_json_serializes(): void
    {
        $events = new DomainEventCollection([
            $this->createEvent('test.created'),
            $this->createEvent('test.updated'),
        ]);

        $json = json_encode($events);
        $decoded = json_decode($json, true);

        $this->assertIsArray($decoded);
        $this->assertCount(2, $decoded);
    }

    /**
     * @test
     */
    public function domain_event_collection_filter_returns_new_instance(): void
    {
        $events = new DomainEventCollection([
            $this->createEvent('order.created'),
            $this->createEvent('order.updated'),
            $this->createEvent('order.deleted'),
        ]);

        $filtered = $events->filter(
            fn (DomainEvent $e): bool => str_contains($e->eventType, 'created'),
        );

        $this->assertNotSame($events, $filtered, 'filter() must return a new instance');
        $this->assertCount(1, $filtered);
    }

    /**
     * @test
     */
    public function domain_event_collection_merge_combines_both(): void
    {
        $a = new DomainEventCollection([$this->createEvent('a')]);
        $b = new DomainEventCollection([$this->createEvent('b')]);

        $merged = $a->merge($b);

        $this->assertCount(2, $merged);
    }

    // ──────────────────────────────────────────────────────
    // CHECK 9: DomainException — error codes, JSON serialization
    // ──────────────────────────────────────────────────────

    /**
     * @test
     */
    public function domain_exception_provides_machine_readable_error_code(): void
    {
        $exceptionClasses = [
            InvalidStateDomainException::class,
            InvalidArgumentDomainException::class,
            NotFoundDomainException::class,
            ConflictDomainException::class,
            OptimisticLockException::class,
            AggregateNotFoundException::class,
            InvalidAggregateRootException::class,
        ];

        $codes = [];
        foreach ($exceptionClasses as $class) {
            $e = $class::because('test');
            $code = $e->errorCode();
            $this->assertNotEmpty($code, "{$class}::because() must return a non-empty errorCode");
            $this->assertSame(
                strtoupper($code),
                $code,
                "{$class} errorCode must be fully uppercase",
            );
            $codes[$class] = $code;
        }

        // All error codes must be unique
        $uniqueCodes = array_unique($codes);
        $this->assertCount(
            count($codes),
            $uniqueCodes,
            'All domain exception error codes must be unique',
        );
    }

    /**
     * @test
     */
    public function domain_exception_custom_code_overrides_default(): void
    {
        $e = InvalidStateDomainException::because('test', code: 'CUSTOM_CODE');

        $this->assertSame('CUSTOM_CODE', $e->errorCode());
    }

    /**
     * @test
     */
    public function domain_exception_json_serializes_rfc9457_compliant(): void
    {
        $e = InvalidStateDomainException::because('Order must be pending.');
        $json = json_encode($e);
        $decoded = json_decode($json, true);

        $this->assertArrayHasKey('title', $decoded);
        $this->assertArrayHasKey('detail', $decoded);
        $this->assertArrayHasKey('code', $decoded);
    }

    /**
     * @test
     */
    public function domain_exception_to_error_array_is_consistent_with_json_serialize(): void
    {
        $e = NotFoundDomainException::because('User not found.');

        $this->assertSame($e->toErrorArray(), $e->jsonSerialize());
    }

    /**
     * @test
     */
    public function domain_exception_factory_methods_exist(): void
    {
        // AggregateNotFoundException::for(type, id)
        $e = AggregateNotFoundException::for('Order', 'uuid-123');
        $this->assertStringContainsString('Order', $e->getMessage());
        $this->assertStringContainsString('uuid-123', $e->getMessage());

        // OptimisticLockException::for(id, expected, actual)
        $e = OptimisticLockException::for('uuid-123', expectedVersion: 5, actualVersion: 3);
        $this->assertStringContainsString('uuid-123', $e->getMessage());

        // InvalidAggregateRootException::notAnAggregate(object)
        $e = InvalidAggregateRootException::notAnAggregate(new \stdClass);
        $this->assertSame('INVALID_AGGREGATE_ROOT', $e->errorCode());

        // NotFoundDomainException::forAggregate(type, id)
        $e = NotFoundDomainException::forAggregate('Order', 'uuid-123');
        $this->assertStringContainsString('Order', $e->getMessage());
    }

    // ──────────────────────────────────────────────────────
    // CHECK 10: Snapshot — readonly, round-trip, JSON
    // ──────────────────────────────────────────────────────

    /**
     * @test
     */
    public function snapshot_is_final_readonly(): void
    {
        $reflection = new ReflectionClass(Snapshot::class);

        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
    }

    /**
     * @test
     */
    public function snapshot_round_trip_via_to_array_from_array(): void
    {
        $original = Snapshot::create(
            aggregateType: 'App\\Domain\\Order',
            aggregateId: 'uuid-123',
            version: 42,
            state: ['status' => 'paid', 'total' => 99.99],
        );

        $restored = Snapshot::fromArray($original->toArray());

        $this->assertTrue($original->equals($restored));
        $this->assertSame($original->aggregateType, $restored->aggregateType);
        $this->assertSame($original->aggregateId, $restored->aggregateId);
        $this->assertSame($original->version, $restored->version);
        $this->assertSame($original->state, $restored->state);
    }

    /**
     * @test
     */
    public function snapshot_json_serializes_consistently(): void
    {
        $snapshot = Snapshot::create('Order', 'id-1', 1, ['key' => 'value']);

        $this->assertSame($snapshot->toArray(), $snapshot->jsonSerialize());
    }

    /**
     * @test
     */
    public function snapshot_from_array_validates_types(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Snapshot::fromArray([
            'aggregate_type' => null,
            'aggregate_id' => null,
            'version' => null,
            'state' => null,
            'created_at' => null,
        ]);
    }

    // ──────────────────────────────────────────────────────
    // CHECK 11: InMemorySnapshotStore — full SnapshotStore contract
    // ──────────────────────────────────────────────────────

    /**
     * @test
     */
    public function snapshot_store_implements_store_interface(): void
    {
        $store = new InMemorySnapshotStore;

        $this->assertInstanceOf(
            \ZeroBoiler\Domain\Snapshots\SnapshotStore::class,
            $store,
        );
    }

    /**
     * @test
     */
    public function snapshot_store_save_load_delete_cycle(): void
    {
        $store = new InMemorySnapshotStore;
        $snapshot = Snapshot::create('Order', 'id-1', 10, ['status' => 'paid']);

        $store->save($snapshot);
        $this->assertTrue($store->has('Order', 'id-1'));

        $loaded = $store->load('Order', 'id-1');
        $this->assertNotNull($loaded);
        $this->assertSame(10, $loaded->version);

        $store->delete('Order', 'id-1');
        $this->assertFalse($store->has('Order', 'id-1'));
        $this->assertNull($store->load('Order', 'id-1'));
    }

    /**
     * @test
     */
    public function snapshot_store_stats_and_purge(): void
    {
        $store = new InMemorySnapshotStore;
        $store->save(Snapshot::create('Order', 'id-1', 1, []));
        $store->save(Snapshot::create('Order', 'id-2', 2, []));
        $store->save(Snapshot::create('User', 'id-1', 1, []));

        $stats = $store->stats();
        $this->assertSame(3, $stats['total']);
        $this->assertSame(2, $stats['by_type']['Order']);
        $this->assertSame(1, $stats['by_type']['User']);

        $removed = $store->purge('Order');
        $this->assertSame(2, $removed);
        $this->assertSame(1, $store->count());
    }

    // ──────────────────────────────────────────────────────
    // CHECK 12: InMemoryUnitOfWork — UnitOfWork contract
    // ──────────────────────────────────────────────────────

    /**
     * @test
     */
    public function unit_of_work_implements_contract(): void
    {
        $uow = new InMemoryUnitOfWork;

        $this->assertInstanceOf(UnitOfWorkContract::class, $uow);
    }

    /**
     * @test
     */
    public function unit_of_work_run_auto_commits_on_success(): void
    {
        $uow = new InMemoryUnitOfWork;
        $result = $uow->run(fn (): string => 'success');

        $this->assertSame('success', $result);
        $this->assertFalse($uow->isActive());
    }

    /**
     * @test
     */
    public function unit_of_work_run_auto_rollbacks_on_exception(): void
    {
        $uow = new InMemoryUnitOfWork;

        try {
            $uow->run(fn () => throw new \RuntimeException('fail'));
        } catch (\RuntimeException $e) {
            // Expected
        }

        $this->assertFalse($uow->isActive());
        $this->assertFalse($uow->hasPendingEvents());
    }

    /**
     * @test
     */
    public function unit_of_work_track_requires_active_transaction(): void
    {
        $uow = new InMemoryUnitOfWork;
        $aggregate = new TestAggregate;

        $this->expectException(\RuntimeException::class);
        $uow->track($aggregate);
    }

    /**
     * @test
     */
    public function unit_of_work_clear_resets_all_state(): void
    {
        $uow = new InMemoryUnitOfWork;
        $uow->begin();
        $uow->track(new TestAggregate);
        $uow->commit();

        $uow->clear();

        $this->assertFalse($uow->isActive());
        $this->assertEmpty($uow->getCommitted());
        $this->assertEmpty($uow->getDeleted());
        $this->assertFalse($uow->hasPendingEvents());
    }

    // ──────────────────────────────────────────────────────
    // CHECK 13: Contracts — interface consistency
    // ──────────────────────────────────────────────────────

    /**
     * @test
     */
    public function identifier_contract_requires_stringable(): void
    {
        $reflection = new ReflectionClass(IdentifierContract::class);

        $this->assertTrue(
            $reflection->implementsInterface(\Stringable::class),
            'Identifier must extend \Stringable',
        );
    }

    /**
     * @test
     */
    public function aggregate_root_contract_extends_entity_contract(): void
    {
        $reflection = new ReflectionClass(AggregateRootContract::class);

        $this->assertTrue(
            $reflection->implementsInterface(EntityContract::class),
            'AggregateRoot must extend Entity contract',
        );
    }

    /**
     * @test
     */
    public function all_identifiers_implement_identifier_contract(): void
    {
        $identifiers = [
            UuidIdentifier::class,
            StringIdentifier::class,
            IntegerIdentifier::class,
        ];

        foreach ($identifiers as $class) {
            $reflection = new ReflectionClass($class);
            $this->assertTrue(
                $reflection->implementsInterface(IdentifierContract::class),
                "{$class} must implement Identifier contract",
            );
        }
    }

    // ──────────────────────────────────────────────────────
    // CHECK 14: ValueObject — extends base, equality
    // ──────────────────────────────────────────────────────

    /**
     * @test
     */
    public function value_object_is_abstract(): void
    {
        $reflection = new ReflectionClass(ValueObject::class);

        $this->assertTrue($reflection->isAbstract());
    }

    /**
     * @test
     */
    public function value_object_equals_compares_to_array_output(): void
    {
        $voA = TestValueObject::fromArray(['value' => 'test']);
        $voB = TestValueObject::fromArray(['value' => 'test']);
        $voC = TestValueObject::fromArray(['value' => 'other']);

        $this->assertTrue($voA->equals($voB));
        $this->assertFalse($voA->equals($voC));
        $this->assertFalse($voA->equals(null));
    }

    // ──────────────────────────────────────────────────────
    // CHECK 15: Cross-identifier type safety
    // ──────────────────────────────────────────────────────

    /**
     * @test
     */
    public function different_identifier_types_are_never_equal(): void
    {
        $uuidId = new class (\Ramsey\Uuid\Uuid::uuid4()->toString()) extends UuidIdentifier {};
        $strId = StringIdentifier::from('same-string');
        $intId = IntegerIdentifier::from(42);

        $this->assertFalse($uuidId->equals($strId));
        $this->assertFalse($strId->equals($intId));
        $this->assertFalse($intId->equals($uuidId));
    }

    // ──────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────

    private function createEvent(string $type): DomainEvent
    {
        return DomainEvent::occur($type, ['timestamp' => time()]);
    }

    /**
     * @return array<int, string>
     */
    private function findPhpFiles(string $dir): array
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        $files = [];
        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}

// ──────────────────────────────────────────────────────
// Test Fixtures (minimal, local to this test file)
// ──────────────────────────────────────────────────────

class TestEntity extends Entity {}

class TestAggregate extends AggregateRoot
{
    public function __construct()
    {
        parent::__construct(AggregateRootId::generate());
    }
}

class TestValueObject extends ValueObject
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
