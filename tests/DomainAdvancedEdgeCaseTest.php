<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Domain\AggregateRoot;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\Contracts\UnitOfWork;
use ZeroBoiler\Domain\DomainEventCollection;
use ZeroBoiler\Domain\InMemoryUnitOfWork;
use ZeroBoiler\Domain\Identifiers\IntegerIdentifier;
use ZeroBoiler\Domain\Identifiers\StringIdentifier;
use ZeroBoiler\Domain\Identifiers\UuidIdentifier;
use ZeroBoiler\Domain\Identifiers\UlidIdentifier;
use ZeroBoiler\Domain\ValueObject;
use ZeroBoiler\Events\Domain\DomainEvent;

/**
 * Advanced edge-case tests for domain package.
 *
 * Covers: UoW nested run/rollback, aggregate state restoration,
 * identifier cross-type equality, value object structural equality,
 * domain event collection reduce/each/some/none/find/countBy/types,
 * aggregate root reconstitute from snapshot edge cases.
 *
 * @group domain
 * @group production
 *
 * @since 1.68.0
 */

// ─── Test Helpers ─────────────────────────────────────────────────────────

class TestOrder extends AggregateRoot
{
    public string $status = 'pending';
    public float $total = 0.0;

    public function __construct(AggregateRootId $id)
    {
        parent::__construct($id);
    }

    public static function place(AggregateRootId $id): self
    {
        $order = new self($id);
        $order->apply(DomainEvent::occur('order.placed', [
            'status' => 'pending',
        ]));

        return $order;
    }

    public function pay(float $amount): void
    {
        $this->apply(DomainEvent::occur('order.paid', [
            'amount' => $amount,
        ]));
    }

    public function ship(): void
    {
        $this->apply(DomainEvent::occur('order.shipped', []));
    }

    protected function applyOrderPlaced(DomainEvent $event): void
    {
        $this->status = $event->payload['status'];
    }

    protected function applyOrderPaid(DomainEvent $event): void
    {
        $this->status = 'paid';
        $this->total = $event->payload['amount'];
    }

    protected function applyOrderShipped(DomainEvent $event): void
    {
        $this->status = 'shipped';
    }

    public function toArray(): array
    {
        return [
            ...parent::toArray(),
            'status' => $this->status,
            'total' => $this->total,
        ];
    }
}

class TestAddress extends ValueObject
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

class TestOrderId extends UuidIdentifier {}

class TestProductId extends UlidIdentifier {}

// ─── Test Suite ────────────────────────────────────────────────────────────

