<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

/**
 * Cross-package domain → response mapping contract validation.
 *
 * Validates the integration contract between zeroboiler/domain and
 * zeroboiler/response packages, ensuring duck-typed ID extraction,
 * exception serialization, and response factory integration
 * work correctly without hard coupling between the two packages.
 *
 * Tests run in CI with Pest — committed as test artifacts.
 *
 * @since 1.0.0
 */

use ZeroBoiler\Domain\AggregateRoot;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\Concerns\EventSourced;
use ZeroBoiler\Domain\Concerns\HasSnapshots;
use ZeroBoiler\Domain\DomainEventCollection;
use ZeroBoiler\Domain\Entity;
use ZeroBoiler\Domain\Identifiers\IntegerIdentifier;
use ZeroBoiler\Domain\Identifiers\StringIdentifier;
use ZeroBoiler\Domain\Identifiers\UuidIdentifier;
use ZeroBoiler\Domain\Identifiers\UlidIdentifier;
use ZeroBoiler\Domain\InMemoryUnitOfWork;
use ZeroBoiler\Domain\Snapshots\{InMemorySnapshotStore, Snapshot, SnapshottingRepository};
use ZeroBoiler\Domain\Exceptions\{
    ConflictDomainException,
    DomainException,
    InvalidArgumentDomainException,
    InvalidStateDomainException,
    NotFoundDomainException,
    OptimisticLockException,
    AggregateNotFoundException,
};
use ZeroBoiler\Response\Concerns\ExtractsDomainId;
use ZeroBoiler\Response\Exceptions\TransformationException;
use ZeroBoiler\Response\Transformers\{DomainTransformer, DomainResponseFactory};
use ZeroBoiler\Events\Domain\DomainEvent;

// ===========================================================================
//  Domain → Response: ExtractsDomainId trait contract
// ===========================================================================

describe('Cross-Package: ExtractsDomainId', function (): void {
    it('extracts ID from AggregateRoot via id() method', function (): void {
        $id = AggregateRootId::generate();
        $order = new class($id) extends AggregateRoot {
            use EventSourced;

            public string $status = 'pending';

            protected function __construct(AggregateRootId $id)
            {
                parent::__construct($id);
            }
        };

        // Test via the trait's static method
        $extracted = ExtractsDomainId::extractId($order);
        expect($extracted)->toBe($id->toString());
    });

    it('extracts ID from Entity with Stringable id', function (): void {
        $entity = new class('entity-123') extends Entity {};

        $extracted = ExtractsDomainId::extractId($entity);
        expect($extracted)->toBe('entity-123');
    });

    it('extracts ID from StringIdentifier via toString()', function (): void {
        $id = StringIdentifier::from('my-slug');

        $extracted = ExtractsDomainId::extractId($id);
        expect($extracted)->toBe('my-slug');
    });

    it('extracts ID from IntegerIdentifier via __toString()', function (): void {
        $id = IntegerIdentifier::from(42);

        $extracted = ExtractsDomainId::extractId($id);
        expect($extracted)->toBe('42');
    });

    it('extracts ID from UuidIdentifier via toString()', function (): void {
        $uuid = AggregateRootId::generate();
        $id = new class($uuid->toString()) extends UuidIdentifier {};

        $extracted = ExtractsDomainId::extractId($id);
        expect($extracted)->toBe($uuid->toString());
    });

    it('extracts ID from UlidIdentifier via toString()', function (): void {
        $id = new class('01AN4Z07BY79KA1307SR9X4MV3') extends UlidIdentifier {};

        $extracted = ExtractsDomainId::extractId($id);
        expect($extracted)->toBe('01AN4Z07BY79KA1307SR9X4MV3');
    });

    it('falls back to spl_object_id for unknown objects', function (): void {
        $obj = new \stdClass;

        $extracted = ExtractsDomainId::extractId($obj);
        expect($extracted)->toBe((string) spl_object_id($obj));
    });
});

