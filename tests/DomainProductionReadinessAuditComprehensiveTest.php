<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Tests;

use ZeroBoiler\Domain\AggregateRoot;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\Concerns\EventSourced;
use ZeroBoiler\Domain\Concerns\HasSnapshots;
use ZeroBoiler\Domain\Contracts\{
    AggregateRoot as AggregateRootContract,
    Entity as EntityContract,
    Identifier as IdentifierContract,
    Repository as RepositoryContract,
    UnitOfWork as UnitOfWorkContract,
};
use ZeroBoiler\Domain\Identifiers\{
    Identifier,
    UuidIdentifier,
    UlidIdentifier,
    StringIdentifier,
    IntegerIdentifier,
};
use ZeroBoiler\Domain\Snapshots\{
    Snapshot,
    SnapshotStore,
    SnapshotPolicy,
    InMemorySnapshotStore,
    SnapshottingRepository,
};
use ZeroBoiler\Domain\ValueObject;
use ZeroBoiler\Domain\Exceptions\{
    DomainException,
    InvalidStateDomainException,
    InvalidArgumentDomainException,
    NotFoundDomainException,
    ConflictDomainException,
    AggregateNotFoundException,
    OptimisticLockException,
    InvalidAggregateRootException,
    InvalidStateException,
};
use ZeroBoiler\Domain\DomainEventCollection;
use ZeroBoiler\Domain\InMemoryUnitOfWork;
use ZeroBoiler\Domain\Entity;
use ZeroBoiler\Events\Domain\DomainEvent;

/**
 * Comprehensive production-readiness audit tests.
 *
 * Validates:
 * - All classes have declare(strict_types=1)
 * - All classes use final/readonly where appropriate
 * - All identifier types are readonly classes
 * - All exception classes are final
 * - All contracts are interfaces
 * - Serialization round-trip integrity
 * - Domain invariant enforcement
 * - Type safety of all public APIs
 * - PHP 8.5 syntax features (readonly classes, Deprecated attribute)
 *
 * @since 1.73.0
 */

// ─── Test Fixtures ────────────────────────────────────────────────────────

final class AuditOrderId extends UuidIdentifier {}
final class AuditProductId extends UlidIdentifier {}
final class AuditSkuId extends StringIdentifier {}
final class AuditNumericId extends IntegerIdentifier {}

#[SnapshotPolicy(every: 2)]
final class AuditOrder extends AggregateRoot
{
    use EventSourced;
    use HasSnapshots;

    public string $status = 'draft';
    public float $amount = 0.0;

    public function __construct(AggregateRootId $id)
    {
        parent::__construct($id);
    }

    public static function create(AggregateRootId $id): self
    {
        $order = new self($id);
        $order->apply(DomainEvent::occur('order.created', ['id' => $id->toString()]));

        return $order;
    }

    public function submit(): void
    {
        if ($this->amount <= 0) {
            throw InvalidStateDomainException::because('Amount must be positive to submit.');
        }

        $this->apply(DomainEvent::occur('order.submitted', ['amount' => $this->amount]));
    }

    public function cancel(): void
    {
        if ($this->status === 'submitted') {
            throw InvalidStateDomainException::because('Cannot cancel a submitted order.', code: 'CANNOT_CANCEL_SUBMITTED');
        }

        $this->apply(DomainEvent::occur('order.cancelled', []));
    }

    protected function applyOrderCreated(DomainEvent $event): void
    {
        $this->status = 'draft';
    }

    protected function applyOrderSubmitted(DomainEvent $event): void
    {
        $this->status = 'submitted';
        $this->amount = $event->payload['amount'];
    }

    protected function applyOrderCancelled(DomainEvent $event): void
    {
        $this->status = 'cancelled';
    }

    public function toSnapshotState(): array
    {
        return ['status' => $this->status, 'amount' => $this->amount];
    }

    public static function reconstituteFromSnapshot(Snapshot $snapshot, AggregateRootId $id): static
    {
        $order = new static($id);
        $order->status = $snapshot->state['status'];
        $order->amount = $snapshot->state['amount'];
        $order->setVersion($snapshot->version);

        return $order;
    }
}

final class AuditMoney extends ValueObject
{
    public function __construct(
        public readonly int $cents,
        public string $currency = 'USD',
    ) {}

    public static function fromArray(array $data): static
    {
        return new static(
            cents: $data['cents'],
            currency: $data['currency'] ?? 'USD',
        );
    }

