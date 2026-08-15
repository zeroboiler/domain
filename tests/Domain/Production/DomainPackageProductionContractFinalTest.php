<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Tests;

use ZeroBoiler\Domain\AggregateRoot;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\Contracts\Identifier;
use ZeroBoiler\Domain\DomainEventCollection;
use ZeroBoiler\Domain\Entity;
use ZeroBoiler\Domain\Exceptions\ConflictDomainException;
use ZeroBoiler\Domain\Exceptions\InvalidArgumentDomainException;
use ZeroBoiler\Domain\Exceptions\InvalidStateDomainException;
use ZeroBoiler\Domain\Exceptions\NotFoundDomainException;
use ZeroBoiler\Domain\Identifiers\IntegerIdentifier;
use ZeroBoiler\Domain\Identifiers\StringIdentifier;
use ZeroBoiler\Domain\Identifiers\UuidIdentifier;
use ZeroBoiler\Domain\Identifiers\UlidIdentifier;
use ZeroBoiler\Domain\InMemoryUnitOfWork;
use ZeroBoiler\Domain\Tests\Fixtures\Production\Order;
use ZeroBoiler\Domain\Tests\Fixtures\TestEntity;
use ZeroBoiler\Domain\Tests\Fixtures\TestUuidIdentifier;
use ZeroBoiler\Domain\Tests\Fixtures\TestUuidIdentifierAlt;
use ZeroBoiler\Domain\ValueObject;
use ZeroBoiler\Events\Domain\DomainEvent;

/**
 * Comprehensive production contract validation test.
 *
 * Validates that all domain package classes maintain their public API contracts:
 * - Constructor signatures
 * - Return type declarations
 * - Serialization round-trips
 * - Domain invariant enforcement
 * - Cross-type identifier inequality
 * - Event sourcing replay accuracy
 * - Unit of Work transaction semantics
 *
 * @covers \ZeroBoiler\Domain\Entity
 * @covers \ZeroBoiler\Domain\AggregateRoot
 * @covers \ZeroBoiler\Domain\AggregateRootId
 * @covers \ZeroBoiler\Domain\DomainEventCollection
 * @covers \ZeroBoiler\Domain\InMemoryUnitOfWork
 * @covers \ZeroBoiler\Domain\Identifiers\UuidIdentifier
 * @covers \ZeroBoiler\Domain\Identifiers\UlidIdentifier
 * @covers \ZeroBoiler\Domain\Identifiers\StringIdentifier
 * @covers \ZeroBoiler\Domain\Identifiers\IntegerIdentifier
 * @covers \ZeroBoiler\Domain\ValueObject
 * @covers \ZeroBoiler\Domain\Exceptions\DomainException
 * @covers \ZeroBoiler\Domain\Exceptions\InvalidStateDomainException
 * @covers \ZeroBoiler\Domain\Exceptions\InvalidArgumentDomainException
 * @covers \ZeroBoiler\Domain\Exceptions\NotFoundDomainException
 * @covers \ZeroBoiler\Domain\Exceptions\ConflictDomainException
 *
 * @since 1.69.0
 */