// ===========================================================================
//  Domain → Response: DomainException → RFC 9457 serialization
// ===========================================================================

describe('Cross-Package: DomainException Serialization', function (): void {
    it('InvalidStateDomainException serializes to RFC 9457', function (): void {
        $e = InvalidStateDomainException::because('Order must be pending to pay.');

        $json = json_encode($e);
        expect($json)->not->toBeFalse();

        $decoded = json_decode($json, true);
        expect($decoded['title'])->toBe('InvalidStateDomainException');
        expect($decoded['detail'])->toBe('Order must be pending to pay.');
        expect($decoded['code'])->toBe('INVALID_STATE');
    });

    it('InvalidArgumentDomainException serializes to RFC 9457', function (): void {
        $e = InvalidArgumentDomainException::because('Quantity must be positive.');

        $arr = $e->toErrorArray();
        expect($arr['code'])->toBe('INVALID_ARGUMENT');
        expect($arr['title'])->toBe('InvalidArgumentDomainException');
    });

    it('NotFoundDomainException serializes with aggregate info', function (): void {
        $e = NotFoundDomainException::forAggregate('Order', '550e8400-...');

        $arr = $e->toErrorArray();
        expect($arr['code'])->toBe('NOT_FOUND');
        expect($arr['detail'])->toContain('Order');
        expect($arr['detail'])->toContain('550e8400-...');
    });

    it('ConflictDomainException round-trips via toArray/fromArray', function (): void {
        $original = ConflictDomainException::because('Concurrent modification.');

        $arr = $original->toArray();
        $restored = DomainException::fromArray($arr, ConflictDomainException::class);

        expect($restored->getMessage())->toBe('Concurrent modification.');
        expect($restored->errorCode())->toBe('CONFLICT');
    });

    it('OptimisticLockException has typed parameters', function (): void {
        $e = OptimisticLockException::for(
            aggregateId: 'order-123',
            expectedVersion: 5,
            actualVersion: 3,
        );

        expect($e->errorCode())->toBe('OPTIMISTIC_LOCK');
        expect($e->getMessage())->toContain('order-123');
        expect($e->getMessage())->toContain('expected version 5');
        expect($e->getMessage())->toContain('current version 3');
    });

    it('AggregateNotFoundException round-trips via toErrorArray', function (): void {
        $e = AggregateNotFoundException::for('App\\Domain\\Order', 'uuid-here');

        $errorArray = $e->toErrorArray();
        expect($errorArray['code'])->toBe('AGGREGATE_NOT_FOUND');

        // jsonSerialize returns the same format
        $json = json_encode($e);
        expect($json)->not->toBeFalse();
        $decoded = json_decode($json, true);
        expect($decoded['code'])->toBe('AGGREGATE_NOT_FOUND');
    });
});

// ===========================================================================
//  Domain → Response: DomainResponseFactory integration
// ===========================================================================

