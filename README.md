# ZeroBoiler Domain

Zero-boilerplate DDD building blocks for Laravel 13 / PHP 8.5 — aggregates, entities, value objects, events, repositories, and more.

## Features

- **AggregateRoot** — Base class with UUID identifier, domain event recording, and versioning for optimistic locking
- **Entity** — Identity-based equality with `HasDomainEvents` trait compatibility
- **ValueObject** — Immutable equality with serialization support
- **DomainEvent** — Event system with UUID, timestamps, and payload
- **DomainEventDispatcher** — Synchronous and deferred event dispatching with subscription support
- **Repository & UnitOfWork contracts** — Transactional boundaries
- **Identifier types** — UUID, Integer, and String identifiers
- **CLI generators** — Generate aggregates, events, repositories, and value objects

## Installation

```bash
composer require zeroboiler/domain
```

Register the service provider in `config/app.php`:

```php
'providers' => [
    // ...
    ZeroBoiler\Domain\DomainServiceProvider::class,
],
```

Or if using Laravel 13+ auto-discovery, it's already registered.

## DDD Building Blocks

### AggregateRoot

Base class for your domain aggregates:

```php
use ZeroBoiler\Domain\AggregateRoot;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\DomainEvent;

final class OrderAggregate extends AggregateRoot
{
    public static function create(AggregateRootId $id): self
    {
        $order = new self($id);

        $order->apply(DomainEvent::occur('OrderCreated', [
            'id' => $id->toString(),
        ]));

        return $order;
    }

    public function addItem(string $productId, int $quantity): void
    {
        $this->apply(DomainEvent::occur('OrderItemAdded', [
            'productId' => $productId,
            'quantity' => $quantity,
        ]));
    }
}
```

Using the aggregate:

```php
$id = AggregateRootId::generate();
$order = OrderAggregate::create($id);

$order->addItem('product-123', 2);

// Get uncommitted events
$events = $order->releaseEvents();

// Get version for optimistic locking
$version = $order->getVersion();
```

### Entity

Base class for domain entities:

```php
use ZeroBoiler\Domain\Entity;
use ZeroBoiler\Domain\Identifiers\Identifier;

final class OrderItem extends Entity
{
    public function __construct(
        public readonly Identifier $id,
        public readonly string $productId,
        public readonly int $quantity,
    ) {}
}

// Equality based on ID
$item1 = new OrderItem($id, 'product-123', 2);
$item2 = new OrderItem($id, 'product-123', 2);

$item1->equals($item2); // true
```

### ValueObject

Immutable value objects:

```php
use ZeroBoiler\Domain\ValueObject;

final readonly class Money extends ValueObject
{
    public function __construct(
        public readonly int $amount,
        public readonly string $currency,
    ) {
    }

    public static function from(int $amount, string $currency): self
    {
        return new self($amount, $currency);
    }

    public function toArray(): array
    {
        return [
            'amount' => $this->amount,
            'currency' => $this->currency,
        ];
    }

    public function __toString(): string
    {
        return sprintf('%s %s', $this->amount, $this->currency);
    }
}

// Value equality
$money1 = Money::from(100, 'USD');
$money2 = Money::from(100, 'USD');

$money1->equals($money2); // true
```

### DomainEvent

Domain events:

```php
use ZeroBoiler\Domain\DomainEvent;

$event = DomainEvent::occur('OrderShipped', [
    'orderId' => 'uuid-here',
    'shippedAt' => '2026-06-26T21:36:00+00:00',
]);

echo $event->eventType; // 'OrderShipped'
echo $event->eventId->toString(); // UUID
echo $event->occurredAt->format('Y-m-d H:i:s'); // Timestamp

// Serialization
$event->toArray();
// DomainEvent::fromArray($array);
```

### DomainEventDispatcher

Event dispatcher with subscription and deferred dispatch:

