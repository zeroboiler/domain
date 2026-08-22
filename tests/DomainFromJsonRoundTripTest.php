<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace Tests\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\DomainEventCollection;
use ZeroBoiler\Domain\Entity;
use ZeroBoiler\Domain\Exceptions\DomainException;
use ZeroBoiler\Domain\Exceptions\InvalidArgumentDomainException;
use ZeroBoiler\Domain\Exceptions\InvalidStateDomainException;
use ZeroBoiler\Domain\Exceptions\NotFoundDomainException;
use ZeroBoiler\Domain\Identifiers\IntegerIdentifier;
use ZeroBoiler\Domain\Identifiers\StringIdentifier;
use ZeroBoiler\Domain\Identifiers\UuidIdentifier;
use ZeroBoiler\Domain\Identifiers\UlidIdentifier;
use ZeroBoiler\Domain\Identifiers\Identifier as BaseIdentifier;
use ZeroBoiler\Domain\Snapshot;
use ZeroBoiler\Domain\ValueObject;
use ZeroBoiler\Events\Domain\DomainEvent;

#[CoversClass(AggregateRootId::class)]
#[CoversClass(DomainEventCollection::class)]
#[CoversClass(Snapshot::class)]
#[CoversClass(DomainException::class)]
#[Group('serde')]
#[Group('fromJson')]
final class DomainFromJsonRoundTripTest extends TestCase
{
    // ────────────────────────────────────────────────────────
    // AggregateRootId::fromJson()
    // ────────────────────────────────────────────────────────

    public function test_aggregate_root_id_from_json_round_trip(): void
    {
        $id = AggregateRootId::generate();
        $json = json_encode($id->toArray(), JSON_THROW_ON_ERROR);

        $restored = AggregateRootId::fromJson($json);

        $this->assertTrue($id->equals($restored));
        $this->assertSame($id->toString(), $restored->toString());
    }

    public function test_aggregate_root_id_from_json_with_id_key(): void
    {
        $id = AggregateRootId::generate();
        $json = json_encode(['id' => $id->toString()], JSON_THROW_ON_ERROR);

        $restored = AggregateRootId::fromJson($json);

        $this->assertTrue($id->equals($restored));
    }

    public function test_aggregate_root_id_from_json_invalid_throws_json_exception(): void
    {
        $this->expectException(\JsonException::class);
        AggregateRootId::fromJson('{invalid-json');
    }

