<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace Tests\Domain;

use PHPUnit\Framework\TestCase;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\Contracts\Identifier as IdentifierContract;
use ZeroBoiler\Domain\Identifiers\IntegerIdentifier;
use ZeroBoiler\Domain\Identifiers\StringIdentifier;
use ZeroBoiler\Domain\Identifiers\UuidIdentifier;
use ZeroBoiler\Domain\Identifiers\UlidIdentifier;
use ZeroBoiler\Domain\Snapshots\InMemorySnapshotStore;
use ZeroBoiler\Domain\Snapshots\Snapshot;

/**
 * Comprehensive production-ready serde and contract tests.
 *
 * Covers round-trip serialization, type safety, interface compliance,
 * and boundary conditions for all domain building blocks that don't
 * require the external zeroboiler/events package.
 */
final class DomainProductionCompleteSerdeTest extends TestCase
{
    // ─── AggregateRootId ───────────────────────────────────────────

    public function test_aggregate_root_id_generate_returns_valid_uuid(): void
    {
        $id = AggregateRootId::generate();

        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $id->toString(),
        );
    }

    public function test_aggregate_root_id_from_string_round_trip(): void
    {
        $original = AggregateRootId::generate();
        $restored = AggregateRootId::fromString($original->toString());

        self::assertTrue($original->equals($restored));
        self::assertSame($original->toString(), $restored->toString());
    }

    public function test_aggregate_root_id_from_array_round_trip(): void
    {
        $original = AggregateRootId::generate();
        $restored = AggregateRootId::fromArray($original->toArray());

        self::assertTrue($original->equals($restored));
    }

    public function test_aggregate_root_id_from_array_accepts_id_key(): void
    {
        $id = AggregateRootId::fromArray(['id' => '550e8400-e29b-41d4-a716-446655440000']);

        self::assertSame('550e8400-e29b-41d4-a716-446655440000', $id->toString());
    }

    public function test_aggregate_root_id_json_serialization(): void
    {
        $id = AggregateRootId::generate();
        $json = json_encode($id);

        self::assertIsString($json);
        self::assertSame('"' . $id->toString() . '"', $json);
    }

    public function test_aggregate_root_id_to_string_magic(): void
    {
        $id = AggregateRootId::generate();

        self::assertSame($id->toString(), (string) $id);
    }

    public function test_aggregate_root_id_different_ids_not_equal(): void
    {
        $id1 = AggregateRootId::fromString('550e8400-e29b-41d4-a716-446655440000');
        $id2 = AggregateRootId::fromString('660e8400-e29b-41d4-a716-446655440001');

        self::assertFalse($id1->equals($id2));
    }

    public function test_aggregate_root_id_equals_with_stringable(): void
    {
        $id1 = AggregateRootId::fromString('550e8400-e29b-41d4-a716-446655440000');

        $mock = new class ('550e8400-e29b-41d4-a716-446655440000') implements \Stringable {
            public function __construct(public readonly string $value) {}
            public function __toString(): string { return $this->value; }
        };

        self::assertTrue($id1->equals($mock));
    }

    // ─── UuidIdentifier (abstract, subclassable) ──────────────────

    public function test_uuid_identifier_generate_and_equals(): void
    {
        $id1 = OrderId::generate();
        $id2 = OrderId::fromString($id1->toString());

        self::assertTrue($id1->equals($id2));
    }

    public function test_uuid_identifier_is_valid_pre_validation(): void
    {
        self::assertTrue(UuidIdentifier::isValid('550e8400-e29b-41d4-a716-446655440000'));
        self::assertFalse(UuidIdentifier::isValid('not-a-uuid'));
    }

    public function test_uuid_identifier_from_array_round_trip(): void
    {
        $original = OrderId::generate();
        $restored = OrderId::fromArray($original->toArray());

        self::assertTrue($original->equals($restored));
    }

    public function test_uuid_identifier_json_serialization(): void
    {
        $id = OrderId::generate();
        $json = json_encode($id);

        self::assertIsString($json);
        self::assertStringContainsString($id->toString(), $json);
    }

    public function test_uuid_identifier_from_array_with_value_key(): void
    {
        $id = OrderId::fromArray(['uuid' => '550e8400-e29b-41d4-a716-446655440000']);

        self::assertSame('550e8400-e29b-41d4-a716-446655440000', $id->toString());
    }

    public function test_uuid_identifier_invalid_throws_exception(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        OrderId::fromString('not-a-uuid');
    }

    public function test_uuid_identifier_different_types_not_equal(): void
    {
        $id1 = OrderId::fromString('550e8400-e29b-41d4-a716-446655440000');
        $id2 = ProductId::fromString('550e8400-e29b-41d4-a716-446655440000');

        // Same UUID value but different subclasses — not equal
        self::assertFalse($id1->equals($id2));
    }

    // ─── UlidIdentifier (abstract, subclassable) ──────────────────

    public function test_ulid_identifier_generate_and_equals(): void
    {
        $id1 = ProductId::generate();
        $id2 = ProductId::fromString($id1->toString());

        self::assertTrue($id1->equals($id2));
    }

    public function test_ulid_identifier_is_valid_pre_validation(): void
    {
        // Valid ULID format (26 chars, Crockford base32)
        self::assertTrue(UlidIdentifier::isValid('01J0K5S5K5S5S5S5S5S5S5S5S5'));
        self::assertFalse(UlidIdentifier::isValid('not-a-ulid'));
    }

    public function test_ulid_identifier_from_array_round_trip(): void
    {
        $original = ProductId::generate();
        $restored = ProductId::fromArray($original->toArray());

        self::assertTrue($original->equals($restored));
    }

    public function test_ulid_identifier_to_ulid(): void
    {
        $id = ProductId::generate();
        $ulid = $id->toUlid();

        self::assertSame($id->toString(), $ulid->toBase32());
    }

    public function test_ulid_identifier_invalid_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ProductId::fromString('invalid-ulid');
    }

    // ─── StringIdentifier (final, not subclassable) ──────────────

    public function test_string_identifier_from_valid(): void
    {
        $id = StringIdentifier::from('my-slug');

        self::assertSame('my-slug', $id->toString());
    }

    public function test_string_identifier_rejects_empty(): void
    {
        $this->expectException(\ValueError::class);

        StringIdentifier::from('');
    }

    public function test_string_identifier_from_array_round_trip(): void
    {
        $original = StringIdentifier::from('test-slug');
        $restored = StringIdentifier::fromArray($original->toArray());

        self::assertTrue($original->equals($restored));
        self::assertSame('test-slug', $restored->toString());
    }

    public function test_string_identifier_from_string(): void
    {
        $id = StringIdentifier::fromString('hello');

        self::assertSame('hello', $id->toString());
    }

    public function test_string_identifier_from_array_with_id_key(): void
    {
        $id = StringIdentifier::fromArray(['id' => 'my-id']);

        self::assertSame('my-id', $id->toString());
    }

    public function test_string_identifier_from_array_with_string_key(): void
    {
        $id = StringIdentifier::fromArray(['string' => 'my-string']);

        self::assertSame('my-string', $id->toString());
    }

    public function test_string_identifier_json_serialization(): void
    {
        $id = StringIdentifier::from('test');
        $json = json_encode($id);

        self::assertIsString($json);
        self::assertStringContainsString('test', $json);
    }

    // ─── IntegerIdentifier (final, not subclassable) ─────────────

    public function test_integer_identifier_from_and_to_int(): void
    {
        $id = IntegerIdentifier::from(42);

        self::assertSame(42, $id->toInt());
        self::assertSame('42', $id->toString());
    }

    public function test_integer_identifier_json_serializes_as_int(): void
    {
        $id = IntegerIdentifier::from(42);
        $json = json_encode($id);

        self::assertSame('42', $json);
    }

    public function test_integer_identifier_from_string(): void
    {
        $id = IntegerIdentifier::fromString('100');

        self::assertSame(100, $id->toInt());
    }

    public function test_integer_identifier_from_array_round_trip(): void
    {
        $original = IntegerIdentifier::from(42);
        $restored = IntegerIdentifier::fromArray($original->toArray());

        self::assertTrue($original->equals($restored));
    }

    public function test_integer_identifier_from_string_invalid_throws(): void
    {
        $this->expectException(\ValueError::class);

        IntegerIdentifier::fromString('not-a-number');
    }

    public function test_integer_identifier_from_array_with_integer_key(): void
    {
        $id = IntegerIdentifier::fromArray(['integer' => 99]);

        self::assertSame(99, $id->toInt());
    }

    public function test_integer_identifier_is_valid_pre_validation(): void
    {
        self::assertTrue(IntegerIdentifier::isValid('42'));
        self::assertFalse(IntegerIdentifier::isValid('abc'));
    }

    // ─── ValueObject ──────────────────────────────────────────────

    public function test_value_object_equality_based_on_to_array(): void
    {
        $vo1 = TestValueObject::fromArray(['name' => 'John', 'age' => 30]);
        $vo2 = TestValueObject::fromArray(['name' => 'John', 'age' => 30]);

        self::assertTrue($vo1->equals($vo2));
    }

    public function test_value_object_inequality_different_values(): void
    {
        $vo1 = TestValueObject::fromArray(['name' => 'John', 'age' => 30]);
        $vo2 = TestValueObject::fromArray(['name' => 'Jane', 'age' => 25]);

        self::assertFalse($vo1->equals($vo2));
    }

    public function test_value_object_to_array_round_trip(): void
    {
        $data = ['name' => 'John', 'age' => 30];
        $vo = TestValueObject::fromArray($data);
        $restored = TestValueObject::fromArray($vo->toArray());

        self::assertSame($data, $restored->toArray());
    }

    public function test_value_object_json_serialization(): void
    {
        $vo = TestValueObject::fromArray(['name' => 'Jane', 'age' => 25]);
        $json = json_encode($vo);

        self::assertIsString($json);
        $decoded = json_decode($json, true);
        self::assertSame('Jane', $decoded['name']);
        self::assertSame(25, $decoded['age']);
    }

    // ─── Entity ───────────────────────────────────────────────────

    public function test_entity_id_returns_string(): void
    {
        $entity = new TestEntity('abc');

        self::assertSame('abc', $entity->id());
    }

    public function test_entity_to_array_contains_id_and_type(): void
    {
        $entity = new TestEntity('123');

        $array = $entity->toArray();

        self::assertArrayHasKey('id', $array);
        self::assertArrayHasKey('type', $array);
        self::assertSame('123', $array['id']);
    }

    public function test_entity_json_serialization(): void
    {
        $entity = new TestEntity('456');
        $json = json_encode($entity);

        self::assertIsString($json);
        $decoded = json_decode($json, true);
        self::assertSame('456', $decoded['id']);
    }

    public function test_entity_equals_same_type_same_id(): void
    {
        $e1 = new TestEntity('abc');
        $e2 = new TestEntity('abc');

        self::assertTrue($e1->equals($e2));
    }

    public function test_entity_not_equals_different_type(): void
    {
        $e1 = new TestEntity('abc');
        $e2 = new AnotherEntity('abc');

        self::assertFalse($e1->equals($e2));
    }

    public function test_entity_not_equals_different_id(): void
    {
        $e1 = new TestEntity('abc');
        $e2 = new TestEntity('xyz');

        self::assertFalse($e1->equals($e2));
    }

    // ─── Interface Compliance ──────────────────────────────────────

    public function test_aggregate_root_id_implements_identifier_contract(): void
    {
        self::assertInstanceOf(IdentifierContract::class, AggregateRootId::generate());
    }

    public function test_aggregate_root_id_implements_stringable(): void
    {
        $id = AggregateRootId::generate();

        self::assertInstanceOf(\Stringable::class, $id);
        self::assertSame($id->toString(), (string) $id);
    }

    public function test_aggregate_root_id_implements_json_serializable(): void
    {
        self::assertInstanceOf(\JsonSerializable::class, AggregateRootId::generate());
    }

    public function test_all_identifiers_implement_identifier_contract(): void
    {
        self::assertInstanceOf(IdentifierContract::class, OrderId::generate());
        self::assertInstanceOf(IdentifierContract::class, ProductId::generate());
        self::assertInstanceOf(IdentifierContract::class, StringIdentifier::from('test'));
        self::assertInstanceOf(IdentifierContract::class, IntegerIdentifier::from(1));
    }

    public function test_all_identifiers_are_json_serializable(): void
    {
        self::assertInstanceOf(\JsonSerializable::class, OrderId::generate());
        self::assertInstanceOf(\JsonSerializable::class, ProductId::generate());
        self::assertInstanceOf(\JsonSerializable::class, StringIdentifier::from('test'));
        self::assertInstanceOf(\JsonSerializable::class, IntegerIdentifier::from(1));
    }

    public function test_snapshot_is_json_serializable(): void
    {
        $snapshot = Snapshot::create('Test', 'id', 1, []);

        self::assertInstanceOf(\JsonSerializable::class, $snapshot);
    }

    // ─── Domain Exceptions ───────────────────────────────────────

    public function test_domain_exception_error_code_hierarchy(): void
    {
        $exceptions = [
            [new \ZeroBoiler\Domain\Exceptions\InvalidStateDomainException('test'), 'INVALID_STATE'],
            [new \ZeroBoiler\Domain\Exceptions\InvalidArgumentDomainException('test'), 'INVALID_ARGUMENT'],
            [new \ZeroBoiler\Domain\Exceptions\NotFoundDomainException('test'), 'NOT_FOUND'],
            [new \ZeroBoiler\Domain\Exceptions\ConflictDomainException('test'), 'CONFLICT'],
            [new \ZeroBoiler\Domain\Exceptions\OptimisticLockException('id', 1, 2), 'OPTIMISTIC_LOCK'],
            [new \ZeroBoiler\Domain\Exceptions\AggregateNotFoundException('Type', 'id'), 'AGGREGATE_NOT_FOUND'],
            [new \ZeroBoiler\Domain\Exceptions\InvalidAggregateRootException(new \stdClass), 'INVALID_AGGREGATE_ROOT'],
        ];

        foreach ($exceptions as [$exception, $expectedCode]) {
            self::assertSame($expectedCode, $exception->errorCode());
        }
    }

    public function test_domain_exception_to_error_array_rfc9457(): void
    {
        $exception = \ZeroBoiler\Domain\Exceptions\InvalidStateDomainException::because('Must be pending');

        $array = $exception->toErrorArray();

        self::assertArrayHasKey('title', $array);
        self::assertArrayHasKey('detail', $array);
        self::assertArrayHasKey('code', $array);
        self::assertSame('INVALID_STATE', $array['code']);
        self::assertSame('Must be pending', $array['detail']);
    }

    public function test_domain_exception_json_serialization(): void
    {
        $exception = \ZeroBoiler\Domain\Exceptions\NotFoundDomainException::because('Not found');
        $json = json_encode($exception);

        self::assertIsString($json);
        $decoded = json_decode($json, true);
        self::assertSame('NOT_FOUND', $decoded['code']);
    }

    public function test_domain_exception_custom_error_code(): void
    {
        $exception = \ZeroBoiler\Domain\Exceptions\InvalidStateDomainException::because('test', 'CUSTOM_CODE');

        self::assertSame('CUSTOM_CODE', $exception->errorCode());
    }

    public function test_optimistic_lock_exception_factory(): void
    {
        $exception = \ZeroBoiler\Domain\Exceptions\OptimisticLockException::for('order-123', 3, 5);

        self::assertSame('OPTIMISTIC_LOCK', $exception->errorCode());
        self::assertStringContainsString('order-123', $exception->getMessage());
        self::assertStringContainsString('version 3', $exception->getMessage());
        self::assertStringContainsString('version 5', $exception->getMessage());
    }

    public function test_aggregate_not_found_exception_factory(): void
    {
        $exception = \ZeroBoiler\Domain\Exceptions\AggregateNotFoundException::for('Order', 'uuid-1');

        self::assertSame('AGGREGATE_NOT_FOUND', $exception->errorCode());
        self::assertStringContainsString('Order', $exception->getMessage());
        self::assertStringContainsString('uuid-1', $exception->getMessage());
    }

    public function test_conflict_exception_because(): void
    {
        $exception = \ZeroBoiler\Domain\Exceptions\ConflictDomainException::because('Duplicate entry');

        self::assertSame('CONFLICT', $exception->errorCode());
        self::assertSame('Duplicate entry', $exception->getMessage());
    }

    public function test_invalid_argument_exception_because(): void
    {
        $exception = \ZeroBoiler\Domain\Exceptions\InvalidArgumentDomainException::because('Bad param');

        self::assertSame('INVALID_ARGUMENT', $exception->errorCode());
    }

    // ─── Snapshots ──────────────────────────────────────────────────

    public function test_snapshot_from_array_round_trip(): void
    {
        $original = Snapshot::create('Order', 'uuid-1', 10, ['status' => 'paid']);

        $restored = Snapshot::fromArray($original->toArray());

        self::assertTrue($original->equals($restored));
        self::assertSame('Order', $restored->aggregateType);
        self::assertSame('uuid-1', $restored->aggregateId);
        self::assertSame(10, $restored->version);
    }

    public function test_snapshot_from_array_fallback_keys(): void
    {
        $snapshot = Snapshot::fromArray([
            'aggregate_type' => 'Test',
            'aggregate_id' => 'id-123',
            'version' => 5,
            'state' => ['key' => 'val'],
            'created_at' => (new \DateTimeImmutable)->format(\DateTimeImmutable::ATOM),
        ]);

        self::assertSame('Test', $snapshot->aggregateType);
        self::assertSame('id-123', $snapshot->aggregateId);
        self::assertSame(5, $snapshot->version);
        self::assertSame(['key' => 'val'], $snapshot->state);
    }

    public function test_snapshot_equality(): void
    {
        $s1 = Snapshot::create('Order', 'id-1', 5, ['a' => 1]);
        $s2 = Snapshot::create('Order', 'id-1', 5, ['a' => 1]);
        $s3 = Snapshot::create('Order', 'id-1', 6, ['a' => 1]);

        self::assertTrue($s1->equals($s2));
        self::assertFalse($s1->equals($s3));
    }

    public function test_snapshot_json_serialization(): void
    {
        $snapshot = Snapshot::create('Order', 'uuid-1', 5, ['data' => true]);
        $json = json_encode($snapshot);

        self::assertIsString($json);
        self::assertStringContainsString('aggregate_type', $json);
        self::assertStringContainsString('Order', $json);
    }

    public function test_snapshot_to_array_structure(): void
    {
        $snapshot = Snapshot::create('Order', 'uuid-1', 3, ['status' => 'paid']);

        $array = $snapshot->toArray();

        self::assertSame('Order', $array['aggregate_type']);
        self::assertSame('uuid-1', $array['aggregate_id']);
        self::assertSame(3, $array['version']);
        self::assertSame(['status' => 'paid'], $array['payload']);
    }

    public function test_in_memory_snapshot_store_lifecycle(): void
    {
        $store = new InMemorySnapshotStore;
        $id = AggregateRootId::generate();

        $snapshot = Snapshot::create('Order', $id->toString(), 10, ['status' => 'paid']);

        self::assertFalse($store->has('Order', $id->toString()));

        $store->save($snapshot);

        self::assertTrue($store->has('Order', $id->toString()));
        self::assertSame(10, $store->count());

        $loaded = $store->load('Order', $id->toString());

        self::assertNotNull($loaded);
        self::assertSame(10, $loaded->version);

        $stats = $store->stats();
        self::assertSame(1, $stats['total']);
        self::assertArrayHasKey('Order', $stats['by_type']);

        $store->delete('Order', $id->toString());
        self::assertFalse($store->has('Order', $id->toString()));
    }

    public function test_in_memory_snapshot_store_purge_clears_all(): void
    {
        $store = new InMemorySnapshotStore;

        $store->save(Snapshot::create('A', '1', 1, []));
        $store->save(Snapshot::create('B', '2', 2, []));

        self::assertSame(2, $store->count());

        $removed = $store->purge();

        self::assertSame(2, $removed);
        self::assertSame(0, $store->count());
    }

    public function test_in_memory_snapshot_store_load_missing_returns_null(): void
    {
        $store = new InMemorySnapshotStore;

        self::assertNull($store->load('Missing', 'id'));
    }

    public function test_in_memory_snapshot_store_latest_version(): void
    {
        $store = new InMemorySnapshotStore;

        $store->save(Snapshot::create('Order', 'id-1', 1, []));
        $store->save(Snapshot::create('Order', 'id-1', 3, []));
        $store->save(Snapshot::create('Order', 'id-1', 2, []));

        $loaded = $store->load('Order', 'id-1');

        self::assertNotNull($loaded);
        self::assertSame(3, $loaded->version);
    }

    public function test_in_memory_snapshot_store_delete_missing_no_error(): void
    {
        $store = new InMemorySnapshotStore;

        // Should not throw
        $store->delete('Missing', 'id');

        self::assertSame(0, $store->count());
    }
}

// ─── Test Fixtures ──────────────────────────────────────────────────

/** Domain-specific UUID identifier for orders. */
final readonly class OrderId extends UuidIdentifier {}

/** Domain-specific ULID identifier for products. */
final readonly class ProductId extends UlidIdentifier {}

/** Test value object for equality and serialization tests. */
final class TestValueObject extends \ZeroBoiler\Domain\ValueObject
{
    public function __construct(
        public readonly string $name = '',
        public readonly int $age = 0,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'] ?? '',
            age: $data['age'] ?? 0,
        );
    }

    #[\Override]
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'age' => $this->age,
        ];
    }
}

/** Test entity for equality tests. */
final class TestEntity extends \ZeroBoiler\Domain\Entity
{
    public function __construct(int|string|\Stringable $id)
    {
        parent::__construct($id);
    }
}

/** Another test entity for cross-type equality tests. */
final class AnotherEntity extends \ZeroBoiler\Domain\Entity
{
    public function __construct(int|string|\Stringable $id)
    {
        parent::__construct($id);
    }
}
