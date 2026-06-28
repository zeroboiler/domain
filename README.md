# ZeroBoiler Domain

DDD building blocks for rich domain models with aggregate roots, entities, value objects, and domain events.

## Installation

```bash
composer require zeroboiler/domain
```

## Features

- **AggregateRoot** — ID, domain events, versioning
- **Entity** — identity equality, lifecycle
- **ValueObject** — immutable, equality
- **DomainEvent** — base class + dispatcher
- **Repository** interface — contract for persistence
- **UnitOfWork** interface — transactional boundary
- **DomainException** hierarchy
- UUID/ULID identifier types
- Event sourcing support (optional trait)

## Usage

### Aggregate Root

```php
use ZeroBoiler\Domain\Concerns\HasDomainEvents;
use ZeroBoiler\Domain\Contracts\AggregateRoot as AggregateRootContract;

class Order extends AggregateRootContract
{
    use HasDomainEvents;

    public function __construct(
        public readonly OrderId $id,
        public Customer $customer,
        public OrderStatus $status,
        public float $total,
    ) {}

    public static function create(OrderId $id, Customer $customer): static
    {
        $order = new self($id, $customer, OrderStatus::PENDING, 0.0);
        $order->raise(new OrderPlaced($id));
        return $order;
    }

    public function pay(PaymentDetails $payment): void
    {
        if ($this->status !== OrderStatus::PENDING) {
            throw DomainException::invalidState('Order must be pending to pay');
        }

        $this->status = OrderStatus::PAID;
        $this->raise(new OrderPaid($this->id, $payment));
    }

    public function ship(TrackingNumber $tracking): void
    {
        if ($this->status !== OrderStatus::PAID) {
            throw DomainException::invalidState('Order must be paid to ship');
        }

        $this->status = OrderStatus::SHIPPED;
        $this->raise(new OrderShipped($this->id, $tracking));
    }
}
```

### Domain Event

```php
use ZeroBoiler\Domain\Contracts\DomainEvent as DomainEventContract;

readonly class OrderPlaced extends DomainEventContract
{
    public function __construct(
        public OrderId $orderId,
    ) {
        parent::__construct();
    }
}

readonly class OrderPaid extends DomainEventContract
{
    public function __construct(
        public OrderId $orderId,
        public PaymentDetails $payment,
    ) {
        parent::__construct();
    }
}
```

### Entity

```php
use ZeroBoiler\Domain\Contracts\Entity as EntityContract;

class OrderItem extends EntityContract
{
    public function __construct(
        public OrderItemId $id,
        public Product $product,
        public int $quantity,
        public float $unitPrice,
    ) {}

    public function updateQuantity(int $quantity): void
    {
        if ($quantity <= 0) {
            throw DomainException::invalidArgument('Quantity must be positive');
        }

        $this->quantity = $quantity;
    }

    public function subtotal(): float
    {
        return $this->quantity * $this->unitPrice;
    }
}
```

### Value Object

```php
use ZeroBoiler\Domain\Contracts\ValueObject as ValueObjectContract;

readonly class Money extends ValueObjectContract
{
    public function __construct(
        public float $amount,
        public Currency $currency,
    ) {
        if ($amount < 0) {
            throw DomainException::invalidArgument('Amount cannot be negative');
        }
    }

    public function add(Money $other): Money
    {
        if ($this->currency !== $other->currency) {
            throw DomainException::invalidArgument('Cannot add different currencies');
        }

        return new Money($this->amount + $other->amount, $this->currency);
    }

    public function subtract(Money $other): Money
    {
        if ($this->currency !== $other->currency) {
            throw DomainException::invalidArgument('Cannot subtract different currencies');
        }

        $result = $this->amount - $other->amount;
        if ($result < 0) {
            throw DomainException::invalidArgument('Result cannot be negative');
        }

        return new Money($result, $this->currency);
    }
}
```

### Repository

