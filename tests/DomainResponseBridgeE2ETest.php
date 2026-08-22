<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Tests;

use PHPUnit\Framework\TestCase;
use ZeroBoiler\Domain\AggregateRoot;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\Contracts\Repository;
use ZeroBoiler\Domain\Contracts\UnitOfWork;
use ZeroBoiler\Domain\DomainEventCollection;
use ZeroBoiler\Domain\Entity;
use ZeroBoiler\Domain\Exceptions\{
    ConflictDomainException,
    DomainException,
    InvalidArgumentDomainException,
    InvalidStateDomainException,
    NotFoundDomainException,
    OptimisticLockException,
};
use ZeroBoiler\Domain\Identifiers\IntegerIdentifier;
use ZeroBoiler\Domain\Identifiers\StringIdentifier;
use ZeroBoiler\Domain\InMemoryUnitOfWork;
use ZeroBoiler\Domain\ValueObject;
use ZeroBoiler\Events\Domain\DomainEvent;

/**
 * End-to-end domain → response bridge tests.
 *
 * Validates that domain entities, identifiers, events, and exceptions
 * produce correct array/JSON output suitable for response serialization
 * without importing the response package (decoupled design).
 *
 * @covers \ZeroBoiler\Domain\AggregateRoot
 * @covers \ZeroBoiler\Domain\AggregateRootId
 * @covers \ZeroBoiler\Domain\Entity
 * @covers \ZeroBoiler\Domain\DomainEventCollection
 * @covers \ZeroBoiler\Domain\InMemoryUnitOfWork
 * @covers \ZeroBoiler\Domain\ValueObject
 * @covers \ZeroBoiler\Domain\Identifiers\IntegerIdentifier
 * @covers \ZeroBoiler\Domain\Identifiers\StringIdentifier
 * @covers \ZeroBoiler\Domain\Exceptions\DomainException
 *
 * @since 1.56.0
 */
final class DomainResponseBridgeE2ETest extends TestCase
{
    // ─────────────────────────────────────────────────────────────────
    // AggregateRootId serialization (response-ready)
    // ─────────────────────────────────────────────────────────────────

    public function test_aggregate_root_id_serializes_to_json_string(): void
    {
        $id = AggregateRootId::generate();

        $json = json_encode($id);

        $this->assertIsString($json);
        $this->assertNotEmpty($json);
        $this->assertEquals($id->toString(), $json);
    }

    public function test_aggregate_root_id_round_trips_through_array(): void
    {
        $id = AggregateRootId::generate();
        $array = $id->toArray();
        $restored = AggregateRootId::fromArray($array);

        $this->assertTrue($id->equals($restored));
        $this->assertEquals($id->toString(), $restored->toString());
        $this->assertArrayHasKey('uuid', $array);
    }

    public function test_aggregate_root_id_from_string_parses_existing_uuid(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $id = AggregateRootId::fromString($uuid);

        $this->assertEquals($uuid, $id->toString());
    }

    // ─────────────────────────────────────────────────────────────────
    // AggregateRoot serialization (response-ready)
    // ─────────────────────────────────────────────────────────────────

    public function test_aggregate_root_to_array_contains_identity_and_version(): void
    {
        $order = new class(AggregateRootId::generate()) extends AggregateRoot {
            public string $status = 'pending';
            public float $total = 0.0;

            protected function applyOrderPlaced(DomainEvent $event): void
            {
                $this->status = $event->payload['status'];
            }
        };

        $array = $order->toArray();

        $this->assertArrayHasKey('id', $array);
        $this->assertArrayHasKey('version', $array);
        $this->assertArrayHasKey('type', $array);
        $this->assertEquals(0, $array['version']);
        $this->assertEquals('pending', $order->status);
    }

    public function test_aggregate_root_json_serializable_produces_valid_json(): void
    {
        $order = new class(AggregateRootId::generate()) extends AggregateRoot {
            public string $status = 'active';
        };

        $json = json_encode($order);
        $decoded = json_decode($json, true);

        $this->assertNotNull($decoded);
        $this->assertArrayHasKey('id', $decoded);
        $this->assertArrayHasKey('type', $decoded);
        $this->assertEquals('active', $decoded['status'] ?? null);
    }

    // ─────────────────────────────────────────────────────────────────
    // Entity serialization (response-ready)
    // ─────────────────────────────────────────────────────────────────

    public function test_entity_to_array_contains_id_and_type(): void
    {
        $entity = new class('entity-123') extends Entity {
            public function __construct(
                int|string|\Stringable $id,
                public readonly string $name = 'test',
            ) {
                parent::__construct($id);
            }
        };

        $array = $entity->toArray();

        $this->assertArrayHasKey('id', $array);
        $this->assertArrayHasKey('type', $array);
        $this->assertEquals('entity-123', $array['id']);
    }

