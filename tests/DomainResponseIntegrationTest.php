<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Tests;

use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\Contracts\Identifier as IdentifierContract;
use ZeroBoiler\Domain\DomainEventCollection;
use ZeroBoiler\Domain\Identifiers\IntegerIdentifier;
use ZeroBoiler\Domain\Identifiers\StringIdentifier;
use ZeroBoiler\Domain\Identifiers\UuidIdentifier;
use ZeroBoiler\Domain\Identifiers\UlidIdentifier;
use ZeroBoiler\Domain\Snapshots\InMemorySnapshotStore;
use ZeroBoiler\Domain\Snapshots\Snapshot;
use ZeroBoiler\Domain\SnapshottingRepository;
use ZeroBoiler\Domain\InMemoryUnitOfWork;

// ===========================================================================
//  Production readiness: Domain → Response serialization integration
//
//  Verifies that all domain primitives serialize correctly for API
//  consumption. These tests ensure round-trip safety, JSON schema
//  compliance, and cross-type boundary behavior.
// ===========================================================================

describe('Domain production serialization readiness', function (): void {
    // --- AggregateRootId ---
    describe('AggregateRootId JSON serialization', function (): void {
        it('serializes to a plain UUID string', function (): void {
            $id = AggregateRootId::generate();
            $json = json_encode($id);

            expect($json)->toBeJson();
            $decoded = json_decode($json);
            expect($decoded)->toBe($id->toString());
        });

        it('round-trips through json_encode/json_decode', function (): void {
            $id = AggregateRootId::generate();
            $json = json_encode(['id' => $id]);
            $decoded = json_decode($json, true);

            expect($decoded['id'])->toBe($id->toString());
            expect(AggregateRootId::fromString($decoded['id'])->equals($id))->toBeTrue();
        });

        it('implements Stringable and JsonSerializable consistently', function (): void {
            $id = AggregateRootId::generate();

            expect((string) $id)->toBe($id->toString())
                ->and($id->jsonSerialize())->toBe($id->toString())
                ->and(json_encode($id))->toBe('"' . $id->toString() . '"');
        });
    });

    // --- UuidIdentifier ---
    describe('UuidIdentifier JSON serialization', function (): void {
        it('serializes as a plain UUID string', function (): void {
            $id = TestUuidId::generate();
            $json = json_encode($id);

            $decoded = json_decode($json);
            expect($decoded)->toBe($id->toString());
        });

        it('round-trips from JSON back to identifier', function (): void {
            $id = TestUuidId::generate();
            $json = json_encode(['order_id' => $id]);
            $decoded = json_decode($json, true);

            $restored = TestUuidId::fromString($decoded['order_id']);
            expect($restored->equals($id))->toBeTrue();
        });
    });

    // --- UlidIdentifier ---
    describe('UlidIdentifier JSON serialization', function (): void {
        it('serializes as a plain ULID string', function (): void {
            $id = TestUlidId::generate();
            $json = json_encode($id);

            $decoded = json_decode($json);
            expect($decoded)->toBe($id->toString());
        });

        it('validates ULID format on construction', function (): void {
            expect(fn () => TestUlidId::fromString('not-a-ulid'))
                ->toThrow(\InvalidArgumentException::class);
        });
    });

    // --- StringIdentifier ---
    describe('StringIdentifier JSON serialization', function (): void {
        it('serializes as a plain string', function (): void {
            $id = StringIdentifier::from('my-slug');
            $json = json_encode($id);

            expect($json)->toBe('"my-slug"');
        });

        it('rejects empty strings', function (): void {
            expect(fn () => StringIdentifier::from(''))
                ->toThrow(\ValueError::class);
        });

        it('round-trips from JSON', function (): void {
            $id = StringIdentifier::from('product-abc');
            $json = json_encode(['slug' => $id]);
            $decoded = json_decode($json, true);

            $restored = StringIdentifier::from($decoded['slug']);
            expect($restored->equals($id))->toBeTrue();
        });
    });

    // --- IntegerIdentifier ---
    describe('IntegerIdentifier JSON serialization', function (): void {
        it('serializes as an integer (not string)', function (): void {
            $id = IntegerIdentifier::from(42);
            $json = json_encode($id);

            expect($json)->toBe('42');
            expect(json_decode($json))->toBe(42);
        });

        it('round-trips from JSON', function (): void {
            $id = IntegerIdentifier::from(99);
            $json = json_encode(['seq' => $id]);
            $decoded = json_decode($json, true);

            $restored = IntegerIdentifier::from($decoded['seq']);
            expect($restored->equals($id))->toBeTrue();
        });

        it('handles negative integers', function (): void {
            $id = IntegerIdentifier::from(-5);
            expect($id->toInt())->toBe(-5);
            expect($id->toString())->toBe('-5');
            expect(json_encode($id))->toBe('-5');
        });
    });

    // --- DomainEventCollection ---
    describe('DomainEventCollection JSON serialization', function (): void {
        it('serializes to a JSON array', function (): void {
            $collection = new DomainEventCollection;
            $json = json_encode($collection);

            expect($json)->toBe('[]');
        });

        it('includes toArray() output when available', function (): void {
            $event = new class extends \ZeroBoiler\Events\Domain\DomainEvent
            {
                public function __construct()
                {
                    parent::__construct(
                        eventType: 'test.occurred',
                        aggregateId: 'agg-123',
                        payload: ['key' => 'value'],
                    );
                }

                public function toArray(): array
                {
                    return [
                        'event_type' => $this->eventType,
                        'aggregate_id' => $this->aggregateId,
                        'payload' => $this->payload,
                    ];
                }
            };

            $collection = new DomainEventCollection([$event]);
            $json = json_encode($collection);

            expect($json)->toBeJson();
            $decoded = json_decode($json, true);
            expect($decoded)->toHaveCount(1);
            expect($decoded[0])->toHaveKey('event_type');
        });
    });

    // --- Snapshot ---
    describe('Snapshot round-trip serialization', function (): void {
        it('serializes to array and back', function (): void {
            $snapshot = Snapshot::create(
                aggregateType: 'App\\Domain\\Order',
                aggregateId: 'order-123',
                version: 42,
                state: ['status' => 'paid', 'total' => 99.99],
            );

            $array = $snapshot->toArray();

            expect($array)->toBe([
                'aggregate_type' => 'App\\Domain\\Order',
                'aggregate_id' => 'order-123',
                'version' => 42,
                'state' => ['status' => 'paid', 'total' => 99.99],
                'created_at' => $snapshot->createdAt->format(\DateTimeInterface::ATOM),
            ]);

            // Round-trip
            $restored = Snapshot::fromArray($array);

            expect($restored->aggregateType)->toBe($snapshot->aggregateType)
                ->and($restored->aggregateId)->toBe($snapshot->aggregateId)
                ->and($restored->version)->toBe($snapshot->version)
                ->and($restored->state)->toBe($snapshot->state);
        });

        it('JSON-serializes cleanly', function (): void {
            $snapshot = Snapshot::create('Order', 'id-1', 1, ['x' => true]);

            $json = json_encode($snapshot);
            expect($json)->toBeJson();

            $decoded = json_decode($json, true);
            expect($decoded)->toHaveKeys([
                'aggregate_type', 'aggregate_id', 'version', 'state', 'created_at',
            ]);
        });
    });

    // --- InMemorySnapshotStore ---
    describe('InMemorySnapshotStore production API', function (): void {
        it('supports full CRUD lifecycle', function (): void {
            $store = new InMemorySnapshotStore;
            $snapshot = Snapshot::create('Order', 'id-1', 5, ['status' => 'paid']);

            expect($store->has('Order', 'id-1'))->toBeFalse();

            $store->save($snapshot);

            expect($store->has('Order', 'id-1'))->toBeTrue();
            expect($store->load('Order', 'id-1')?->version)->toBe(5);

            $store->delete('Order', 'id-1');
            expect($store->has('Order', 'id-1'))->toBeFalse();
        });

        it('stats() returns correct counts', function (): void {
            $store = new InMemorySnapshotStore;

            $store->save(Snapshot::create('Order', 'o1', 1, []));
            $store->save(Snapshot::create('Order', 'o2', 2, []));
            $store->save(Snapshot::create('User', 'u1', 1, []));

            $stats = $store->stats();

            expect($stats['total'])->toBe(3)
                ->and($stats['by_type']['Order'])->toBe(2)
                ->and($stats['by_type']['User'])->toBe(1);
        });

        it('purge removes only targeted types', function (): void {
            $store = new InMemorySnapshotStore;

            $store->save(Snapshot::create('Order', 'o1', 1, []));
            $store->save(Snapshot::create('User', 'u1', 1, []));

            $removed = $store->purge('Order');

            expect($removed)->toBe(1)
                ->and($store->count())->toBe(1)
                ->and($store->count('User'))->toBe(1)
                ->and($store->count('Order'))->toBe(0);
        });
    });

    // --- InMemoryUnitOfWork ---
    describe('InMemoryUnitOfWork transactional semantics', function (): void {
        it('dispatches events only after commit', function (): void {
            $uow = new InMemoryUnitOfWork;

            $dispatched = [];
            $uow->setEventDispatcher(function (object $event) use (&$dispatched): void {
                $dispatched[] = $event;
            });

            $event = new \ZeroBoiler\Events\Domain\DomainEvent(
                eventType: 'test.created',
                aggregateId: 'agg-1',
                payload: [],
            );

            $uow->begin();
            $uow->queueEvent($event);

            // Events NOT dispatched yet
            expect($dispatched)->toBeEmpty();
            expect($uow->hasPendingEvents())->toBeTrue();
            expect($uow->getPendingEventCount())->toBe(1);

            $uow->commit();

            // Events dispatched after commit
            expect($dispatched)->toHaveCount(1);
        });

        it('discards events on rollback', function (): void {
            $uow = new InMemoryUnitOfWork;

            $dispatched = [];
            $uow->setEventDispatcher(function (object $event) use (&$dispatched): void {
                $dispatched[] = $event;
            });

            $uow->begin();
            $uow->queueEvent(new \ZeroBoiler\Events\Domain\DomainEvent(
                eventType: 'test.rolled_back',
                aggregateId: 'agg-1',
                payload: [],
            ));

            $uow->rollback();

            expect($dispatched)->toBeEmpty()
                ->and($uow->hasPendingEvents())->toBeFalse();
        });

        it('supports nested run() with savepoints', function (): void {
            $uow = new InMemoryUnitOfWork;
            $results = [];

            $uow->run(function () use ($uow, &$results): void {
                $results[] = 'outer-begin';

                $uow->run(function () use (&$results): void {
                    $results[] = 'inner';
                });

                $results[] = 'outer-end';
            });

            expect($results)->toBe(['outer-begin', 'inner', 'outer-end']);
        });

        it('run() rolls back on exception', function (): void {
            $uow = new InMemoryUnitOfWork;
            $dispatched = [];
            $uow->setEventDispatcher(function (object $event) use (&$dispatched): void {
                $dispatched[] = $event;
            });

            $uow->begin();
            $uow->queueEvent(new \ZeroBoiler\Events\Domain\DomainEvent(
                eventType: 'test.error',
                aggregateId: 'agg-1',
                payload: [],
            ));

            expect(fn () => $uow->run(function (): never {
                throw new \RuntimeException('fail');
            }))->toThrow(\RuntimeException::class);

            // Events queued before run() should be discarded by rollback of outer scope
            expect($dispatched)->toBeEmpty();
        });
    });

    // --- IdentifierContract cross-type equality ---
    describe('Identifier cross-type equality safety', function (): void {
        it('different identifier types never equal', function (): void {
            $uuid = TestUuidId::fromString('550e8400-e29b-41d4-a716-446655440000');
            $str = StringIdentifier::from('550e8400-e29b-41d4-a716-446655440000');
            $int = IntegerIdentifier::from(42);

            // Each uses instanceof checks, so cross-type always returns false
            expect($uuid->equals($str))->toBeFalse();
            expect($uuid->equals($int))->toBeFalse();
            expect($str->equals($int))->toBeFalse();
        });
    });
});

// ===========================================================================
//  Test fixtures — concrete identifier subclasses
// ===========================================================================

final class TestUuidId extends UuidIdentifier {}

final class TestUlidId extends UlidIdentifier {}
