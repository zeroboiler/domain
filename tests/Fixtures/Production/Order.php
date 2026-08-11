<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Tests\Fixtures\Production;

use Ramsey\Uuid\Uuid;
use ZeroBoiler\Domain\AggregateRoot;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\Concerns\EventSourced;
use ZeroBoiler\Domain\Entity;
use ZeroBoiler\Domain\Exceptions\InvalidArgumentDomainException;
use ZeroBoiler\Domain\Exceptions\InvalidStateDomainException;
use ZeroBoiler\Domain\ValueObject;
use ZeroBoiler\Events\Domain\DomainEvent;

/**
 * Production-ready test fixture: Order aggregate root with full DDD lifecycle.
 *
 * Demonstrates:
 * - Aggregate root with typed identity
 * - Domain event recording and application
 * - State mutations via apply* handlers
 * - Version tracking and optimistic locking
 * - toArray() serialization for response mapping
 * - fromArray() hydration (Entity base)
 * - Domain invariant enforcement
 *
 * @since 1.0.0
 *
 * @example
 * ```php
 * $order = Order::create(AggregateRootId::generate(), total: 99.99);
 * $order->addItem('prod-1', 2, 9.99);
 * $order->pay();
 * $order->toArray();
 * // ['id' => '...', 'version' => 3, 'type' => 'Order', 'status' => 'paid', 'total' => 119.97, ...]
 * ```
 */
final class Order extends AggregateRoot
{
    use EventSourced;

    public string $status = 'pending';
    public float $total = 0.0;

    /** @var array<int, array{productId: string, quantity: int, unitPrice: float}> */
    public array $items = [];

    public function __construct(AggregateRootId $id)
    {
        parent::__construct($id);
    }

    /**
     * Factory: create a new order with initial total.
     *
     * @return self
     */
    public static function create(AggregateRootId $id, float $total = 0.0): self
    {
        $order = new self($id);
        $order->apply(DomainEvent::occur('order.placed', [
            'id' => $id->toString(),
            'status' => 'pending',
            'total' => $total,
        ]));

        return $order;
    }

    /**
     * Add an item to the order.
     *
     * @throws InvalidStateDomainException If order is not pending.
     * @throws InvalidArgumentDomainException If quantity or unitPrice is invalid.
     */
    public function addItem(string $productId, int $quantity, float $unitPrice): void
    {
        if ($this->status !== 'pending') {
            throw InvalidStateDomainException::because('Cannot add items to a non-pending order.');
        }

        if ($quantity <= 0) {
            throw InvalidArgumentDomainException::because('Quantity must be positive.');
        }

        if ($unitPrice < 0) {
            throw InvalidArgumentDomainException::because('Unit price cannot be negative.');
        }

        $this->apply(DomainEvent::occur('order.item_added', [
            'id' => $this->id(),
            'productId' => $productId,
            'quantity' => $quantity,
            'unitPrice' => $unitPrice,
            'subtotal' => $quantity * $unitPrice,
        ]));
    }

    /**
     * Pay for the order.
     *
     * @throws InvalidStateDomainException If order is not pending.
     */
    public function pay(): void
    {
        if ($this->status !== 'pending') {
            throw InvalidStateDomainException::because('Order must be pending to pay.');
        }

        $this->apply(DomainEvent::occur('order.paid', [
            'id' => $this->id(),
            'total' => $this->total,
        ]));
    }

    /**
     * Ship the order.
     *
     * @throws InvalidStateDomainException If order is not paid.
     */
    public function ship(): void
    {
        if ($this->status !== 'paid') {
            throw InvalidStateDomainException::because('Order must be paid to ship.');
        }

        $this->apply(DomainEvent::occur('order.shipped', [
            'id' => $this->id(),
        ]));
    }

    /**
     * Cancel the order.
     *
     * @throws InvalidStateDomainException If order is already shipped.
     */
    public function cancel(): void
    {
        if ($this->status === 'shipped') {
            throw InvalidStateDomainException::because('Cannot cancel a shipped order.');
        }

        $this->apply(DomainEvent::occur('order.cancelled', [
            'id' => $this->id(),
            'previous_status' => $this->status,
        ]));
    }

    /**
     * Get the number of items in the order.
     */
    public function itemCount(): int
    {
        return count($this->items);
    }

    protected function applyOrderPlaced(DomainEvent $event): void
    {
        $this->status = $event->payload['status'];
        $this->total = $event->payload['total'];
    }

    protected function applyOrderItemAdded(DomainEvent $event): void
    {
        $this->items[] = [
            'productId' => $event->payload['productId'],
            'quantity' => $event->payload['quantity'],
            'unitPrice' => $event->payload['unitPrice'],
        ];

        $this->total += $event->payload['subtotal'];
    }

    protected function applyOrderPaid(DomainEvent $event): void
    {
        $this->status = 'paid';
        $this->total = $event->payload['total'];
    }

    protected function applyOrderShipped(DomainEvent $event): void
    {
        $this->status = 'shipped';
    }

    protected function applyOrderCancelled(DomainEvent $event): void
    {
        $this->status = 'cancelled';
    }

    #[\Override]
    public function toArray(): array
    {
        return [
            'id' => $this->id(),
            'version' => $this->version(),
            'type' => class_basename(static::class),
            'status' => $this->status,
            'total' => $this->total,
            'items' => $this->items,
            'item_count' => $this->itemCount(),
        ];
    }
}

/**
 * Order item entity — demonstrates Entity usage within an aggregate.
 *
 * @since 1.0.0
 */
final class OrderItem extends Entity
{
    public function __construct(
        int|string|\Stringable $id,
        public readonly string $productId,
        public int $quantity,
        public float $unitPrice,
    ) {
        parent::__construct($id);
    }

    public function subtotal(): float
    {
        return $this->quantity * $this->unitPrice;
    }

    #[\Override]
    public function toArray(): array
    {
        return [
            'id' => $this->id(),
            'type' => class_basename(static::class),
            'product_id' => $this->productId,
            'quantity' => $this->quantity,
            'unit_price' => $this->unitPrice,
            'subtotal' => $this->subtotal(),
        ];
    }
}

/**
 * Order status value object — demonstrates domain ValueObject with state transition rules.
 *
 * @since 1.0.0
 */
final class OrderStatus extends ValueObject
{
    public function __construct(public readonly string $value)
    {
        if (! in_array($value, ['pending', 'paid', 'shipped', 'cancelled'], true)) {
            throw new \InvalidArgumentException("Invalid order status: {$value}");
        }
    }

    public static function pending(): self
    {
        return new self('pending');
    }

    public static function paid(): self
    {
        return new self('paid');
    }

    public static function shipped(): self
    {
        return new self('shipped');
    }

    public static function cancelled(): self
    {
        return new self('cancelled');
    }

    /**
     * Check if transition to the given status is valid per domain rules.
     */
    public function canTransitionTo(self $next): bool
    {
        return match ($this->value) {
            'pending' => in_array($next->value, ['paid', 'cancelled'], true),
            'paid' => $next->value === 'shipped',
            'shipped', 'cancelled' => false,
            default => false,
        };
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        return new static(value: $data['value']);
    }

    #[\Override]
    public function toArray(): array
    {
        return ['value' => $this->value];
    }

    #[\Override]
    public function __toString(): string
    {
        return $this->value;
    }
}