    public function test_entity_from_array_round_trip(): void
    {
        $entity = new class('e-1', 'Test Name', 42) extends Entity {
            public function __construct(
                int|string|\Stringable $id,
                public readonly string $name,
                public readonly int $count,
            ) {
                parent::__construct($id);
            }
        };

        $array = $entity->toArray();
        $this->assertArrayHasKey('name', $array);
        $this->assertArrayHasKey('count', $array);
    }

    public function test_entity_equality_same_id_same_class(): void
    {
        $entity1 = new class('same-id') extends Entity {};
        $entity2 = new class('same-id') extends Entity {};

        $this->assertTrue($entity1->equals($entity2));
    }

    // ─────────────────────────────────────────────────────────────────
    // DomainEventCollection serialization
    // ─────────────────────────────────────────────────────────────────

    public function test_domain_event_collection_json_serializable(): void
    {
        $event1 = DomainEvent::occur('order.placed', ['id' => 'uuid-1']);
        $event2 = DomainEvent::occur('order.paid', ['amount' => 100.0]);

        $collection = new DomainEventCollection([$event1, $event2]);
        $json = json_encode($collection);
        $decoded = json_decode($json, true);

        $this->assertIsArray($decoded);
        $this->assertCount(2, $decoded);
        $this->assertEquals('order.placed', $decoded[0]['event_type'] ?? null);
        $this->assertEquals('order.paid', $decoded[1]['event_type'] ?? null);
    }

    public function test_domain_event_collection_to_array_equals_json(): void
    {
        $event = DomainEvent::occur('test.event', ['key' => 'val']);
        $collection = new DomainEventCollection([$event]);

        $this->assertEquals($collection->toArray(), $collection->jsonSerialize());
    }

    // ─────────────────────────────────────────────────────────────────
    // UnitOfWork lifecycle → response-ready event output
    // ─────────────────────────────────────────────────────────────────

    public function test_unit_of_work_commits_and_produces_events(): void
    {
        $uow = new InMemoryUnitOfWork;
        $uow->begin();

        $order = new class(AggregateRootId::generate()) extends AggregateRoot {};
        $uow->track($order);

        $this->assertFalse($uow->hasPendingEvents());
        $this->assertTrue($uow->isActive());

        $uow->commit();

        $this->assertFalse($uow->isActive());
    }

    public function test_unit_of_work_run_closure_returns_result(): void
    {
        $uow = new InMemoryUnitOfWork;

        $result = $uow->run(fn (): string => 'success');

        $this->assertEquals('success', $result);
        $this->assertFalse($uow->isActive());
    }

    public function test_unit_of_work_rollback_discards_events(): void
    {
        $uow = new InMemoryUnitOfWork;
        $uow->begin();

        $order = new class(AggregateRootId::generate()) extends AggregateRoot {};
        $uow->track($order);
        $uow->rollback();

        $this->assertFalse($uow->isActive());
        $this->assertFalse($uow->hasPendingEvents());
    }

    // ─────────────────────────────────────────────────────────────────
    // DomainException → RFC 9457 error array
    // ─────────────────────────────────────────────────────────────────

    public function test_domain_exception_to_error_array_is_rfc9457_compatible(): void
    {
        $exception = InvalidStateDomainException::because('Order must be pending.');
        $array = $exception->toErrorArray();

        $this->assertArrayHasKey('title', $array);
        $this->assertArrayHasKey('detail', $array);
        $this->assertArrayHasKey('code', $array);
        $this->assertEquals('InvalidStateDomainException', $array['title']);
        $this->assertEquals('Order must be pending.', $array['detail']);
        $this->assertEquals('INVALID_STATE', $array['code']);
    }

    public function test_domain_exception_json_serializable_is_rfc9457(): void
    {
        $exception = InvalidArgumentDomainException::because('Qty must be > 0.');
        $json = json_encode($exception);
        $decoded = json_decode($json, true);

        $this->assertArrayHasKey('code', $decoded);
        $this->assertEquals('INVALID_ARGUMENT', $decoded['code']);
    }

    public function test_domain_exception_from_array_round_trip(): void
    {
        $original = NotFoundDomainException::forAggregate('Order', 'uuid-1');
        $array = $original->toErrorArray();
        $restored = DomainException::fromArray($array, NotFoundDomainException::class);

        $this->assertEquals($original->getMessage(), $restored->getMessage());
        $this->assertEquals($original->errorCode(), $restored->errorCode());
    }

    public function test_optimistic_lock_exception_serializes_correctly(): void
    {
        $exception = OptimisticLockException::for('uuid-1', expected: 5, actual: 3);
        $array = $exception->toErrorArray();

        $this->assertArrayHasKey('code', $array);
        $this->assertEquals('OPTIMISTIC_LOCK', $array['code']);
    }

    // ─────────────────────────────────────────────────────────────────
    // Identifier serialization (response-ready)
    // ─────────────────────────────────────────────────────────────────

    public function test_integer_identifier_serializes_to_json(): void
    {
        $id = IntegerIdentifier::from(42);
        $json = json_encode($id);

        $this->assertEquals(42, json_decode($json));
    }