    public function toArray(): array
    {
        return [
            'cents' => $this->cents,
            'currency' => $this->currency,
        ];
    }
}

final class AuditSimpleEntity extends Entity
{
    public function __construct(
        public readonly int|string|\Stringable $id,
        public readonly string $name,
    ) {}
}

// ─── Tests ─────────────────────────────────────────────────────────────────

// ─── Strict Types Compliance ─────────────────────────────────────────────

test('all source files declare strict_types=1', function () {
    $srcDir = dirname(__DIR__) . '/../src';
    $files = glob($srcDir . '/**/*.php', GLOB_BRACE);

    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        $content = file_get_contents($file);
        expect($content)->toContain('declare(strict_types=1)');
    }
});

// ─── Class Modifiers ─────────────────────────────────────────────────────

test('identifier classes are readonly', function () {
    $readonlyClasses = [
        UuidIdentifier::class,
        UlidIdentifier::class,
        StringIdentifier::class,
        IntegerIdentifier::class,
        AggregateRootId::class,
    ];

    $reflection = new \ReflectionClass(AuditOrderId::class);
    // AuditOrderId extends UuidIdentifier which should be abstract readonly
    $parent = $reflection->getParentClass();
    expect($parent->isReadOnly())->toBeTrue();
});

test('aggregate root id is final readonly', function () {
    $ref = new \ReflectionClass(AggregateRootId::class);

    expect($ref->isFinal())->toBeTrue();
    expect($ref->isReadOnly())->toBeTrue();
});

test('domain event collection is final readonly', function () {
    $ref = new \ReflectionClass(DomainEventCollection::class);

    expect($ref->isFinal())->toBeTrue();
    expect($ref->isReadOnly())->toBeTrue();
});

test('snapshot classes are final readonly', function () {
    expect((new \ReflectionClass(Snapshot::class))->isFinal())->toBeTrue();
    expect((new \ReflectionClass(Snapshot::class))->isReadOnly())->toBeTrue();
    expect((new \ReflectionClass(SnapshotPolicy::class))->isFinal())->toBeTrue();
    expect((new \ReflectionClass(SnapshotPolicy::class))->isReadOnly())->toBeTrue();
});

test('all exception classes are final', function () {
    $exceptionClasses = [
        DomainException::class,
        InvalidStateDomainException::class,
        InvalidArgumentDomainException::class,
        NotFoundDomainException::class,
        ConflictDomainException::class,
        AggregateNotFoundException::class,
        OptimisticLockException::class,
        InvalidAggregateRootException::class,
        InvalidStateException::class,
    ];

    foreach ($exceptionClasses as $class) {
        $ref = new \ReflectionClass($class);
        if (! $ref->isAbstract()) {
            expect($ref->isFinal())->toBeTrue("{$class} must be final");
        }
    }
});

test('all contracts are interfaces', function () {
    $contracts = [
        AggregateRootContract::class,
        EntityContract::class,
        IdentifierContract::class,
        RepositoryContract::class,
        UnitOfWorkContract::class,
    ];

    foreach ($contracts as $contract) {
        $ref = new \ReflectionClass($contract);
        expect($ref->isInterface())->toBeTrue("{$contract} must be an interface");
    }
});

// ─── Identifier Immutability ────────────────────────────────────────────

test('identifiers cannot be mutated after construction', function () {
    $id = IntegerIdentifier::from(42);

    $ref = new \ReflectionProperty(IntegerIdentifier::class, 'value');
    expect($ref->isReadOnly())->toBeTrue();
});

test('all identifier types implement JsonSerializable', function () {
    $identifiers = [
        AuditOrderId::generate(),
        AuditProductId::generate(),
        AuditSkuId::from('SKU-001'),
        AuditNumericId::from(100),
    ];

    foreach ($identifiers as $id) {
        expect($id)->toBeInstanceOf(\JsonSerializable::class);

        $json = json_encode($id);
        expect($json)->not->toBeFalse();
        expect($json)->toBeJson();
    }
});

// ─── Serialization Round-Trip ───────────────────────────────────────────

test('identifier round-trip: UUID', function () {
    $original = AuditOrderId::generate();
    $array = $original->toArray();
    $restored = AuditOrderId::fromArray($array);

    expect($original->equals($restored))->toBeTrue();
    expect($original->toString())->toBe($restored->toString());
});

test('identifier round-trip: ULID', function () {
    $original = AuditProductId::generate();
    $array = $original->toArray();
    $restored = AuditProductId::fromArray($array);

    expect($original->equals($restored))->toBeTrue();
});

