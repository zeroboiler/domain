<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace Tests\Domain;

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
use ZeroBoiler\Domain\Snapshots\Snapshot;
use ZeroBoiler\Domain\ValueObject;
use ZeroBoiler\Events\Domain\DomainEvent;

/**
 * Production round-trip audit for domain package.
 *
 * Verifies: strict types, immutability, return types, docblocks, typed properties,
 * round-trip serialization (toArray/fromArray), JSON serialization,
 * contract compliance, and domain invariants.
 *
 * @since 2.0.0
 *
 * @covers \ZeroBoiler\Domain\AggregateRoot
 * @covers \ZeroBoiler\Domain\AggregateRootId
 * @covers \ZeroBoiler\Domain\Entity
 * @covers \ZeroBoiler\Domain\DomainEventCollection
 * @covers \ZeroBoiler\Domain\Identifiers\UuidIdentifier
 * @covers \ZeroBoiler\Domain\Identifiers\UlidIdentifier
 * @covers \ZeroBoiler\Domain\Identifiers\StringIdentifier
 * @covers \ZeroBoiler\Domain\Identifiers\IntegerIdentifier
 * @covers \ZeroBoiler\Domain\Exceptions\DomainException
 * @covers \ZeroBoiler\Domain\Exceptions\OptimisticLockException
 * @covers \ZeroBoiler\Domain\Exceptions\InvalidStateDomainException
 * @covers \ZeroBoiler\Domain\Exceptions\InvalidArgumentDomainException
 * @covers \ZeroBoiler\Domain\Exceptions\NotFoundDomainException
 * @covers \ZeroBoiler\Domain\Exceptions\ConflictDomainException
 * @covers \ZeroBoiler\Domain\Snapshots\Snapshot
 */
final class DomainProductionRoundTripAuditTest extends TestCase
{
    // ─────────────────────────────────────────────────────────
    // AggregateRootId — Immutability & Round-trip
    // ─────────────────────────────────────────────────────────

    public function test_aggregate_root_id_is_final_readonly(): void
    {
        $reflection = new \ReflectionClass(AggregateRootId::class);

        $this->assertTrue($reflection->isFinal(), 'AggregateRootId must be final');
        $this->assertTrue($reflection->isReadOnly(), 'AggregateRootId must be readonly');
    }

    public function test_aggregate_root_id_round_trip(): void
    {
        $id = AggregateRootId::generate();
        $restored = AggregateRootId::fromArray($id->toArray());

        $this->assertTrue($id->equals($restored));
        $this->assertSame($id->toString(), $restored->toString());
        $this->assertSame($id->toArray(), $restored->toArray());
    }

    public function test_aggregate_root_id_from_string_round_trip(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $id = AggregateRootId::fromString($uuid);

        $this->assertSame($uuid, $id->toString());
        $this->assertSame($uuid, (string) $id);
        $this->assertSame($uuid, json_encode($id));
    }

