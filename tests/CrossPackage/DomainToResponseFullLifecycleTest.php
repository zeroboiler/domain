<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Tests\CrossPackage;

use PHPUnit\Framework\TestCase;
use ZeroBoiler\Domain\AggregateRoot;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\Contracts\Identifier;
use ZeroBoiler\Domain\DomainEventCollection;
use ZeroBoiler\Domain\Entity;
use ZeroBoiler\Domain\Exceptions\ConflictDomainException;
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
 * Comprehensive cross-package integration test.
 *
 * Documents and verifies the full domain → response mapping lifecycle:
 *
 * 1. Aggregate Root creation and event recording
 * 2. Entity identity and equality semantics
 * 3. Identifier round-trip serialization
 * 4. Value object structural equality
 * 5. Domain exception → RFC 9457 error array mapping
 * 6. DomainEventCollection functional operations
 * 7. Snapshot round-trip serialization
 *
 * This test exercises the domain package's public API surface area
 * that the response package's DomainTransformer and DomainResponseFactory
 * consume via duck typing (method_exists, instanceof \Stringable).
 *
 * @see \ZeroBoiler\Response\Transformers\DomainTransformer
 * @see \ZeroBoiler\Response\Transformers\DomainResponseFactory
 * @see \ZeroBoiler\Response\Concerns\ExtractsDomainId
 *
 * @since 1.69.0
 */
final class DomainToResponseFullLifecycleTest extends TestCase
{
    // ─────────────────────────────────────────────────────────
    // 1. Aggregate Root Identity Contract
    // ─────────────────────────────────────────────────────────

    public function test_aggregate_root_provides_string_identity(): void
    {
        $id = AggregateRootId::generate();
        $root = TestAggregate::create($id);

        // DomainTransformer::extractId() uses id() method
        $this->assertIsString($root->id());
        $this->assertSame($id->toString(), $root->id());
    }

    public function test_aggregate_root_provides_version(): void
    {
        $root = TestAggregate::create(AggregateRootId::generate());
        $this->assertSame(0, $root->version());

        $root->recordEvent();
        $this->assertSame(1, $root->version());
    }

    public function test_aggregate_root_equality_is_identity_based(): void
    {
        $id = AggregateRootId::generate();
        $root1 = TestAggregate::create($id);
        $root2 = TestAggregate::create($id);

        $this->assertTrue($root1->equals($root2));

        $root3 = TestAggregate::create(AggregateRootId::generate());
        $this->assertFalse($root1->equals($root3));
    }

    public function test_aggregate_root_to_array_includes_identity_and_version(): void
    {
        $id = AggregateRootId::generate();
        $root = TestAggregate::create($id);

        $array = $root->toArray();

        $this->assertArrayHasKey('id', $array);
        $this->assertArrayHasKey('version', $array);
        $this->assertArrayHasKey('type', $array);
        $this->assertSame($id->toString(), $array['id']);
        $this->assertSame(0, $array['version']);
        $this->assertSame('TestAggregate', $array['type']);
    }

    public function test_aggregate_root_json_serialization_round_trip(): void
    {
        $root = TestAggregate::create(AggregateRootId::generate());
        $root->recordEvent();

        $json = $root->toJson();
        $decoded = json_decode($json, true);

        $this->assertSame($root->id(), $decoded['id']);
        $this->assertSame(1, $decoded['version']);
    }

    // ─────────────────────────────────────────────────────────
    // 2. Entity Identity Contract
    // ─────────────────────────────────────────────────────────

    public function test_entity_supports_multiple_id_types(): void
    {
        $intEntity = new TestEntity(42);
        $this->assertSame('42', $intEntity->id());

        $stringEntity = new TestEntity('slug-123');
        $this->assertSame('slug-123', $stringEntity->id());

        $uuidEntity = new TestEntity(UuidIdentifier::generate());
        $this->assertIsString($uuidEntity->id());
    }

    public function test_entity_equality_requires_same_class_and_id(): void
    {
        $entity1 = new TestEntity(42);
        $entity2 = new TestEntity(42);
        $entity3 = new TestEntity(99);

        $this->assertTrue($entity1->equals($entity2));
        $this->assertFalse($entity1->equals($entity3));
    }

    public function test_entity_json_serialize_delegates_to_to_array(): void
    {
        $entity = new TestEntity(1);
        $entity->name = 'Test';

        $serialized = $entity->jsonSerialize();
        $this->assertSame('1', $serialized['id']);
        $this->assertSame('TestEntity', $serialized['type']);
    }

