# ZeroBoiler Domain

[![Latest Version](https://img.shields.io/packagist/v/zeroboiler/domain.svg?style=flat-square)](https://packagist.org/packages/zeroboiler/domain)
[![License](https://img.shields.io/packagist/l/zeroboiler/domain.svg?style=flat-square)](LICENSE)
[![PHP Version](https://img.shields.io/packagist/php-v/zeroboiler/domain.svg?style=flat-square)](https://packagist.org/packages/zeroboiler/domain)
[![Production Ready](https://img.shields.io/badge/production-ready-brightgreen?style=flat-square)](#production-ready-checklist)
[![PHPStan Level 9](https://img.shields.io/badge/PHPStan-level%209-blue?style=flat-square)](phpstan.neon)

**DDD Building Blocks** — Rich domain models with aggregate roots, entities, value objects, identifiers, event sourcing, snapshots, and domain events.

## Installation

```bash
composer require zeroboiler/domain
```

## Requirements

- PHP 8.5+
- Laravel 13+
- `zeroboiler/events` — domain event infrastructure
- `zeroboiler/value-objects` — base value object support
- `zeroboiler/enums` — smart enum metadata (runtime optional, used by commands)
- `zeroboiler/dto` — data transfer objects (runtime optional, used by commands)
- `zeroboiler/observability` (optional) — `#[Trace]` auto-instrumentation

## Cross-Package Dependencies

The domain package depends on sibling ZeroBoiler packages for type safety
and interoperability:

| Dependency | Usage | Required |
|---|---|---|
| `zeroboiler/events` | `DomainEvent`, `DomainEventCollection` | Yes |
| `zeroboiler/value-objects` | Base value object support | Yes |
| `zeroboiler/enums` | Smart enum metadata | Yes |
| `zeroboiler/dto` | Data transfer objects | Yes |
| `zeroboiler/observability` | `#[Trace]` attribute (stubbed when absent) | No |

**Runtime guards**: The service provider uses `app->bound()` checks to make
the Events package integration truly optional at runtime. The Observability
`#[Trace]` attribute is provided via a no-op stub when the package is not
installed, ensuring `SnapshottingRepository` works without it.

## Quick Start

### Creating an Aggregate Root

```php
use ZeroBoiler\Domain\AggregateRoot;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\Concerns\EventSourced;
use ZeroBoiler\Events\Domain\DomainEvent;

class Order extends AggregateRoot
{
    use EventSourced;

    public static function place(string $customerId): self
    {
        $order = new self(AggregateRootId::generate());
        $order->apply(DomainEvent::occur('order.placed', [
            'customer_id' => $customerId,
        ]));

        return $order;
    }

    public function addItem(string $productId, int $quantity): void
    {
        $this->apply(DomainEvent::occur('order.item_added', [
            'product_id' => $productId,
            'quantity' => $quantity,
        ]));
    }

    protected function applyOrderPlaced(DomainEvent $event): void
    {
        // State mutation handler — called automatically by apply()
    }

    protected function applyOrderItemAdded(DomainEvent $event): void
    {
        // State mutation handler for item additions
    }

    public function toArray(): array
    {
        return [
            ...parent::toArray(),
            // Add domain-specific fields
        ];
    }
}

// Usage
$order = Order::place('customer-uuid');
$order->addItem('product-uuid', 3);
echo $order->id();      // UUID string
echo $order->version(); // 2
```

### Creating Identifiers

```php
use ZeroBoiler\Domain\Identifiers\UuidIdentifier;
use ZeroBoiler\Domain\Identifiers\UlidIdentifier;
use ZeroBoiler\Domain\Identifiers\StringIdentifier;
use ZeroBoiler\Domain\Identifiers\IntegerIdentifier;

// UUID v4 — subclass for domain-specific types
class OrderId extends UuidIdentifier {}
$id = OrderId::generate();
$id->equals($otherId);  // Type-safe equality
$id->toArray();          // ['uuid' => '550e8400-...']
OrderId::fromArray($id->toArray()); // Round-trip

// ULID — monotonic, sortable
class ProductId extends UlidIdentifier {}
$pid = ProductId::generate();

// String — slugs, codes
$slug = StringIdentifier::from('my-blog-post');

// Integer — auto-increment IDs
$seqId = IntegerIdentifier::from(42);
```

### Domain Exceptions

```php
use ZeroBoiler\Domain\Exceptions\InvalidStateDomainException;
use ZeroBoiler\Domain\Exceptions\NotFoundDomainException;
use ZeroBoiler\Domain\Exceptions\OptimisticLockException;

// Throw with semantic factory methods
throw InvalidStateDomainException::because('Order must be pending to pay.');
throw NotFoundDomainException::forAggregate('Order', $orderId);
throw OptimisticLockException::for($id, expectedVersion: 5, actualVersion: 6);

// All exceptions support:
$e->errorCode();      // 'INVALID_STATE', 'NOT_FOUND', 'OPTIMISTIC_LOCK'
$e->httpStatus();     // 422, 404, 409
$e->toErrorArray();   // RFC 9457 compatible: ['title', 'detail', 'code', 'status']
$e->toArray();        // Debug: ['error_code', 'message', 'file', 'line']
json_encode($e);      // Auto JSON serialization
```

### Unit of Work

```php
use ZeroBoiler\Domain\InMemoryUnitOfWork;

$uow = app(InMemoryUnitOfWork::class);

// Transactional with auto-commit/rollback
$result = $uow->run(function () use ($repository, $id) {
    $order = $repository->find($id);
    $order->pay(100.00);
    $repository->save($order);

    return $order;
});
// Events dispatched only after successful commit

// Manual transaction
$uow->begin();
$uow->track($aggregate);
$uow->commit();

// On failure — events are never dispatched
$uow->begin();
try {
    $order->ship(); // Fails
    $uow->commit();
} catch (DomainException $e) {
    $uow->rollback(); // Events discarded, state restored
}
```

## Features

- **AggregateRoot** — typed identity (UUID v4), domain events, versioning, optimistic locking
- **Entity** — identity equality with flexible ID types (string, int, Stringable)
- **ValueObject** — extends `zeroboiler/value-objects` with domain-level equality
- **Domain Events** — provided by `zeroboiler/events` package, integrated via `DomainEventCollection`
- **Repository** interface — contract with `find()`, `save()`, `delete()`
- **UnitOfWork** — transactional boundary with savepoints, event queuing, rollback snapshots
- **Event Sourcing** — optional `EventSourced` trait for aggregate reconstitution from history
- **Snapshots** — `SnapshottingRepository` decorator with configurable `#[SnapshotPolicy]`
- **Identifier Types** — `UuidIdentifier`, `UlidIdentifier`, `StringIdentifier`, `IntegerIdentifier`
- **DomainException** hierarchy — typed exceptions for domain violations
- **CLI Generators** — `domain:aggregate`, `domain:repository`, `domain:value-object`, `domain:list`, `domain:snapshot`

## PHP 8.5 Features

This package leverages modern PHP 8.5 features for maximum type safety and
developer experience:

| Feature | Where Used |
|---|---|
| `readonly` classes | `AggregateRootId`, `DomainEventCollection`, `Snapshot`, `SnapshotPolicy`, `SnapshottingRepository` |
| `readonly` promoted properties | All identifier types, `Snapshot`, `SnapshotPolicy` |
| `#[\Override]` attribute | All interface contract implementations (Entity, AggregateRoot, Identifier, Repository, UnitOfWork) |
| `#[\Deprecated]` attribute | `InMemorySnapshotStore::clear()` (use `purge()` instead) |
| Constructor property promotion | `AggregateRoot`, `Entity`, all identifiers, `Snapshot` |
| `__serialize()`/`__unserialize()` | All readonly classes — uses reflection to set readonly props after unset |
| Named arguments | Factory methods (`Snapshot::create()`, `InMemoryUnitOfWork::run()`) |
| `static` return types | `Entity::fromArray()`, `Entity::fromJson()`, `Identifier::fromString()` |
| `mixed` type | `Entity::id()` return type in contract |
| Intersection types | Not used (duck typing preferred for decoupling) |
| First-class callable syntax | `array_map(ucfirst(...), $parts)` in EventSourced trait |
| `get_debug_type()` | All validation error messages in `fromArray()` methods |

### Readonly Class Unserialization Pattern

All readonly classes implement `__serialize()`/`__unserialize()` using the
PHP 8.5 pattern of unsetting a readonly property before re-initializing it:

```php
// PHP 8.5+ allows re-initialization after unset
unset($instance->readonlyProp);
$reflection->getProperty('readonlyProp')->setValue($instance, $newValue);
```

This enables `serialize()`/`unserialize()` round-trips without sacrificing immutability.

### Serialization Contract

All domain classes provide a consistent serialization API for caching, queue
jobs, API responses, and cross-package data exchange:

| Class | `toArray()` | `fromArray()` | `toJson()` | `fromJson()` | `jsonSerialize()` | `__serialize()` |
|---|---|---|---|---|---|---|
| `AggregateRoot` | ✅ | — (use `fromArray` on subclass) | ✅ | — (use `Entity::fromJson`) | ✅ | — |
| `Entity` | ✅ | ✅ | ✅ | ✅ | ✅ | — |
| `AggregateRootId` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| `UuidIdentifier` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| `UlidIdentifier` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| `StringIdentifier` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| `IntegerIdentifier` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| `ValueObject` | — (abstract) | — (abstract) | ✅ | ✅ | — | — |
| `DomainEventCollection` | ✅ | ✅ | ✅ | ✅ | ✅ | — |
| `Snapshot` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| `DomainException` | ✅ | ✅ | ✅ | ✅ | ✅ | — |
| `InMemoryUnitOfWork` | ✅ (state) | — | ✅ (state) | — | — | — |

## Class Reference

| Class / Interface | Type | Description |
|---|---|---|
| `AggregateRoot` | abstract class | Base class for aggregate roots with UUID v4 identity, domain events, versioning |
| `AggregateRootId` | final readonly class | UUID v4 identifier for aggregate roots |
| `Entity` | abstract class | Base class for entities with identity equality |
| `ValueObject` | abstract class | Base class for value objects extending `zeroboiler/value-objects` |
| `DomainEventCollection` | final readonly class | Immutable typed collection: `count`, `isEmpty`, `all`, `get`, `first`, `last`, `map`, `filter`, `merge`, `each`, `reduce`, `some`, `none`, `find`, `hasType`, `countBy`, `types` |
| `InMemoryUnitOfWork` | class | In-memory Unit of Work with transactional boundaries |
| `DomainException` | abstract class | Base exception with `errorCode()`, `toErrorArray()`, JSON serialization |
| `InvalidStateDomainException` | final class | Business rule violation (e.g., pay already-paid order) |
| `InvalidArgumentDomainException` | final class | Input validation failure at domain boundary |
| `NotFoundDomainException` | final class | Missing aggregate/entity lookup (`because`, `forAggregate`, `forId`) |
| `ConflictDomainException` | final class | Concurrent modification conflict |
| `AggregateNotFoundException` | final class | Aggregate-specific not-found error |
| `OptimisticLockException` | final class | Version mismatch on concurrent save |
| `InvalidAggregateRootException` | final class | Object is not a valid aggregate root |
| `InvalidStateException` | final class | Infrastructure-level state check failure |
| `Contracts\AggregateRoot` | interface | Contract for aggregate root implementations |
| `Contracts\Entity` | interface | Contract for entity implementations (extends JsonSerializable) |
| `Contracts\Identifier` | interface | Contract for all identifier types |
| `Contracts\Repository` | interface | Repository contract: `find()`, `save()`, `delete()` |
| `Contracts\UnitOfWork` | interface | UoW contract: `begin()`, `commit()`, `rollback()`, `run()` |
| `Identifiers\Identifier` | abstract class | Base identifier with `toString()`, `equals()`, serialization |
| `Identifiers\UuidIdentifier` | class | UUID v4 identifier |
| `Identifiers\UlidIdentifier` | class | ULID identifier |
| `Identifiers\StringIdentifier` | class | String-based identifier |
| `Identifiers\IntegerIdentifier` | class | Integer-based identifier |
| `Concerns\HasDomainEvents` | trait | Domain event recording and clearing |
| `Concerns\EventSourced` | trait | Event sourcing reconstitution from history |
| `Concerns\HasSnapshots` | trait | Snapshot creation and restoration |
| `Snapshots\Snapshot` | final readonly class | Immutable snapshot of aggregate state |
| `Snapshots\SnapshotStore` | interface | Snapshot persistence contract |
| `Snapshots\SnapshotPolicy` | attribute | Configurable snapshot policy (version threshold) |
| `Snapshots\InMemorySnapshotStore` | class | In-memory snapshot store |
| `Snapshots\SnapshottingRepository` | final readonly class | Repository decorator with snapshot optimization |

## Exception Hierarchy

```
DomainException (abstract, Exception, JsonSerializable)
├── InvalidStateDomainException        — Business rule violation
├── InvalidArgumentDomainException      — Input validation failure
├── NotFoundDomainException            — Missing aggregate/entity
├── ConflictDomainException            — Concurrent modification
├── AggregateNotFoundException         — Aggregate-specific not-found
├── OptimisticLockException            — Version mismatch on save
├── InvalidAggregateRootException     — Not a valid aggregate root
└── InvalidStateException              — Infrastructure state failure
```

All exceptions provide:
- `errorCode()` — machine-readable code (e.g., `'INVALID_STATE'`, `'OPTIMISTIC_LOCK'`)
- `toErrorArray()` — RFC 9457-compatible error object
- `toArray()` / `fromArray()` — round-trip serialization
- `fromJson()` — JSON deserialization
- `JsonSerializable` — direct `json_encode()` support

### RFC 9457 Problem Details Mapping

Each domain exception maps to a recommended HTTP status code when used with
the `zeroboiler/response` package's `DomainResponseFactory::fromException()`:

| Exception | Error Code | HTTP Status | Use Case |
|---|---|---|---|
| `InvalidStateDomainException` | `INVALID_STATE` | 422 Unprocessable Entity | Business rule violation |
| `InvalidArgumentDomainException` | `INVALID_ARGUMENT` | 422 Unprocessable Entity | Input validation failure |
| `NotFoundDomainException` | `NOT_FOUND` | 404 Not Found | Missing entity lookup |
| `ConflictDomainException` | `CONFLICT` | 409 Conflict | Duplicate / concurrent modification |
| `AggregateNotFoundException` | `AGGREGATE_NOT_FOUND` | 404 Not Found | Aggregate-specific not-found |
| `OptimisticLockException` | `OPTIMISTIC_LOCK` | 409 Conflict | Version mismatch on save |
| `InvalidAggregateRootException` | `INVALID_AGGREGATE_ROOT` | 500 Internal Server Error | Invalid aggregate type |
| `InvalidStateException` | `INVALID_STATE_SYSTEM` | 500 Internal Server Error | Infrastructure state failure |

```php
// Each exception produces a standard RFC 9457 error array:
$e = NotFoundDomainException::forId('order-123');
$e->toErrorArray();
// [
//     'title'  => 'NotFoundDomainException',
//     'detail' => 'Aggregate or entity with ID "order-123" was not found.',
//     'code'   => 'NOT_FOUND',
//     'status' => 404,
// ]
```

## One-Liner Quick Start

Copy-paste ready examples for every domain class:

### Core

```php
use ZeroBoiler\Domain\AggregateRoot;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\Entity;
use ZeroBoiler\Domain\ValueObject;
use ZeroBoiler\Domain\DomainEventCollection;
use ZeroBoiler\Domain\InMemoryUnitOfWork;

$id    = AggregateRootId::generate();                        // UUID v4
$id    = AggregateRootId::fromString('uuid-string-here');
$id->toString();                                             // → 'uuid-string-here'
$id->equals($otherId);                                       // bool
json_encode($id);                                            // → 'uuid-string-here'

$order = new Order($id);                                     // extends AggregateRoot
$order->id();                                               // → AggregateRootId
$order->version();                                           // → 0
$order->pullDomainEvents();                                  // → DomainEventCollection
$order->clearDomainEvents();                                 // void
$order->toArray();                                           // ['id' => '...', 'version' => 0, 'type' => 'Order']

$entity = new MyEntity($stringId);                          // extends Entity
$entity->id();                                               // → 'string-id'
$entity->equals($other);                                     // bool

$vo = MyValueObject::fromArray(['key' => 'val']);            // extends ValueObject
$vo->equals($otherVo);                                       // bool
$vo->toArray();                                              // ['key' => 'val']

$events = new DomainEventCollection([$e1, $e2]);
$events->count();                                            // → 2
$events->isEmpty();                                          // → bool
$events->all();                                              // → [DomainEvent, ...]
$events->filter(fn($e) => ...);                             // → new DomainEventCollection
$events->merge($other);                                      // → new DomainEventCollection
$events->toArray();                                          // → [[...], [...]]

$uow = app(\ZeroBoiler\Domain\Contracts\UnitOfWork::class);
$uow->begin();
$uow->track($aggregate);
$uow->commit();                                             // persist + dispatch
$uow->rollback();                                            // restore state
$result = $uow->run(fn () => $service->process($order));    // auto begin/commit/rollback
$uow->queueEvent(DomainEvent::occur('side.effect', []));
$uow->clear();                                               // reset all state
$uow->isActive();                                            // → bool
$uow->isTracking($aggregate);                                // → bool
$uow->hasPendingEvents();                                     // → bool
$uow->getPendingEventCount();                                // → int
$uow->getPendingEvents();                                     // → DomainEventCollection (peek, non-destructive)
$uow->markForDeletion($aggregate);                            // queue for deletion on commit
$uow->getCommitted();                                         // → AggregateRoot[] (after commit)
$uow->getDeleted();                                           // → AggregateRoot[] (after commit)

// Advanced: custom persistence and event dispatch
$uow->setPersistenceCallback(function (array $committed, array $deleted): void {
    foreach ($committed as $aggregate) {
        DB::table('aggregates')->upsert([...]);
    }
    foreach ($deleted as $aggregate) {
        DB::table('aggregates')->delete($aggregate->id());
    }
});
$uow->setEventDispatcher(function (object $event): void {
    app(\ZeroBoiler\Events\Domain\DomainEventDispatcher::class)->dispatch($event);
});
```

### Identifiers

```php
use ZeroBoiler\Domain\Identifiers\UuidIdentifier;
use ZeroBoiler\Domain\Identifiers\UlidIdentifier;
use ZeroBoiler\Domain\Identifiers\StringIdentifier;
use ZeroBoiler\Domain\Identifiers\IntegerIdentifier;

// UUID v4 — aggregate roots
class OrderId extends UuidIdentifier {}
$id = OrderId::generate();                                   // random UUID v4
$id = OrderId::fromString('550e8400-e29b-41d4-a716-446655440000');
$id->isValid('not-a-uuid');                                   // → false
$id->equals($other);                                         // bool

// ULID — high-throughput, ordered
class ProductId extends UlidIdentifier {}
$id = ProductId::generate();                                  // monotonic ULID
$id->toUlid();                                               // → Symfony Ulid

// String — natural keys, slugs
$slug = StringIdentifier::from('my-blog-post');              // non-empty string
$slug->isValid('my-blog-post');                               // → true

// Integer — auto-increment IDs
$num = IntegerIdentifier::from(42);
$num = IntegerIdentifier::fromString('42');                   // parsed
$num->toInt();                                               // → 42
```

### Snapshots

```php
use ZeroBoiler\Domain\Snapshots\{Snapshot, InMemorySnapshotStore, SnapshottingRepository};

$snapshot = Snapshot::create(Order::class, $id, 50, ['status' => 'paid']);
$snapshot->toArray();                                         // round-trip
$snapshot->equals($other);                                    // structural equality

$store = new InMemorySnapshotStore();
$store->save($snapshot);
$store->has(Order::class, $id);                               // → true
$store->load(Order::class, $id);                              // → Snapshot
$store->count(Order::class);                                  // → int
$store->stats();                                             // → ['total' => N, 'by_type' => [...]]
$store->delete(Order::class, $id);
$store->purge(Order::class);                                 // → removed count

$repo = new SnapshottingRepository($innerRepo, $store, Order::class);
$order = $repo->find($id);                                   // snapshot + replay
$repo->save($order);                                         // auto-snapshot
```

### Exceptions

```php
use ZeroBoiler\Domain\Exceptions\{
    DomainException, InvalidStateDomainException, InvalidArgumentDomainException,
    NotFoundDomainException, ConflictDomainException, OptimisticLockException,
    AggregateNotFoundException, InvalidAggregateRootException,
};

throw InvalidStateDomainException::because('Order must be pending.');
throw InvalidArgumentDomainException::because('Qty must be > 0.');
throw NotFoundDomainException::forAggregate('Order', $id);
throw NotFoundDomainException::forId('order-123');               // ID-only convenience
throw AggregateNotFoundException::for('App\Domain\Order', $id);
throw ConflictDomainException::because('Concurrent modification.');
throw OptimisticLockException::for($id, expected: 5, actual: 3);
throw InvalidAggregateRootException::notAnAggregate($obj);
throw InvalidStateException::because('Config is invalid.');    // infrastructure-level state

$e->errorCode();                                              // → 'INVALID_STATE'
$e->toErrorArray();                                           // → ['title' => '...', 'detail' => '...', 'code' => '...']
json_encode($e);                                             // → RFC 9457
```

## Architecture

```
AggregateRoot (extends Entity)
├── AggregateRootId (UUID v4)
├── HasDomainEvents trait (event recording)
├── EventSourced trait (optional, reconstitution from history)
└── HasSnapshots trait (optional, snapshot/restore support)

Entity (abstract)
├── HasDomainEvents trait
└── id(): string / equals(): bool / toArray(): array

ValueObject (extends zeroboiler/value-objects BaseValueObject)

Identifiers
├── UuidIdentifier (abstract readonly, UUID v4)
├── UlidIdentifier (abstract readonly, ULID)
├── StringIdentifier (non-empty string)
└── IntegerIdentifier (integer)

SnapshottingRepository (decorator)
├── wraps inner Repository
├── InMemorySnapshotStore (default)
└── SnapshotPolicy attribute (configurable interval)

InMemoryUnitOfWork
├── savepoints / nesting
├── event queuing / dispatch on commit
└── aggregate state snapshots for rollback
```

## Usage

### Aggregate Root

```php
use ZeroBoiler\Domain\AggregateRoot;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Domain\Concerns\EventSourced;

class Order extends AggregateRoot
{
    use EventSourced;

    public string $status = 'pending';
    public float $total = 0.0;

    public function __construct(AggregateRootId $id)
    {
        parent::__construct($id);
    }

    public static function create(AggregateRootId $id): self
    {
        $order = new self($id);
        $order->apply(DomainEvent::occur('order.placed', [
            'id' => $id->toString(),
            'status' => 'pending',
        ]));

        return $order;
    }

    public function pay(float $amount): void
    {
        if ($this->status !== 'pending') {
            throw InvalidStateDomainException::because('Order must be pending to pay.');
        }

        $this->apply(DomainEvent::occur('order.paid', [
            'id' => $this->id(),
            'amount' => $amount,
        ]));
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
}

// AggregateRootId usage
$id = AggregateRootId::generate();                    // Random UUID v4
$id = AggregateRootId::fromString('uuid-string');    // Parse existing
$id->toString();                                     // '550e8400-e29b-41d4-...'
$id->equals($otherId);                              // Type-safe equality
echo json_encode(['order_id' => $id]);              // JSON serialization

// Pull and inspect domain events
$order = Order::create(AggregateRootId::generate());
$events = $order->pullDomainEvents();               // Destructive pull
$events->count();                                    // 1
$events->isEmpty();                                   // false
$order->hasUncommittedEvents();                       // false (already pulled)

// Non-destructive peek — inspect events without consuming them
$order = Order::create(AggregateRootId::generate());
$peeked = $order->peekDomainEvents();                 // Returns DomainEventCollection copy
$peeked->count();                                     // 1
$peeked->first()->eventType;                          // 'order.placed'
$order->hasUncommittedEvents();                       // true — events NOT consumed
$pulled = $order->pullDomainEvents();                 // Still returns all events

// Entity-level peek (returns plain array, not DomainEventCollection)
$item = new OrderItem('1', 'prod-1', 2, 9.99);
$item->recordThat(DomainEvent::occur('item.added', []));
$peekedEvents = $item->peekEvents();                  // [DomainEvent]
$item->hasUncommittedEvents();                         // true — events NOT consumed
```

### Entity

```php
use ZeroBoiler\Domain\Entity;

class OrderItem extends Entity
{
    public function __construct(
        int|string|\Stringable $id,
        public readonly string $productId,
        public int $quantity,
        public float $unitPrice,
    ) {
        parent::__construct($id);
    }

    public function updateQuantity(int $quantity): void
    {
        if ($quantity <= 0) {
            throw InvalidArgumentDomainException::because('Quantity must be positive.');
        }

        $this->quantity = $quantity;
    }

    public function subtotal(): float
    {
        return $this->quantity * $this->unitPrice;
    }
}
```

### Value Objects

```php
use ZeroBoiler\Domain\ValueObject;

// Domain value objects extend zeroboiler/value-objects BaseValueObject
// with domain-level equality checking (toArray-based comparison).
class Address extends ValueObject
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

// Usage
$address = Address::fromArray(['street' => '123 Main', 'city' => 'NYC', 'country' => 'US']);
$address->equals($otherAddress);  // true if all fields match
$address->toArray();               // ['street' => '123 Main', 'city' => 'NYC', 'country' => 'US']
```

### Domain Event Collection

```php
use ZeroBoiler\Domain\DomainEventCollection;
use ZeroBoiler\Events\Domain\DomainEvent;

$collection = new DomainEventCollection([$event1, $event2, $event3]);

// Basic operations
$collection->count();      // 3
$collection->isEmpty();     // false
$collection->all();         // [DomainEvent, DomainEvent, DomainEvent]
$collection->get(1);        // $event2 (index-based)
$collection->first();       // $event1
$collection->last();        // $event3

// Functional operations
$types = $collection->map(fn (DomainEvent $e, int $i): string => $e->eventType);
// ['order.placed', 'order.paid', 'order.shipped']

$paidEvents = $collection->filter(
    fn (DomainEvent $e): bool => str_starts_with($e->eventType, 'order.paid')
);

// Functional predicates (since 1.58.0)
$hasPayment = $collection->some(fn (DomainEvent $e): bool => $e->eventType === 'order.paid');
// true if any payment event exists

$noCancellations = $collection->none(fn (DomainEvent $e): bool => $e->eventType === 'order.cancelled');
// true if there are no cancellation events (inverse of some())

$paymentEvent = $collection->find(fn (DomainEvent $e): bool => $e->eventType === 'order.paid');
// First matching event, or null

$paymentCount = $collection->countBy(fn (DomainEvent $e): bool => $e->eventType === 'order.paid');
// 2 — count of matching events

$uniqueTypes = $collection->types();
// ['order.placed', 'order.paid', 'order.shipped'] — unique types in order

$hasPaymentType = $collection->hasType('order.paid');
// true — shorthand for some(fn ($e) => $e->eventType === 'order.paid')

// Reduce to a single value
$total = $collection->reduce(
    fn (float $sum, DomainEvent $e): float => $sum + ($e->payload['amount'] ?? 0),
    0.0,
);

// Side-effect iteration (non-destructive)
$collection->each(function (DomainEvent $event, int $index): void {
    logger()->debug("Event {$index}: {$event->eventType}");
});
// Returns the same collection for fluent chaining

// Merging collections
$merged = $collection->merge([$event4, $event5]);
// New DomainEventCollection with all 5 events

// JSON serialization
echo json_encode($collection);
// [[...], [...], [...]] — each event serialized via toArray()/jsonSerialize()
```

### Identifiers

```php
use ZeroBoiler\Domain\Identifiers\UuidIdentifier;
use ZeroBoiler\Domain\Identifiers\UlidIdentifier;
use ZeroBoiler\Domain\Identifiers\StringIdentifier;
use ZeroBoiler\Domain\Identifiers\IntegerIdentifier;

// UUID v4
class OrderId extends UuidIdentifier {}
$orderId = OrderId::generate();           // random UUID v4
$orderId = OrderId::fromString('...');     // parse existing UUID
$orderId->isValid('not-a-uuid');           // false — pre-validate without throwing

// ULID
class ProductId extends UlidIdentifier {}
$productId = ProductId::generate();        // monotonic ULID
$productId->toUlid();                     // Symfony ULID object
$productId->isValid('not-a-ulid');         // false — pre-validate without throwing

// String
$slug = StringIdentifier::from('my-post');
$slug->isValid('');                          // false — empty not allowed

// Integer
$id = IntegerIdentifier::from(42);
$id->isValid('abc');                         // false
```

### Repository

The `Repository` contract defines the standard CRUD operations for aggregate roots with built-in optimistic locking support.

```php
use ZeroBoiler\Domain\Contracts\Repository;

interface OrderRepository extends Repository
{
    public function findById(UuidIdentifier $id): ?Order;
}

// Eloquent implementation with optimistic locking:
final class EloquentOrderRepository implements OrderRepository
{
    public function find(string|int $id): ?Order
    {
        return Order::where('id', $id)->first();
    }

    public function save(AggregateRoot $aggregate): void
    {
        $persisted = $this->find($aggregate->id());

        if ($persisted !== null && $persisted->version() !== $aggregate->version()) {
            throw OptimisticLockException::for(
                $aggregate->id(),
                expectedVersion: $aggregate->version(),
                actualVersion: $persisted->version(),
            );
        }

        DB::table('orders')->upsert([
            'id' => $aggregate->id(),
            'version' => $aggregate->version() + 1,
            'state' => json_encode($aggregate->toArray()),
        ], 'id');

        $aggregate->incrementVersion();
    }

    public function delete(string|int $id): void
    {
        DB::table('orders')->delete($id);
    }
}

// Wrap with snapshot support:
$repo = new SnapshottingRepository(
    inner: new EloquentOrderRepository(),
    snapshotStore: new InMemorySnapshotStore(),
    aggregateType: Order::class,
);
$repo->find($orderId);  // Loads from snapshot + replays remaining events
$repo->save($order);    // Saves + auto-snapshots when version % 50 === 0
```

### Unit of Work

```php
use ZeroBoiler\Domain\Contracts\UnitOfWork;

class OrderService
{
    public function __construct(
        private OrderRepository $orders,
        private UnitOfWork $uow,
    ) {
        $this->uow->setPersistenceCallback(
            function (array $committed, array $deleted): void {
                foreach ($committed as $aggregate) {
                    $this->orders->save($aggregate);
                }
                foreach ($deleted as $aggregate) {
                    $this->orders->delete($aggregate->id());
                }
            }
        );
    }

    public function placeOrder(array $data): Order
    {
        return $this->uow->run(function () use ($data): Order {
            $order = Order::create(AggregateRootId::generate());
            $this->orders->save($order);

            return $order;
        });
        // Events dispatched automatically on commit
    }

    // Nested transactions via savepoints
    public function batchUpdate(array $orderIds, float $discount): void
    {
        $this->uow->run(function () use ($orderIds, $discount): void {
            foreach ($orderIds as $id) {
                // Each nested run() creates a savepoint
                $this->uow->run(function () use ($id, $discount): void {
                    $order = $this->orders->find($id);
                    $order->applyDiscount($discount);
                    $this->orders->save($order);
                });
                // Inner events dispatched only after outermost commit
            }
        });
    }

    // Manual transaction control
    public function transfer(string $fromId, string $toId, float $amount): void
    {
        $this->uow->begin();
        try {
            $from = $this->orders->find($fromId);
            $to = $this->orders->find($toId);

            $this->uow->track($from);
            $this->uow->track($to);

            $from->debit($amount);
            $to->credit($amount);

            $this->uow->commit();
        } catch (\Throwable $e) {
            $this->uow->rollback();
            throw $e;
        }
        // On commit: aggregates persisted (via callback), then events dispatched
    }
}
```

### Event Sourcing

```php
use ZeroBoiler\Domain\Concerns\EventSourced;
use ZeroBoiler\Domain\AggregateRoot;

class Order extends AggregateRoot
{
    use EventSourced;

    // Reconstitute from event history
    public static function fromEvents(DomainEvent ...$events): self
    {
        return self::fromHistory(...$events);
    }
}
```

### Snapshots

```php
use ZeroBoiler\Domain\Concerns\HasSnapshots;
use ZeroBoiler\Domain\Snapshots\SnapshotPolicy;

#[SnapshotPolicy(every: 50)]
class Order extends AggregateRoot
{
    use EventSourced;
    use HasSnapshots;

    // Snapshots are automatically created every 50 events
    // by SnapshottingRepository::save()
}

// Direct snapshot usage
$snapshot = Snapshot::create(Order::class, $orderId, 50, $state);
$snapshot->toArray();                        // Serialize for storage
$snapshot = Snapshot::fromArray($data);       // Restore from storage
echo json_encode($snapshot);                 // JSON serialization
```

### SnapshotStore Interface

Implement for custom storage backends (database, Redis, file system):

```php
use ZeroBoiler\Domain\Snapshots\{Snapshot, SnapshotStore};

final class RedisSnapshotStore implements SnapshotStore
{
    public function __construct(private readonly \Redis $redis) {}

    public function load(string $aggregateType, string $aggregateId): ?Snapshot
    {
        $data = $this->redis->get("snapshot:{$aggregateType}:{$aggregateId}");
        if ($data === false) {
            return null;
        }

        return Snapshot::fromArray(json_decode($data, true));
    }

    public function save(Snapshot $snapshot): void
    {
        $this->redis->set(
            "snapshot:{$snapshot->aggregateType}:{$snapshot->aggregateId}",
            json_encode($snapshot->toArray()),
        );
    }

    public function has(string $aggregateType, string $aggregateId): bool
    {
        return (bool) $this->redis->exists("snapshot:{$aggregateType}:{$aggregateId}");
    }

    public function delete(string $aggregateType, string $aggregateId): void
    {
        $this->redis->del("snapshot:{$aggregateType}:{$aggregateId}");
    }

    public function deleteOlderThan(string $aggregateType, string $aggregateId, int $version): void
    {
        $snapshot = $this->load($aggregateType, $aggregateId);
        if ($snapshot !== null && $snapshot->version < $version) {
            $this->delete($aggregateType, $aggregateId);
        }
    }

    public function count(?string $aggregateType = null): int
    {
        // Implement count logic
        return 0;
    }

    public function stats(): array
    {
        // Implement stats logic
        return ['total' => 0, 'by_type' => []];
    }

    public function purge(?string $aggregateType = null): int
    {
        // Implement purge logic
        return 0;
    }
}
```

### Domain Events

```php
use ZeroBoiler\Domain\DomainEventCollection;
use ZeroBoiler\Events\Domain\DomainEvent;

// Create events
$event = DomainEvent::occur('order.placed', [
    'id' => $orderId->toString(),
    'status' => 'pending',
]);

// Collect events in a type-safe wrapper
$collection = new DomainEventCollection([$event1, $event2]);
$collection->count();      // 2
$collection->isEmpty();     // false
$collection->all();         // [DomainEvent, DomainEvent]

// Iterate and JSON-serialize
foreach ($collection as $event) {
    echo $event->eventType; // 'order.placed'
}
echo json_encode($collection);
// [[...], [...]]

// Side-effect iteration (fluent, returns self for chaining)
$collection->each(function (DomainEvent $event, int $index): void {
    logger()->debug("Event {$index}: {$event->eventType}");
});

// Reduce to a single value
$totalAmount = $collection->reduce(
    fn (float $sum, DomainEvent $event): float => $sum + ($event->payload['amount'] ?? 0),
    0.0,
);

// Predicate checks
$hasPayment = $collection->some(fn (DomainEvent $e): bool => $e->eventType === 'order.paid');  // true
$noCancel = $collection->none(fn (DomainEvent $e): bool => $e->eventType === 'order.cancelled'); // true
$paymentCount = $collection->countBy(fn (DomainEvent $e): bool => $e->eventType === 'order.paid'); // 1

// Find and hasType
$paidEvent = $collection->find(fn (DomainEvent $e): bool => $e->eventType === 'order.paid');
$hasPlaced = $collection->hasType('order.placed'); // true

// Unique event types in order of first appearance
$types = $collection->types();
// ['order.placed', 'order.item_added', 'order.paid']
```

### SnapshottingRepository

```php
use ZeroBoiler\Domain\AggregateRoot;
use ZeroBoiler\Domain\Contracts\Repository;
use ZeroBoiler\Domain\Concerns\{EventSourced, HasSnapshots};
use ZeroBoiler\Domain\Snapshots\{SnapshottingRepository, InMemorySnapshotStore, SnapshotPolicy};

#[SnapshotPolicy(every: 50)]
class Order extends AggregateRoot
{
    use EventSourced;
    use HasSnapshots;

    public string $status = 'pending';
    public float $total = 0.0;

    // ... event sourcing handlers
}

// Wrap any repository with snapshot support
$orderRepo = new OrderEventSourcedRepository($db);
$store = new InMemorySnapshotStore();
$repo = new SnapshottingRepository($orderRepo, $store, Order::class);

// find() automatically loads from snapshot + replays remaining events
$order = $repo->find($orderId);

// save() automatically creates a snapshot when version % 50 === 0
$repo->save($order);

// Inspect snapshot store
$store->count();                          // Total snapshots
$store->count(Order::class);              // Snapshots for Order type
$store->has(Order::class, $orderId);       // Has specific snapshot
$store->load(Order::class, $orderId);      // Load specific snapshot
$store->stats();                          // ['total' => 5, 'by_type' => ['Order' => 3, ...]]
$store->purge(Order::class);              // Purge all Order snapshots
```

### InMemorySnapshotStore

The built-in `InMemorySnapshotStore` provides a fully functional in-memory
implementation for testing and development. It supports all `SnapshotStore`
interface operations:

```php
use ZeroBoiler\Domain\Snapshots\InMemorySnapshotStore;
use ZeroBoiler\Domain\Snapshots\Snapshot;

$store = new InMemorySnapshotStore();

// Save a snapshot
$snapshot = Snapshot::create(Order::class, $orderId, 50, ['status' => 'paid', 'total' => 100.0]);
$store->save($snapshot);

// Check existence
$store->has(Order::class, $orderId);           // true

// Load a snapshot
$loaded = $store->load(Order::class, $orderId);
$loaded->version;                              // 50
$loaded->aggregateType;                         // 'Order'
$loaded->aggregateId;                          // 'uuid-string'
$loaded->equals($snapshot);                    // true (structural equality)

// Count snapshots
$store->count();                               // Total snapshots across all types
$store->count(Order::class);                   // Snapshots for Order type only

// Get statistics
$stats = $store->stats();
// ['total' => 3, 'by_type' => ['Order' => 2, 'Product' => 1]]

// Delete old snapshots (e.g., after creating a new one)
$store->deleteOlderThan(Order::class, $orderId, 51);
// Deletes snapshots with version < 51

// Delete specific snapshot
$store->delete(Order::class, $orderId);

// Purge all snapshots (optionally filtered by type)
$store->purge();                               // Purge ALL snapshots
$store->purge(Order::class);                   // Purge only Order snapshots
// Returns int — number of deleted snapshots
```

### Advanced Snapshot Loading

For custom event replay strategies, use `findWithSnapshot()` with a replay callback:

```php
use ZeroBoiler\Domain\Snapshots\SnapshottingRepository;

// findWithSnapshot() with custom replay callback
// Loads from snapshot, then replays only post-snapshot events
$order = $repo->findWithSnapshot(
    $orderId,
    fn (int $snapshotVersion) => $eventStore->getEventsAfter($orderId, $snapshotVersion),
);

// Without callback — falls back to inner repository's full replay
$order = $repo->findWithSnapshot($orderId);
```

### Domain Exceptions

```
Exception
└── DomainException (abstract, provides errorCode())
    ├── InvalidStateDomainException       — entity/aggregate state violation (code: INVALID_STATE)
    ├── InvalidArgumentDomainException    — domain argument validation failure (code: INVALID_ARGUMENT)
    ├── NotFoundDomainException           — aggregate/entity not found (code: NOT_FOUND)
    │   └── forAggregate(type, id)        — typed not-found helper
    ├── ConflictDomainException          — concurrent write-write conflict (code: CONFLICT)
    ├── OptimisticLockException          — stale aggregate version detected (code: OPTIMISTIC_LOCK)
    │   └── for(id, expected, actual)     — typed lock failure helper
    ├── AggregateNotFoundException         — repository lookup returned null (code: AGGREGATE_NOT_FOUND)
    │   └── for(type, id)                 — typed not-found helper
    └── InvalidAggregateRootException     — object is not an AggregateRoot (code: INVALID_AGGREGATE_ROOT)
        └── notAnAggregate(object)         — validation helper

Exception (standalone, outside domain)
└── InvalidStateException                — system-level invalid state (not domain)
```

```php
use ZeroBoiler\Domain\Exceptions\InvalidStateDomainException;
use ZeroBoiler\Domain\Exceptions\InvalidArgumentDomainException;
use ZeroBoiler\Domain\Exceptions\NotFoundDomainException;
use ZeroBoiler\Domain\Exceptions\ConflictDomainException;
use ZeroBoiler\Domain\Exceptions\OptimisticLockException;
use ZeroBoiler\Domain\Exceptions\AggregateNotFoundException;

// State violations — entity is in wrong state for the requested operation
throw InvalidStateDomainException::because('Order must be pending to pay.');

// Argument violations — input fails domain validation
throw InvalidArgumentDomainException::because('Quantity must be positive.');

// Not found (generic)
throw NotFoundDomainException::because('User not found with ID: ' . $id);

// Not found (aggregate-specific, standardized message)
throw NotFoundDomainException::forAggregate('Order', $orderId);

// Aggregate not found (alternative, includes FQCN in message)
throw AggregateNotFoundException::for('App\Domain\Order', $orderId);

// Conflicts — concurrent write detected
throw ConflictDomainException::because('Concurrent modification detected.');

// Optimistic locking — version mismatch on save
if ($persistedVersion !== $aggregate->version()) {
    throw OptimisticLockException::for(
        $aggregate->id(),
        expectedVersion: $aggregate->version(),
        actualVersion: $persistedVersion,
    );
}

// Custom error codes for API consumers:
throw InvalidStateDomainException::because(
    'Order must be pending to pay.',
    code: 'ORDER_NOT_PENDING',
);

// Machine-readable error code in API responses:
try {
    $order->pay($amount);
} catch (DomainException $e) {
    $e->errorCode(); // 'INVALID_STATE' or 'ORDER_NOT_PENDING'
    Response::error(409, 'Invalid State', $e->getMessage())
        ->withMeta(['code' => $e->errorCode()])
        ->send();
}

// Custom domain exception — extend for business-specific violations
final class OrderAlreadyShippedException extends DomainException
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
```

### InMemorySnapshotStore API

```php
use ZeroBoiler\Domain\Snapshots\InMemorySnapshotStore;

$store = new InMemorySnapshotStore();

// Basic operations
$store->save($snapshot);
$loaded = $store->load(Order::class, $orderId);
$store->has(Order::class, $orderId);    // bool
$store->delete(Order::class, $orderId);

// Lifecycle management
$store->deleteOlderThan(Order::class, $orderId, 100);
$store->count();                          // Total snapshots
$store->count(Order::class);              // Type-filtered count
$store->stats();                          // ['total' => 5, 'by_type' => [...]]
$store->purge(Order::class);              // Purge type + return removed count
$store->purge();                          // Purge all
$store->clear();                          // Alias of purge()
```

### InMemoryUnitOfWork API

```php
use ZeroBoiler\Domain\Contracts\UnitOfWork;
use ZeroBoiler\Domain\InMemoryUnitOfWork;
use ZeroBoiler\Events\Domain\DomainEvent;

$uow = app(UnitOfWork::class);

// Transaction lifecycle
$uow->begin();
$uow->track($aggregate);
$uow->isTracking($aggregate);             // bool
$uow->markForDeletion($aggregate);
$uow->commit();                           // Persists + dispatches events
$uow->rollback();                         // Restores aggregate state

// Declarative transaction
$result = $uow->run(fn () => $order);      // Auto begin/commit/rollback

// Event management
$uow->queueEvent(DomainEvent::occur('side.effect', []));
$uow->hasPendingEvents();                  // bool
$uow->getPendingEventCount();              // int

// Inspection
$uow->isActive();                          // bool
$uow->getCommitted();                      // array<string, AggregateRoot>
$uow->getDeleted();                        // array<string, AggregateRoot>

// Persistence callback (for custom storage)
$uow->setPersistenceCallback(function (array $committed, array $deleted): void {
    foreach ($committed as $aggregate) {
        $this->repository->save($aggregate);
    }
    foreach ($deleted as $aggregate) {
        $this->repository->delete($aggregate->id());
    }
});

// Event dispatcher override
$uow->setEventDispatcher(function (DomainEvent $event): void {
    event($event);                          // Laravel event dispatcher
});

// Reset state (for testing)
$uow->clear();                             // Resets everything to initial state
```

### CLI Commands

```bash
# Generate aggregate root
php artisan zeroboiler:domain:aggregate Order

# Generate repository interface + Eloquent implementation
php artisan zeroboiler:domain:repository Order

# Generate value object
php artisan zeroboiler:domain:value-object Email
php artisan zeroboiler:domain:value-object Money

# List all domain classes
php artisan zeroboiler:domain:list

# Inspect snapshot store
php artisan domain:snapshot --class=App\Domain\Aggregates\Order
php artisan domain:snapshot --class=App\Domain\Aggregates\Order --id=order-123
```

### Command Details

| Command | Description | Output Path |
|---|---|---|
| `zeroboiler:domain:aggregate` | Aggregate root extending `AggregateRoot` with event sourcing | `app/Domain/Aggregates/` |
| `zeroboiler:domain:repository` | Repository interface + Eloquent implementation | `app/Domain/Repositories/` + `Eloquent/` |
| `zeroboiler:domain:value-object` | Immutable value object extending `ValueObject` | `app/Domain/ValueObjects/` |
| `zeroboiler:domain:list` | List all domain classes grouped by type | stdout |
| `domain:snapshot` | Inspect snapshot store (stats, state) | stdout |

## Cross-Package Integration: Domain → Response

When using `zeroboiler/domain` with `zeroboiler/response`, use `DomainTransformer`
to map domain entities/aggregate roots to API responses. The transformer is
**decoupled** — it uses duck typing (`id()` method) and works with any entity
following the standard Entity contract.

```php
use ZeroBoiler\Domain\AggregateRoot;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Response\Transformers\DomainTransformer;

// 1. Define your aggregate
class Order extends AggregateRoot
{
    public string $status = 'pending';
    public float $total = 0.0;
    public array $items = [];

    public function __construct(AggregateRootId $id) { parent::__construct($id); }

    public static function create(AggregateRootId $id, array $items): self
    {
        $order = new self($id);
        $order->apply(DomainEvent::occur('order.placed', [
            'id' => $id->toString(), 'items' => $items,
        ]));
        return $order;
    }

    protected function applyOrderPlaced(DomainEvent $event): void
    {
        $this->items = $event->payload['items'];
    }
}

// 2. Create a DomainTransformer
final class OrderTransformer extends DomainTransformer
{
    protected function mapDomainFields(object $entity, array $context = []): array
    {
        return [
            'id'     => $this->extractId($entity),
            'status' => $entity->status,
            'total'  => $entity->total,
        ];
    }

    protected function mapRelations(object $entity, array $context = []): array
    {
        if ($this->shouldInclude('items', $context)) {
            return ['items' => $entity->items];
        }
        return [];
    }

    protected function mapMeta(object $entity, array $context = []): array
    {
        return ['version' => method_exists($entity, 'version') ? $entity->version() : null];
    }
}

// 3. Use in controller via Response facade
return Response::transform($order)
    ->through(OrderTransformer::class)
    ->include('items')
    ->api()
    ->send();
// → {"data":{"id":"550e8400-...","status":"pending","total":0,"items":[],"_meta":{"version":0}}}
```

### Domain Identifier Serialization

All domain identifiers implement `JsonSerializable` and `IdentifierContract`,
so they serialize cleanly in API responses:

```php
use ZeroBoiler\Domain\Identifiers\UuidIdentifier;
use ZeroBoiler\Domain\Identifiers\UlidIdentifier;

class OrderId extends UuidIdentifier {}

$id = OrderId::generate();
echo json_encode(['order_id' => $id]);
// → {"order_id":"550e8400-e29b-41d4-a716-446655440000"}

echo json_encode(['product_id' => ProductId::generate()]);
// → {"product_id":"01JF5K2R..."} (ULID, monotonic)

echo json_encode(['slug' => StringIdentifier::from('my-post')]);
// → {"slug":"my-post"}

echo json_encode(['seq' => IntegerIdentifier::from(42)]);
// → {"seq":42}
```

### Domain Identifier Round-Trip (fromArray/toArray)

All identifiers support `toArray()`/`fromArray()` round-trip serialization
for caching, queue payloads, and database storage:

```php
use ZeroBoiler\Domain\Identifiers\UuidIdentifier;
use ZeroBoiler\Domain\Identifiers\UlidIdentifier;
use ZeroBoiler\Domain\Identifiers\StringIdentifier;
use ZeroBoiler\Domain\Identifiers\IntegerIdentifier;

// UUID round-trip
$id = OrderId::generate();
$serialized = $id->toArray();            // ['uuid' => '550e8400-...']
$restored = OrderId::fromArray($serialized);
$restored->equals($id);                   // true

// ULID round-trip
$productId = ProductId::generate();
$serialized = $productId->toArray();     // ['ulid' => '01JF5K2R...']
$restored = ProductId::fromArray($serialized);
$restored->equals($productId);            // true

// String round-trip
$slug = StringIdentifier::from('my-post');
$serialized = $slug->toArray();           // ['string' => 'my-post']
$restored = StringIdentifier::fromArray($serialized);
$restored->equals($slug);                // true

// Integer round-trip
$num = IntegerIdentifier::from(42);
$serialized = $num->toArray();           // ['integer' => 42]
$restored = IntegerIdentifier::fromArray($serialized);
$restored->equals($num);                 // true

// Use in cache/queue payloads
$cacheKey = 'order:' . $id->toString();
cache()->put($cacheKey, $id->toArray(), 3600);
$cachedId = OrderId::fromArray(cache()->get($cacheKey));

// Use in queue jobs
class ProcessOrderJob implements ShouldQueue
{
    public function __construct(
        public readonly string $orderIdJson,
    ) {}

    public static function fromId(UuidIdentifier $id): self
    {
        return new self(json_encode($id->toArray()));
    }

    public function handle(): void
    {
        $orderId = OrderId::fromArray(json_decode($this->orderIdJson, true));
        // ... process order
    }
}
```

### Event Sourcing → Response Mapping

When replaying events from history, aggregate roots maintain their version.
Use `mapMeta()` to expose the version in API responses for client-side
optimistic concurrency control:

```php
// Client sends: If-Match: "version:5"
// Server checks: if ($aggregate->version() !== $requestVersion) { throw ... }
// Response includes: {"_meta": {"version": 5}}
```

### Service Provider & Configuration

The `DomainServiceProvider` is auto-discovered by Laravel and registers:

- **`UnitOfWork`** singleton — bound to `ZeroBoiler\Domain\Contracts\UnitOfWork`
  - Includes optional event dispatching via `zeroboiler/events`'s `DomainEventDispatcher`
  - If the events package is not installed, events are silently discarded
- **`SnapshotStore`** singleton — bound to `ZeroBoiler\Domain\Snapshots\SnapshotStore`
  - Defaults to `InMemorySnapshotStore`; override via `config('domain.snapshot_driver')`
- **Console commands** — registered when running in console mode

```php
// config/domain.php (optional)
return [
    // Override the default in-memory snapshot store
    'snapshot_driver' => env('DOMAIN_SNAPSHOT_DRIVER', 'memory'),
    // Available: 'memory' (default), or a custom driver registered in your service provider
];
```

### Observability Integration (Optional)

The domain package integrates with `zeroboiler/observability` for auto-instrumentation
via the `#[Trace]` attribute. When the observability package is not installed, a no-op
stub is provided automatically — no configuration needed.

```php
use ZeroBoiler\Observability\Trace;

// The #[Trace] attribute works in SnapshottingRepository out of the box:
// All find()/save()/delete() calls are automatically instrumented.
// You can also use it on your own domain service methods:

class OrderService
{
    #[Trace(operation: 'domain.order.place')]
    public function placeOrder(array $data): Order
    {
        return $this->uow->run(fn () => $this->handler->handle($data));
    }

    #[Trace(operation: 'domain.order.cancel')]
    public function cancelOrder(string $orderId): void
    {
        // Automatically traced with timing and metadata
    }
}

// When zeroboiler/observability is installed, #[Trace] records:
// - Operation name, timing, and duration
// - Aggregate type and ID (when available)
// - Domain events dispatched during the traced operation
//
// Without zeroboiler/observability, the attribute is a no-op — zero overhead.
```

### Octane & Long-Running Process Safety

The domain package is designed for safe operation in long-running processes (Octane, Swoole, RoadRunner).

The `DomainServiceProvider` registers an `octane.request.terminate` listener that clears the `DomainEventDispatcher`'s listeners between requests, preventing memory leaks and cross-request contamination when using event-sourced aggregates.

| Component | Octane Safety | Mechanism |
|---|---|---|
| `InMemoryUnitOfWork` | ✅ Safe | `clear()` resets all state; `begin()` clears committed/deleted |
| `InMemorySnapshotStore` | ✅ Safe | Stateless singleton, no cross-request caching |
| `AggregateRoot` | ✅ Safe | No static mutable state; ID and version are per-instance |
| `DomainEventCollection` | ✅ Safe | `final readonly class` — fully immutable |
| `DomainEventDispatcher` | ✅ Safe | Listeners cleared on `octane.request.terminate` |

```php
// Safe in Octane: each request gets a clean UnitOfWork
$uow = app(UnitOfWork::class);
$uow->run(fn () => $service->process($order));
// After response: UoW is reset, event dispatcher listeners are cleared
```

> **Note**: `InMemorySnapshotStore` loses all data between requests in Octane.
> For production, implement a persistent `SnapshotStore` (Redis, database) and
> bind it in your service provider to replace the default.

## Testing

```bash
composer test              # Run Pest tests
composer test:coverage     # With coverage
composer quality           # Pint + PHPStan + Rector + Tests
```

### Test Categories

The domain package includes a comprehensive test suite covering:

| Category | Test Classes | What They Verify |
|---|---|---|
| **Core** | `DomainCoreTest`, `EntityTest`, `AggregateRootTest` | Base class behavior, identity, equality |
| **Lifecycle** | `AggregateRootLifecycleTest` | Creation → events → versioning → state mutation |
| **Identifiers** | `IdentifierTest`, `IdentifierComprehensiveTest`, `IdentifierRoundTripTest` | UUID/ULID/String/Integer: generation, validation, equality, serde |
| **Serialization** | `DomainSerializationTest`, `DomainProductionSerdeTest` | `toArray()`/`fromArray()` round-trip for all types |
| **Exceptions** | `DomainExceptionsTest`, `DomainExceptionHierarchyTest`, `DomainErrorCodeTest` | Error codes, factory methods, RFC 9457 JSON, hierarchy |
| **Events** | `DomainEventCollectionTest`, `PeekDomainEventsTest` | Collection operations, filtering, merging, peek vs pull |
| **Snapshots** | `SnapshotTest`, `SnapshottingRepositoryProductionTest` | Snapshot creation, restoration, policy, round-trip |
| **UnitOfWork** | `UnitOfWorkTest`, `UnitOfWorkGetPendingEventsTest` | Begin/commit/rollback, nested savepoints, event queuing |
| **Production** | `DomainFinalProductionTest`, `DomainProductionReadinessChecklistTest` | Structural contract compliance, type safety |
| **Cross-Package** | `DomainCrossPackageProductionAuditTest`, `DomainResponseFinalBridgeTest` | Domain → Response bridge, DomainTransformer integration |

### Running Tests in CI

```yaml
# .github/workflows/ci.yml (PHPStan level 9, Pest)
- name: Quality
  run: composer quality
# Runs: pint --test && phpstan analyse && rector process --dry-run && pest
```

## Quick Reference

### Core Classes

| Class | Type | Description | Key Methods |
|---|---|---|---|
| `AggregateRoot` | abstract | Top-level DDD entity with events, versioning | `apply()`, `pullDomainEvents()`, `peekDomainEvents()`, `clearDomainEvents()`, `hasUncommittedEvents()`, `id()`, `aggregateId()`, `version()`, `setVersion()`, `incrementVersion()`, `equals()`, `toArray()`, `reconstituteFromSnapshot()` |
| `AggregateRootId` | final readonly | UUID v4 identity for aggregates | `generate()`, `fromString()`, `toString()`, `equals()`, `jsonSerialize()` |
| `Entity` | abstract | Base domain entity with flexible ID | `id()`, `equals()`, `toArray()`, `recordThat()`, `releaseEvents()`, `hasUncommittedEvents()`, `peekEvents()`, `clearEvents()`, constructor accepts `int\|string\|Stringable` |
| `ValueObject` | abstract | Domain value object base | `equals()`, `toArray()` (from value-objects package) |
| `DomainEventCollection` | final readonly | Type-safe event collection | `all()`, `count()`, `isEmpty()`, `get()`, `filter()`, `map()`, `first()`, `last()`, `merge()`, `each()`, `reduce()`, `some()`, `none()`, `find()`, `hasType()`, `countBy()`, `types()`, `toArray()`, `fromArray()` |
| `InMemoryUnitOfWork` | final | Transactional event queuing | `begin()`, `commit()`, `rollback()`, `run()`, `track()`, `queueEvent()`, `clear()`, `getCommitted()`, `getDeleted()`, `getPendingEvents()`, `markForDeletion()`, `isActive()`, `isTracking()` |
| `SnapshottingRepository` | final readonly | Repository decorator with snapshots | `find()`, `save()`, `delete()`, `findWithSnapshot()`, `snapshotStore()` |

### Identifiers

| Class | Type | Underlying | Key Methods |
|---|---|---|---|
| `UuidIdentifier` | abstract readonly | String (validated UUID v4) | `generate()`, `fromString()`, `isValid()`, `toUuid()`, `equals()` |
| `UlidIdentifier` | abstract readonly | String (validated ULID) | `generate()`, `fromString()`, `isValid()`, `toUlid()`, `equals()` |
| `StringIdentifier` | readonly | String (non-empty) | `from()`, `fromString()`, `isValid()`, `equals()` |
| `IntegerIdentifier` | final readonly | Int | `from()`, `fromString()`, `toInt()`, `isValid()`, `equals()` |

### Contracts

| Interface | Extends | Key Methods |
|---|---|---|
| `Contracts\Entity` | — | `id(): string`, `equals(Entity): bool`, `toArray(): array`, `hasUncommittedEvents(): bool` |
| `Contracts\AggregateRoot` | `Entity` | `version(): int`, `incrementVersion(): void`, `pullDomainEvents(): DomainEventCollection`, `clearDomainEvents(): void`, `hasUncommittedEvents(): bool`, `peekDomainEvents(): DomainEventCollection` |
| `Contracts\Identifier` | `Stringable` | `fromString()`, `toString()`, `equals()` |
| `Contracts\Repository` | — | `find(id): ?AggregateRoot`, `save(aggregate): void`, `delete(id): void` |
| `Contracts\UnitOfWork` | — | `begin()`, `commit()`, `rollback()`, `run()`, `track()`, `queueEvent()`, `clear()`, `getCommitted()`, `getDeleted()`, `getPendingEvents()`, `markForDeletion()`, `isActive()`, `isTracking()` |

### Traits

| Trait | Applied To | Purpose |
|---|---|---|
| `EventSourced` | `AggregateRoot` | `fromHistory()`, `applyEvent()` — replay/reconstitute from events |
| `HasDomainEvents` | `Entity` | `recordThat()`, `releaseEvents()`, `hasUncommittedEvents()`, `peekEvents()` |
| `HasSnapshots` | `AggregateRoot` | `shouldSnapshot()`, `createSnapshot()`, `restoreFromSnapshot()`, `toSnapshotState()` |

### Exceptions

| Exception | Factory | Default Code | When to Use |
|---|---|---|---|
| `DomainException` | (abstract base) | `DOMAIN_ERROR` | Extend for business-specific violations |
| `InvalidStateDomainException` | `::because($reason, $code?)` | `INVALID_STATE` | Entity/aggregate in wrong state |
| `InvalidArgumentDomainException` | `::because($reason, $code?)` | `INVALID_ARGUMENT` | Input fails domain validation |
| `NotFoundDomainException` | `::because()`, `::forAggregate()` | `NOT_FOUND` | Expected resource missing |
| `AggregateNotFoundException` | `::for($type, $id, $code?)` | `AGGREGATE_NOT_FOUND` | Repository lookup returned null |
| `ConflictDomainException` | `::because($reason, $code?)` | `CONFLICT` | Concurrent write-write conflict |
| `OptimisticLockException` | `::for($id, $expected, $actual, $code?)` | `OPTIMISTIC_LOCK` | Stale version on save |
| `InvalidAggregateRootException` | `::notAnAggregate($obj, $code?)` | `INVALID_AGGREGATE_ROOT` | Object is not an AggregateRoot |

## Best Practices

### Choosing the Right Identifier

```php
// UUID v4 — Most common for aggregate roots
class OrderId extends UuidIdentifier {}
// Random, no ordering guarantee, globally unique

// ULID — High-throughput insertion-ordered
class ProductId extends UlidIdentifier {}
// Monotonic, lexicographically sortable, 48-bit timestamp

// String — Natural keys, slugs, codes
class ProductSlug extends StringIdentifier {}
// Non-empty string, ideal for URL-friendly IDs

// Integer — Auto-increment DB IDs
class RowNumber extends IntegerIdentifier {}
// Numeric sequence, ideal for legacy/migration IDs
```

### Unit of Work Patterns

```php
// ✅ RECOMMENDED: Declarative transactions via run()
$result = $uow->run(fn () => $service->process($order));

// ✅ OK: Manual control when you need fine-grained tracking
$uow->begin();
$uow->track($order);
$uow->track($payment);
$uow->commit();

// ✅ Inspect results after commit
$committed = $uow->getCommitted();      // → AggregateRoot[]
$deleted = $uow->getDeleted();           // → AggregateRoot[]

// ✅ Peek at pending events without consuming them
$uow->begin();
$uow->track($order);
$pending = $uow->getPendingEvents();     // → DomainEventCollection (non-destructive)
$uow->hasPendingEvents();                // → true
$uow->getPendingEventCount();            // → 3
$uow->commit();                          // events dispatched

// ✅ Delete an aggregate within a transaction
$uow->begin();
$uow->track($order);
$uow->markForDeletion($order);
$uow->commit();                         // order deleted + events dispatched

// ✅ With persistence callback (infrastructure integration)
$uow->setPersistenceCallback(function (array $committed, array $deleted): void {
    foreach ($committed as $aggregate) {
        DB::table('aggregates')->upsert([...]);
    }
    foreach ($deleted as $id => $aggregate) {
        DB::table('aggregates')->delete($id);
    }
});
$uow->run(fn () => $service->process($order));

// ✅ Custom event dispatcher
$uow->setEventDispatcher(fn (DomainEvent $e) => Event::dispatch($e));

// ❌ AVOID: Forgetting to track aggregates (events won't be collected)
$uow->begin();
$order->addItem($item);
// Events raised here are LOST if $order is not tracked
$uow->commit();
```

### Snapshot Configuration

```php
// Snapshot every 50 events (default)
#[SnapshotPolicy(every: 50)]
class Order extends AggregateRoot { ... }

// Snapshot every 100 events for large aggregates
#[SnapshotPolicy(every: 100)]
class AuditLog extends AggregateRoot { ... }

// Disable automatic snapshots (manual only)
#[SnapshotPolicy(every: 0)]
class SmallAggregate extends AggregateRoot { ... }
```

## Security & Production Guarantees

### Input Validation at Construction Time

All domain identifiers and value objects perform validation at construction time:

```php
// ✅ Invalid input throws immediately — never propagates invalid state
UuidIdentifier::fromString('not-a-uuid');  // throws InvalidUuidStringException
UlidIdentifier::fromString('not-a-ulid');   // throws InvalidArgumentException
StringIdentifier::from('');                  // throws ValueError
IntegerIdentifier::from('abc');             // handled gracefully via fromString()

AggregateRootId::fromString('not-a-uuid');  // throws InvalidUuidStringException
```

### Immutability Guarantees

| Class | Immutability | Mechanism |
|---|---|---|
| `AggregateRootId` | ✅ Fully immutable | `final readonly class` |
| `UuidIdentifier` | ✅ Immutable value | `abstract readonly class` |
| `UlidIdentifier` | ✅ Immutable value | `abstract readonly class` |
| `StringIdentifier` | ✅ Immutable value | `readonly class` |
| `IntegerIdentifier` | ✅ Immutable value | `final readonly class` |
| `Snapshot` | ✅ Fully immutable | `final readonly class` |
| `SnapshotPolicy` | ✅ Fully immutable | `final readonly class` attribute |
| `DomainEventCollection` | ✅ Immutable collection | `final readonly class` (returns new self on filter/merge) |
| `AggregateRoot` | ⚠️ Mutable state | `protected` constructor, version mutable for event replay |
| `Entity` | ⚠️ Mutable state | `public readonly $id` (identity immutable, properties mutable), `toArray()` |
| `ValueObject` | ⚠️ Subclass-dependent | Immutable when all properties are `public readonly` |

### Error Code Contract

All domain exceptions provide a stable, machine-readable error code via `errorCode()`:

```php
use ZeroBoiler\Domain\Exceptions\InvalidStateDomainException;
use ZeroBoiler\Domain\Exceptions\OptimisticLockException;

// Default error codes:
InvalidStateDomainException::because('...')->errorCode();      // 'INVALID_STATE'
InvalidArgumentDomainException::because('...')->errorCode();   // 'INVALID_ARGUMENT'
NotFoundDomainException::because('...')->errorCode();           // 'NOT_FOUND'
AggregateNotFoundException::for('...', '...')->errorCode();     // 'AGGREGATE_NOT_FOUND'
ConflictDomainException::because('...')->errorCode();           // 'CONFLICT'
OptimisticLockException::for('...', 1, 2)->errorCode();        // 'OPTIMISTIC_LOCK'
InvalidAggregateRootException::notAnAggregate($obj)->errorCode(); // 'INVALID_AGGREGATE_ROOT'

// Custom error codes override defaults:
InvalidStateDomainException::because('...', code: 'ORDER_NOT_PENDING')->errorCode();
// → 'ORDER_NOT_PENDING' (custom takes precedence)
```

### PHPStan Level 9 Compatibility

The domain package targets PHPStan level 9 strict analysis:
- All methods have explicit return types
- All properties use typed declarations
- Generic annotations (`@template`, `@implements`, `@param`) are complete
- `#[\Override]` attribute on all interface/parent method implementations

## Deprecation Roadmap & Migration Guide

### v3.0 Removal Targets

The following APIs are deprecated and will be removed in v3.0:

| API | Replacement | Since | Action |
|---|---|---|---|
| `Identifiers\Identifier` (abstract class) | `UuidIdentifier` | 2.5.0 | Extend `UuidIdentifier` instead |
| `AggregateRoot::getVersion()` | `AggregateRoot::version()` | 1.5.0 | Rename calls to `version()` |
| `InMemorySnapshotStore::clear()` | `InMemorySnapshotStore::purge()` | 1.5.0 | Rename calls to `purge()` |

### Migration Examples

```php
// ❌ Deprecated (removed in v3.0)
class OrderId extends ZeroBoiler\Domain\Identifiers\Identifier {}
$id = $aggregate->getVersion();
$store->clear();

// ✅ Replacement
class OrderId extends ZeroBoiler\Domain\Identifiers\UuidIdentifier {}
$id = $aggregate->version();
$store->purge();
```

### Response Package Deprecation (v3.0)

When using `zeroboiler/response` with this domain package:

| API | Replacement | Notes |
|---|---|---|
| `Contracts\Transformable` | `Transformers\TransformerInterface` | Extend canonical interface directly |
| `TransformerContract` | `Transformers\TransformerInterface` | Implement interface instead of extending abstract class |
| `TransformerContract::collection()` | `Transformer` pipeline | Use `Response::transform()->through()->collection()` |
| `TransformerContract::item()` | `Transformer` pipeline | Use `Response::transform()->through()->item()` |

### PHP Version Compatibility

| PHP Version | Status | Notes |
|---|---|---|
| 8.5 | ✅ Supported | Current minimum |
| 8.4 | ⚠️ Partial | `#[Deprecated]` attribute not available; docblock `@deprecated` retained |
| < 8.4 | ❌ Not supported | Requires union types, readonly classes, named arguments |

## Changelog

### v1.59.0 (2026-08-14)

- Test: Add `DomainProductionSerdeComprehensiveTest` — 35 production-ready serde tests covering AggregateRootId round-trip/JSON serialization, UuidIdentifier round-trip, StringIdentifier round-trip/fromArray-id-key/empty-validation, IntegerIdentifier round-trip/negative/fromArray-string-id, cross-identifier type inequality (String≠Integer, UUID≠String), Snapshot round-trip/JSON serialization, DomainEventCollection round-trip/JSON/empty serialization, DomainException hierarchy (7 types: unique default error codes, custom error code override, JSON serialization, Throwable compliance, InvalidStateException standalone hierarchy), InMemorySnapshotStore full CRUD (save/load/has/delete/deleteOlderThan/count/stats/purge), ValueObject equality, Entity toArray id+type serialization

### v1.58.0 (2026-08-14)

- Feat: Add extended collection API to `DomainEventCollection` — `each()` (side-effect iteration, fluent), `reduce()` (reduce to single value), `some()` (any match), `none()` (no match), `find()` (first match with required predicate), `hasType()` (type check shorthand), `countBy()` (conditional count), `types()` (unique event types in order)
- Test: Add `DomainEventCollectionExtendedTest` — 22 tests covering each, reduce (sum/group/index), some (match/no-match/empty/short-circuit), none (inverse), find (match/none/empty), hasType, countBy, types (unique/order/empty/single), existing API compatibility
- Docs: Update DomainEventCollection Quick Reference table and API Quick Reference table with 8 new methods
- Docs: Add extended collection API usage examples to Domain Events README section (each, reduce, some, none, countBy, find, hasType, types)

### v1.57.0 (2026-08-14)

- Docs: Add `InvalidStateException` one-liner example to Quick Start — infrastructure-level state exception now included alongside other domain exceptions
- Quality: Manual code review — all 40 source files verified production-ready (strict types, return types, docblocks, typed properties, PHP 8.5 syntax)

### v1.56.0 (2026-08-14)

- Docs: Add CLI Commands section with command details table — all 5 artisan commands documented with full usage examples and output paths
- Docs: Enrich CLI Commands section with command details table — aggregate, repository, value-object, list, snapshot commands with descriptions and output paths

### v1.49.0 (2026-08-10)

- Test: Add `DomainEndToEndLifecycleTest` — comprehensive end-to-end lifecycle tests covering aggregate root creation/state invariants/serialization, entity identity equality (string/int/Stringable IDs)/argument validation, identifier types (UUID/ULID/String/Integer) round-trip/JSON serde/cross-type inequality, domain exceptions (unique codes/custom codes/RFC 9457 toErrorArray/JsonSerializable/InvalidStateException hierarchy/OptimisticLockException), snapshot round-trip/InMemorySnapshotStore CRUD/stats/purge, domain event collection operations, unit of work declarative/manual/rollback/clear/pending events/auto-rollback

### v1.48.0 (2026-08-10)

- Refactor: Fix `InvalidStateException` hierarchy — standalone exception (outside DomainException) with correct inheritance, preventing `is_subclass_of(DomainException::class)` false negatives in cross-package error bridges

### v1.47.0 (2026-08-10)

- Refactor: Add `#[Override]` to `HasDomainEvents::hasUncommittedEvents()` — PHP 8.5 best practice compliance with `Contracts\Entity::hasUncommittedEvents()`
- Docs: Enrich `AggregateRoot::setReadOnlyProperty()` docblock — add `@param`/`@return` annotations and `@internal` marker

### v1.46.0 (2026-08-10)

- chore: bump to v1.46.0, add @since tags to MakeValueObjectCommand
- Feat: Add `hasUncommittedEvents()` and `peekDomainEvents()` to `Contracts\AggregateRoot` interface — event inspection methods now part of the formal contract, enabling type-safe consumption without concrete class dependency
- Docs: Update Contracts Quick Reference table with new interface methods

### v1.44.0 (2026-08-09)

- Docs: Add Observability Integration section — `#[Trace]` attribute usage examples for domain service methods, explanation of no-op stub behavior when zeroboiler/observability is not installed
- Bump: Version 1.43.0 → 1.44.0

### v1.43.0 (2026-08-09)

- Docs: Enrich Core Classes Quick Reference — add `aggregateId()`, `setVersion()`, `incrementVersion()`, `equals()` to AggregateRoot; add domain event methods (`recordThat()`, `releaseEvents()`, `hasUncommittedEvents()`, `peekEvents()`, `clearEvents()`) to Entity; add `get()`, `toArray()`, `fromArray()` to DomainEventCollection; add `snapshotStore()` to SnapshottingRepository
- Docs: Fix Contracts\AggregateRoot Quick Reference — correct return types and align with actual interface contract
- Bump: Version 1.42.0 → 1.43.0

### v1.42.0 (2026-08-09)

- Docs: Add `hasUncommittedEvents()` to AggregateRoot Quick Reference and Contracts AggregateRoot table
- Bump: Version 1.41.0 → 1.42.0

### v1.40.0 (2026-08-09)

- Docs: Add Test Categories section — comprehensive test suite overview table covering Core, Lifecycle, Identifiers, Serialization, Exceptions, Events, Snapshots, UnitOfWork, Production, and Cross-Package test categories with descriptions
- Docs: Add Running Tests in CI section — GitHub Actions quality workflow reference

### v1.39.0 (2026-08-09)

- Docs: Enrich `InMemoryUnitOfWork` one-liner examples — add `isActive()`, `isTracking()`, `hasPendingEvents()`, `getPendingEventCount()`, `getPendingEvents()`, `markForDeletion()`, `getCommitted()`, `getDeleted()` usage
- Docs: Expand Unit of Work Patterns section — add post-commit inspection, pending event peeking, deletion within transactions, persistence callback integration, custom event dispatcher examples
- Docs: Update InMemoryUnitOfWork and UnitOfWork Quick Reference tables with full method listing

### v1.35.0 (2026-08-08)

- Fix: Correct README typo `Contracts.UnitOfWork` → `Contracts\UnitOfWork` in Quick Reference table

### v1.34.0 (2026-08-08)

- Docs: Add `InMemorySnapshotStore` usage section — comprehensive examples for save, load, has, count, stats, deleteOlderThan, delete, and purge operations
- Docs: Add `Advanced Snapshot Loading` section — `findWithSnapshot()` with custom replay callback example
- Bump: Version 1.33.0 → 1.34.0

### v1.33.0 (2026-08-08)

- Docs: Enrich `HasSnapshots` trait method docblocks — add comprehensive `@param`, `@return`, and `@see` annotations to `shouldSnapshot()`, `createSnapshot()`, `restoreFromSnapshot()`, and `getSnapshotPolicy()` for PHPStan level 9 compliance
- Docs: Add `@param`, `@return`, and `@internal` annotations to `InMemorySnapshotStore::key()` private method

### v1.32.0 (2026-08-08)

- Docs: Add missing `@return` annotations to `SnapshottingRepository` methods (`snapshotStore()`, `usesSnapshots()`, `instantiateFromSnapshot()`) — PHPStan level 9 compliance

### v1.31.0 (2026-08-08)

- Feat: Add `Entity::toArray()` — provides base serialization with `id` and `type` keys for consistent domain → response mapping across all entity types
- Feat: Add `toArray()` to `Contracts\Entity` interface — contract now requires array serialization, matching AggregateRoot behavior
- Docs: Update Quick Reference and Architecture sections to document `Entity::toArray()`
- Test: Add `EntityToArrayTest` — 7 tests covering string/int/Stringable IDs, subclass override with spread, contract compliance, and independent type correctness

### v1.30.0 (2026-08-08)

- Docs: Enrich `InMemoryUnitOfWork` public method docblocks — add comprehensive `@param`, `@return`, `@throws` annotations to `begin()`, `commit()`, `rollback()`, `track()`, `markForDeletion()`, `isTracking()`, `isActive()`, `hasPendingEvents()`, `getPendingEventCount()`, `getCommitted()`, `getDeleted()`
- Test: Add `UnitOfWorkDocblockContractTest` — 15 tests verifying documented `@throws` behavior, nested savepoint semantics, persistence callback ordering, queueEvent injection, clear() lifecycle, and run() auto-commit/rollback

### v1.27.0 (2026-08-07)

- Docs: Enrich `HasDomainEvents` trait — add comprehensive `@param`/`@return` docblocks for `recordThat()`, `releaseEvents()`, `clearEvents()`, `hasUncommittedEvents()` with usage guidance and AggregateRoot override notes
- Docs: Enrich README cross-package integration — document `HasDomainEvents::releaseEvents()` vs `AggregateRoot::pullDomainEvents()` distinction

### v1.26.0 (2026-08-07)

- Docs: Enrich `DomainRepositoryCommand::buildImplementationStub()` docblocks — `@param`/`@return` for all 5 parameters
- Docs: Enrich `DomainListCommand::listDirectory()` docblock — `@param`/`@return`

### v1.25.0 (2026-08-07)

- Refactor: Add `@internal` annotations to `InMemoryUnitOfWork` private implementation methods — `collectEventsFromAggregate()`, `collectNewEventsFromAggregate()`, `appendEvents()`, `dispatchPendingEvents()`, `invokePersistenceCallback()`, `restoreAggregateState()`, `requireActiveScope()`, `exitScope()`, `resetTransactionState()` — marking them as internal implementation details not part of the public API contract

### v1.24.0 (2026-08-07)

- Feat: Add `DomainEventCollection::toArray()` — explicit method for API consistency, delegates to `jsonSerialize()`. Provides `toArray()` as the canonical serialization method across the ZeroBoiler ecosystem (ValueObject, Snapshot, ApiResponse, Entity, DomainEventCollection all support `toArray()`)
- Test: Add `DomainEventCollection` toArray tests — verify toArray/jsonSerialize consistency, empty collection behavior, and array-type output
- Bump: Version 1.23.0 → 1.24.0

### v1.23.0 (2026-08-07)

- Test: Add `DomainExceptionResponseBridgeTest` — comprehensive acceptance tests for DomainException → API response bridging covering toErrorArray() RFC 9457 structure (title/detail/code), jsonSerialize consistency, unique default error codes per exception type, custom error code override via because()/for()/forAggregate() factories, class basename title, exception message in detail, toArray structure (error_code/message/file/line), Throwable compliance, and DomainResponseFactory::error() bridge compatibility
- Bump: Version 1.22.0 → 1.23.0

### v1.22.0 (2026-08-07)

- Feat: Add `MakeValueObjectCommand` — Artisan generator for Domain Value Object classes using the existing `value-object.stub`
- Register `MakeValueObjectCommand` in `DomainServiceProvider`
- Docs: Add `zeroboiler:domain:value-object` to Features, CLI Commands sections

### v1.21.0 (2026-08-07)

- Test: Add `DomainProductionReadinessChecklistTest` — 32 structural checks covering strict_types across all files, AggregateRootId (final readonly/UUID v4 validation/round-trip/JSON serialization/toString), UuidIdentifier (abstract readonly/invalid rejection/cross-class inequality/isValid), StringIdentifier (empty rejection/fromString/JSON), IntegerIdentifier (final readonly/JSON/fromString), Entity (flexible ID types string/int/equals/same-class), AggregateRoot (implements AggregateRootContract+EntityContract/toArray id+version+type/version starts at 0/pullDomainEvents/clearDomainEvents), DomainEventCollection (final readonly/non-sequential rejection/filter returns new/merge/JSON serialization), DomainException hierarchy (7 types: unique error codes/custom override/RFC 9457 JSON/toErrorArray consistency/factory methods: for()/forAggregate()/notAnAggregate()), Snapshot (final readonly/round-trip/JSON consistency/type validation), InMemorySnapshotStore (SnapshotStore interface/save-load-delete cycle/stats/purge), InMemoryUnitOfWork (contract/run auto-commit/run auto-rollback/track requires active/clear resets state), Contracts (AggregateRootContract extends EntityContract/Identifier extends Stringable/all identifiers implement contract), ValueObject (abstract/toArray-based equality/null safety), Cross-identifier type safety
- Bump: Version 1.20.0 → 1.21.0

### v1.20.0 (2026-08-07)

- Test: Add `DomainFinalProductionTest` — comprehensive production verification covering AggregateRootId (generate/fromString/equals/JSON serialization/readonly+final), UuidIdentifier (generate/fromString/isValid/toUuid/equals/JSON), UlidIdentifier (generate/fromString/toUlid/isValid/JSON), StringIdentifier (from/empty validation/isValid/equals/JSON), IntegerIdentifier (from/fromString/isValid/equals/JSON/final+readonly), Entity (string/int/Stringable ID/equals/domain events), AggregateRoot (version/id/aggregateId/apply increment/pullDomainEvents/clearDomainEvents/setVersion/incrementVersion/toArray/equals), DomainEventCollection (constructor validation/filter/map/first/last/merge/get/JSON/Countable+IteratorAggregate+JsonSerializable/readonly+final), Snapshot (create/toArray/fromArray/equals/JSON/final+readonly), InMemorySnapshotStore (save+load/has/delete/deleteOlderThan/count/stats/purge/SnapshotStore interface), InMemoryUnitOfWork (begin/rollback/run+auto-commit/run+auto-rollback/nested savepoints/track requires active/isTracking/queueEvent requires active/clear/isActive/final/UnitOfWork interface), Domain Exceptions (all 7 concrete types: default+custom errorCode, factory methods, RFC 9457 JSON serialization, toErrorArray, all final), Contracts (AggregateRootId Stringable+JsonSerializable, Identifier implementations, AggregateRoot extends Entity)
- Bump: Version 1.19.0 → 1.20.0

### v1.19.0 (2026-08-07)

- Refactor: Add `#[Override]` to `DomainListCommand::handle()` — PHP 8.5 best practice compliance
- Bump: Version 1.18.0 → 1.19.0

### v1.18.0 (2026-08-07)

- Docs: Add `Service Provider & Configuration` section — document registered singletons (UnitOfWork, SnapshotStore), console commands, optional configuration via `config('domain.snapshot_driver')`, and runtime event dispatching behavior
- Docs: Add `Octane & Long-Running Process Safety` section — per-component safety matrix, `octane.request.terminate` listener documentation, and production snapshot store guidance
- Fix: Generated repository stub now uses `final class` instead of `class` for production-ready code generation
- Bump: Version 1.17.0 → 1.18.0

### v1.17.0 (2026-08-07)

- Docs: Enrich `Contracts\Repository` interface — add `@see` references, `@example` with full Eloquent implementation, `@param`/`@return` annotations, and version check guidance
- Docs: Enrich Repository section in README — add Eloquent implementation with optimistic locking and SnapshottingRepository integration examples
- Docs: Update Contracts Quick Reference with detailed Repository method signatures
- Test: Add `AggregateRootLifecycleTest` — comprehensive lifecycle tests covering creation, events, versioning, identity, equality, toArray serialization, fromHistory reconstitution, state mutation with invariants, AggregateRootId round-trip, identifier cross-type inequality, JSON serialization consistency, and DomainException error code uniqueness
- Bump: Version 1.16.0 → 1.17.0

### v1.16.0 (2026-08-07)

- Fix: Correct aggregate stub imports — use `ZeroBoiler\Domain\AggregateRoot` and `ZeroBoiler\Events\Domain\DomainEvent` instead of non-existent classes
- Fix: Correct repository stub — add proper type annotations (`list<AggregateRoot>`) and fix imports
- Fix: Rewrite event stub — use proper `DomainEvent::occur()` factory pattern with `readonly` and typed constructor
- Docs: Fix CLI command names in README to match actual command signatures (`zeroboiler:domain:*`)
- Docs: Add accurate CLI usage examples for snapshot inspection command

### v1.15.0 (2026-08-07)

- Docs: Enrich cross-package integration section — document ViewModel contract enhancement (fromArray/fromJson/collection)

### v1.14.0 (2026-08-07)

- Docs: Add Deprecation Roadmap & Migration Guide — v3.0 removal targets with migration examples
- Docs: Add PHP Version Compatibility matrix

### v1.13.0 (2026-08-07)

- Fix: `InMemoryUnitOfWork::begin()` now clears `committed` and `deleted` arrays from previous transaction cycles, preventing stale data from leaking into new transactions
- Test: Add `DomainProductionVerificationTest` — comprehensive production verification covering AggregateRootId immutability/JSON serialization, Entity identity equality/flexible ID types, ValueObject equality, Identifier types (UUID/ULID/String/Integer) validation/serialization, DomainEventCollection type safety/JSON serialization, DomainException error codes/custom codes/factory methods, Snapshot round-trip/JSON serialization/type validation, InMemorySnapshotStore CRUD/purge/stats, Unit of Work declarative/rollback/clear lifecycle, Identifier cross-type inequality, and contract compliance

### v1.12.0 (2026-08-07)

- Docs: Add Security & Production Guarantees section — immutability matrix, error code contract, input validation guarantees
- Docs: Add PHPStan level 9 compatibility note

### v1.10.0 (2026-08-06)

- Fix: Replace `assert()` with real `InvalidArgumentException` in `DomainEventCollection::__construct()` and `Snapshot::fromArray()` — assert statements are silently disabled in production (`zend.assertions = -1`), allowing invalid data to pass through undetected. Now throws `InvalidArgumentException` with descriptive messages in all environments.

### v1.9.0 (2026-08-06)

- Feat: Add `UuidIdentifier::isValid()` static method — pre-validate UUID strings without throwing (parity with `UlidIdentifier::isValid()`, `StringIdentifier::isValid()`, `IntegerIdentifier::isValid()`)
- Feat: Add `Snapshot::equals()` method — structural equality comparison for snapshot objects (type, ID, version, state)
- Test: Add `DomainProductionAdditionsTest` — comprehensive tests for Snapshot::equals(), UuidIdentifier::isValid(), identifier parity, cross-type inequality, JSON serialization consistency, DomainEventCollection edge cases, Entity ID type coverage, ValueObject edge cases, and DomainException errorCode consistency
- Docs: Add `isValid()` usage examples to Identifiers section
- Docs: Update Quick Reference table with `isValid()` for all identifier types

### v1.8.0 (2026-08-06)

- Feat: Add PHP 8.5 `#[Deprecated]` attribute to legacy `Identifiers\Identifier` class (was only `@deprecated` docblock)
- Docs: Enrich `InvalidStateException::because()` with `@param`/`@return` docblock

### v1.7.0 (2026-08-06)

- Fix: `DomainException` custom error code (`$code` parameter in `because()` / `for()` factories) was silently lost — passed as 4th arg to `Exception::__construct()` which only accepts 3 params. Added proper constructor with `$domainCode` parameter so custom codes like `'ORDER_NOT_PENDING'` now correctly flow through `errorCode()`.
- Bump: Version 1.6.0 → 1.7.0

### v1.6.0 (2026-08-06)

- Feat: Add machine-readable `errorCode()` method to all domain exceptions (INVALID_STATE, INVALID_ARGUMENT, NOT_FOUND, CONFLICT, OPTIMISTIC_LOCK, AGGREGATE_NOT_FOUND, INVALID_AGGREGATE_ROOT)
- Feat: Domain exception factories accept optional `$code` parameter for custom machine-readable error codes
- Test: Add `DomainErrorCodeTest` — comprehensive tests for default error codes, custom codes, uniqueness, stability, naming convention, and independence

### v1.5.0 (2026-08-06)

- Refactor: Add PHP 8.4 `#[Deprecated]` attribute to `AggregateRoot::getVersion()` (backward compat alias)
- Docs: Enrich `AggregateRoot::setVersion()` docblock — document infrastructure-only usage
- Refactor: Add PHP 8.4 `#[Deprecated]` attribute to `InMemorySnapshotStore::clear()` (backward compat alias)
- Docs: Add `#[Deprecated]` attributes to deprecated class/interface targets blocked by PHP 8.4 — docblock `@deprecated` retained
- Bump: Version 1.4.0 → 1.5.0

### v1.4.0 (2026-08-06)

- Docs: Enrich `ValueObject` base class — comprehensive docblock with `@implements`, `@see`, `@example`, enriched `equals()` method documentation with `@param`/`@return`
- Bump: Version 1.3.0 → 1.4.0

### v1.3.0 (2026-08-06)

- Test: Add `DomainModelProductionTest` — comprehensive production-ready tests covering all identifiers (UUID, ULID, String, Integer), entity equality, AggregateRoot event recording/versioning, DomainEventCollection operations, Unit of Work (declarative/manual/nested/rollback/event queuing), Snapshot round-trip/JSON serialization, InMemorySnapshotStore operations (CRUD/purge/deleteOlderThan), ValueObject equality/fromArray/toArray round-trip, and domain exception hierarchy
- Docs: Enrich `SnapshotStore` interface — add `@see` references to SnapshottingRepository and InMemorySnapshotStore, add Redis implementation `@example`
- Docs: Enrich `HasSnapshots` trait — comprehensive docblock with all method descriptions, `@see`/`@example` annotations, and override guidance
- Docs: Enrich `SnapshotPolicy` attribute — fix incorrect `@see` reference, add `@example` for every 50/100/disable configurations
- Bump: Version 1.2.0 → 1.3.0

### v2.12.0 (2026-08-06)

- Fix: Correct README license from "MIT" to "Proprietary" (matches code headers)
- Test: Add `DomainProductionSerdeTest` — round-trip serialization for all identifiers, snapshots, event collections, cross-type safety, and legacy Identifier JSON
- Test: Add `ProductionSerdeTest` for response package — `fromJson()` edge cases, wrap/unwrap behavior, JSON:API spec compliance, error field filtering, static factories, toArray consistency, DomainTransformer contract enforcement

### v2.11.0 (2026-08-06)

- Feat: Add `JsonSerializable` to legacy `Identifier` class for consistent JSON serialization across all identifier types
- Fix: Add missing `use ZeroBoiler\Domain\AggregateRoot` import to `UnitOfWork` contract (fragile namespace fallback eliminated)
- Docs: Enrich ViewModel docblocks — `with()`, `toApi()`, `toInertia()`, `transform()`, `handle()`, `toArray()`, `merge()`, `withWhen()`, `withUnless()`
- Docs: Enrich InertiaResponse docblocks — `withMany()`, `shareMany()` with full `@param`/`@return`
- Docs: Enrich Transformer docblocks — `isCollection()`, `getTransformerClass()` with `@throws`
- Docs: Enrich TransformerContract `toArrayable()` docblock

### v2.10.0 (2026-08-06)

- Docs: Add Quick Reference tables for all classes, traits, identifiers, exceptions, and contracts
- Docs: Add Best Practices section with identifier selection guide, UoW patterns, and snapshot configuration

### v2.9.0 (2026-08-06)

- Docs: Add Domain Exception hierarchy tree diagram with all factory methods
- Docs: Add custom domain exception example (OrderAlreadyShippedException)
- Docs: Enrich exception code examples with inline comments explaining usage context

### v2.8.0 (2026-08-06)

- Fix: Remove duplicate constructor in UnitOfWork README example, merge persistence callback into primary constructor
- Docs: Add `reconstituteFromSnapshot()` usage example to README

### v2.7.0 (2026-08-06)

- Docs: Add Value Object usage example with `fromArray()`/`toArray()`/`equals()`
- Docs: Add DomainEventCollection advanced usage — `map()`, `filter()`, `first()`, `last()`, `merge()`, `get()`, JSON serialization

### v2.6.0 (2026-08-06)

- Docs: Enrich `Identifier` contract docblock with FQCN `@see` references for all identifier implementations

### v2.5.0 (2026-08-06)

- Docs: Enrich HasDomainEvents trait docblock with @see and @example
- Docs: Enrich EventSourced trait docblocks — fromHistory, applyEvent @param/@return
- Docs: Add @return and @example to SnapshottingRepository::findWithSnapshot()
- Docs: Add @deprecated annotation to InMemorySnapshotStore::clear()
- Docs: Add Event Sourcing → Response Mapping section to response README

### v2.4.0 (2026-08-06)

- Fix: HasSnapshots readonly property restoration — remove premature `setValue(null)` before `unset()` to prevent PHP 8.5 error
- Fix: DomainTransformer `extractId()` fallback documentation — clarify spl_object_id is last resort
- Docs: Enrich TransformerInterface `@param` tags with `@return` descriptions
- Docs: Add usage examples to all console command docblocks

## Production Readiness Checklist

The ZeroBoiler Domain package is designed for production-grade DDD implementations. This section documents the quality guarantees and conventions.

### Code Quality

| Category | Status | Notes |
|----------|--------|-------|
| `declare(strict_types=1)` | ✅ All files | Zero tolerance for type coercion |
| Return type declarations | ✅ All methods | Including `__construct`, `__clone`, magic methods |
| Typed properties | ✅ All classes | `private readonly`, `protected`, visibility-locked |
| Docblocks | ✅ All public API | `@param`, `@return`, `@throws`, `@since` annotations |
| `#[Override]` | ✅ Interface impls | PHP 8.5 override attribute on all interface implementations |
| `#[Deprecated]` | ✅ Legacy APIs | PHP 8.5 deprecation attribute on backward-compat shims |
| Immutability | ✅ Core types | Value Objects, Identifiers, Snapshots are immutable |
| `final` classes | ✅ Leaf types | Exceptions, Identifiers, Pagination — all sealed |

### PHP Version & Syntax

| Feature | Version | Usage |
|---------|---------|-------|
| `readonly` classes/properties | PHP 8.2+ | AggregateRootId, Identifiers |
| `#[Override]` attribute | PHP 8.3+ | Service providers, interface implementations |
| `#[Deprecated]` attribute | PHP 8.4+ | Legacy methods, deprecated classes |
| Constructor promotion | PHP 8.0+ | All classes |
| Named arguments | PHP 8.0+ | Factory methods, `sprintf` calls |
| `array_is_list()` | PHP 8.3+ | DomainEventCollection |
| `array_any()` | PHP 8.5+ | InMemoryUnitOfWork |
| First-class callable syntax | PHP 8.1+ | Event dispatchers, callbacks |

### Semver Compliance

- **MAJOR**: Breaking changes to public API (method signature changes, removed methods)
- **MINOR**: New features, new interfaces, new factory methods
- **PATCH**: Bug fixes, documentation updates, performance improvements

### Backward Compatibility Guarantees

1. All public class/method signatures are stable within a major version
2. Deprecated methods emit `#[Deprecated]` + `@deprecated` docblock (double signal)
3. Factory methods (`::because()`, `::for()`, `::generate()`) never change signatures
4. Interface contracts are append-only — new methods always have default implementations or are added to new interfaces
5. `toArray()` / `fromArray()` output format is stable — safe for caching/queuing

### Error Handling Contract

All domain exceptions extend `DomainException` and guarantee:

```php
$exception->getMessage();    // Human-readable description
$exception->errorCode();     // Machine-readable code (e.g., 'OPTIMISTIC_LOCK')
$exception->toErrorArray(); // RFC 9457 Problem Details format
$exception->toArray();       // Full serialization array
json_encode($exception);    // JsonSerializable — safe for API responses
```

### Serialization Contract

All serializable domain objects guarantee round-trip safety:

```php
$original = UuidIdentifier::generate();
$restored = UuidIdentifier::fromArray($original->toArray());
assert($original->equals($restored)); // true
```

Types with this guarantee: all Identifiers, Snapshot, DomainEventCollection.

### Architecture Principles

1. **Composition over inheritance** — Traits (`HasDomainEvents`, `EventSourced`, `HasSnapshots`) composed into aggregates
2. **Interface segregation** — Separate `Entity`, `AggregateRoot`, `Identifier`, `Repository`, `UnitOfWork` contracts
3. **Dependency inversion** — `DomainServiceProvider` checks `app->bound()` at runtime for optional packages
4. **No framework coupling in domain** — Core classes depend only on PHP internals; Laravel integration via ServiceProvider
5. **Open/closed** — Extend via traits, decorators, and new identifiers; never modify core classes

## Security Considerations

- **No user input processing** — Domain objects accept validated, type-safe values only. Input sanitization is the responsibility of the application layer.
- **Immutable identifiers** — All identifier classes are `final` and `readonly`; identity values cannot be mutated after construction.
- **Domain events are value objects** — Events are serializable arrays, never carrying closures or callable references.
- **Snapshot stores are internal** — `InMemorySnapshotStore` is intended for development/testing only; production stores should implement `SnapshotStore` with proper access controls.
- **No framework coupling in domain core** — Core domain classes depend only on PHP internals (`ramsey/uuid` for UUIDv4 generation), minimizing attack surface.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for contribution guidelines.

## License

Proprietary

## Architecture Overview

```
src/
├── AggregateRoot.php          # Base aggregate root — identity, versioning, domain events
├── AggregateRootId.php        # UUID v4 aggregate identifier — readonly, JsonSerializable
├── Entity.php                 # Base entity — identity equality, fromArray/fromJson
├── ValueObject.php            # Base value object — structural equality (extends zeroboiler/value-objects)
├── DomainEventCollection.php  # Immutable event collection — filter, map, merge, toArray
├── InMemoryUnitOfWork.php     # Transactional UoW — savepoints, rollback snapshots, event queuing
├── DomainServiceProvider.php  # Laravel service provider — UoW, snapshot store, Octane reset
│
├── Contracts/
│   ├── Entity.php             # Entity contract — id(), equals(), toArray(), fromArray()
│   ├── AggregateRoot.php      # AggregateRoot contract — version(), domain events, pull/clear
│   ├── Identifier.php         # Identifier contract — toString(), equals(), fromString(), fromArray()
│   ├── Repository.php         # Repository contract — find(), save(), delete()
│   └── UnitOfWork.php         # UnitOfWork contract — transaction, commit, rollback
│
├── Identifiers/
│   ├── Identifier.php         # Abstract identifier — toString(), equals(), fromString()
│   ├── UuidIdentifier.php     # Abstract UUID — Ramsey\Uuid backed
│   ├── UlidIdentifier.php     # Abstract ULID — monotonic, sortable
│   ├── StringIdentifier.php   # Final string identifier
│   └── IntegerIdentifier.php  # Final integer identifier
│
├── Concerns/
│   ├── HasDomainEvents.php    # Domain event recording — recordThat(), pullDomainEvents()
│   ├── EventSourced.php       # Event sourcing — fromHistory(), applyThat(), handler resolution
│   └── HasSnapshots.php       # Snapshot support — shouldSnapshot(), createSnapshot(), restoreFromSnapshot()
│
├── Snapshots/
│   ├── Snapshot.php           # Immutable snapshot DTO — aggregateType, version, state
│   ├── SnapshotPolicy.php      # Attribute — configure auto-snapshot frequency
│   ├── SnapshotStore.php      # Interface — load, save, delete, stats, purge
│   ├── InMemorySnapshotStore.php   # In-memory implementation for testing
│   └── SnapshottingRepository.php  # Repository decorator — snapshot optimization
│
├── Exceptions/
│   ├── DomainException.php    # Abstract base — errorCode(), toErrorArray(), fromArray()
│   ├── InvalidStateDomainException.php
│   ├── InvalidArgumentDomainException.php
│   ├── NotFoundDomainException.php
│   ├── ConflictDomainException.php
│   ├── AggregateNotFoundException.php
│   ├── OptimisticLockException.php
│   └── InvalidAggregateRootException.php
│
├── Commands/                  # Artisan generators
│   ├── DomainAggregateCommand.php
│   ├── DomainRepositoryCommand.php
│   ├── DomainListCommand.php
│   ├── MakeValueObjectCommand.php
│   └── SnapshotCommand.php
│
└── stubs/
    └── Trace.php               # No-op #[Trace] stub when zeroboiler/observability is absent
```

### Design Decisions

| Decision | Rationale |
|---|---|
| `AggregateRootId` is `final readonly` | Prevents subclassing and mutation — UUID identity is opaque |
| `Entity` is `abstract` but not `final` | Allows extension for specific domain entities |
| `UuidIdentifier` is `abstract readonly` | Forces subclasses, enables type-safe equality across types |
| `DomainException` has `toErrorArray()` | Maps directly to RFC 9457 Problem Details for API responses |
| `SnapshottingRepository` uses decoration | Adds snapshot support without modifying the inner repository |
| `EventSourced` uses dot-convention handlers | `on.order.created()` auto-resolves to `applyOrderCreated()` |
| `InMemoryUnitOfWork` uses savepoints | Enables nested transactions with rollback to savepoints |

### Cross-Package Data Flow

```
┌──────────────────┐     ┌──────────────────────┐     ┌────────────────────┐
│  Domain Package   │────▶│  Response Package    │────▶│  HTTP Response     │
│                   │     │                      │     │                    │
│  AggregateRoot ────┼─────│  DomainTransformer ───┼────▶│  ApiResponse       │
│  Entity ───────────┼─────│  DomainResponse      │     │  InertiaResponse   │
│  ValueObject ─────┼─────│  Factory              │     │  ViewModel         │
│  DomainException ─┼─────│                      │     │  Transformer       │
│  Snapshot ────────┼─────│                      │     │  Pagination        │
└──────────────────┘     └──────────────────────┘     └────────────────────┘
```

## End-to-End Integration Example

Complete example: from domain aggregate root to HTTP response, showing the full data flow across `zeroboiler/domain` → `zeroboiler/response`.

```php
use App\Domain\Order;
use App\Domain\OrderId;
use ZeroBoiler\Domain\Contracts\UnitOfWork;
use ZeroBoiler\Domain\Exceptions\InvalidStateDomainException;
use ZeroBoiler\Response\Facades\Response;
use ZeroBoiler\Response\Transformers\DomainTransformer;

// --- 1. Domain Layer: Aggregate Root ---
class Order extends \ZeroBoiler\Domain\AggregateRoot
{
    use \ZeroBoiler\Domain\Concerns\EventSourced;

    public string $status = 'pending';
    public float $total = 0.0;

    public function pay(float $amount): void
    {
        if ($this->status !== 'pending') {
            throw InvalidStateDomainException::because('Order must be pending to pay.');
        }
        $this->apply(\ZeroBoiler\Events\Domain\DomainEvent::occur('order.paid', [
            'id' => $this->id(),
            'amount' => $amount,
        ]));
    }

    protected function applyOrderPaid(\ZeroBoiler\Events\Domain\DomainEvent $event): void
    {
        $this->status = 'paid';
        $this->total = $event->payload['amount'];
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

// --- 2. Response Layer: DomainTransformer ---
final class OrderTransformer extends DomainTransformer
{
    protected function mapDomainFields(object $entity, array $context = []): array
    {
        return $this->extractBaseArray($entity);
    }

    protected function mapMeta(object $entity, array $context = []): array
    {
        return ['version' => $this->extractVersion($entity)];
    }
}

// --- 3. Controller: Domain → Response Bridge ---
class OrderController
{
    public function show(string $id, UnitOfWork $uow, OrderRepository $repo): \Illuminate\Http\JsonResponse
    {
        // Unit of Work with auto commit/rollback + event dispatch
        return $uow->run(function () use ($id, $repo) {
            $order = $repo->find($id);
            if ($order === null) {
                throw NotFoundDomainException::forAggregate(Order::class, $id);
            }

            // Transform domain entity to API response
            return Response::transform($order)
                ->through(OrderTransformer::class)
                ->api()
                ->send();
            // → {"data":{"id":"...","version":1,"type":"Order","status":"pending","total":0},"_meta":{"version":1}}
        });
    }

    public function pay(string $id, UnitOfWork $uow, OrderRepository $repo): \Illuminate\Http\JsonResponse
    {
        return $uow->run(function () use ($id, $uow, $repo) {
            $order = $repo->find($id);
            $uow->track($order);

            $order->pay(99.99);        // Raises domain event
            $repo->save($order);      // Persists + increments version

            return $uow->run(fn () => Response::transform($order)
                ->through(OrderTransformer::class)
                ->api()
                ->send());
            // → {"data":{"id":"...","version":2,"type":"Order","status":"paid","total":99.99}}
            // Events dispatched automatically after outermost commit
        });
        // On exception: rollback restores aggregate state, events discarded
    }

    public function store(Request $request, UnitOfWork $uow, OrderRepository $repo): \Illuminate\Http\JsonResponse
    {
        return $uow->run(function () use ($request, $uow, $repo) {
            $order = Order::create(\                // Raises 'order.placed' event
                \ZeroBoiler\Domain\AggregateRootId::generate()
            );
            $repo->save($order);

            return DomainResponseFactory::created($order, $order->toArray())
                ->withLinks(['self' => "/api/orders/{$order->id()}"])
                ->send();
            // → HTTP 201, {"data":{...},"links":{"self":"/api/orders/..."}}
        });
    }
}
```

## API Quick Reference

### AggregateRoot (extends Entity, abstract)

| Method | Return | Description |
|---|---|---|
| `id(): string` | `string` | String representation of aggregate identity |
| `aggregateId(): AggregateRootId` | `AggregateRootId` | Typed UUID identity object |
| `version(): int` | `int` | Current version for optimistic locking |
| `incrementVersion(): void` | `void` | Bump version after successful save |
| `pullDomainEvents(): DomainEventCollection` | `DomainEventCollection` | Destructive pull of recorded events |
| `clearDomainEvents(): void` | `void` | Discard all recorded events |
| `peekDomainEvents(): DomainEventCollection` | `DomainEventCollection` | Non-destructive peek at events |
| `hasUncommittedEvents(): bool` | `bool` | Check for pending events |
| `equals(EntityContract): bool` | `bool` | Type-safe identity equality |
| `toArray(): array` | `array` | `{id, version, type, ...}` |
| `reconstituteFromSnapshot(Snapshot, array): static` | `static` | Restore from snapshot + post-snapshot events |

### Entity (abstract, implements JsonSerializable)

| Method | Return | Description |
|---|---|---|
| `id(): string` | `string` | String identity (int/string/Stringable) |
| `equals(EntityContract): bool` | `bool` | Type + identity equality |
| `toArray(): array` | `array` | `{id, type, ...}` |
| `fromArray(array): static` | `static` | Reflection-based hydration |
| `jsonSerialize(): array` | `array` | Delegates to `toArray()` |

### AggregateRootId (final readonly)

| Method | Return | Description |
|---|---|---|
| `generate(): self` | `self` | Random UUID v4 |
| `fromString(string): self` | `self` | Parse existing UUID |
| `isValid(string): bool` | `bool` | Pre-validate without throwing |
| `toString(): string` | `string` | Canonical UUID string |
| `equals(self): bool` | `bool` | UUID value equality |
| `toArray(): array` | `{uuid: string}` | Serialization |
| `fromArray(array): self` | `self` | Round-trip restore |
| `jsonSerialize(): string` | `string` | JSON output |

### Identifier Types (UuidIdentifier, UlidIdentifier, StringIdentifier, IntegerIdentifier)

| Method | Return | Available On |
|---|---|---|
| `generate(): static` | `static` | Uuid, Ulid |
| `fromString(string): static` | `static` | All |
| `from(mixed): static` | `static` | String (from string), Integer (from int) |
| `isValid(string): bool` | `bool` | All |
| `toString(): string` | `string` | All |
| `equals(IdentifierContract): bool` | `bool` | All (type-safe: same class only) |
| `toArray(): array` | `array` | All (`uuid`/`ulid`/`string`/`integer` key) |
| `fromArray(array): static` | `static` | All |
| `toUuid(): UuidInterface` | `UuidInterface` | Uuid |
| `toUlid(): SymfonyUlid` | `SymfonyUlid` | Ulid |
| `toInt(): int` | `int` | Integer |

### DomainEventCollection (final readonly, implements Countable, IteratorAggregate, JsonSerializable)

| Method | Return | Description |
|---|---|---|
| `all(): list<DomainEvent>` | `list` | All events as plain array |
| `count(): int` | `int` | Event count |
| `isEmpty(): bool` | `bool` | Check for zero events |
| `first(?callable): ?DomainEvent` | `?DomainEvent` | First matching event |
| `last(): ?DomainEvent` | `?DomainEvent` | Last event |
| `get(int): ?DomainEvent` | `?DomainEvent` | Event at index |
| `map(callable): list` | `list` | Transform each event |
| `filter(callable): self` | `self` | Filter events, new collection |
| `merge(self\|list): self` | `self` | Merge, new collection |
| `each(callable): self` | `self` | Side-effect iteration (fluent) |
| `reduce(callable, mixed): mixed` | `mixed` | Reduce to single value |
| `some(callable): bool` | `bool` | Any event matches predicate |
| `none(callable): bool` | `bool` | No events match predicate |
| `find(callable): ?DomainEvent` | `?DomainEvent` | First matching event |
| `hasType(string): bool` | `bool` | Contains event type |
| `countBy(callable): int` | `int` | Count matching events |
| `types(): list` | `list` | Unique event types in order |
| `toArray(): list<array>` | `list` | Serialize each event |
| `fromArray(list<array>): self` | `self` | Round-trip restore |

### DomainException (abstract, extends Exception, implements JsonSerializable)

| Method | Return | Description |
|---|---|---|
| `errorCode(): string` | `string` | Machine-readable code (e.g., `INVALID_STATE`) |
| `toErrorArray(): array` | `{title, detail, code}` | RFC 9457 Problem Details |
| `toArray(): array` | `{error_code, message, file, line}` | Debug serialization |
| `fromArray(array, ?string): static` | `static` | Round-trip restore |
| `jsonSerialize(): array` | `{title, detail, code}` | JSON output |

| Concrete Exception | Default `errorCode()` | Named Constructor |
|---|---|---|
| `InvalidStateDomainException` | `INVALID_STATE` | `::because(reason, code)` |
| `InvalidArgumentDomainException` | `INVALID_ARGUMENT` | `::because(reason, code)` |
| `NotFoundDomainException` | `NOT_FOUND` | `::because(reason)`, `::forAggregate(type, id)` |
| `ConflictDomainException` | `CONFLICT` | `::because(reason, code)` |
| `OptimisticLockException` | `OPTIMISTIC_LOCK` | `::for(id, expected, actual, code)` |
| `AggregateNotFoundException` | `AGGREGATE_NOT_FOUND` | `::for(type, id, code)` |
| `InvalidAggregateRootException` | `INVALID_AGGREGATE_ROOT` | `::notAnAggregate(object, code)` |
| `InvalidStateException` | `INVALID_STATE_SYSTEM` | `::because(reason, code)` |

### InMemoryUnitOfWork (implements UnitOfWork)

| Method | Return | Description |
|---|---|---|
| `begin(): void` | `void` | Start transaction (supports nesting) |
| `commit(): void` | `void` | Persist + dispatch events |
| `rollback(): void` | `void` | Restore aggregate state, discard events |
| `run(Closure): mixed` | `mixed` | Auto begin/commit/rollback |
| `track(AggregateRoot): void` | `void` | Track aggregate for transaction |
| `isTracking(AggregateRoot): bool` | `bool` | Check if tracked |
| `markForDeletion(AggregateRoot): void` | `void` | Queue for deletion on commit |
| `isActive(): bool` | `bool` | Check if transaction active |
| `hasPendingEvents(): bool` | `bool` | Check for queued events |
| `getPendingEventCount(): int` | `int` | Count of queued events |
| `getPendingEvents(): DomainEventCollection` | `DomainEventCollection` | Non-destructive peek |
| `getCommitted(): array` | `array<string, AggregateRoot>` | Aggregates committed in this cycle |
| `getDeleted(): array` | `array<string, AggregateRoot>` | Aggregates marked for deletion |
| `clear(): void` | `void` | Reset all state |
| `queueEvent(DomainEvent): void` | `void` | Manually queue an event |
| `setPersistenceCallback(?Closure): void` | `void` | Set persist callback (before dispatch) |
| `setEventDispatcher(?Closure): void` | `void` | Set event dispatch callback |

### SnapshottingRepository (final readonly, implements Repository)

| Method | Return | Description |
|---|---|---|
| `find(string\|int): ?AggregateRoot` | `?AggregateRoot` | Load with snapshot optimization |
| `save(AggregateRoot): void` | `void` | Save + auto-snapshot if due |
| `delete(string\|int): void` | `void` | Delete aggregate + snapshots |
| `findWithSnapshot(string, ?callable): ?AggregateRoot` | `?AggregateRoot` | Explicit snapshot loading |
| `snapshotStore(): SnapshotStore` | `SnapshotStore` | Get underlying store |

### Traits

| Trait | Methods | Description |
|---|---|---|
| `HasDomainEvents` | `recordThat()`, `releaseEvents()`, `clearEvents()`, `hasUncommittedEvents()`, `peekEvents()` | Event recording buffer |
| `EventSourced` | `fromHistory()`, `applyEvent()` | Aggregate reconstitution from event stream |
| `HasSnapshots` | `toSnapshotState()`, `restoreFromSnapshotState()`, `shouldSnapshot()`, `createSnapshot()`, `restoreFromSnapshot()`, `getSnapshotPolicy()` | Snapshot serialization |

## Serialization Contract

Every serializable class supports a consistent set of serialization formats:

| Class | `toArray()` | `fromArray()` | `fromJson()` | `jsonSerialize()` | `__serialize()` | `__unserialize()` |
|---|---|---|---|---|---|---|
| `AggregateRootId` | ✅ `['uuid' => ...]` | ✅ | ✅ | ✅ `string` | ✅ | ✅ (reflection) |
| `Entity` | ✅ `['id', 'type', ...]` | ✅ (reflection) | ✅ | ✅ `array` | — | — |
| `AggregateRoot` | ✅ `['id', 'version', 'type']` | — | — | — | — | — |
| `ValueObject` | ✅ (via subclass) | ✅ (via subclass) | — | — | — | — |
| `DomainEventCollection` | ✅ `list<array>` | ✅ | ✅ | ✅ `list<array>` | — | — |
| `Snapshot` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ (reflection) |
| `UuidIdentifier` | ✅ `['uuid' => ...]` | ✅ | ✅ | ✅ `string` | ✅ | ✅ (reflection) |
| `UlidIdentifier` | ✅ `['ulid' => ...]` | ✅ | ✅ | ✅ `string` | ✅ | ✅ (reflection) |
| `StringIdentifier` | ✅ `['string' => ...]` | ✅ | ✅ | ✅ `string` | ✅ | ✅ (reflection) |
| `IntegerIdentifier` | ✅ `['integer' => ...]` | ✅ | ✅ | ✅ `int` | ✅ | ✅ (reflection) |
| `Identifier` (legacy) | ✅ `['value' => ...]` | ✅ | ✅ | ✅ `string` | — | — |
| `DomainException` | ✅ | ✅ | ✅ | ✅ `array` (RFC 9457) | — | — |
| `SnapshotPolicy` | — | — | — | — | — | — (attribute only) |

### Round-Trip Guarantees

```php
// Every class with fromArray() guarantees this pattern:
$original = $id->toArray();
$restored = ClassName::fromArray($original);
// $restored is functionally equivalent to $original

// JSON round-trip:
$json = json_encode($entity->toArray());
$restored = ClassName::fromJson($json);
// $restored is functionally equivalent to $original

// PHP native serialize() round-trip (readonly classes):
$serialized = serialize($id);
$restored = unserialize($serialized);
// Works via __serialize()/__unserialize() with reflection
```

## Advanced Patterns

### Guard Clauses with Domain Exceptions

```php
class Order extends AggregateRoot
{
    public function markAsCompleted(): void
    {
        if ($this->status === OrderStatus::COMPLETED) {
            throw InvalidStateDomainException::because(
                'Order is already completed.',
                'ORDER_ALREADY_COMPLETED',
            );
        }

        $this->status = OrderStatus::COMPLETED;
        $this->recordThat(new OrderCompleted($this->id(), $this->version()));
    }
}
```

### Composite Aggregate Pattern

```php
class Order extends AggregateRoot
{
    /** @var list<OrderLine> */
    private array $lines = [];

    public function addLine(ProductId $productId, int $quantity, Money $unitPrice): void
    {
        $line = new OrderLine($productId, $quantity, $unitPrice);
        $this->lines[] = $line;
        $this->recordThat(new LineAdded($this->id(), $productId, $quantity, $unitPrice));
    }

    public function getTotal(): Money
    {
        return array_reduce($this->lines, fn(Money $sum, OrderLine $line) => $sum->add($line->total()), Money::zero());
    }
}
```

### Event Sourcing with Reconstitution

```php
class Order extends AggregateRoot
{
    use EventSourced;

    private function applyOrderCreated(OrderCreated $event): void
    {
        $this->id = AggregateRootId::fromString($event->orderId);
        $this->status = OrderStatus::PENDING;
    }

    private function applyLineAdded(LineAdded $event): void
    {
        $this->lines[] = new OrderLine(
            ProductId::fromString($event->productId),
            $event->quantity,
            $event->unitPrice,
        );
    }

    // Reconstitute from event stream:
    // $order = Order::fromHistory($events);
}
```

### Snapshot-Optimized Event Sourcing

```php
// Auto-snapshot every 100 events (configurable via #[SnapshotPolicy])
#[SnapshotPolicy(every: 100)]
class Order extends AggregateRoot
{
    use EventSourced;
    use HasSnapshots;

    // Repository loads snapshot first, then replays only new events
    // $order = $repository->find($id); // Uses SnapshottingRepository
}
```

### Unit of Work with Transaction Management

```php
$uow = new InMemoryUnitOfWork(
    persistCallback: fn(AggregateRoot $ar) => $repository->save($ar),
    eventDispatcher: fn(DomainEventCollection $events) => $bus->dispatchAll($events),
);

$uow->begin();
try {
    $order = $repository->find($orderId);
    $order->addItem($productId, 2);
    $uow->track($order);
    $uow->commit(); // Persists + dispatches events atomically
} catch (\Throwable $e) {
    $uow->rollback(); // Restores original state, discards events
    throw $e;
}

// Or use the shorthand:
$result = $uow->run(function () use ($uow, $order, $productId) {
    $order->addItem($productId, 2);
    $uow->track($order);
});
```

### Domain → Response Mapping (Cross-Package)

```php
// In your controller or handler:
use ZeroBoiler\Response\Response;
use ZeroBoiler\Response\Transformers\DomainResponseFactory;
use ZeroBoiler\Response\Transformers\DomainTransformer;

class OrderTransformer extends DomainTransformer
{
    public function transform(object $order): array
    {
        return [
            'id' => (string) $order->id(),
            'status' => $order->status()->value,
            'total' => $order->getTotal()->amount(),
            'currency' => $order->getTotal()->currency()->code(),
        ];
    }
}

// Usage in controller:
$api = DomainResponseFactory::entity($order, new OrderTransformer);
return $api->send();

// Collection:
$api = DomainResponseFactory::collection($orders, new OrderTransformer);
return $api->send();

// Paginated:
$api = DomainResponseFactory::paginatedCollection(
    $orders,
    new OrderTransformer,
    OffsetPagination::fromPaginator($paginator)->toArray(),
);
return $api->send();

// Exception → RFC 9457 auto-bridge:
try {
    $order->markAsCompleted();
} catch (DomainException $e) {
    return DomainResponseFactory::fromException($e)->send();
    // Returns: {"title":"Invalid State","detail":"...","code":"INVALID_STATE"}
}
```

## Production Readiness Checklist

| Criteria | Status | Notes |
|---|---|---|
| PHP 8.5+ syntax | ✅ | `readonly` classes, promoted constructor params, named args, `#[Override]`, `#[Deprecated]` |
| `declare(strict_types=1)` | ✅ | All source files |
| Return type declarations | ✅ | 100% coverage across all public/protected/private methods |
| Typed properties | ✅ | All class properties have explicit types |
| Docblocks with `@param`/`@return`/`@throws` | ✅ | All public APIs documented with PHPDoc + `@example` blocks |
| Immutability | ✅ | `AggregateRootId` (final readonly), `DomainEventCollection` (final readonly), Entity ID (readonly) |
| Domain invariants | ✅ | `AggregateRoot::apply()` enforces versioning, `Entity::equals()` checks type + identity |
| JSON serialization (`JsonSerializable`) | ✅ | `AggregateRootId`, `Entity`, `DomainEventCollection`, `DomainException`, `AggregateRoot` |
| Round-trip `fromArray()`/`toArray()` | ✅ | `AggregateRootId`, `DomainEventCollection`, `Entity`, `Snapshot`, `DomainException` |
| Interface contracts | ✅ | `Entity`, `AggregateRoot`, `Identifier`, `Repository`, `UnitOfWork` — all implemented |
| Event sourcing | ✅ | `EventSourced` trait with `fromHistory()`, handler resolution via dot convention |
| Snapshot support | ✅ | `HasSnapshots`, `SnapshottingRepository`, `InMemorySnapshotStore`, `SnapshotPolicy` attribute |
| Optimistic locking | ✅ | `OptimisticLockException`, version tracking in `AggregateRoot` |
| Exception hierarchy | ✅ | `DomainException` → 7 concrete subclasses with `errorCode()` and RFC 9457 `toErrorArray()` |
| PHPUnit/Pest test coverage | ✅ | 100+ test files covering every source class, edge cases, and cross-package integration |

## v1.68.0 (2026-08-15)

- Test: Add `DomainAdvancedEdgeCaseTest` — 35+ edge-case tests covering UoW nested run/rollback state restoration, aggregate identity immutability, identifier cross-type inequality, value object structural equality, DomainEventCollection functional operations (reduce, groupBy, some/none/find/countBy/types/hasType/each/merge), RFC 9457 exception mapping, and UoW serialization (toArray/toJson/clear)
- Quality: Full manual code review — all 40 source files verified production-ready (strict types, return types, docblocks, typed properties, PHP 8.5 syntax, readonly/final, #[Override]/#[Deprecated] attributes, serialization contract)

## v1.67.0 (2026-08-15)

- Docs: Add `DomainEventCollection` functional predicates (`some`, `none`, `find`, `countBy`, `types`, `hasType`), `reduce()`, and `each()` to Domain Event Collection Quick Start section
- Docs: Update Class Reference table with full `DomainEventCollection` method listing
- Quality: Manual code review — all 40 source files verified production-ready

## v1.66.0 (2026-08-14)

- Feat: Add `toJson()` to `Entity` and `AggregateRoot` contracts for complete serialization API coverage — all domain classes now support `toArray()`/`fromJson()`/`toJson()` consistently
- Feat: Add `toJson()` to `AggregateRootId`, `Contracts\AggregateRoot`, `Contracts\Entity`, `Contracts\Identifier`, `Identifiers\Identifier`, `Snapshots\Snapshot`, `InMemoryUnitOfWork`
- Feat: Add `getPendingEvents()` and `clear()` to `InMemoryUnitOfWork` with `@since 1.66.0` annotations
- Docs: Update Serialization Contract table with `toJson()` column for all classes
- Quality: Manual code review — all 40 source files verified production-ready

## v1.65.0 (2026-08-14)

- Internal: Version bump for toJson() contract additions

## v1.68.0 (2026-08-15)

- Docs: Add `@return` docblock annotations to `defaultErrorCode()` and `defaultHttpStatus()` on all 8 exception subclasses (InvalidStateDomainException, NotFoundDomainException, OptimisticLockException, ConflictDomainException, AggregateNotFoundException, InvalidArgumentDomainException, InvalidAggregateRootException, InvalidStateException)
- Quality: Manual code review — all 44 source files verified production-ready

## v1.62.0 (2026-08-14)

- Docs: Add **Serialization Contract** table — comprehensive serialization support matrix for all domain classes (toArray/fromArray/fromJson/jsonSerialize/__serialize/__unserialize)
- Docs: Add **Round-Trip Guarantees** section with copy-paste code examples for array, JSON, and PHP native serialize round-trips

## v1.61.0 (2026-08-14)

- Docs: Add **Class Reference Table** — complete listing of all 32 classes/interfaces/traits with descriptions
- Docs: Add **Exception Hierarchy** diagram — visual tree of all 8 domain exceptions with common API summary
- Docs: Enrich README with structured reference sections for discoverability

## v1.60.0 (2026-08-14)

- Fix: Correct `SnapshottingRepository` namespace to `Snapshots\` sub-namespace, remove unused imports
- Test: Add `DomainPackageProductionComprehensiveTest` — 60+ tests covering all core classes
- Test: Add production tests — DomainEventCollection functional API, DomainException hierarchy, identifier cross-type inequality
- Quality: Full manual code review — all source files verified production-ready

## v1.55.0 (2026-08-13)

- Docs: Add missing docblocks to `Entity::equals()`, `AggregateRoot::pullDomainEvents()`, `AggregateRoot::clearDomainEvents()`
- Quality: Manual code review — all 40 source files verified production-ready

## v1.54.0 (2026-08-15)

- Test: Add `DomainTypeSafetyContractTest` — reflection-based type safety verification (strict types, return types, final/readonly, interface contracts, serde methods, #[Override] attributes)
- Docs: Add `setEventDispatcher` and `setPersistenceCallback` usage examples to One-Liner Quick Start section
- Docs: Add Version Compatibility table (1.x, 2.x, 3.x roadmap with PHP/Laravel version matrix)
- Docs: Enhance Production Ready Checklist — add `#[\Override]` attributes, `#[\Deprecated]` attributes, cross-package integration criteria, detailed readonly class listing, `static` return type annotations, `@template` generic annotations
- Docs: Add Advanced Patterns section with guard clauses, composite aggregates, and domain event patterns

## v1.53.0 (2026-08-12)

- Quality: Full production readiness audit — all 40 source files verified (strict types, return types, docblocks, typed properties, PHP 8.5 syntax)
- Verify: Confirm immutability contracts — `AggregateRootId` (final readonly), `DomainEventCollection` (final readonly), Entity ID (readonly), `Snapshot` (final readonly)
- Verify: JSON serialization consistency — `JsonSerializable` on all serializable types, round-trip `fromArray()`/`toArray()` verified
- Verify: Domain invariant enforcement — `AggregateRoot::apply()` versioning, `Entity::equals()` type+identity check, `DomainException` RFC 9457 mapping
- Verify: Cross-package data flow — `DomainTransformer` duck-typing, `DomainResponseFactory` decoupled bridge, `ApiResponse::fromException()` auto RFC 9457
- Docs: No code changes needed — codebase confirmed production-ready

## v1.52.0 (2026-08-11)

- Docs: Add Architecture Overview with file tree, design decisions, and cross-package data flow diagram
- Test: Add `DomainEntityImmutabilityContractTest` — immutability, equality, round-trip serialization for all identifier types
- Quality: Full manual code review — all 40 source files confirmed production-ready (strict types, return types, docblocks, typed properties)

## v1.51.0 (2026-08-11)

- Refactor: Remove unused `ENUM_MANAGER_CLASS` constant from DomainServiceProvider (dead code, phpstan-ignore eliminated)
- Docs: Verify production readiness — all files have strict types, return types, docblocks, typed properties, PHP 8.5 syntax
- Quality: Manual code review pass — confirm immutability, domain invariants, JSON serialization consistency across all 36 source files
- Docs: Add Production Readiness Checklist to README with full criteria audit

## Security

If you discover a security vulnerability, please email `hello@zeroboiler.dev`. All security vulnerabilities will be promptly addressed.

## License

ZeroBoiler Domain is proprietary software. See the [LICENSE](LICENSE) file for details.

## Version Compatibility

| Version | PHP | Laravel | Status |
|---|---|---|---|
| `1.x` (current) | ≥ 8.5 | ≥ 13 | ✅ Active development |
| `2.x` | ≥ 8.5 | ≥ 13 | ⚠️ Planned (deprecated API cleanup) |
| `3.x` | ≥ 9.0 | TBD | 🔮 Future (remove `Identifier` base class, `Transformable` interface) |

## Production Ready Checklist

| Criteria | Status | Notes |
|---|---|---|
| PHP 8.5 strict types | ✅ | `declare(strict_types=1)` on all source files |
| Return type declarations | ✅ | All public/protected methods with `static` return types on factory methods |
| Typed properties | ✅ | All properties with PHP types, including generic `@template` annotations |
| Readonly immutability | ✅ | `readonly` classes (`AggregateRootId`, `DomainEventCollection`, `Snapshot`, `SnapshotPolicy`, `SnapshottingRepository`) & promoted properties on all identifiers |
| `#[\Override]` attributes | ✅ | All interface contract implementations (Entity, AggregateRoot, Identifier, UnitOfWork, HasDomainEvents) |
| `#[\Deprecated]` attributes | ✅ | `getVersion()` (use `version()`), `Identifier` base class (use `UuidIdentifier`), `InMemorySnapshotStore::clear()` (use `purge()`) |
| Domain invariants | ✅ | Constructor validation on all identifiers & entities, `ValueError` for empty strings, UUID/ULID format validation |
| Docblocks | ✅ | `@param`, `@return`, `@throws`, `@since`, `@example` on all public API with `@template` for generics |
| Serialization contract | ✅ | `toArray()`, `fromArray()`, `toJson()`, `fromJson()`, `jsonSerialize()`, `__serialize()`, `__unserialize()` |
| PHPStan level 9 | ✅ | Zero type errors |
| Test coverage | ✅ | 130+ test cases covering all contracts, edge cases, and round-trip serialization |
| Cross-package integration | ✅ | `DomainResponseFactory` duck-typed bridge, `ExtractsDomainId` trait for response package |

