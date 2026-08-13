<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace Tests\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use ZeroBoiler\Domain\AggregateRoot;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\Concerns\EventSourced;
use ZeroBoiler\Domain\Concerns\HasSnapshots;
use ZeroBoiler\Domain\DomainEventCollection;
use ZeroBoiler\Domain\Entity;
use ZeroBoiler\Domain\Exceptions\{ConflictDomainException, InvalidArgumentDomainException, InvalidStateDomainException, NotFoundDomainException, OptimisticLockException, AggregateNotFoundException};
use ZeroBoiler\Domain\Identifiers\IntegerIdentifier;
use ZeroBoiler\Domain\Identifiers\StringIdentifier;
use ZeroBoiler\Domain\Identifiers\UlidIdentifier;
use ZeroBoiler\Domain\Identifiers\UuidIdentifier;
use ZeroBoiler\Domain\Snapshots\{InMemorySnapshotStore, Snapshot, SnapshotPolicy, SnapshottingRepository};
use ZeroBoiler\Domain\ValueObject;
use ZeroBoiler\Events\Domain\DomainEvent;

/**
 * Core contract validation tests for the domain package.
 *
 * Validates that all domain types satisfy their contractual invariants:
 * - Strict types and return type declarations
 * - Immutability where required (readonly classes)
 * - fromArray/toArray round-trip serialization
 * - JSON serialization consistency
 * - Domain invariant enforcement
 * - Equality semantics
 * - Exception hierarchy and RFC 9457 output
 *
 * @since 2.10.0
 */
#[CoversClass(AggregateRootId::class)]
#[CoversClass(AggregateRoot::class)]
#[CoversClass(Entity::class)]
#[CoversClass(ValueObject::class)]
#[CoversClass(DomainEventCollection::class)]
#[CoversClass(UuidIdentifier::class)]
#[CoversClass(UlidIdentifier::class)]
#[CoversClass(StringIdentifier::class)]
#[CoversClass(IntegerIdentifier::class)]
#[CoversClass(Snapshot::class)]
#[CoversClass(InMemorySnapshotStore::class)]
#[Group('production')]
final class DomainCoreContractTest extends \PHPUnit\Framework\TestCase
{
    // ────────────────────────────────────────────
    // AggregateRootId
    // ────────────────────────────────────────────

    public function test_aggregate_root_id_is_final_readonly(): void
    {
        $reflection = new \ReflectionClass(AggregateRootId::class);

        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
    }