    public function test_aggregate_root_id_from_json_non_object_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('JSON must decode to an array/object');
        AggregateRootId::fromJson('"just-a-string"');
    }

    public function test_aggregate_root_id_from_json_missing_key_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AggregateRootId::fromJson('{"name":"foo"}');
    }

    // ────────────────────────────────────────────────────────
    // UuidIdentifier::fromJson()
    // ────────────────────────────────────────────────────────

    public function test_uuid_identifier_from_json_round_trip(): void
    {
        $id = UuidIdentifier::generate();
        $json = json_encode($id->toArray(), JSON_THROW_ON_ERROR);

        $restored = UuidIdentifier::fromJson($json);

        $this->assertTrue($id->equals($restored));
    }

    public function test_uuid_identifier_from_json_with_id_key(): void
    {
        $id = UuidIdentifier::generate();
        $json = json_encode(['id' => $id->toString()], JSON_THROW_ON_ERROR);

        $restored = UuidIdentifier::fromJson($json);

        $this->assertTrue($id->equals($restored));
    }

    // ────────────────────────────────────────────────────────
    // UlidIdentifier::fromJson()
    // ────────────────────────────────────────────────────────

    public function test_ulid_identifier_from_json_round_trip(): void
    {
        $id = UlidIdentifier::generate();
        $json = json_encode($id->toArray(), JSON_THROW_ON_ERROR);

        $restored = UlidIdentifier::fromJson($json);

        $this->assertTrue($id->equals($restored));
    }

    // ────────────────────────────────────────────────────────
    // StringIdentifier::fromJson()
    // ────────────────────────────────────────────────────────

    public function test_string_identifier_from_json_round_trip(): void
    {
        $id = StringIdentifier::from('my-slug');
        $json = json_encode($id->toArray(), JSON_THROW_ON_ERROR);

        $restored = StringIdentifier::fromJson($json);

        $this->assertTrue($id->equals($restored));
    }

    public function test_string_identifier_from_json_with_id_key(): void
    {
        $id = StringIdentifier::from('my-slug');
        $json = json_encode(['id' => 'my-slug'], JSON_THROW_ON_ERROR);

        $restored = StringIdentifier::fromJson($json);

        $this->assertTrue($id->equals($restored));
    }

    // ────────────────────────────────────────────────────────
    // IntegerIdentifier::fromJson()
    // ────────────────────────────────────────────────────────

    public function test_integer_identifier_from_json_round_trip(): void
    {
        $id = IntegerIdentifier::from(42);
        $json = json_encode($id->toArray(), JSON_THROW_ON_ERROR);

        $restored = IntegerIdentifier::fromJson($json);

        $this->assertTrue($id->equals($restored));
        $this->assertSame(42, $restored->toInt());
    }

    public function test_integer_identifier_from_json_negative(): void
    {
        $id = IntegerIdentifier::from(-7);
        $json = json_encode($id->toArray(), JSON_THROW_ON_ERROR);

        $restored = IntegerIdentifier::fromJson($json);

        $this->assertSame(-7, $restored->toInt());
    }

    public function test_integer_identifier_from_json_zero(): void
    {
        $id = IntegerIdentifier::from(0);
        $json = json_encode($id->toArray(), JSON_THROW_ON_ERROR);

        $restored = IntegerIdentifier::fromJson($json);

        $this->assertSame(0, $restored->toInt());
    }

    public function test_integer_identifier_from_json_with_string_id(): void
    {
        $json = json_encode(['id' => '99'], JSON_THROW_ON_ERROR);

        $restored = IntegerIdentifier::fromJson($json);

        $this->assertSame(99, $restored->toInt());
    }

    // ────────────────────────────────────────────────────────
    // Base Identifier::fromJson()
    // ────────────────────────────────────────────────────────

    public function test_base_identifier_from_json_round_trip(): void
    {
        $id = BaseIdentifier::generate();
        $json = json_encode($id->toArray(), JSON_THROW_ON_ERROR);

        $restored = BaseIdentifier::fromJson($json);

        $this->assertTrue($id->equals($restored));
    }

    // ────────────────────────────────────────────────────────
    // Snapshot::fromJson()
    // ────────────────────────────────────────────────────────

    public function test_snapshot_from_json_round_trip(): void
    {
        $snapshot = new Snapshot(
            aggregateType: 'Order',
            aggregateId: (string) AggregateRootId::generate(),
            version: 5,
            state: ['status' => 'paid', 'total' => 1999],
            createdAt: new \DateTimeImmutable('2024-01-15T10:30:00+00:00'),
        );

        $json = json_encode($snapshot->toArray(), JSON_THROW_ON_ERROR);

        $restored = Snapshot::fromJson($json);

        $this->assertSame($snapshot->aggregateType, $restored->aggregateType);
        $this->assertSame($snapshot->aggregateId, $restored->aggregateId);
        $this->assertSame($snapshot->version, $restored->version);
        $this->assertSame($snapshot->state, $restored->state);
        $this->assertSame($snapshot->createdAt->format(\DateTimeImmutable::ATOM), $restored->createdAt->format(\DateTimeImmutable::ATOM));
    }

    public function test_snapshot_from_json_invalid_throws(): void
    {
        $this->expectException(\JsonException::class);
        Snapshot::fromJson('not-json');
    }

    // ────────────────────────────────────────────────────────
    // DomainEventCollection::fromJson()
    // ────────────────────────────────────────────────────────

    public function test_domain_event_collection_from_json_round_trip(): void
    {
        $event1 = DomainEvent::occur(
            eventType: 'order.placed',
            aggregateId: (string) AggregateRootId::generate(),
            payload: ['total' => 500],
        );
        $event2 = DomainEvent::occur(
            eventType: 'order.paid',
            aggregateId: (event1)->aggregateId,
            payload: ['method' => 'credit_card'],
        );

        $collection = new DomainEventCollection([$event1, $event2]);
        $json = json_encode($collection->toArray(), JSON_THROW_ON_ERROR);

        $restored = DomainEventCollection::fromJson($json);

        $this->assertSame(2, $restored->count());
        $this->assertSame('order.placed', $restored->first()?->eventType);
        $this->assertSame('order.paid', $restored->last()?->eventType);
    }

    public function test_domain_event_collection_from_json_empty(): void
    {
        $collection = new DomainEventCollection;
        $json = json_encode($collection->toArray(), JSON_THROW_ON_ERROR);

        $restored = DomainEventCollection::fromJson($json);

        $this->assertSame(0, $restored->count());
    }

    // ────────────────────────────────────────────────────────
    // DomainException::fromJson()
    // ────────────────────────────────────────────────────────

    public function test_domain_exception_from_json_to_array_format(): void
    {
        $exception = new InvalidStateDomainException('Order is already paid');
        $json = json_encode($exception->toArray(), JSON_THROW_ON_ERROR);

        $restored = DomainException::fromJson($json, InvalidStateDomainException::class);

        $this->assertInstanceOf(InvalidStateDomainException::class, $restored);
        $this->assertSame('Order is already paid', $restored->getMessage());
        $this->assertSame('INVALID_STATE', $restored->errorCode());
    }

    public function test_domain_exception_from_json_error_array_format(): void
    {
        $exception = new NotFoundDomainException('User not found');
        $json = json_encode($exception->toErrorArray(), JSON_THROW_ON_ERROR);

        $restored = DomainException::fromJson($json, NotFoundDomainException::class);

        $this->assertInstanceOf(NotFoundDomainException::class, $restored);
        $this->assertSame('User not found', $restored->getMessage());
        $this->assertSame('NOT_FOUND', $restored->errorCode());
    }

    public function test_domain_exception_from_json_default_class(): void
    {
        $exception = new InvalidArgumentDomainException('Invalid email');
        $json = json_encode($exception->toErrorArray(), JSON_THROW_ON_ERROR);

        $restored = InvalidArgumentDomainException::fromJson($json);

        $this->assertInstanceOf(InvalidArgumentDomainException::class, $restored);
        $this->assertSame('Invalid email', $restored->getMessage());
    }

    public function test_domain_exception_from_json_invalid_throws(): void
    {
        $this->expectException(\JsonException::class);
        DomainException::fromJson('not-json');
    }

    // ────────────────────────────────────────────────────────
    // Cross-identifier fromJson inequality
    // ────────────────────────────────────────────────────────

    public function test_different_identifier_types_from_json_are_not_equal(): void
    {
        $uuidId = UuidIdentifier::generate();
        $ulidId = UlidIdentifier::generate();

        $uuidJson = json_encode($uuidId->toArray(), JSON_THROW_ON_ERROR);
        $ulidJson = json_encode($ulidId->toArray(), JSON_THROW_ON_ERROR);

        $restoredUuid = UuidIdentifier::fromJson($uuidJson);
        $restoredUlid = UlidIdentifier::fromJson($ulidJson);

        $this->assertFalse($restoredUuid->equals($restoredUlid));
    }

    // ────────────────────────────────────────────────────────
    // Entity::fromJson() — via concrete test entity
    // ────────────────────────────────────────────────────────

    public function test_entity_from_json_round_trip(): void
    {
        $entity = new TestEntity(
            id: '42',
            name: 'Test Item',
            quantity: 5,
        );

        $json = json_encode($entity->toArray(), JSON_THROW_ON_ERROR);

        $restored = TestEntity::fromJson($json);

        $this->assertSame('42', $restored->id());
        $this->assertSame('Test Item', $restored->name);
        $this->assertSame(5, $restored->quantity);
    }

    public function test_entity_from_json_invalid_json_throws(): void
    {
        $this->expectException(\JsonException::class);
        TestEntity::fromJson('not-json');
    }

    public function test_entity_from_json_non_object_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TestEntity::fromJson('"just-a-string"');
    }

    // ────────────────────────────────────────────────────────
    // ValueObject::fromJson() — via concrete test VO
    // ────────────────────────────────────────────────────────

    public function test_value_object_from_json_round_trip(): void
    {
        $vo = new TestValueObject(
            street: '123 Main St',
            city: 'NYC',
        );

        $json = json_encode($vo->toArray(), JSON_THROW_ON_ERROR);

        $restored = TestValueObject::fromJson($json);

        $this->assertTrue($vo->equals($restored));
        $this->assertSame('123 Main St', $restored->street);
        $this->assertSame('NYC', $restored->city);
    }
}

// ─── Concrete test doubles ─────────────────────────────────

final class TestEntity extends Entity
{
    public function __construct(
        int|string|\Stringable $id,
        public string $name = '',
        public int $quantity = 0,
    ) {
        parent::__construct($id);
    }

    public function toArray(): array
    {
        return [
            ...parent::toArray(),
            'name' => $this->name,
            'quantity' => $this->quantity,
        ];
    }
}

final class TestValueObject extends ValueObject
{
    public function __construct(
        public string $street = '',
        public string $city = '',
    ) {}

    public static function fromArray(array $data): static
    {
        return new static(
            street: $data['street'] ?? '',
            city: $data['city'] ?? '',
        );
    }

    public function toArray(): array
    {
        return [
            'street' => $this->street,
            'city' => $this->city,
        ];
    }
}