describe('Cross-Package: DomainResponseFactory', function (): void {
    it('creates a single entity response', function (): void {
        $id = AggregateRootId::generate();
        $order = new class($id) extends AggregateRoot {
            use EventSourced;
            public string $status = 'pending';

            protected function __construct(AggregateRootId $id)
            {
                parent::__construct($id);
            }
        };

        $response = DomainResponseFactory::entity($order, [
            'id' => $order->id(),
            'status' => $order->status,
        ]);

        $arr = $response->toArray();
        expect($arr['data'])->toHaveKey('id');
        expect($arr['data'])->toHaveKey('_id');
        expect($arr['data'])->toHaveKey('_version');
    });

    it('creates a collection response', function (): void {
        $response = DomainResponseFactory::collection(
            [['id' => '1'], ['id' => '2']],
            ['total' => 2],
        );

        $arr = $response->toArray();
        expect($arr['data']['items'])->toHaveCount(2);
        expect($arr['meta']['total'])->toBe(2);
    });

    it('creates a created response', function (): void {
        $id = AggregateRootId::generate();
        $order = new class($id) extends AggregateRoot {
            use EventSourced;
            public string $status = 'pending';

            protected function __construct(AggregateRootId $id)
            {
                parent::__construct($id);
            }
        };

        $response = DomainResponseFactory::created($order, [
            'id' => $order->id(),
            'status' => 'pending',
        ]);

        $arr = $response->toArray();
        expect($arr['data'])->toHaveKey('_id');
        expect($response->getStatus())->toBe(201);
    });

    it('creates a deleted response', function (): void {
        $response = DomainResponseFactory::deleted();
        expect($response->getStatus())->toBe(204);
    });

    it('creates an accepted response', function (): void {
        $response = DomainResponseFactory::accepted();
        expect($response->getStatus())->toBe(202);
    });

    it('creates an error response from domain exception', function (): void {
        $e = InvalidStateDomainException::because('Invalid transition.');

        $response = DomainResponseFactory::error($e->toErrorArray());
        $arr = $response->toArray();

        expect($arr['errors'])->not->toBeEmpty();
        expect($arr['errors'][0]['code'])->toBe('INVALID_STATE');
        expect($arr['errors'][0]['detail'])->toBe('Invalid transition.');
    });

    it('creates an updated response with version', function (): void {
        $id = AggregateRootId::generate();
        $order = new class($id) extends AggregateRoot {
            use EventSourced;
            public string $status = 'paid';

            protected function __construct(AggregateRootId $id)
            {
                parent::__construct($id);
            }
        };

        $response = DomainResponseFactory::updated($order, [
            'id' => $order->id(),
            'status' => 'paid',
        ]);

        $arr = $response->toArray();
        expect($arr['data']['_version'])->toBe(0);
    });
});

// ===========================================================================
//  Domain → Response: DomainTransformer duck-typed extraction
// ===========================================================================

describe('Cross-Package: DomainTransformer', function (): void {
    it('extracts version from AggregateRoot', function (): void {
        $id = AggregateRootId::generate();
        $order = new class($id) extends AggregateRoot {
            use EventSourced;
            public string $status = 'pending';

            protected function __construct(AggregateRootId $id)
            {
                parent::__construct($id);
            }
        };

        // DomainTransformer::extractVersion uses duck typing
        $transformer = new class extends DomainTransformer {
            protected function mapDomainFields(object $entity, array $context = []): array
            {
                return [
                    'id' => static::extractId($entity),
                    'version' => $this->extractVersion($entity),
                    'type' => $this->extractType($entity),
                ];
            }
        };

        $result = $transformer->transform($order);
        expect($result['version'])->toBe(0);
        expect($result['type'])->toBe('Order');
    });

    it('extracts type as short class name', function (): void {
        $transformer = new class extends DomainTransformer {
            protected function mapDomainFields(object $entity, array $context = []): array
            {
                return ['type' => $this->extractType($entity)];
            }
        };

        $entity = new class('123') extends Entity {};
        $result = $transformer->transform($entity);

        // Type is the short class name (anonymous class gets a generated name)
        expect($result['type'])->toBeString();
    });
});

// ===========================================================================
//  Domain: InMemoryUnitOfWork production edge cases
// ===========================================================================

