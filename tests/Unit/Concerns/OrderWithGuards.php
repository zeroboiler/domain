<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Tests\Unit\Concerns;

use ZeroBoiler\Domain\AggregateRoot;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\Concerns\Guards;
use ZeroBoiler\Domain\Exceptions\InvalidArgumentDomainException;
use ZeroBoiler\Domain\Exceptions\InvalidStateDomainException;
use ZeroBoiler\Domain\Exceptions\NotFoundDomainException;

/**
 * @internal Test fixture: AggregateRoot with Guards trait for contract verification.
 */
final class OrderWithGuards extends AggregateRoot
{
    use Guards;

    public function __construct(
        public readonly string $status,
        public readonly int $total,
    ) {
        parent::__construct(AggregateRootId::generate());
        $this->assertNotEmptyString($status, 'status');
        $this->assertPositiveInteger($total, 'total');
    }

    public static function create(string $status, int $total): self
    {
        return new self($status, $total);
    }

    public function pay(): void
    {
        $this->assertStateIs('pending', $this->status, 'pay');
    }

    public function cancel(): void
    {
        $this->assertStateIn(['pending', 'confirmed'], $this->status, 'cancel');
    }

    public function ship(): void
    {
        $this->assertStateIsNot('cancelled', $this->status, 'ship');
    }

    public function validateBalance(int $balance): void
    {
        $this->assertNonNegativeInteger($balance, 'balance');
    }

    public function validateCustomer(mixed $customer): void
    {
        $this->assertNotNull($customer, 'customer');
    }

    public function assertOrderExists(mixed $order): void
    {
        $this->assertFound($order, 'Order');
    }

    public function validateTotalRange(int|float $amount): void
    {
        $this->assertRange($amount, 0.01, 99999.99, 'total');
    }

    public function validateCurrency(string $currency): void
    {
        $this->assertIn(['USD', 'EUR', 'GBP'], $currency, 'currency');
    }

    public function validateNameLength(string $name): void
    {
        $this->assertMaxLength($name, 5, 'name');
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