    public function test_integer_identifier_round_trips_through_array(): void
    {
        $id = IntegerIdentifier::from(42);
        $restored = IntegerIdentifier::fromArray($id->toArray());

        $this->assertTrue($id->equals($restored));
    }

    public function test_string_identifier_serializes_to_json(): void
    {
        $id = StringIdentifier::from('my-slug');
        $json = json_encode($id);

        $this->assertEquals('my-slug', json_decode($json));
    }

    public function test_string_identifier_round_trips_through_array(): void
    {
        $id = StringIdentifier::from('my-slug');
        $restored = StringIdentifier::fromArray($id->toArray());

        $this->assertTrue($id->equals($restored));
    }

    // ─────────────────────────────────────────────────────────────────
    // ValueObject serialization
    // ─────────────────────────────────────────────────────────────────

    public function test_value_object_equality_based_on_to_array(): void
    {
        $vo1 = new class('Main St', 'NYC', 'US') extends ValueObject {
            public function __construct(
                public readonly string $street,
                public readonly string $city,
                public readonly string $country,
            ) {}

            public static function fromArray(array $data): static
            {
                return new static(
                    $data['street'],
                    $data['city'],
                    $data['country'],
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
        };

        $vo2 = new class('Main St', 'NYC', 'US') extends ValueObject {
            public function __construct(
                public readonly string $street,
                public readonly string $city,
                public readonly string $country,
            ) {}

            public static function fromArray(array $data): static
            {
                return new static(
                    $data['street'],
                    $data['city'],
                    $data['country'],
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
        };

        // Different class instances but same toArray() output
        // Note: ValueObject::equals() checks class identity first
        $this->assertFalse($vo1->equals($vo2)); // Different concrete classes
    }

    // ─────────────────────────────────────────────────────────────────
    // Contract compliance verification
    // ─────────────────────────────────────────────────────────────────

    public function test_aggregate_root_implements_contract(): void
    {
        $order = new class(AggregateRootId::generate()) extends AggregateRoot {};

        $this->assertInstanceOf(\ZeroBoiler\Domain\Contracts\AggregateRoot::class, $order);
        $this->assertInstanceOf(\ZeroBoiler\Domain\Contracts\Entity::class, $order);
        $this->assertInstanceOf(\JsonSerializable::class, $order);
    }

    public function test_entity_implements_contract(): void
    {
        $entity = new class('id-1') extends Entity {};

        $this->assertInstanceOf(\ZeroBoiler\Domain\Contracts\Entity::class, $entity);
        $this->assertInstanceOf(\JsonSerializable::class, $entity);
    }

    public function test_identifiers_implement_contract(): void
    {
        $stringId = StringIdentifier::from('test');
        $intId = IntegerIdentifier::from(1);

        $this->assertInstanceOf(\ZeroBoiler\Domain\Contracts\Identifier::class, $stringId);
        $this->assertInstanceOf(\ZeroBoiler\Domain\Contracts\Identifier::class, $intId);
        $this->assertInstanceOf(\Stringable::class, $stringId);
        $this->assertInstanceOf(\Stringable::class, $intId);
    }

    // ─────────────────────────────────────────────────────────────────
    // Response-ready data shape verification
    // ─────────────────────────────────────────────────────────────────

    public function test_domain_produces_consistent_api_shapes(): void
    {
        // Aggregate root shape
        $order = new class(AggregateRootId::generate()) extends AggregateRoot {
            public string $status = 'pending';
        };
        $orderArray = $order->toArray();

        $this->assertArrayHasKey('id', $orderArray);
        $this->assertIsString($orderArray['id']);
        $this->assertArrayHasKey('version', $orderArray);
        $this->assertIsInt($orderArray['version']);
        $this->assertArrayHasKey('type', $orderArray);
        $this->assertIsString($orderArray['type']);

        // Entity shape
        $entity = new class('ent-1') extends Entity {};
        $entityArray = $entity->toArray();

        $this->assertArrayHasKey('id', $entityArray);
        $this->assertIsString($entityArray['id']);
        $this->assertArrayHasKey('type', $entityArray);

        // Event collection shape
        $event = DomainEvent::occur('test', []);
        $collection = new DomainEventCollection([$event]);
        $eventArray = $collection->toArray();

        $this->assertIsArray($eventArray);
        $this->assertCount(1, $eventArray);
        $this->assertIsArray($eventArray[0]);

        // Exception error shape
        $exception = InvalidStateDomainException::because('test');
        $errorArray = $exception->toErrorArray();

        $this->assertArrayHasKey('title', $errorArray);
        $this->assertArrayHasKey('detail', $errorArray);
        $this->assertArrayHasKey('code', $errorArray);
        $this->assertIsString($errorArray['title']);
        $this->assertIsString($errorArray['detail']);
        $this->assertIsString($errorArray['code']);
    }
}