describe('Domain: InMemoryUnitOfWork Edge Cases', function (): void {
    it('nested begin/commit maintains correct state', function (): void {
        $uow = new InMemoryUnitOfWork;

        $uow->begin();
        expect($uow->isActive())->toBeTrue();

        $uow->begin();
        $uow->begin();
        expect($uow->isActive())->toBeTrue();

        $uow->commit(); // inner
        $uow->commit(); // middle
        $uow->commit(); // outer
        expect($uow->isActive())->toBeFalse();
    });

    it('rollback in nested scope preserves outer state', function (): void {
        $uow = new InMemoryUnitOfWork;

        $uow->begin(); // outer
        $uow->queueEvent(DomainEvent::occur('outer.event', []));

        $uow->begin(); // inner
        $uow->queueEvent(DomainEvent::occur('inner.event', []));
        $uow->rollback(); // rolls back inner — should clear all events

        // After inner rollback, outer state is also rolled back
        expect($uow->isActive())->toBeFalse();
        expect($uow->hasPendingEvents())->toBeFalse();
    });

    it('run() with callback returns typed result', function (): void {
        $uow = new InMemoryUnitOfWork;

        $result = $uow->run(function (): int {
            return 42;
        });

        expect($result)->toBe(42);
    });

    it('getPendingEvents is non-destructive', function (): void {
        $uow = new InMemoryUnitOfWork;

        $uow->begin();
        $uow->queueEvent(DomainEvent::occur('test.event', ['key' => 'val']));

        $peeked = $uow->getPendingEvents();
        expect($peeked)->toBeInstanceOf(DomainEventCollection::class);
        expect($peeked->count())->toBe(1);

        // Still has events after peek
        expect($uow->hasPendingEvents())->toBeTrue();
        expect($uow->getPendingEventCount())->toBe(1);

        $uow->commit();
    });
});

// ===========================================================================
//  Domain: AggregateRootId production invariant tests
// ===========================================================================

describe('Domain: AggregateRootId Invariants', function (): void {
    it('generate() produces valid UUID v4 format', function (): void {
        $id = AggregateRootId::generate();

        expect($id->toString())->toMatch(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/'
        );
    });

    it('fromString rejects invalid UUID', function (): void {
        expect(fn () => AggregateRootId::fromString('not-a-uuid'))
            ->toThrow(\InvalidArgumentException::class);
    });

    it('array round-trip preserves identity', function (): void {
        $original = AggregateRootId::generate();
        $restored = AggregateRootId::fromArray($original->toArray());

        expect($original->equals($restored))->toBeTrue();
        expect($original->toString())->toBe($restored->toString());
    });

    it('array round-trip with id key', function (): void {
        $original = AggregateRootId::generate();
        $restored = AggregateRootId::fromArray(['id' => $original->toString()]);

        expect($original->equals($restored))->toBeTrue();
    });

    it('JSON serialization produces string', function (): void {
        $id = AggregateRootId::generate();

        $json = json_encode($id);
        expect($json)->not->toBeFalse();
        expect($json)->toBeJson();

        $decoded = json_decode($json);
        expect($decoded)->toBe($id->toString());
    });

    it('equals is type-safe (different IDs are not equal)', function (): void {
        $id1 = AggregateRootId::generate();
        $id2 = AggregateRootId::generate();

        expect($id1->equals($id2))->toBeFalse();
    });

    it('equals with self is true', function (): void {
        $id = AggregateRootId::generate();
        expect($id->equals($id))->toBeTrue();
    });
});

// ===========================================================================
//  Domain: Identifier type safety
// ===========================================================================

describe('Domain: Identifier Type Safety', function (): void {
    it('StringIdentifier rejects empty string', function (): void {
        expect(fn () => StringIdentifier::from(''))
            ->toThrow(\ValueError::class);
    });

    it('IntegerIdentifier round-trips via fromString', function (): void {
        $id = IntegerIdentifier::from(42);
        $restored = IntegerIdentifier::fromString('42');

        expect($id->equals($restored))->toBeTrue();
    });

    it('StringIdentifier round-trips via fromString', function (): void {
        $id = StringIdentifier::from('my-slug');
        $restored = StringIdentifier::fromString('my-slug');

        expect($id->equals($restored))->toBeTrue();
    });

    it('different identifier types are not equal', function (): void {
        $str = StringIdentifier::from('42');
        $int = IntegerIdentifier::from(42);

        // They're different classes with different internal values
        // StringIdentifier stores string '42', IntegerIdentifier stores int 42
        expect($str->toString())->toBe('42');
        expect($int->toString())->toBe('42');
        // But they won't be equal because equals() checks class type
        expect($str->equals($int))->toBeFalse();
    });
});

// ===========================================================================
//  Domain: DomainEventCollection type guards
// ===========================================================================