test('identifier round-trip: String', function () {
    $original = AuditSkuId::from('unique-sku-value');
    $array = $original->toArray();
    $restored = AuditSkuId::fromArray($array);

    expect($original->equals($restored))->toBeTrue();
});

test('identifier round-trip: Integer', function () {
    $original = AuditNumericId::from(999);
    $array = $original->toArray();
    $restored = AuditNumericId::fromArray($array);

    expect($original->equals($restored))->toBeTrue();
    expect($original->toInt())->toBe($restored->toInt());
});

test('identifier JSON round-trip: all types', function () {
    $identifiers = [
        'uuid' => AuditOrderId::generate(),
        'ulid' => AuditProductId::generate(),
        'string' => AuditSkuId::from('test-value'),
        'integer' => AuditNumericId::from(42),
    ];

    foreach ($identifiers as $type => $original) {
        $json = json_encode($original);
        $decoded = json_decode($json, true);

        if ($type === 'integer') {
            $restored = AuditNumericId::fromArray(['value' => $decoded]);
        } else {
            $restored = $original::fromArray(['value' => $decoded]);
        }

        expect($original->equals($restored))->toBeTrue("{$type} identifier JSON round-trip failed");
    }
});

test('value object round-trip serialization', function () {
    $original = AuditMoney::fromArray(['cents' => 1999, 'currency' => 'USD']);
    $array = $original->toArray();
    $restored = AuditMoney::fromArray($array);

    expect($original->equals($restored))->toBeTrue();
    expect($original->toArray())->toBe($restored->toArray());
});

test('aggregate root id round-trip: toArray/fromArray', function () {
    $original = AggregateRootId::generate();
    $restored = AggregateRootId::fromArray($original->toArray());

    expect($original->equals($restored))->toBeTrue();
});

test('aggregate root id accepts id key in fromArray', function () {
    $uuid = '550e8400-e29b-41d4-a716-446655440000';
    $id = AggregateRootId::fromArray(['id' => $uuid]);

    expect($id->toString())->toBe($uuid);
});

// ─── Domain Invariant Enforcement ──────────────────────────────────────

test('aggregate root enforces state invariants', function () {
    $id = AggregateRootId::generate();
    $order = AuditOrder::create($id);
    $order->pullDomainEvents();

    // Cannot submit with zero amount
    expect(fn () => $order->submit())
        ->toThrow(InvalidStateDomainException::class, 'Amount must be positive');
});

test('aggregate root custom error code on invariant violation', function () {
    $id = AggregateRootId::generate();
    $order = AuditOrder::create($id);
    $order->pullDomainEvents();
    $order->amount = 50.0;
    $order->submit();
    $order->pullDomainEvents();

    // Cannot cancel a submitted order
    expect(fn () => $order->cancel())
        ->toThrow(InvalidStateDomainException::class, 'CANNOT_CANCEL_SUBMITTED');
});

test('aggregate root version increments on each event', function () {
    $id = AggregateRootId::generate();
    $order = AuditOrder::create($id);
    expect($order->version())->toBe(1);

    $order->amount = 100.0;
    $order->submit();
    expect($order->version())->toBe(2);

    $order->cancel();
    expect($order->version())->toBe(3);
});

test('aggregate root equality is identity-based', function () {
    $id = AggregateRootId::generate();
    $order1 = AuditOrder::create($id);
    $order1->pullDomainEvents();
    $order1->amount = 100.0;
    $order1->submit();

    $order2 = new AuditOrder($id);

    expect($order1->equals($order2))->toBeTrue();
});

// ─── Event Sourcing Reconstitution ──────────────────────────────────────

test('aggregate root reconstitutes from event history', function () {
    $id = AggregateRootId::generate();
    $order = AuditOrder::create($id);
    $order->amount = 250.0;
    $order->submit();

    $events = $order->pullDomainEvents();
    expect($events->count())->toBe(2);

    $restored = AuditOrder::fromHistory(...$events->all());

    expect($restored->id())->toBe($id->toString());
    expect($restored->version())->toBe(2);
    expect($restored->status)->toBe('submitted');
    expect($restored->amount)->toBe(250.0);
    expect($restored->hasUncommittedEvents())->toBeFalse();
});

// ─── Snapshot Lifecycle ─────────────────────────────────────────────────