    // ─────────────────────────────────────────────────────────
    // 3. Identifier Round-Trip Serialization
    // ─────────────────────────────────────────────────────────

    public function test_uuid_identifier_serialization_round_trip(): void
    {
        $id = TestUuidIdentifier::generate();
        $array = $id->toArray();
        $restored = TestUuidIdentifier::fromArray($array);

        $this->assertTrue($id->equals($restored));
        $this->assertSame($id->toString(), $restored->toString());
    }

    public function test_ulid_identifier_serialization_round_trip(): void
    {
        $id = TestUlidIdentifier::generate();
        $array = $id->toArray();
        $restored = TestUlidIdentifier::fromArray($array);

        $this->assertTrue($id->equals($restored));
        $this->assertSame($id->toString(), $restored->toString());
    }

    public function test_string_identifier_serialization_round_trip(): void
    {
        $id = StringIdentifier::from('my-slug');
        $array = $id->toArray();
        $restored = StringIdentifier::fromArray($array);

        $this->assertTrue($id->equals($restored));
        $this->assertSame('my-slug', $restored->toString());
    }

    public function test_integer_identifier_serialization_round_trip(): void
    {
        $id = IntegerIdentifier::from(42);
        $array = $id->toArray();
        $restored = IntegerIdentifier::fromArray($array);

        $this->assertTrue($id->equals($restored));
        $this->assertSame(42, $restored->toInt());
    }

    public function test_identifier_json_round_trip(): void
    {
        $id = TestUuidIdentifier::generate();
        $json = $id->toJson();
        $restored = TestUuidIdentifier::fromJson($json);

        $this->assertTrue($id->equals($restored));
    }

    public function test_identifiers_are_json_serializable(): void
    {
        $uuid = TestUuidIdentifier::generate();
        $ulid = TestUlidIdentifier::generate();
        $str = StringIdentifier::from('slug');
        $int = IntegerIdentifier::from(42);
        $rootId = AggregateRootId::generate();

        // Verify all identifiers can be json_encoded directly
        $this->assertIsString(json_encode($uuid));
        $this->assertIsString(json_encode($ulid));
        $this->assertIsString(json_encode($str));
        $this->assertIsString(json_encode($int));
        $this->assertIsString(json_encode($rootId));
    }

    public function test_identifiers_are_stringable(): void
    {
        $id = TestUuidIdentifier::generate();
        $this->assertSame($id->toString(), (string) $id);

        $rootId = AggregateRootId::generate();
        $this->assertSame($rootId->toString(), (string) $rootId);
    }

    public function test_cross_type_identifiers_are_never_equal(): void
    {
        $uuid = TestUuidIdentifier::generate();
        $ulid = TestUlidIdentifier::generate();
        $str = StringIdentifier::from('test');
        $int = IntegerIdentifier::from(1);

        $this->assertFalse($uuid->equals($ulid));
        $this->assertFalse($uuid->equals($str));
        $this->assertFalse($uuid->equals($int));
    }

    // ─────────────────────────────────────────────────────────
    // 4. Value Object Structural Equality
    // ─────────────────────────────────────────────────────────

    public function test_value_object_equality_is_structural(): void
    {
        $vo1 = TestAddress::fromArray(['street' => '123 Main', 'city' => 'NYC', 'country' => 'US']);
        $vo2 = TestAddress::fromArray(['street' => '123 Main', 'city' => 'NYC', 'country' => 'US']);
        $vo3 = TestAddress::fromArray(['street' => '456 Oak', 'city' => 'NYC', 'country' => 'US']);

        $this->assertTrue($vo1->equals($vo2));
        $this->assertFalse($vo1->equals($vo3));
    }

    public function test_value_object_json_serialization_round_trip(): void
    {
        $vo = TestAddress::fromArray(['street' => '123 Main', 'city' => 'NYC', 'country' => 'US']);
        $json = $vo->toJson();
        $restored = TestAddress::fromJson($json);

        $this->assertTrue($vo->equals($restored));
    }

    // ─────────────────────────────────────────────────────────
    // 5. Domain Exception → RFC 9457 Error Array Mapping
    // ─────────────────────────────────────────────────────────