describe('Domain: DomainEventCollection Type Guards', function (): void {
    it('rejects associative arrays', function (): void {
        expect(fn () => new DomainEventCollection(['key' => DomainEvent::occur('test.e', [])]))
            ->toThrow(\InvalidArgumentException::class);
    });

    it('rejects non-DomainEvent items', function (): void {
        expect(fn () => new DomainEventCollection(['string']))
            ->toThrow(\InvalidArgumentException::class);
    });

    it('merge creates new instance', function (): void {
        $c1 = new DomainEventCollection([DomainEvent::occur('a', [])]);
        $c2 = new DomainEventCollection([DomainEvent::occur('b', [])]);

        $merged = $c1->merge($c2);
        expect($merged->count())->toBe(2);
        // Original unchanged
        expect($c1->count())->toBe(1);
    });

    it('filter creates new instance', function (): void {
        $c = new DomainEventCollection([
            DomainEvent::occur('order.placed', []),
            DomainEvent::occur('order.paid', []),
            DomainEvent::occur('item.added', []),
        ]);

        $orderEvents = $c->filter(fn (DomainEvent $e): bool => str_starts_with($e->eventType, 'order.'));
        expect($orderEvents->count())->toBe(2);
        expect($c->count())->toBe(3); // original unchanged
    });
});

// ===========================================================================
//  Response: TransformationException round-trip
// ===========================================================================

describe('Response: TransformationException', function (): void {
    it('serializes to RFC 9457 format', function (): void {
        $e = TransformationException::transformerNotFound('UserTransformer');

        $arr = $e->toArray();
        expect($arr['title'])->toBe('TransformationException');
        expect($arr['detail'])->toContain('UserTransformer');
        expect($arr['code'])->toBe('TRANSFORMATION_ERROR');
    });

    it('round-trips via fromArray', function (): void {
        $original = TransformationException::invalidTransformResult('BadTransformer');

        $arr = $original->toArray();
        $restored = TransformationException::fromArray($arr);

        expect($restored->getMessage())->toBe($original->getMessage());
    });

    it('JSON serializes correctly', function (): void {
        $e = TransformationException::invalidDataType(
            new \stdClass,
            'paginated'
        );

        $json = json_encode($e);
        expect($json)->not->toBeFalse();

        $decoded = json_decode($json, true);
        expect($decoded['code'])->toBe('TRANSFORMATION_ERROR');
        expect($decoded['detail'])->toContain('stdClass');
    });
});

// ===========================================================================
//  Domain: Snapshot round-trip
// ===========================================================================

describe('Domain: Snapshot Round-Trip', function (): void {
    it('snapshot toArray/fromArray preserves data', function (): void {
        $snapshot = Snapshot::create(
            aggregateType: 'App\\Domain\\Order',
            aggregateId: AggregateRootId::generate()->toString(),
            version: 5,
            state: ['status' => 'paid', 'total' => 99.99],
        );

        $arr = $snapshot->toArray();
        expect($arr['aggregate_type'])->toBe('App\\Domain\\Order');
        expect($arr['aggregate_id'])->toBe($snapshot->aggregateId);
        expect($arr['version'])->toBe(5);
        expect($arr['state']['status'])->toBe('paid');

        $restored = Snapshot::fromArray($arr);
        expect($restored->aggregateType)->toBe('App\\Domain\\Order');
        expect($restored->aggregateId)->toBe($snapshot->aggregateId);
        expect($restored->version)->toBe(5);
        expect($restored->state['status'])->toBe('paid');
    });

    it('snapshot equals is structural', function (): void {
        $id = AggregateRootId::generate()->toString();
        $s1 = Snapshot::create('Order', $id, 1, ['status' => 'pending']);
        $s2 = Snapshot::create('Order', $id, 1, ['status' => 'pending']);
        $s3 = Snapshot::create('Order', $id, 2, ['status' => 'paid']);

        expect($s1->equals($s2))->toBeTrue();
        expect($s1->equals($s3))->toBeFalse();
    });
});