```php
use ZeroBoiler\Domain\Contracts\Repository as RepositoryContract;

interface OrderRepository extends RepositoryContract
{
    public function find(OrderId $id): ?Order;

    public function findByCustomer(CustomerId $customerId): array;

    public function save(Order $order): void;

    public function delete(OrderId $id): void;
}
```

### Unit of Work

```php
use ZeroBoiler\Domain\Contracts\UnitOfWork as UnitOfWorkContract;

class OrderService
{
    public function __construct(
        private OrderRepository $orders,
        private UnitOfWorkContract $uow,
    ) {}

    public function placeOrder(CreateOrderDto $dto): Order
    {
        $order = Order::create(
            OrderId::generate(),
            $this->getCustomer($dto->customerId),
        );

        $this->orders->save($order);
        $this->uow->commit();

        return $order;
    }
}
```

### Identifiers

```php
use ZeroBoiler\Domain\Identifiers\UuidIdentifier;
use ZeroBoiler\Domain\Identifiers\UlidIdentifier;

// UUID v4
$orderId = OrderId::generate(); // e.g., "550e8400-e29b-41d4-a716-446655440000"

// ULID
$productId = ProductId::generate(); // e.g., "01HZ5X5X5X5X5X5X5X5X5X5X5X"

// Custom
class OrderId extends UuidIdentifier {}
class ProductId extends UlidIdentifier {}
```

### Event Sourcing (Optional)

```php
use ZeroBoiler\Domain\Concerns\EventSourced;

class Order extends AggregateRootContract
{
    use EventSourced;

    protected function applyOrderPlaced(OrderPlaced $event): void
    {
        $this->id = $event->orderId;
        $this->status = OrderStatus::PENDING;
    }

    protected function applyOrderPaid(OrderPaid $event): void
    {
        $this->status = OrderStatus::PAID;
    }
}
```

### Domain Events

```php
use ZeroBoiler\Domain\Facades\EventDispatcher;

// Manual dispatch
EventDispatcher::dispatch(new OrderPlaced($orderId));

// Get pending events from aggregate
$order = Order::create(...);
$events = $order->pullDomainEvents();

// Clear recorded events
$order->clearDomainEvents();
```

## CLI Commands

### Generate Aggregate Root

```bash
php artisan domain:aggregate Order
```

Generates: `Domain/Orders/Order.php`

### Generate Domain Event

```bash
php artisan domain:event OrderPlaced
```

Generates: `Domain/Events/OrderPlaced.php`

### Generate Repository Interface

```bash
php artisan domain:repository Order
```

Generates: `Domain/Repositories/OrderRepository.php`

## Architecture

### Aggregate Root
- Represents a cluster of domain objects that can be treated as a single unit
- Maintains consistency boundary
- Publishes domain events
- Has versioning for optimistic locking

### Entity
- Has identity
- Has lifecycle (created, updated, deleted)
- Equality based on identity, not attributes

### Value Object
- Immutable by default
- Equality based on attributes
- No identity
- Self-validating

### Domain Event
- Something that happened in the domain
- Immutable and named in past tense
- Contains all relevant data
- Dispatched after business logic completes

### Repository
- Mediates between domain and data mapping
- Collection-like interface
- No business logic

### Unit of Work
- Maintains list of affected objects
- Coordinates writing out changes
- Transactional boundary

## Testing

```php
use ZeroBoiler\Domain\Tests\TestCase;

class OrderTest extends TestCase
{
    public function test_can_create_order(): void
    {
        $orderId = OrderId::generate();
        $customer = Customer::create(...);

        $order = Order::create($orderId, $customer);

        $this->assertEquals($orderId, $order->id);
        $this->assertEquals(OrderStatus::PENDING, $order->status);
        $this->assertCount(1, $order->pullDomainEvents());
        $this->assertInstanceOf(OrderPlaced::class, $order->pullDomainEvents()[0]);
    }

    public function test_cannot_pay_non_pending_order(): void
    {
        $order = Order::create(...);
        $order->pay(...);

        $this->expectException(DomainException::class);

        $order->pay(...);
    }
}
```

## License

MIT