```php
use ZeroBoiler\Domain\DomainEventDispatcher;

$dispatcher = app(DomainEventDispatcher::class);

// Subscribe to events
$dispatcher->subscribe('OrderCreated', function (DomainEvent $event): void {
    // Handle order creation
    logger()->info('Order created', $event->payload);
});

// Dispatch immediately
$dispatcher->dispatch($event);

// Defer for later (e.g., after database commit)
$dispatcher->defer($event);

// Release all deferred events
$dispatcher->releaseDeferred();

// Check for deferred events
$dispatcher->hasDeferredEvents(); // bool
$dispatcher->getDeferredEventsCount(); // int

// Clear deferred events without dispatching
$dispatcher->clearDeferred();
```

### Identifier Types

**UUID-based Identifier (default for aggregates):**

```php
use ZeroBoiler\Domain\AggregateRootId;

$id = AggregateRootId::generate();
$id->toString(); // UUID string
```

**Integer Identifier:**

```php
use ZeroBoiler\Domain\Identifiers\IntegerIdentifier;

$id = IntegerIdentifier::from(42);
$id->toInt(); // 42
(string) $id; // "42"
$id1->equals($id2); // bool
```

**String Identifier:**

```php
use ZeroBoiler\Domain\Identifiers\StringIdentifier;

$id = StringIdentifier::from('order-123');
$id->toString(); // "order-123"
(string) $id; // "order-123"
$id1->equals($id2); // bool
```

### Repository Contract

Implement for persistence:

```php
use ZeroBoiler\Domain\Contracts\Repository;
use ZeroBoiler\Domain\AggregateRoot;

interface OrderRepository extends Repository
{
    public function findById(string $id): ?OrderAggregate;
}

class EloquentOrderRepository implements OrderRepository
{
    public function find(string $id): ?object
    {
        // Implementation
    }

    public function save(AggregateRoot $aggregate): void
    {
        // Save with version check for optimistic locking
    }

    public function delete(AggregateRoot $aggregate): void
    {
        // Implementation
    }

    public function findById(string $id): ?OrderAggregate
    {
        // Implementation
    }
}
```

### UnitOfWork Contract

Transaction boundary:

```php
use ZeroBoiler\Domain\Contracts\UnitOfWork;
use ZeroBoiler\Domain\AggregateRoot;

class DatabaseUnitOfWork implements UnitOfWork
{
    public function begin(): void
    {
        DB::beginTransaction();
    }

    public function commit(): void
    {
        DB::commit();
    }

    public function rollback(): void
    {
        DB::rollBack();
    }

    public function persist(AggregateRoot $aggregate): void
    {
        // Track changes
    }

    public function remove(AggregateRoot $aggregate): void
    {
        // Track removal
    }

    public function hasChanges(): bool
    {
        // Check if changes pending
    }
}
```

## CLI Commands

### Generate Aggregate

```bash
php artisan zeroboiler:domain:aggregate Order
```

Creates: `app/Domain/Aggregates/OrderAggregate.php`

```php
<?php

declare(strict_types=1);

namespace App\Domain\Aggregates;

use ZeroBoiler\Domain\AggregateRoot;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\DomainEvent;

final class OrderAggregate extends AggregateRoot
{
    public static function create(AggregateRootId $id): self
    {
        $aggregate = new self($id);

        $aggregate->apply(DomainEvent::occur('OrderAggregateCreated', [
            'id' => $id->toString(),
        ]));

        return $aggregate;
    }
}
```

### Generate Domain Event

```bash
php artisan zeroboiler:domain:event OrderCreated
```

Creates: `app/Domain/Events/OrderCreatedEvent.php`

```php
<?php

declare(strict_types=1);

namespace App\Domain\Events;

use ZeroBoiler\Domain\DomainEvent;

final class OrderCreatedEvent extends DomainEvent
{
    public static function occur(array $payload = []): self
    {
        return new self('OrderCreatedEvent', $payload);
    }
}
```

### Generate Repository

```bash
php artisan zeroboiler:domain:repository Order
```

Creates: `app/Domain/Repositories/OrderRepository.php`