    public function test_domain_exceptions_provide_rfc9457_error_arrays(): void
    {
        $exceptions = [
            InvalidStateDomainException::because('State violation'),
            InvalidArgumentDomainException::because('Invalid argument'),
            NotFoundDomainException::because('Not found'),
            ConflictDomainException::because('Conflict detected'),
            OptimisticLockException::for('order-1', 5, 3),
        ];

        foreach ($exceptions as $exception) {
            $errorArray = $exception->toErrorArray();

            $this->assertArrayHasKey('title', $errorArray, get_class($exception) . '::title');
            $this->assertArrayHasKey('detail', $errorArray, get_class($exception) . '::detail');
            $this->assertArrayHasKey('code', $errorArray, get_class($exception) . '::code');
            $this->assertArrayHasKey('status', $errorArray, get_class($exception) . '::status');
            $this->assertIsInt($errorArray['status']);
            $this->assertIsString($errorArray['code']);
            $this->assertNotEmpty($errorArray['code']);
        }
    }

    public function test_domain_exceptions_auto_detect_http_status(): void
    {
        $this->assertSame(422, InvalidStateDomainException::because('x')->httpStatus());
        $this->assertSame(422, InvalidArgumentDomainException::because('x')->httpStatus());
        $this->assertSame(404, NotFoundDomainException::because('x')->httpStatus());
        $this->assertSame(409, ConflictDomainException::because('x')->httpStatus());
        $this->assertSame(409, OptimisticLockException::for('x', 1, 2)->httpStatus());
    }

    public function test_domain_exceptions_json_serialization(): void
    {
        $exception = InvalidStateDomainException::because('Test error', 'CUSTOM_CODE');

        $json = $exception->toJson();
        $decoded = json_decode($json, true);

        $this->assertSame('InvalidStateDomainException', $decoded['title']);
        $this->assertSame('Test error', $decoded['detail']);
        $this->assertSame('CUSTOM_CODE', $decoded['code']);
        $this->assertSame(422, $decoded['status']);
    }

    public function test_domain_exceptions_from_json_round_trip(): void
    {
        $original = NotFoundDomainException::forAggregate('Order', 'uuid-123');

        $json = $original->toJson();
        $restored = NotFoundDomainException::fromJson($json, NotFoundDomainException::class);

        $this->assertSame($original->getMessage(), $restored->getMessage());
        $this->assertSame($original->errorCode(), $restored->errorCode());
    }

    // ─────────────────────────────────────────────────────────
    // 6. DomainEventCollection Functional Operations
    // ─────────────────────────────────────────────────────────

    public function test_event_collection_supports_functional_operations(): void
    {
        $e1 = DomainEvent::occur('order.placed', ['id' => '1']);
        $e2 = DomainEvent::occur('order.item_added', ['product_id' => 'p1']);
        $e3 = DomainEvent::occur('order.paid', ['amount' => 100]);
        $e4 = DomainEvent::occur('order.placed', ['id' => '2']);

        $collection = new DomainEventCollection([$e1, $e2, $e3, $e4]);

        // some / none
        $this->assertTrue($collection->some(fn (DomainEvent $e): bool => $e->eventType === 'order.placed'));
        $this->assertFalse($collection->none(fn (DomainEvent $e): bool => $e->eventType === 'order.placed'));
        $this->assertTrue($collection->none(fn (DomainEvent $e): bool => $e->eventType === 'order.shipped'));

        // countBy
        $this->assertSame(2, $collection->countBy(fn (DomainEvent $e): bool => $e->eventType === 'order.placed'));

        // hasType
        $this->assertTrue($collection->hasType('order.paid'));
        $this->assertFalse($collection->hasType('order.shipped'));

        // types
        $types = $collection->types();
        $this->assertSame(['order.placed', 'order.item_added', 'order.paid'], $types);

        // reduce
        $total = $collection->reduce(
            fn (int $sum, DomainEvent $e): int => $sum + ($e->payload['amount'] ?? 0),
            0,
        );
        $this->assertSame(100, $total);

        // find
        $found = $collection->find(fn (DomainEvent $e): bool => $e->eventType === 'order.paid');
        $this->assertNotNull($found);
        $this->assertSame(100, $found->payload['amount']);

        // each (side effects)
        $collected = [];
        $collection->each(function (DomainEvent $e, int $index) use (&$collected): void {
            $collected[] = $index . ':' . $e->eventType;
        });
        $this->assertSame(['0:order.placed', '1:order.item_added', '2:order.paid', '3:order.placed'], $collected);
    }