    public function test_aggregate_root_id_generate_creates_valid_uuid(): void
    {
        $id = AggregateRootId::generate();

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $id->toString(),
        );
    }

    public function test_aggregate_root_id_from_string_round_trip(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $id = AggregateRootId::fromString($uuid);

        $this->assertSame($uuid, $id->toString());
        $this->assertSame($uuid, (string) $id);
        $this->assertSame($uuid, json_encode($id));
    }

    public function test_aggregate_root_id_equality(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $a = AggregateRootId::fromString($uuid);
        $b = AggregateRootId::fromString($uuid);
        $c = AggregateRootId::generate();

        $this->assertTrue($a->equals($b));
        $this->assertFalse($a->equals($c));
    }

    public function test_aggregate_root_id_from_array_round_trip(): void
    {
        $id = AggregateRootId::generate();
        $array = $id->toArray();

        $this->assertArrayHasKey('uuid', $array);
        $this->assertSame($id->toString(), $array['uuid']);

        $restored = AggregateRootId::fromArray($array);
        $this->assertTrue($id->equals($restored));
    }

    public function test_aggregate_root_id_from_array_accepts_id_key(): void
    {
        $id = AggregateRootId::fromArray(['id' => '550e8400-e29b-41d4-a716-446655440000']);
        $this->assertSame('550e8400-e29b-41d4-a716-446655440000', $id->toString());
    }

    #[DataProvider('invalidUuidProvider')]
    public function test_aggregate_root_id_from_string_rejects_invalid(string $invalid): void
    {
        $this->expectException(\Ramsey\Uuid\Exception\InvalidUuidStringException::class);
        AggregateRootId::fromString($invalid);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidUuidProvider(): array
    {
        return [
            'empty' => [''],
            'not-uuid' => ['not-a-uuid'],
            'partial' => ['550e8400-'],
        ];
    }

    // ────────────────────────────────────────────
    // Identifiers
    // ────────────────────────────────────────────

    public function test_uuid_identifier_is_abstract_readonly(): void
    {
        $reflection = new \ReflectionClass(UuidIdentifier::class);
        $this->assertTrue($reflection->isAbstract());
        $this->assertTrue($reflection->isReadOnly());
    }

    public function test_ulid_identifier_is_abstract_readonly(): void
    {
        $reflection = new \ReflectionClass(UlidIdentifier::class);
        $this->assertTrue($reflection->isAbstract());
        $this->assertTrue($reflection->isReadOnly());
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

    public function test_string_identifier_rejects_empty(): void
    {
        $this->expectException(\ValueError::class);
        StringIdentifier::from('');
    }

    public function test_string_identifier_from_array_round_trip(): void
    {
        $id = StringIdentifier::from('my-slug');
        $restored = StringIdentifier::fromArray($id->toArray());
        $this->assertTrue($id->equals($restored));
    }

    public function test_integer_identifier_from_array_round_trip(): void
    {
        $id = IntegerIdentifier::from(42);
        $restored = IntegerIdentifier::fromArray($id->toArray());
        $this->assertTrue($id->equals($restored));
    }

    public function test_integer_identifier_from_string(): void
    {
        $id = IntegerIdentifier::fromString('42');
        $this->assertSame(42, $id->toInt());
        $this->assertSame('42', $id->toString());
        $this->assertSame(42, json_encode($id));
    }

    public function test_integer_identifier_json_serializes_as_int(): void
    {
        $id = IntegerIdentifier::from(42);
        $this->assertSame(42, $id->jsonSerialize());
    }

    // ────────────────────────────────────────────
    // Entity (using test fixtures)
    // ────────────────────────────────────────────

    public function test_entity_is_abstract(): void
    {
        $reflection = new \ReflectionClass(Entity::class);
        $this->assertTrue($reflection->isAbstract());
    }

    public function test_entity_equality_based_on_id(): void
    {
        $entity = new class('abc') extends Entity {
            public function toArray(): array
            {
                return [...parent::toArray(), 'name' => 'test'];
            }
        };

        $same = new class('abc') extends Entity {
            public function toArray(): array
            {
                return [...parent::toArray(), 'name' => 'different'];
            }
        };

        $different = new class('xyz') extends Entity {
            public function toArray(): array
            {
                return [...parent::toArray(), 'name' => 'test'];
            }
        };

        $this->assertTrue($entity->equals($same));
        $this->assertFalse($entity->equals($different));
    }

    public function test_entity_to_array_contains_id_and_type(): void
    {
        $entity = new class('42') extends Entity {};
        $array = $entity->toArray();

        $this->assertSame('42', $array['id']);
        $this->assertArrayHasKey('type', $array);
    }

    public function test_entity_json_serialize(): void
    {
        $entity = new class('42') extends Entity {};
        $this->assertJson(json_encode($entity));
    }

    // ────────────────────────────────────────────
    // AggregateRoot
    // ────────────────────────────────────────────

    public function test_aggregate_root_is_abstract(): void
    {
        $reflection = new \ReflectionClass(AggregateRoot::class);
        $this->assertTrue($reflection->isAbstract());
    }

    public function test_aggregate_root_constructor_is_protected(): void
    {
        $method = new \ReflectionMethod(AggregateRoot::class, '__construct');
        $this->assertTrue($method->isProtected());
    }

    public function test_aggregate_root_exposes_id_method(): void
    {
        $methods = array_filter(
            (new \ReflectionClass(AggregateRoot::class))->getMethods(\ReflectionMethod::IS_PUBLIC),
            fn (\ReflectionMethod $m) => $m->getName() === 'id',
        );

        $this->assertNotEmpty($methods, 'AggregateRoot must expose a public id() method');
    }

    // ────────────────────────────────────────────
    // DomainEventCollection
    // ────────────────────────────────────────────

    public function test_domain_event_collection_is_final_readonly(): void
    {
        $reflection = new \ReflectionClass(DomainEventCollection::class);
        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
    }

    public function test_domain_event_collection_validates_list(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('sequential list');

        new DomainEventCollection(['key' => 'value']);
    }

    public function test_domain_event_collection_json_serialize(): void
    {
        $event = DomainEvent::occur('test.event', ['key' => 'val']);
        $collection = new DomainEventCollection([$event]);

        $json = json_encode($collection);
        $this->assertNotFalse($json);
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
        $this->assertCount(1, $decoded);
    }

    // ────────────────────────────────────────────
    // Snapshot
    // ────────────────────────────────────────────

    public function test_snapshot_is_final_readonly(): void
    {
        $reflection = new \ReflectionClass(Snapshot::class);
        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
    }

    public function test_snapshot_from_array_round_trip(): void
    {
        $original = Snapshot::create('Order', 'uuid-1', 5, ['status' => 'paid']);
        $restored = Snapshot::fromArray($original->toArray());

        $this->assertSame($original->aggregateType, $restored->aggregateType);
        $this->assertSame($original->aggregateId, $restored->aggregateId);
        $this->assertSame($original->version, $restored->version);
        $this->assertSame($original->state, $restored->state);
        $this->assertTrue($original->equals($restored));
    }

    public function test_snapshot_json_serialize(): void
    {
        $snapshot = Snapshot::create('Order', 'uuid-1', 5, ['status' => 'paid']);
        $json = json_encode($snapshot);
        $this->assertNotFalse($json);
        $decoded = json_decode($json, true);
        $this->assertSame('Order', $decoded['aggregate_type']);
    }

    // ────────────────────────────────────────────
    // Exception hierarchy
    // ────────────────────────────────────────────

    public function test_domain_exceptions_are_final(): void
    {
        $exceptions = [
            InvalidStateDomainException::class,
            InvalidArgumentDomainException::class,
            NotFoundDomainException::class,
            ConflictDomainException::class,
            OptimisticLockException::class,
            AggregateNotFoundException::class,
        ];

        foreach ($exceptions as $class) {
            $this->assertTrue(
                (new \ReflectionClass($class))->isFinal(),
                "{$class} should be final",
            );
        }
    }

    public function test_domain_exception_error_codes_are_stable(): void
    {
        $expected = [
            InvalidStateDomainException::class => 'INVALID_STATE',
            InvalidArgumentDomainException::class => 'INVALID_ARGUMENT',
            NotFoundDomainException::class => 'NOT_FOUND',
            ConflictDomainException::class => 'CONFLICT',
            OptimisticLockException::class => 'OPTIMISTIC_LOCK',
            AggregateNotFoundException::class => 'AGGREGATE_NOT_FOUND',
        ];

        foreach ($expected as $class => $code) {
            $e = $class::because('test');
            $this->assertSame($code, $e->errorCode(), "{$class} error code mismatch");
        }
    }

    public function test_domain_exception_to_error_array_is_rfc9457_compatible(): void
    {
        $e = InvalidStateDomainException::because('Order must be pending.');
        $array = $e->toErrorArray();

        $this->assertArrayHasKey('title', $array);
        $this->assertArrayHasKey('detail', $array);
        $this->assertArrayHasKey('code', $array);
        $this->assertSame('InvalidStateDomainException', $array['title']);
        $this->assertSame('Order must be pending.', $array['detail']);
        $this->assertSame('INVALID_STATE', $array['code']);
    }

    public function test_domain_exception_json_serialize_matches_to_error_array(): void
    {
        $e = NotFoundDomainException::forAggregate('Order', 'uuid-1');
        $this->assertSame(
            $e->toErrorArray(),
            $e->jsonSerialize(),
        );
    }

    public function test_optimistic_lock_exception_includes_version_info(): void
    {
        $e = OptimisticLockException::for('uuid-1', expectedVersion: 5, actualVersion: 3);

        $this->assertStringContainsString('5', $e->getMessage());
        $this->assertStringContainsString('3', $e->getMessage());
        $this->assertSame('OPTIMISTIC_LOCK', $e->errorCode());
    }

    public function test_domain_exception_from_array_round_trip(): void
    {
        $original = InvalidStateDomainException::because('Test reason');
        $restored = InvalidStateDomainException::fromArray(
            $original->toArray(),
            InvalidStateDomainException::class,
        );

        $this->assertSame($original->getMessage(), $restored->getMessage());
        $this->assertSame($original->errorCode(), $restored->errorCode());
    }

    // ────────────────────────────────────────────
    // SnapshotStore contract
    // ────────────────────────────────────────────

    public function test_in_memory_snapshot_store_crud(): void
    {
        $store = new InMemorySnapshotStore;
        $snapshot = Snapshot::create('Order', 'uuid-1', 5, ['status' => 'paid']);

        $this->assertFalse($store->has('Order', 'uuid-1'));

        $store->save($snapshot);
        $this->assertTrue($store->has('Order', 'uuid-1'));

        $loaded = $store->load('Order', 'uuid-1');
        $this->assertNotNull($loaded);
        $this->assertTrue($snapshot->equals($loaded));

        $store->delete('Order', 'uuid-1');
        $this->assertFalse($store->has('Order', 'uuid-1'));
    }

    public function test_in_memory_snapshot_store_stats(): void
    {
        $store = new InMemorySnapshotStore;
        $store->save(Snapshot::create('Order', 'uuid-1', 1, []));
        $store->save(Snapshot::create('Order', 'uuid-2', 2, []));
        $store->save(Snapshot::create('User', 'uuid-3', 1, []));

        $stats = $store->stats();
        $this->assertSame(3, $stats['total']);
        $this->assertSame(2, $stats['by_type']['Order']);
        $this->assertSame(1, $stats['by_type']['User']);
    }

    // ────────────────────────────────────────────
    // declare(strict_types=1) validation
    // ────────────────────────────────────────────

    #[DataProvider('domainSourceFilesProvider')]
    public function test_all_domain_source_files_have_strict_types(string $file): void
    {
        $content = file_get_contents($file);
        $this->assertStringContainsString(
            'declare(strict_types=1)',
            $content,
            "{$file} must have declare(strict_types=1)",
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function domainSourceFilesProvider(): array
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(__DIR__ . '/../src', \FilesystemIterator::SKIP_DOTS),
        );
        $files = [];
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[$file->getFilename()] = [(string) $file->getRealPath()];
            }
        }

        return $files;
    }
}
