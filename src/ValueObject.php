<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain;

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
 * @extends BaseValueObject<array<string, mixed>>
 */
abstract class ValueObject extends BaseValueObject
{
    /**
     * Check equality with another value object.
     *
     * @param  BaseValueObject<mixed>  $other
     */
    #[\Override]
    public function equals(BaseValueObject $other): bool
    {
        if ($other::class !== static::class) {
            return false;
        }

        return $this->toArray() === $other->toArray();
    }
}