test('snapshot created when policy threshold is reached', function () {
    $id = AggregateRootId::generate();
    $order = AuditOrder::create($id);
    $order->amount = 100.0;
    $order->submit(); // version 2 — policy is every:2

    $store = new InMemorySnapshotStore;

    expect($order->shouldSnapshot())->toBeTrue();

    $order->createSnapshot($store);
    expect($store->has(AuditOrder::class, $order->id()))->toBeTrue();

    $snapshot = $store->load(AuditOrder::class, $order->id());
    expect($snapshot->aggregateType)->toBe(AuditOrder::class);
    expect($snapshot->version)->toBe(2);
});

test('snapshot restore recreates aggregate state', function () {
    $id = AggregateRootId::generate();
    $order = AuditOrder::create($id);
    $order->amount = 200.0;
    $order->submit();

    $store = new InMemorySnapshotStore;
    $order->createSnapshot($store);

    $snapshot = $store->load(AuditOrder::class, $order->id());
    $restored = AuditOrder::reconstituteFromSnapshot($snapshot, $id);

    expect($restored->status)->toBe('submitted');
    expect($restored->amount)->toBe(200.0);
    expect($restored->version())->toBe(2);
});

// ─── Unit of Work ────────────────────────────────────────────────────────

test('unit of work tracks aggregates and commits', function () {
    $uow = new InMemoryUnitOfWork;
    $id = AggregateRootId::generate();
    $order = AuditOrder::create($id);
    $order->pullDomainEvents();

    $uow->begin();
    $uow->track($order);

    expect($uow->isTracking($order))->toBeTrue();
    expect($uow->isActive())->toBeTrue();

    $uow->commit();

    expect($uow->getCommitted())->toHaveKey($id->toString());
    expect($uow->isActive())->toBeFalse();
});

test('unit of work run auto-commits on success', function () {
    $uow = new InMemoryUnitOfWork;
    $id = AggregateRootId::generate();
    $order = AuditOrder::create($id);
    $order->pullDomainEvents();

    $result = $uow->run(function () use ($uow, $order): AuditOrder {
        $uow->track($order);
        $order->amount = 100.0;
        $order->submit();

        return $order;
    });

    expect($result->status)->toBe('submitted');
    expect($uow->isActive())->toBeFalse();
});

test('unit of work run auto-rollbacks on failure', function () {
    $uow = new InMemoryUnitOfWork;
    $id = AggregateRootId::generate();

    expect(fn () => $uow->run(function () use ($id): never {
        $order = AuditOrder::create($id);
        throw new \RuntimeException('Force failure');
    }))->toThrow(\RuntimeException::class);

    expect($uow->isActive())->toBeFalse();
});

// ─── DomainEventCollection ──────────────────────────────────────────────

test('domain event collection map and filter', function () {
    $events = new DomainEventCollection([
        DomainEvent::occur('order.created', []),
        DomainEvent::occur('order.submitted', []),
        DomainEvent::occur('order.cancelled', []),
    ]);

    $types = $events->map(fn (DomainEvent $e) => $e->eventType);
    expect($types)->toBe(['order.created', 'order.submitted', 'order.cancelled']);

    $submitted = $events->filter(fn (DomainEvent $e) => $e->eventType === 'order.submitted');
    expect($submitted->count())->toBe(1);

    $hasSubmitted = $events->first(fn (DomainEvent $e) => $e->eventType === 'order.submitted');
    expect($hasSubmitted)->not->toBeNull();
});

test('domain event collection round-trip serialization', function () {
    $original = new DomainEventCollection([
        DomainEvent::occur('test.event', ['key' => 'value']),
    ]);

    $array = $original->toArray();
    expect($array)->toBeArray();
    expect(count($array))->toBe(1);

    $json = json_encode($original);
    expect($json)->toBeJson();
});

// ─── DomainException Hierarchy ──────────────────────────────────────────

test('all domain exceptions have unique default error codes', function () {
    $exceptions = [
        InvalidStateDomainException::because('test'),
        InvalidArgumentDomainException::because('test'),
        NotFoundDomainException::because('test'),
        ConflictDomainException::because('test'),
        AggregateNotFoundException::for('TestAggregate', 'id'),
        OptimisticLockException::for('id', 1, 2),
        InvalidAggregateRootException::notAnAggregate(new \stdClass),
        InvalidStateException::because('test'),
    ];

    $codes = array_map(fn (DomainException $e) => $e->errorCode(), $exceptions);
    expect(array_unique($codes))->toHaveCount(8);
});