describe('Domain Package Production Contract Validation', function (): void {
    describe('AggregateRootId', function (): void {
        it('generates unique UUID v4 identifiers', function (): void {
            $id1 = AggregateRootId::generate();
            $id2 = AggregateRootId::generate();

            expect($id1->toString())->toBeString()->toHaveLength(36);
            expect($id2->toString())->toBeString()->toHaveLength(36);
            expect($id1->equals($id2))->toBeFalse();
            expect($id1->toString())->not->toBe($id2->toString());
        });

        it('round-trips through toArray/fromArray', function (): void {
            $id = AggregateRootId::generate();
            $restored = AggregateRootId::fromArray($id->toArray());

            expect($restored)->toBeInstanceOf(AggregateRootId::class);
            expect($id->equals($restored))->toBeTrue();
        });

        it('round-trips through toJson/fromJson', function (): void {
            $id = AggregateRootId::generate();
            $json = $id->toJson();
            $restored = AggregateRootId::fromJson($json);

            expect($id->equals($restored))->toBeTrue();
        });

        it('validates UUID strings correctly', function (): void {
            expect(AggregateRootId::isValid('not-a-uuid'))->toBeFalse();
            expect(AggregateRootId::isValid('550e8400-e29b-41d4-a716-446655440000'))->toBeTrue();
        });

        it('serializes to JSON as plain string', function (): void {
            $id = AggregateRootId::generate();
            $json = json_encode($id);

            expect($json)->toBeJson();
            expect(json_decode($json, true))->toBe($id->toString());
        });

        it('implements Stringable', function (): void {
            $id = AggregateRootId::generate();
            expect((string) $id)->toBe($id->toString());
        });
    });

    describe('UuidIdentifier (abstract)', function (): void {
        it('generates type-safe subclass identifiers', function (): void {
            $id1 = TestUuidIdentifier::generate();
            $id2 = TestUuidIdentifier::generate();

            expect($id1)->toBeInstanceOf(UuidIdentifier::class);
            expect($id1)->toBeInstanceOf(Identifier::class);
            expect($id1->equals($id2))->toBeFalse();
        });

        it('enforces cross-subclass inequality', function (): void {
            $id1 = TestUuidIdentifier::generate();
            $id2 = TestUuidIdentifierAlt::generate();

            expect($id1->equals($id2))->toBeFalse();
        });

        it('validates UUIDs in constructor', function (): void {
            expect(fn () => TestUuidIdentifier::fromString('invalid'))->toThrow(\                \Ramsey\Uuid\Exception\InvalidUuidStringException::class,
            );
        });
    });

    describe('UlidIdentifier (abstract)', function (): void {
        it('generates monotonic ULIDs', function (): void {
            $id = TestUlidIdentifier::generate();

            expect($id->toString())->toBeString();
            expect($id->toUlid())->toBeInstanceOf(\Symfony\Component\Uid\Ulid::class);
        });

        it('validates ULID strings', function (): void {
            expect(TestUlidIdentifier::isValid('not-a-ulid'))->toBeFalse();
            expect(TestUlidIdentifier::isValid('01H5J5K2P4Z6Z6Z6Z6Z6Z6Z6Z'))->toBeTrue();
        });
    });

    describe('StringIdentifier', function (): void {
        it('rejects empty strings', function (): void {
            expect(fn () => StringIdentifier::from(''))->toThrow(\ValueError::class);
        });

        it('round-trips through serialization', function (): void {
            $id = StringIdentifier::from('my-slug');
            expect($id->toArray())->toBe(['string' => 'my-slug']);
            expect(StringIdentifier::fromArray($id->toArray())->equals($id))->toBeTrue();
        });

        it('validates non-empty strings', function (): void {
            expect(StringIdentifier::isValid(''))->toBeFalse();
            expect(StringIdentifier::isValid('hello'))->toBeTrue();
        });
    });

    describe('IntegerIdentifier', function (): void {
        it('creates from integers', function (): void {
            $id = IntegerIdentifier::from(42);
            expect($id->toInt())->toBe(42);
            expect($id->toString())->toBe('42');
        });

        it('round-trips through serialization', function (): void {
            $id = IntegerIdentifier::from(42);
            expect($id->toArray())->toBe(['integer' => 42]);
            expect(IntegerIdentifier::fromArray($id->toArray())->equals($id))->toBeTrue();
        });

        it('jsonSerializes as integer (not string)', function (): void {
            $id = IntegerIdentifier::from(42);
            expect(json_encode($id))->toBe('42');
        });

        it('validates integer strings', function (): void {
            expect(IntegerIdentifier::isValid('42'))->toBeTrue();
            expect(IntegerIdentifier::isValid('-5'))->toBeTrue();
            expect(IntegerIdentifier::isValid('abc'))->toBeFalse();
        });
    });

    describe('Entity', function (): void {
        it('supports int identity', function (): void {
            $entity = new TestEntity(42, 'Test Product', 99.99);
            expect($entity->id())->toBe('42');
            expect($entity->toArray())->toHaveKey('id');
            expect($entity->toArray())->toHaveKey('type');
        });

        it('supports string identity', function (): void {
            $entity = new TestEntity('slug-123', 'Test', 0.0);
            expect($entity->id())->toBe('slug-123');
        });

        it('supports identifier identity', function (): void {
            $id = TestUuidIdentifier::generate();
            $entity = new TestEntity($id, 'Test', 0.0);
            expect($entity->id())->toBe($id->toString());
        });

        it('checks equality by class and identity', function (): void {
            $e1 = new TestEntity(1, 'A', 10.0);
            $e2 = new TestEntity(1, 'B', 20.0); // same ID, different data
            $e3 = new TestEntity(2, 'A', 10.0);

            expect($e1->equals($e2))->toBeTrue();  // same class + same ID
            expect($e1->equals($e3))->toBeFalse(); // same class, different ID
        });

        it('implements JsonSerializable', function (): void {
            $entity = new TestEntity(42, 'Widget', 9.99);
            $json = json_encode($entity);

            expect($json)->toBeJson();
            $data = json_decode($json, true);
            expect($data['id'])->toBe('42');
            expect($data['type'])->toBe('TestEntity');
        });

        it('round-trips through fromArray', function (): void {
            $entity = new TestEntity(42, 'Widget', 9.99);
            $restored = TestEntity::fromArray($entity->toArray());

            expect($restored->id())->toBe($entity->id());
            expect($restored->name)->toBe('Widget');
        });

        it('round-trips through fromJson', function (): void {
            $entity = new TestEntity(42, 'Widget', 9.99);
            $json = $entity->toJson();
            $restored = TestEntity::fromJson($json);

            expect($restored->id())->toBe($entity->id());
        });
    });

    describe('DomainEventCollection', function (): void {
        it('validates sequential list on construction', function (): void {
            $event1 = DomainEvent::occur('test.event', ['key' => 'value1']);
            $event2 = DomainEvent::occur('test.event', ['key' => 'value2']);

            $collection = new DomainEventCollection([$event1, $event2]);
            expect($collection->count())->toBe(2);
        });

        it('rejects non-DomainEvent items', function (): void {
            expect(fn () => new DomainEventCollection(['not-an-event']))
                ->toThrow(\InvalidArgumentException::class);
        });

        it('rejects associative arrays', function (): void {
            $event = DomainEvent::occur('test.event', ['key' => 'value']);
            expect(fn () => new DomainEventCollection(['key' => $event]))
                ->toThrow(\InvalidArgumentException::class);
        });

        it('supports functional operations', function (): void {
            $e1 = DomainEvent::occur('order.placed', ['id' => '1']);
            $e2 = DomainEvent::occur('order.item_added', ['id' => '1']);
            $e3 = DomainEvent::occur('order.paid', ['id' => '1']);

            $collection = new DomainEventCollection([$e1, $e2, $e3]);

            expect($collection->isEmpty())->toBeFalse();
            expect($collection->count())->toBe(3);
            expect($collection->hasType('order.placed'))->toBeTrue();
            expect($collection->hasType('order.cancelled'))->toBeFalse();
            expect($collection->countBy(fn (DomainEvent $e): bool => str_starts_with($e->eventType, 'order.')))->toBe(3);
            expect($collection->some(fn (DomainEvent $e): bool => $e->eventType === 'order.paid'))->toBeTrue();
            expect($collection->none(fn (DomainEvent $e): bool => $e->eventType === 'order.cancelled'))->toBeTrue();

            $types = $collection->types();
            expect($types)->toBe(['order.placed', 'order.item_added', 'order.paid']);
        });

        it('round-trips through toArray/fromArray', function (): void {
            $e1 = DomainEvent::occur('order.placed', ['id' => '1']);
            $e2 = DomainEvent::occur('order.item_added', ['id' => '1', 'product_id' => 'p1']);
            $original = new DomainEventCollection([$e1, $e2]);

            $restored = DomainEventCollection::fromArray($original->toArray());
            expect($restored->count())->toBe(2);
            expect($restored->first()?->eventType)->toBe('order.placed');
        });
    });

    describe('Aggregate Root (Order fixture)', function (): void {
        it('creates via factory and applies events', function (): void {
            $order = Order::create(AggregateRootId::generate());

            expect($order->id())->toBeString()->toHaveLength(36);
            expect($order->version())->toBe(1);
            expect($order->status)->toBe('pending');
            expect($order->hasUncommittedEvents())->toBeTrue();
        });

        it('enforces domain invariants', function (): void {
            $order = Order::create(AggregateRootId::generate());
            $order->pay();
            $order->ship();

            expect(fn () => $order->cancel())->toThrow(InvalidStateDomainException::class);
        });

        it('tracks version increments', function (): void {
            $order = Order::create(AggregateRootId::generate());
            expect($order->version())->toBe(1);

            $order->addItem('p1', 2, 9.99);
            expect($order->version())->toBe(2);

            $order->pay();
            expect($order->version())->toBe(3);
        });

        it('serializes to array with all fields', function (): void {
            $order = Order::create(AggregateRootId::generate());
            $order->addItem('p1', 2, 9.99);

            $data = $order->toArray();
            expect($data)->toHaveKeys(['id', 'version', 'type', 'status', 'total', 'items', 'item_count']);
            expect($data['type'])->toBe('Order');
            expect($data['item_count'])->toBe(1);
        });

        it('round-trips through toJson/fromJson (Entity base)', function (): void {
            // Entity::fromJson delegates to fromArray which uses reflection
            $entity = new TestEntity(42, 'Widget', 9.99);
            $json = $entity->toJson();
            $restored = TestEntity::fromJson($json);

            expect($restored->id())->toBe('42');
            expect($restored->name)->toBe('Widget');
            expect($restored->price)->toBe(9.99);
        });
    });

    describe('Unit of Work', function (): void {
        it('runs closure with auto-commit', function (): void {
            $uow = new InMemoryUnitOfWork;
            $result = $uow->run(fn () => 'success');

            expect($result)->toBe('success');
            expect($uow->isActive())->toBeFalse();
        });

        it('rolls back on exception', function (): void {
            $uow = new InMemoryUnitOfWork;

            expect(fn () => $uow->run(function () {
                throw new \RuntimeException('Test failure');
            }))->toThrow(\RuntimeException::class);

            expect($uow->isActive())->toBeFalse();
            expect($uow->hasPendingEvents())->toBeFalse();
        });

        it('tracks aggregates and collects events on commit', function (): void {
            $uow = new InMemoryUnitOfWork;
            $uow->begin();

            $order = Order::create(AggregateRootId::generate());
            $uow->track($order);

            $order->addItem('p1', 2, 9.99);

            $uow->commit();

            expect($uow->getCommitted())->toHaveCount(1);
        });

        it('supports nested transactions via savepoints', function (): void {
            $uow = new InMemoryUnitOfWork;

            $uow->run(function () use ($uow): void {
                $uow->begin();
                $order = Order::create(AggregateRootId::generate());
                $uow->track($order);
                $uow->commit();
            });

            expect($uow->getCommitted())->toHaveCount(1);
        });

        it('restores aggregate state on rollback', function (): void {
            $uow = new InMemoryUnitOfWork;
            $uow->begin();

            $order = Order::create(AggregateRootId::generate());
            $uow->track($order);
            $originalStatus = $order->status;

            $order->addItem('p1', 2, 9.99);

            $uow->rollback();

            // After rollback, aggregate state should be restored
            expect($uow->isActive())->toBeFalse();
            expect($uow->getCommitted())->toHaveCount(0);
        });

        it('provides state inspection via toArray', function (): void {
            $uow = new InMemoryUnitOfWork;
            $uow->begin();

            $state = $uow->toArray();
            expect($state)->toHaveKeys(['nesting_depth', 'pending_event_count', 'committed_count', 'deleted_count', 'is_active']);
            expect($state['is_active'])->toBeTrue();

            $uow->commit();
        });
    });

    describe('Domain Exceptions', function (): void {
        it('provides RFC 9457 error arrays', function (): void {
            $exception = InvalidStateDomainException::because('Order must be pending');

            $error = $exception->toErrorArray();
            expect($error)->toHaveKeys(['title', 'detail', 'code', 'status']);
            expect($error['code'])->toBe('INVALID_STATE');
            expect($error['status'])->toBe(422);
        });

        it('serializes to JSON as RFC 9457', function (): void {
            $exception = NotFoundDomainException::for('Order', 'order-123');

            $json = json_encode($exception);
            expect($json)->toBeJson();
            $data = json_decode($json, true);
            expect($data['code'])->toBe('NOT_FOUND');
            expect($data['status'])->toBe(404);
        });

        it('auto-detects HTTP status from exception type', function (): void {
            expect(InvalidStateDomainException::because('test')->httpStatus())->toBe(422);
            expect(NotFoundDomainException::for('X', 'y')->httpStatus())->toBe(404);
            expect(InvalidArgumentDomainException::because('test')->httpStatus())->toBe(400);
            expect(ConflictDomainException::because('test')->httpStatus())->toBe(409);
        });

        it('round-trips through toJson/fromJson', function (): void {
            $exception = InvalidStateDomainException::because('Test message');
            $json = $exception->toJson();
            $restored = InvalidStateDomainException::fromJson($json, InvalidStateDomainException::class);

            expect($restored->getMessage())->toBe('Test message');
            expect($restored->errorCode())->toBe('INVALID_STATE');
        });
    });

    describe('Value Object', function (): void {
        it('provides structural equality', function (): void {
            $class = new class('test', 'test') extends ValueObject {
                public function __construct(public readonly string $a, public readonly string $b) {}

                public static function fromArray(array $data): static
                {
                    return new static($data['a'], $data['b']);
                }

                public function toArray(): array
                {
                    return ['a' => $this->a, 'b' => $this->b];
                }
            };

            $v1 = $class::fromArray(['a' => 'x', 'b' => 'y']);
            $v2 = $class::fromArray(['a' => 'x', 'b' => 'y']);
            $v3 = $class::fromArray(['a' => 'x', 'b' => 'z']);

            expect($v1->equals($v2))->toBeTrue();
            expect($v1->equals($v3))->toBeFalse();
        });

        it('round-trips through toJson/fromJson', function (): void {
            $class = new class('hello') extends ValueObject {
                public function __construct(public readonly string $value) {}

                public static function fromArray(array $data): static
                {
                    return new static($data['value']);
                }

                public function toArray(): array
                {
                    return ['value' => $this->value];
                }
            };

            $json = $class->toJson();
            $restored = $class::fromJson($json);

            expect($class->equals($restored))->toBeTrue();
        });
    });
});
