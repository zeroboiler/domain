# ZeroBoiler Domain

DDD building blocks for rich domain models with aggregate roots, entities, value objects, and domain events.

## Installation

```bash
composer require zeroboiler/domain
```

## Requirements

- PHP 8.5+
- Laravel 13+
- `zeroboiler/events` — domain event infrastructure
- `zeroboiler/value-objects` — base value object support
- `zeroboiler/enums` — smart enum metadata
- `zeroboiler/dto` — data transfer objects
- `zeroboiler/observability` (optional) — `#[Trace]` auto-instrumentation

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
```

### Entity

```php
use ZeroBoiler\Domain\Entity;

class OrderItem extends Entity
{
    public function __construct(
        public readonly mixed $id,
        public readonly string $productId,
        public int $quantity,
        public float $unitPrice,
    ) {}

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
    ) {}

    public function placeOrder(array $data): Order
    {
        return $this->uow->run(function () use ($data): Order {
            $order = Order::create(AggregateRootId::generate());
            $this->orders->save($order);

            return $order;
        });
        // Events dispatched automatically on commit
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
```

### Domain Exceptions

```php
use ZeroBoiler\Domain\Exceptions\InvalidStateDomainException;
use ZeroBoiler\Domain\Exceptions\InvalidArgumentDomainException;
use ZeroBoiler\Domain\Exceptions\NotFoundDomainException;
use ZeroBoiler\Domain\Exceptions\ConflictDomainException;

// State violations
throw InvalidStateDomainException::because('Order must be pending to pay.');

// Argument violations
throw InvalidArgumentDomainException::because('Quantity must be positive.');

// Not found
throw NotFoundDomainException::forAggregate('Order', $orderId);

// Conflicts
throw ConflictDomainException::because('Concurrent modification detected.');
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

## Testing

```bash
composer test              # Run Pest tests
composer test:coverage     # With coverage
composer quality           # Pint + PHPStan + Rector + Tests
```

## License

Proprietary