```php
<?php

declare(strict_types=1);

namespace App\Domain\Repositories;

use App\Domain\Aggregates\OrderAggregate;
use ZeroBoiler\Domain\Contracts\Repository;

interface OrderRepository extends Repository
{
    public function findById(string $id): ?OrderAggregate;
}
```

### Generate Value Object

```bash
php artisan zeroboiler:domain:value-object Money
```

Creates: `app/Domain/ValueObjects/MoneyValueObject.php`

```php
<?php

declare(strict_types=1);

namespace App\Domain\ValueObjects;

use ZeroBoiler\Domain\ValueObject;

final readonly class MoneyValueObject extends ValueObject
{
    public function __construct(
        public string $value,
    ) {
    }

    public static function from(string $value): self
    {
        return new self($value);
    }

    public function toArray(): array
    {
        return [
            'value' => $this->value,
        ];
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
```

### List Domain Classes

```bash
php artisan zeroboiler:domain:list
```

Lists all domain classes organized by type:
- Aggregates
- Events
- Repositories
- ValueObjects

## Testing

Run tests:

```bash
composer test
```

Run specific test:

```bash
vendor/bin/pest tests/AggregateRootTest.php
```

## Quality Tools

Run all quality checks:

```bash
composer ci
```

Individual tools:

```bash
# Code formatting (Laravel Pint)
composer lint

# Static analysis (PHPStan level 6)
composer analyse

# Automated refactoring (Rector)
composer rector
```

## Configuration

### Pint (Code Formatting)

Laravel preset with strict types and ordered imports. See `pint.json`.

### PHPStan

Level 6 static analysis. See `phpstan.neon`.

### Rector

PHP 8.5 + Laravel 13 automated refactoring. See `rector.php`.

## Exceptions

```php
use ZeroBoiler\Domain\Exceptions\DomainException;
use ZeroBoiler\Domain\Exceptions\InvalidStateException;
use ZeroBoiler\Domain\Exceptions\AggregateNotFoundException;

// General domain exception
throw DomainException::because('Something went wrong');

// Invalid state
throw InvalidStateException::because('Cannot complete operation in current state');

// Aggregate not found
throw new AggregateNotFoundException('OrderAggregate', $id);
```

## Best Practices

1. **Use AggregateRoot for aggregates** — Provides event recording and versioning
2. **Use Entity for entities** — Identity-based equality
3. **Use ValueObject for values** — Immutable, value-based equality
4. **Record domain events** — Use `apply()` method to record events
5. **Dispatch events after persistence** — Use UnitOfWork or release after commit
6. **Version check** — Use `$aggregate->getVersion()` for optimistic locking
7. **Strict types** — Always use `declare(strict_types=1);`

## Example: Order Domain

```php
<?php

declare(strict_types=1);

namespace App\Domain\Aggregates;

use ZeroBoiler\Domain\AggregateRoot;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\DomainEvent;
use App\Domain\ValueObjects\ShippingAddressValueObject;

final class OrderAggregate extends AggregateRoot
{
    public static function create(
        AggregateRootId $id,
        string $customerId,
        ShippingAddressValueObject $shippingAddress,
    ): self {
        $order = new self($id);

        $order->apply(DomainEvent::occur('OrderCreated', [
            'id' => $id->toString(),
            'customerId' => $customerId,
            'shippingAddress' => $shippingAddress->toArray(),
        ]));

        return $order;
    }

    public function addItem(string $productId, int $quantity, int $price): void
    {
        $this->apply(DomainEvent::occur('OrderItemAdded', [
            'orderId' => $this->id->toString(),
            'productId' => $productId,
            'quantity' => $quantity,
            'price' => $price,
        ]));
    }

    public function markAsShipped(): void
    {
        $this->apply(DomainEvent::occur('OrderShipped', [
            'orderId' => $this->id->toString(),
            'shippedAt' => (new DateTimeImmutable)->format(DateTimeImmutable::ATOM),
        ]));
    }
}
```

## License

Proprietary. All rights reserved.

## Support

For issues and questions, please use the [GitHub repository](https://github.com/zeroboiler/domain).

---

Built with 🚫 boilerplate and ❤️ for Laravel DDD.