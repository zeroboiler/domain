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
- **CLI Generators** — `domain:aggregate`, `domain:repository`, `domain:list`, `domain:snapshot`

## Architecture

```
AggregateRoot (extends Entity)
├── AggregateRootId (UUID v4)
├── HasDomainEvents trait (event recording)
├── EventSourced trait (optional, reconstitution from history)
└── HasSnapshots trait (optional, snapshot/restore support)

Entity (abstract)
├── HasDomainEvents trait
└── id(): string / equals(): bool

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

// ULID
class ProductId extends UlidIdentifier {}
$productId = ProductId::generate();        // monotonic ULID
$productId->toUlid();                     // Symfony ULID object

// String
$slug = StringIdentifier::from('my-post');

// Integer
$id = IntegerIdentifier::from(42);
```

### Repository

```php
use ZeroBoiler\Domain\Contracts\Repository;

interface OrderRepository extends Repository
{
    public function findById(UuidIdentifier $id): ?Order;
}
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
└── DomainException (abstract)
    ├── InvalidStateDomainException       — entity/aggregate state violation
    ├── InvalidArgumentDomainException    — domain argument validation failure
    ├── NotFoundDomainException           — aggregate/entity not found
    │   └── forAggregate(type, id)        — typed not-found helper
    ├── ConflictDomainException          — concurrent write-write conflict
    ├── OptimisticLockException          — stale aggregate version detected
    │   └── for(id, expected, actual)     — typed lock failure helper
    ├── AggregateNotFoundException         — repository lookup returned null
    │   └── for(type, id)                 — typed not-found helper
    └── InvalidAggregateRootException     — object is not an AggregateRoot
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

// Custom domain exception — extend for business-specific violations
final class OrderAlreadyShippedException extends DomainException
{
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
php artisan domain:aggregate Order

# Generate repository interface
php artisan domain:repository Order

# List all domain aggregates
php artisan domain:list

# Manage snapshots
php artisan domain:snapshot list
php artisan domain:snapshot purge --type=Order
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

## Testing

```bash
composer test              # Run Pest tests
composer test:coverage     # With coverage
composer quality           # Pint + PHPStan + Rector + Tests
```

## Changelog

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

MIT