    public function test_aggregate_root_id_json_serialization_returns_string(): void
    {
        $id = AggregateRootId::generate();
        $json = json_encode($id);

        $this->assertIsString($json);
        $this->assertNotFalse($json);
        $this->assertTrue(
            (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[0-9a-f]{4}-[0-9a-f]{12}$/', $json),
            'JSON serialization must be a valid UUID v4 string',
        );
    }

    // ─────────────────────────────────────────────────────────
    // Identifier Types — Contract Compliance & Round-trip
    // ─────────────────────────────────────────────────────────

    public function test_uuid_identifier_is_abstract_readonly(): void
    {
        $reflection = new \ReflectionClass(UuidIdentifier::class);

        $this->assertTrue($reflection->isAbstract(), 'UuidIdentifier must be abstract');
        $this->assertTrue($reflection->isReadOnly(), 'UuidIdentifier must be readonly');
        $this->assertTrue($reflection->implementsInterface(IdentifierContract::class));
    }

    public function test_uuid_identifier_round_trip(): void
    {
        $id = TestOrderId::generate();
        $restored = TestOrderId::fromArray($id->toArray());

        $this->assertTrue($id->equals($restored));
        $this->assertSame($id->toString(), $restored->toString());
        $this->assertSame($id->toArray(), $restored->toArray());
    }

    public function test_uuid_identifier_subclass_equality_strict(): void
    {
        $orderId = TestOrderId::generate();
        $productId = TestProductId::fromString($orderId->toString());

        $this->assertFalse($orderId->equals($productId), 'Different subclasses must not be equal');
    }

    public function test_ulid_identifier_round_trip(): void
    {
        $id = TestProductId::fromUlid();
        $restored = TestProductId::fromArray($id->toArray());

        $this->assertTrue($id->equals($restored));
        $this->assertSame($id->toString(), $restored->toString());
    }

    public function test_string_identifier_validates_non_empty(): void
    {
        $this->expectException(\ValueError::class);
        StringIdentifier::from('');
    }

    public function test_string_identifier_round_trip(): void
    {
        $id = StringIdentifier::from('my-slug');
        $restored = StringIdentifier::fromString($id->toString());

        $this->assertSame($id->toString(), $restored->toString());
        $this->assertSame($id->toArray(), $restored->toArray());
    }

    public function test_integer_identifier_round_trip(): void
    {
        $id = IntegerIdentifier::from(42);
        $restored = IntegerIdentifier::fromString($id->toString());

        $this->assertSame($id->toInt(), $restored->toInt());
        $this->assertSame('42', $restored->toString());
        $this->assertSame($id->toArray(), $restored->toArray());
    }

    public function test_integer_identifier_json_serialization(): void
    {
        $id = IntegerIdentifier::from(42);
        $this->assertSame(42, json_encode($id));
    }

    // ─────────────────────────────────────────────────────────
    // DomainEventCollection — Round-trip & Immutability
    // ─────────────────────────────────────────────────────────

    public function test_domain_event_collection_is_final_readonly(): void
    {
        $reflection = new \ReflectionClass(DomainEventCollection::class);

        $this->assertTrue($reflection->isFinal(), 'DomainEventCollection must be final');
        $this->assertTrue($reflection->isReadOnly(), 'DomainEventCollection must be readonly');
    }

    public function test_domain_event_collection_filter_returns_new_instance(): void
    {
        $events = [
            DomainEvent::occur('test.event_a', ['key' => 'a']),
            DomainEvent::occur('test.event_b', ['key' => 'b']),
        ];
        $collection = new DomainEventCollection($events);
        $filtered = $collection->filter(
            fn (DomainEvent $e): bool => $e->eventType === 'test.event_a',
        );

        $this->assertNotSame($collection, $filtered, 'filter() must return a new instance');
        $this->assertSame(1, $filtered->count());
        $this->assertSame(2, $collection->count(), 'Original collection must be unchanged');
    }

    public function test_domain_event_collection_merge_returns_new_instance(): void
    {
        $c1 = new DomainEventCollection([DomainEvent::occur('a', [])]);
        $c2 = new DomainEventCollection([DomainEvent::occur('b', [])]);
        $merged = $c1->merge($c2);

        $this->assertNotSame($c1, $merged);
        $this->assertSame(2, $merged->count());
        $this->assertSame(1, $c1->count());
    }

    // ─────────────────────────────────────────────────────────
    // Domain Exceptions — Hierarchy & Error Codes
    // ─────────────────────────────────────────────────────────

    public function test_domain_exception_is_abstract(): void
    {
        $reflection = new \ReflectionClass(DomainException::class);
        $this->assertTrue($reflection->isAbstract());
    }

    public function test_domain_exception_implements_json_serializable(): void
    {
        $reflection = new \ReflectionClass(DomainException::class);
        $this->assertTrue($reflection->implementsInterface(\JsonSerializable::class));
    }

    public function test_domain_exception_error_code_format(): void
    {
        $e = InvalidStateDomainException::because('test');
        $this->assertSame('INVALID_STATE', $e->errorCode());

        $e2 = OptimisticLockException::for('id-1', 1, 2);
        $this->assertSame('OPTIMISTIC_LOCK', $e2->errorCode());

        $e3 = InvalidArgumentDomainException::for('field');
        $this->assertSame('INVALID_ARGUMENT', $e3->errorCode());

        $e4 = NotFoundDomainException::for('Resource');
        $this->assertSame('NOT_FOUND', $e4->errorCode());

        $e5 = ConflictDomainException::for('resource', 'conflict');
        $this->assertSame('CONFLICT', $e5->errorCode());
    }

    public function test_domain_exception_to_error_array_rfc9457_compliant(): void
    {
        $e = InvalidStateDomainException::because('Order must be pending');
        $array = $e->toErrorArray();

        $this->assertArrayHasKey('title', $array);
        $this->assertArrayHasKey('detail', $array);
        $this->assertArrayHasKey('code', $array);
        $this->assertSame('INVALID_STATE', $array['code']);
        $this->assertSame('Order must be pending', $array['detail']);
    }

    public function test_domain_exception_json_serialization_matches_to_error_array(): void
    {
        $e = InvalidStateDomainException::because('test');
        $this->assertSame($e->toErrorArray(), $e->jsonSerialize());
    }

    public function test_optimistic_lock_exception_final(): void
    {
        $reflection = new \ReflectionClass(OptimisticLockException::class);
        $this->assertTrue($reflection->isFinal());
    }

    public function test_optimistic_lock_exception_for_factory(): void
    {
        $e = OptimisticLockException::for('order-123', expectedVersion: 5, actualVersion: 7);

        $this->assertStringContainsString('order-123', $e->getMessage());
        $this->assertStringContainsString('5', $e->getMessage());
        $this->assertStringContainsString('7', $e->getMessage());
    }

    public function test_custom_domain_exception_with_error_code(): void
    {
        $e = new TestOrderShippedException('Order X is already shipped', code: 'ORDER_SHIPPED');

        $this->assertSame('ORDER_SHIPPED', $e->errorCode());
        $this->assertInstanceOf(DomainException::class, $e);
    }

    // ─────────────────────────────────────────────────────────
    // Snapshot — Immutability & Round-trip
    // ─────────────────────────────────────────────────────────

    public function test_snapshot_is_final_readonly(): void
    {
        $reflection = new \ReflectionClass(Snapshot::class);

        $this->assertTrue($reflection->isFinal(), 'Snapshot must be final');
        $this->assertTrue($reflection->isReadOnly(), 'Snapshot must be readonly');
    }

    public function test_snapshot_round_trip(): void
    {
        $snapshot = Snapshot::create(
            'App\\Domain\\Order',
            '550e8400-e29b-41d4-a716-446655440000',
            42,
            ['status' => 'shipped', 'total' => 99.99],
        );

        $restored = Snapshot::fromArray($snapshot->toArray());

        $this->assertTrue($snapshot->equals($restored));
        $this->assertSame($snapshot->aggregateType, $restored->aggregateType);
        $this->assertSame($snapshot->aggregateId, $restored->aggregateId);
        $this->assertSame($snapshot->version, $restored->version);
        $this->assertSame($snapshot->state, $restored->state);
    }

    public function test_snapshot_json_serialization(): void
    {
        $snapshot = Snapshot::create('Order', 'id', 1, []);
        $json = json_encode($snapshot);

        $this->assertIsString($json);
        $this->assertNotFalse($json);
        $data = json_decode($json, true);
        $this->assertArrayHasKey('aggregate_type', $data);
        $this->assertArrayHasKey('version', $data);
    }

    // ─────────────────────────────────────────────────────────
    // Contracts — Return Types & Signatures
    // ─────────────────────────────────────────────────────────

    public function test_entity_contract_has_return_types(): void
    {
        $reflection = new \ReflectionClass(EntityContract::class);

        $idMethod = $reflection->getMethod('id');
        $this->assertSame('string', $idMethod->getReturnType()?->getName());

        $equalsMethod = $reflection->getMethod('equals');
        $this->assertSame('bool', $equalsMethod->getReturnType()?->getName());

        $toArrayMethod = $reflection->getMethod('toArray');
        $this->assertSame('array', $toArrayMethod->getReturnType()?->getName());
    }

    public function test_aggregate_root_contract_extends_entity(): void
    {
        $reflection = new \ReflectionClass(AggregateRootContract::class);

        $this->assertTrue($reflection->isSubclassOf(EntityContract::class));

        $this->assertTrue($reflection->hasMethod('version'));
        $this->assertTrue($reflection->hasMethod('incrementVersion'));
        $this->assertTrue($reflection->hasMethod('pullDomainEvents'));
        $this->assertTrue($reflection->hasMethod('clearDomainEvents'));
    }

    public function test_repository_contract_methods(): void
    {
        $reflection = new \ReflectionClass(RepositoryContract::class);

        $find = $reflection->getMethod('find');
        $this->assertSame('array', $find->getParameters()[0]->getType()?->getName() ?? 'string');

        $save = $reflection->getMethod('save');
        $this->assertSame('void', $save->getReturnType()?->getName());

        $delete = $reflection->getMethod('delete');
        $this->assertSame('void', $delete->getReturnType()?->getName());
    }

    public function test_unit_of_work_contract_run_has_mixed_return(): void
    {
        $reflection = new \ReflectionClass(UnitOfWorkContract::class);
        $run = $reflection->getMethod('run');

        $this->assertSame('mixed', $run->getReturnType()?->getName());
    }

    public function test_identifier_contract_has_stringable(): void
    {
        $reflection = new \ReflectionClass(IdentifierContract::class);

        $this->assertTrue($reflection->isSubclassOf(\Stringable::class));
        $this->assertTrue($reflection->hasMethod('fromString'));
        $this->assertTrue($reflection->hasMethod('toString'));
        $this->assertTrue($reflection->hasMethod('equals'));
    }

    // ─────────────────────────────────────────────────────────
    // PHP 8.5 Syntax — Override Attributes
    // ─────────────────────────────────────────────────────────

    public function test_entity_class_uses_php85_override_attribute(): void
    {
        $idMethod = new \ReflectionMethod(Entity::class, 'id');
        $attrs = $idMethod->getAttributes(\Override::class);
        $this->assertNotEmpty($attrs, 'Entity::id() should have #[Override] attribute');
    }

    public function test_aggregate_root_uses_php85_override_attributes(): void
    {
        $versionMethod = new \ReflectionMethod(AggregateRoot::class, 'version');
        $attrs = $versionMethod->getAttributes(\Override::class);
        $this->assertNotEmpty($attrs, 'AggregateRoot::version() should have #[Override]');

        $equalsMethod = new \ReflectionMethod(AggregateRoot::class, 'equals');
        $attrs = $equalsMethod->getAttributes(\Override::class);
        $this->assertNotEmpty($attrs, 'AggregateRoot::equals() should have #[Override]');
    }

    // ─────────────────────────────────────────────────────────
    // AggregateRoot — Abstract Constructor
    // ─────────────────────────────────────────────────────────

    public function test_aggregate_root_constructor_is_protected(): void
    {
        $constructor = new \ReflectionMethod(AggregateRoot::class, '__construct');
        $this->assertFalse($constructor->isPublic());
        $this->assertTrue($constructor->isProtected());
    }

    public function test_aggregate_root_to_array_includes_version(): void
    {
        $reflection = new \ReflectionClass(AggregateRoot::class);
        $instance = $reflection->newInstanceWithoutConstructor();

        // Set readonly properties via reflection
        $id = AggregateRootId::generate();
        $property = $reflection->getProperty('aggregateId');
        $property->setAccessible(true);
        $property->setValue($instance, $id);

        $idProp = (new \ReflectionClass(Entity::class))->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($instance, $id);

        $versionProp = $reflection->getProperty('version');
        $versionProp->setAccessible(true);
        $versionProp->setValue($instance, 5);

        $array = $instance->toArray();

        $this->assertArrayHasKey('id', $array);
        $this->assertArrayHasKey('version', $array);
        $this->assertSame(5, $array['version']);
        $this->assertArrayHasKey('type', $array);
    }
}

// ─────────────────────────────────────────────────────────
// Test Fixtures
// ─────────────────────────────────────────────────────────

/** @extends UuidIdentifier */
final class TestOrderId extends UuidIdentifier {}

/** @extends UuidIdentifier */
final class TestProductId extends UuidIdentifier
{
    public static function fromUlid(): self
    {
        // For testing purposes, just generate a UUID-based ID
        return self::generate();
    }
}

/** @extends DomainException */
final class TestOrderShippedException extends DomainException
{
    protected function defaultErrorCode(): string
    {
        return 'ORDER_ALREADY_SHIPPED';
    }

    public static function forOrder(string $orderId): self
    {
        return new self("Order {$orderId} has already been shipped.");
    }
}