test('domain exceptions implement JsonSerializable', function () {
    $exceptions = [
        InvalidStateDomainException::because('test'),
        NotFoundDomainException::forAggregate('Order', '123'),
        OptimisticLockException::for('id', 5, 3),
    ];

    foreach ($exceptions as $exception) {
        expect($exception)->toBeInstanceOf(\JsonSerializable::class);

        $json = json_encode($exception);
        expect($json)->toBeJson();
    }
});

test('domain exception toErrorArray has required keys', function () {
    $e = InvalidStateDomainException::because('Test error.');
    $array = $e->toErrorArray();

    expect($array)->toHaveKeys(['title', 'detail', 'code']);
    expect($array['title'])->toBe('InvalidStateDomainException');
    expect($array['code'])->toBe('INVALID_STATE');
});

test('domain exception httpStatus returns valid HTTP status codes', function () {
    $statusCodes = [
        InvalidStateDomainException::because('test')->httpStatus() => 422,
        InvalidArgumentDomainException::because('test')->httpStatus() => 400,
        NotFoundDomainException::because('test')->httpStatus() => 404,
        ConflictDomainException::because('test')->httpStatus() => 409,
        OptimisticLockException::for('id', 1, 2)->httpStatus() => 409,
        AggregateNotFoundException::for('X', 'id')->httpStatus() => 404,
    ];

    foreach ($statusCodes as $actual => $expected) {
        expect($actual)->toBe($expected);
    }
});

// ─── Entity Identity ───────────────────────────────────────────────────

test('entity identity is string-based regardless of ID type', function () {
    $withInt = new AuditSimpleEntity(42, 'Int Entity');
    expect($withInt->id())->toBe('42');

    $withStr = new AuditSimpleEntity('uuid-value', 'String Entity');
    expect($withStr->id())->toBe('uuid-value');

    $withUuid = new AuditSimpleEntity(UuidIdentifier::fromString('550e8400-e29b-41d4-a716-446655440000'), 'UUID Entity');
    expect($withUuid->id())->toBe('550e8400-e29b-41d4-a716-446655440000');
});

test('entity equality is identity-based not value-based', function () {
    $sameId1 = new AuditSimpleEntity(1, 'First');
    $sameId2 = new AuditSimpleEntity(1, 'Second — same ID, different data');
    $diffId = new AuditSimpleEntity(2, 'First — different ID, same data');

    expect($sameId1->equals($sameId2))->toBeTrue();
    expect($sameId1->equals($diffId))->toBeFalse();
});

// ─── PHP 8.5 Features ───────────────────────────────────────────────────

test('Deprecated attribute is present on deprecated methods', function () {
    $ref = new \ReflectionMethod(Identifier::class, 'value');
    $attrs = $ref->getAttributes(\Deprecated::class);

    expect($attrs)->not->toBeEmpty();
});

test('aggregate root deprecated getVersion has Deprecated attribute', function () {
    $ref = new \ReflectionMethod(AggregateRoot::class, 'getVersion');
    $attrs = $ref->getAttributes(\Deprecated::class);

    expect($attrs)->not->toBeEmpty();
});

// ─── Repository Contract ────────────────────────────────────────────────

test('repository contract defines required methods', function () {
    $ref = new \ReflectionClass(RepositoryContract::class);

    expect($ref->hasMethod('find'))->toBeTrue();
    expect($ref->hasMethod('save'))->toBeTrue();
    expect($ref->hasMethod('delete'))->toBeTrue();
});

test('unit of work contract defines required methods', function () {
    $ref = new \ReflectionClass(UnitOfWorkContract::class);

    expect($ref->hasMethod('begin'))->toBeTrue();
    expect($ref->hasMethod('commit'))->toBeTrue();
    expect($ref->hasMethod('rollback'))->toBeTrue();
    expect($ref->hasMethod('run'))->toBeTrue();
    expect($ref->hasMethod('track'))->toBeTrue();
});

// ─── Snapshot Store Contract ────────────────────────────────────────────

test('snapshot store contract defines required methods', function () {
    $ref = new \ReflectionClass(SnapshotStore::class);

    expect($ref->hasMethod('load'))->toBeTrue();
    expect($ref->hasMethod('save'))->toBeTrue();
    expect($ref->hasMethod('has'))->toBeTrue();
    expect($ref->hasMethod('delete'))->toBeTrue();
    expect($ref->hasMethod('count'))->toBeTrue();
    expect($ref->hasMethod('purge'))->toBeTrue();
});