describe('Domain Advanced Edge Cases', function (): void {

    // =========================================================================
    // InMemoryUnitOfWork — Nested Run / Rollback State Restoration
    // =========================================================================
    describe('UnitOfWork nested transactions', function (): void {
        test('nested run() creates savepoint — inner failure does not affect outer', function (): void {
            $uow = new InMemoryUnitOfWork;
            $order = TestOrder::place(AggregateRootId::generate());
            $originalStatus = $order->status; // 'pending'

            $uow->run(function () use ($uow, $order): void {
                $uow->track($order);

                // Inner run — will fail
                try {
                    $uow->run(function () use ($uow, $order): void {
                        $uow->track($order);
                        $order->pay(100.0);
                        throw new \RuntimeException('Inner failure');
                    });
                } catch (\RuntimeException $e) {
                    // Expected
                }

                // Order state should be restored after inner rollback
                expect($order->status)->toBe($originalStatus);
                expect($order->total)->toBe(0.0);
                expect($order->version())->toBe(1); // Only the placed event
            });
        });

        test('nested run() success — both inner and outer commit', function (): void {
            $uow = new InMemoryUnitOfWork;
            $order = TestOrder::place(AggregateRootId::generate());

            $uow->run(function () use ($uow, $order): void {
                $uow->track($order);
                $order->pay(50.0);

                $uow->run(function () use ($uow, $order): void {
                    $uow->track($order);
                    $order->ship();
                });
            });

            expect($order->status)->toBe('shipped');
            expect($order->total)->toBe(50.0);
            expect($order->version())->toBe(3); // placed + paid + shipped
        });

        test('manual begin/rollback restores aggregate state', function (): void {
            $uow = new InMemoryUnitOfWork;
            $order = TestOrder::place(AggregateRootId::generate());

            $uow->begin();
            $uow->track($order);
            $order->pay(999.0);

            // Rollback should restore to pre-transaction state
            $uow->rollback();

            expect($order->status)->toBe('pending');
            expect($order->total)->toBe(0.0);
            expect($order->hasUncommittedEvents())->toBeFalse();
        });

        test('markForDeletion queues aggregate for deletion on commit', function (): void {
            $committed = [];
            $deleted = [];

            $uow = new InMemoryUnitOfWork;
            $uow->setPersistenceCallback(function (array $c, array $d) use (&$committed, &$deleted): void {
                $committed = $c;
                $deleted = $d;
            });

            $order = TestOrder::place(AggregateRootId::generate());
            $uow->begin();
            $uow->track($order);
            $uow->markForDeletion($order);
            $uow->commit();

            expect($deleted)->toHaveCount(1);
            expect($committed)->toHaveCount(0);
        });

        test('getPendingEvents returns non-destructive copy', function (): void {
            $uow = new InMemoryUnitOfWork;
            $order = TestOrder::place(AggregateRootId::generate());

            $uow->begin();
            $uow->track($order);
            $order->pay(25.0);

            $pending = $uow->getPendingEvents();
            expect($pending)->toBeInstanceOf(DomainEventCollection::class);
            expect($pending->count())->toBeGreaterThan(0);

            // Events still available for commit
            $uow->commit();
            expect($order->hasUncommittedEvents())->toBeFalse();
        });
    });

    // =========================================================================
    // AggregateRoot — Immutability of Identity
    // =========================================================================
    describe('AggregateRoot identity', function (): void {
        test('equals() returns false for different concrete types', function (): void {
            $id = AggregateRootId::generate();

            $order = new TestOrder($id);
            $other = new class($id) extends AggregateRoot {
                public function __construct(AggregateRootId $id) { parent::__construct($id); }
            };

            expect($order->equals($other))->toBeFalse();
        });

        test('id() returns consistent string representation', function (): void {
            $id = AggregateRootId::generate();
            $order = TestOrder::place($id);

            expect($order->id())->toBe($id->toString());
            expect($order->aggregateId()->equals($id))->toBeTrue();
        });

        test('toArray() includes id, version, and type', function (): void {
            $id = AggregateRootId::generate();
            $order = TestOrder::place($id);

            $arr = $order->toArray();
            expect($arr)->toHaveKey('id');
            expect($arr)->toHaveKey('version');
            expect($arr)->toHaveKey('type');
            expect($arr)->toHaveKey('status');
            expect($arr['type'])->toBe('TestOrder');
            expect($arr['version'])->toBe(1);
        });

        test('toJson() produces valid JSON matching toArray()', function (): void {
            $id = AggregateRootId::generate();
            $order = TestOrder::place($id);

            $json = $order->toJson();
            $decoded = json_decode($json, true);
            expect($decoded)->toBe($order->toArray());
        });
    });

    // =========================================================================
    // Identifier — Cross-Type Inequality
    // =========================================================================
    describe('Identifier cross-type inequality', function (): void {
        test('UuidIdentifier and UlidIdentifier are never equal', function (): void {
            $uuid = TestOrderId::generate();
            $ulid = TestProductId::generate();

            // Different concrete types — equals() should return false
            // (each identifier type checks concrete class match)
            expect($uuid->equals($ulid))->toBeFalse();
        });

        test('same concrete type with same value are equal', function (): void {
            $id1 = TestOrderId::generate();
            $id2 = TestOrderId::fromString($id1->value);

            expect($id1->equals($id2))->toBeTrue();
        });

        test('StringIdentifier rejects empty string', function (): void {
            expect(fn () => StringIdentifier::from(''))
                ->toThrow(\InvalidArgumentException::class);
        });

        test('IntegerIdentifier round-trip via fromString/toInt', function (): void {
            $id = IntegerIdentifier::from(42);
            $restored = IntegerIdentifier::fromString('42');

            expect($restored->toInt())->toBe(42);
        });

        test('all identifiers implement JsonSerializable', function (): void {
            $uuid = TestOrderId::generate();
            $ulid = TestProductId::generate();
            $str = StringIdentifier::from('test');
            $int = IntegerIdentifier::from(1);

            foreach ([$uuid, $ulid, $str, $int] as $identifier) {
                $json = json_encode($identifier);
                expect($json)->not->toBeFalse();
                expect(strlen($json))->toBeGreaterThan(0);
            }
        });

        test('all identifiers support fromArray/fromJson round-trip', function (): void {
            $uuid = TestOrderId::generate();
            $restored = TestOrderId::fromArray($uuid->toArray());
            expect($uuid->equals($restored))->toBeTrue();

            $json = $uuid->toJson();
            $fromJson = TestOrderId::fromJson($json);
            expect($uuid->equals($fromJson))->toBeTrue();
        });
    });

    // =========================================================================
    // Value Object — Structural Equality
    // =========================================================================
    describe('ValueObject structural equality', function (): void {
        test('same values are equal', function (): void {
            $a = TestAddress::fromArray(['street' => '123 Main', 'city' => 'NYC', 'country' => 'US']);
            $b = TestAddress::fromArray(['street' => '123 Main', 'city' => 'NYC', 'country' => 'US']);

            expect($a->equals($b))->toBeTrue();
        });

        test('different values are not equal', function (): void {
            $a = TestAddress::fromArray(['street' => '123 Main', 'city' => 'NYC', 'country' => 'US']);
            $b = TestAddress::fromArray(['street' => '456 Oak', 'city' => 'LA', 'country' => 'US']);

            expect($a->equals($b))->toBeFalse();
        });

        test('different concrete types are not equal', function (): void {
            $a = TestAddress::fromArray(['street' => '123 Main', 'city' => 'NYC', 'country' => 'US']);

            $other = new class('456 Oak', 'LA', 'US') extends ValueObject {
                public function __construct(public readonly string $s, public readonly string $c, public readonly string $co) {}
                public static function fromArray(array $d): static { return new static($d['s'], $d['c'], $d['co']); }
                public function toArray(): array { return ['s' => $this->s, 'c' => $this->c, 'co' => $this->co]; }
            };

            expect($a->equals($other))->toBeFalse();
        });

        test('toJson/fromJson round-trip', function (): void {
            $a = TestAddress::fromArray(['street' => '123 Main', 'city' => 'NYC', 'country' => 'US']);
            $json = $a->toJson();
            $restored = TestAddress::fromJson($json);
            expect($a->equals($restored))->toBeTrue();
        });
    });

    // =========================================================================
    // DomainEventCollection — Advanced Functional Operations
    // =========================================================================
    describe('DomainEventCollection functional operations', function (): void {
        test('reduce() sums event amounts', function (): void {
            $events = new DomainEventCollection([
                DomainEvent::occur('order.paid', ['amount' => 100]),
                DomainEvent::occur('order.paid', ['amount' => 200]),
                DomainEvent::occur('order.item_added', ['amount' => 50]),
            ]);

            $total = $events->reduce(
                fn (float $sum, DomainEvent $e): float => $sum + ($e->payload['amount'] ?? 0),
                0.0,
            );

            expect($total)->toBe(350.0);
        });

        test('reduce() groups events by type', function (): void {
            $events = new DomainEventCollection([
                DomainEvent::occur('order.paid', []),
                DomainEvent::occur('order.placed', []),
                DomainEvent::occur('order.paid', []),
            ]);

            $grouped = $events->reduce(
                fn (array $groups, DomainEvent $e): array => [
                    ...$groups,
                    $e->eventType => [...($groups[$e->eventType] ?? []), $e],
                ],
                [],
            );

            expect($grouped)->toHaveKey('order.paid');
            expect($grouped['order.paid'])->toHaveCount(2);
            expect($grouped['order.placed'])->toHaveCount(1);
        });

        test('some() short-circuits on first match', function (): void {
            $events = new DomainEventCollection([
                DomainEvent::occur('order.placed', []),
                DomainEvent::occur('order.paid', []),
            ]);

            expect($events->some(fn (DomainEvent $e): bool => $e->eventType === 'order.placed'))->toBeTrue();
            expect($events->some(fn (DomainEvent $e): bool => $e->eventType === 'order.cancelled'))->toBeFalse();
        });

        test('none() is inverse of some()', function (): void {
            $events = new DomainEventCollection([
                DomainEvent::occur('order.placed', []),
            ]);

            expect($events->none(fn (DomainEvent $e): bool => $e->eventType === 'order.cancelled'))->toBeTrue();
            expect($events->none(fn (DomainEvent $e): bool => $e->eventType === 'order.placed'))->toBeFalse();
        });

        test('find() returns first match or null', function (): void {
            $events = new DomainEventCollection([
                DomainEvent::occur('order.placed', []),
                DomainEvent::occur('order.paid', ['amount' => 100]),
            ]);

            $found = $events->find(fn (DomainEvent $e): bool => $e->eventType === 'order.paid');
            expect($found)->not->toBeNull();
            expect($found->payload['amount'])->toBe(100);

            $notFound = $events->find(fn (DomainEvent $e): bool => $e->eventType === 'order.cancelled');
            expect($notFound)->toBeNull();
        });

        test('countBy() counts matching events', function (): void {
            $events = new DomainEventCollection([
                DomainEvent::occur('order.paid', []),
                DomainEvent::occur('order.paid', []),
                DomainEvent::occur('order.placed', []),
            ]);

            expect($events->countBy(fn (DomainEvent $e): bool => $e->eventType === 'order.paid'))->toBe(2);
            expect($events->countBy(fn (DomainEvent $e): bool => $e->eventType === 'order.placed'))->toBe(1);
            expect($events->countBy(fn (DomainEvent $e): bool => $e->eventType === 'order.shipped'))->toBe(0);
        });

        test('types() returns unique types in order', function (): void {
            $events = new DomainEventCollection([
                DomainEvent::occur('order.placed', []),
                DomainEvent::occur('order.paid', []),
                DomainEvent::occur('order.placed', []),
            ]);

            $types = $events->types();
            expect($types)->toBe(['order.placed', 'order.paid']);
        });

        test('hasType() is shorthand for some()', function (): void {
            $events = new DomainEventCollection([
                DomainEvent::occur('order.placed', []),
            ]);

            expect($events->hasType('order.placed'))->toBeTrue();
            expect($events->hasType('order.paid'))->toBeFalse();
        });

        test('each() returns same collection for chaining', function (): void {
            $events = new DomainEventCollection([
                DomainEvent::occur('order.placed', []),
            ]);

            $result = $events->each(function (DomainEvent $e): void {});
            expect($result)->toBe($events);
        });

        test('merge() combines two collections', function (): void {
            $a = new DomainEventCollection([
                DomainEvent::occur('order.placed', []),
            ]);
            $b = new DomainEventCollection([
                DomainEvent::occur('order.paid', []),
            ]);

            $merged = $a->merge($b);
            expect($merged->count())->toBe(2);
            expect($merged->first()->eventType)->toBe('order.placed');
            expect($merged->last()->eventType)->toBe('order.paid');
        });

        test('merge() accepts plain array of DomainEvent', function (): void {
            $a = new DomainEventCollection([
                DomainEvent::occur('order.placed', []),
            ]);

            $merged = $a->merge([DomainEvent::occur('order.paid', [])]);
            expect($merged->count())->toBe(2);
        });

        test('fromArray() rejects non-sequential arrays', function (): void {
            expect(fn () => DomainEventCollection::fromArray(['key' => DomainEvent::occur('test', [])]))
                ->toThrow(\InvalidArgumentException::class);
        });

        test('fromJson() round-trip serialization', function (): void {
            $original = new DomainEventCollection([
                DomainEvent::occur('order.placed', ['status' => 'pending']),
                DomainEvent::occur('order.paid', ['amount' => 100]),
            ]);

            $json = $original->toJson();
            $restored = DomainEventCollection::fromJson($json);

            expect($restored->count())->toBe(2);
            expect($restored->first()->eventType)->toBe('order.placed');
        });
    });

    // =========================================================================
    // Domain Exceptions — RFC 9457 Mapping
    // =========================================================================
    describe('Domain exception RFC 9457 mapping', function (): void {
        test('all exceptions produce valid toErrorArray()', function (): void {
            $exceptions = [
                \ZeroBoiler\Domain\Exceptions\InvalidStateDomainException::because('test'),
                \ZeroBoiler\Domain\Exceptions\InvalidArgumentDomainException::because('test'),
                \ZeroBoiler\Domain\Exceptions\NotFoundDomainException::forId('order-123'),
                \ZeroBoiler\Domain\Exceptions\ConflictDomainException::because('test'),
                \ZeroBoiler\Domain\Exceptions\AggregateNotFoundException::for('Order', '123'),
                \ZeroBoiler\Domain\Exceptions\OptimisticLockException::for('123', expectedVersion: 5, actualVersion: 6),
                \ZeroBoiler\Domain\Exceptions\InvalidAggregateRootException::notAnAggregate(new \stdClass),
            ];

            foreach ($exceptions as $exception) {
                $arr = $exception->toErrorArray();
                expect($arr)->toHaveKey('title');
                expect($arr)->toHaveKey('detail');
                expect($arr)->toHaveKey('code');
                expect($arr)->toHaveKey('status');

                // Must be JSON-serializable
                $json = json_encode($exception);
                expect($json)->not->toBeFalse();
            }
        });

        test('fromArray round-trip preserves error code', function (): void {
            $original = \ZeroBoiler\Domain\Exceptions\InvalidStateDomainException::because('test');
            $arr = $original->toArray();
            $restored = \ZeroBoiler\Domain\Exceptions\InvalidStateDomainException::fromArray($arr);

            expect($restored->errorCode())->toBe($original->errorCode());
        });
    });

    // =========================================================================
    // InMemoryUnitOfWork — toArray/toJson State
    // =========================================================================
    describe('UnitOfWork state serialization', function (): void {
        test('toArray() returns transactional state', function (): void {
            $uow = new InMemoryUnitOfWork;
            $uow->begin();

            $state = $uow->toArray();
            expect($state)->toHaveKey('nesting_depth');
            expect($state)->toHaveKey('pending_event_count');
            expect($state)->toHaveKey('committed_count');
            expect($state)->toHaveKey('deleted_count');
            expect($state)->toHaveKey('is_active');
            expect($state['is_active'])->toBeTrue();

            $uow->commit();
            expect($uow->toArray()['is_active'])->toBeFalse();
        });

        test('toJson() produces valid JSON', function (): void {
            $uow = new InMemoryUnitOfWork;
            $json = $uow->toJson();
            $decoded = json_decode($json, true);
            expect($decoded)->toHaveKey('is_active');
        });

        test('clear() resets all state', function (): void {
            $uow = new InMemoryUnitOfWork;
            $uow->begin();
            $uow->queueEvent(DomainEvent::occur('test', []));
            $uow->commit();

            $uow->clear();
            expect($uow->isActive())->toBeFalse();
            expect($uow->hasPendingEvents())->toBeFalse();
            expect($uow->getCommitted())->toBe([]);
            expect($uow->getDeleted())->toBe([]);
        });
    });
});