    public function test_event_collection_serialization_round_trip(): void
    {
        $e1 = DomainEvent::occur('order.placed', ['id' => '1']);
        $e2 = DomainEvent::occur('order.item_added', ['product_id' => 'p1']);

        $collection = new DomainEventCollection([$e1, $e2]);
        $array = $collection->toArray();

        $this->assertIsArray($array);
        $this->assertCount(2, $array);

        // Verify JSON serialization
        $json = json_encode($collection);
        $this->assertIsString($json);
        $this->assertNotSame('[]', $json);
    }

    // ─────────────────────────────────────────────────────────
    // 7. Snapshot Round-Trip Serialization
    // ─────────────────────────────────────────────────────────

    public function test_snapshot_serialization_round_trip(): void
    {
        $state = ['status' => 'pending', 'total' => 1999, 'items' => 3];
        $snapshot = Snapshot::create('App\\Domain\\Order', 'uuid-123', 5, $state);

        $array = $snapshot->toArray();
        $restored = Snapshot::fromArray($array);

        $this->assertTrue($snapshot->equals($restored));
        $this->assertSame('App\\Domain\\Order', $restored->aggregateType);
        $this->assertSame('uuid-123', $restored->aggregateId);
        $this->assertSame(5, $restored->version);
        $this->assertSame($state, $restored->state);
    }

    public function test_snapshot_json_serialization(): void
    {
        $snapshot = Snapshot::create('App\\Domain\\Order', 'uuid-123', 10, ['status' => 'shipped']);

        $json = $snapshot->toJson();
        $restored = Snapshot::fromJson($json);

        $this->assertTrue($snapshot->equals($restored));
    }

    // ─────────────────────────────────────────────────────────
    // 8. AggregateRootId — Response Package Contract
    // ─────────────────────────────────────────────────────────

    public function test_aggregate_root_id_is_fully_serializable(): void
    {
        $id = AggregateRootId::generate();

        // Stringable (used by ExtractsDomainId trait)
        $this->assertIsString((string) $id);

        // JsonSerializable (used by DomainTransformer)
        $this->assertIsString(json_encode($id));

        // toArray/fromArray round-trip
        $restored = AggregateRootId::fromArray($id->toArray());
        $this->assertTrue($id->equals($restored));

        // toJson/fromJson round-trip
        $json = $id->toJson();
        $fromJson = AggregateRootId::fromJson($json);
        $this->assertTrue($id->equals($fromJson));
    }

    // ─────────────────────────────────────────────────────────
    // 9. Contract Compliance Verification
    // ─────────────────────────────────────────────────────────

    public function test_all_identifiers_implement_identifier_contract(): void
    {
        $identifiers = [
            TestUuidIdentifier::generate(),
            TestUlidIdentifier::generate(),
            StringIdentifier::from('test'),
            IntegerIdentifier::from(1),
        ];

        foreach ($identifiers as $id) {
            $this->assertInstanceOf(Identifier::class, $id, get_class($id) . ' must implement Identifier');
            $this->assertInstanceOf(\Stringable::class, $id, get_class($id) . ' must be Stringable');
            $this->assertInstanceOf(\JsonSerializable::class, $id, get_class($id) . ' must be JsonSerializable');
        }
    }

    public function test_domain_exceptions_are_json_serializable(): void
    {
        $exceptions = [
            InvalidStateDomainException::because('x'),
            InvalidArgumentDomainException::because('x'),
            NotFoundDomainException::because('x'),
            ConflictDomainException::because('x'),
            OptimisticLockException::for('x', 1, 2),
        ];

        foreach ($exceptions as $e) {
            $this->assertInstanceOf(\JsonSerializable::class, $e);
            $json = json_encode($e);
            $this->assertIsString($json);
            $this->assertNotEmpty($json);
            $this->assertNotSame('[]', $json);
        }
    }
}

// ─────────────────────────────────────────────────────────────
// Test Fixtures
// ─────────────────────────────────────────────────────────────

/**
 * Test aggregate root for lifecycle testing.
 */
final class TestAggregate extends AggregateRoot
{
    public static function create(AggregateRootId $id): self
    {
        return new self($id);
    }

    public function recordEvent(): void
    {
        $this->apply(DomainEvent::occur('test.event', ['id' => $this->id()]));
    }

    protected function applyTestEvent(DomainEvent $event): void {}
}

/**
 * Test address value object.
 */
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

/**
 * Test entity for identity testing.
 */
final class TestEntity extends Entity
{
    public function __construct(
        int|string|\Stringable $id,
        public ?string $name = null,
    ) {
        parent::__construct($id);
    }
}
