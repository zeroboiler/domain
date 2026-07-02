<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain;

use ZeroBoiler\ValueObjects\Contracts\ValueObject as ValueObjectContract;
use ZeroBoiler\ValueObjects\ValueObject as BaseValueObject;

/**
 * Domain value object base class.
 *
 * Extends the zeroboiler/value-objects package to provide
 * immutability, equality, validation, and Eloquent auto-casting
 * for domain-level value objects.
 *
 * Domain VOs (OrderId, CustomerId, Address, etc.) should extend
 * this class to get full integration with the value-objects ecosystem.
 *
 * @extends BaseValueObject<string, mixed>
 */
abstract class ValueObject extends BaseValueObject
{
    /**
     * Check equality with another value object.
     */
    #[\Override]
    public function equals(?ValueObjectContract $other): bool
    {
        if (! $other instanceof ValueObjectContract || $other::class !== static::class) {
            return false;
        }

        return $this->toArray() === $other->toArray();
    }
}
