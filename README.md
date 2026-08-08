# ZeroBoiler Domain

[![Latest Version](https://img.shields.io/packagist/v/zeroboiler/domain.svg?style=flat-square)](https://packagist.org/packages/zeroboiler/domain)
[![License](https://img.shields.io/packagist/l/zeroboiler/domain.svg?style=flat-square)](LICENSE)
[![PHP Version](https://img.shields.io/packagist/php-v/zeroboiler/domain.svg?style=flat-square)](https://packagist.org/packages/zeroboiler/domain)

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

## Quick Reference

### Core Classes

| Class | Type | Description | Key Methods |
|---|---|---|---|
| `AggregateRoot` | abstract | Top-level DDD entity with events, versioning | `apply()`, `pullDomainEvents()`, `id()`, `version()`, `reconstituteFromSnapshot()` |
| `AggregateRootId` | final readonly | UUID v4 identity for aggregates | `generate()`, `fromString()`, `toString()`, `equals()`, `jsonSerialize()` |
| `Entity` | abstract | Base domain entity with flexible ID | `id()`, `equals()`, `toArray()`, constructor accepts `int\|string\|Stringable` |
| `ValueObject` | abstract | Domain value object base | `equals()`, `toArray()` (from value-objects package) |
| `DomainEventCollection` | final readonly | Type-safe event collection | `all()`, `count()`, `isEmpty()`, `filter()`, `map()`, `first()`, `last()`, `merge()` |
| `InMemoryUnitOfWork` | final | Transactional event queuing | `begin()`, `commit()`, `rollback()`, `run()`, `track()`, `queueEvent()`, `clear()` |
| `SnapshottingRepository` | final readonly | Repository decorator with snapshots | `find()`, `save()`, `delete()`, `findWithSnapshot()` |

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
| `Contracts\Entity` | — | `id(): string`, `equals(Entity): bool`, `toArray(): array` |
| `Contracts\AggregateRoot` | `Entity` | `version(): int`, `pullDomainEvents()`, `incrementVersion()`, `clearDomainEvents()` |
| `Contracts\Identifier` | `Stringable` | `fromString()`, `toString()`, `equals()` |
| `Contracts\Repository` | — | `find(id): ?AggregateRoot`, `save(aggregate): void`, `delete(id): void` |
| `Contracts.UnitOfWork` | — | `begin()`, `commit()`, `rollback()`, `run()`, `track()`, `queueEvent()`, `clear()` |

### Traits

| Trait | Applied To | Purpose |
|---|---|---|
| `EventSourced` | `AggregateRoot` | `fromHistory()`, `applyEvent()` — replay/reconstitute from events |
| `HasDomainEvents` | `Entity` | `recordThat()`, `releaseEvents()`, `hasUncommittedEvents()` |
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

## License

Proprietary
