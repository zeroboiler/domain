<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Contracts;

use Stringable;
use ZeroBoiler\Domain\Identifiers\IntegerIdentifier;
use ZeroBoiler\Domain\Identifiers\StringIdentifier;
use ZeroBoiler\Domain\Identifiers\UuidIdentifier;

/**
 * Shared identifier contract for cross-package interoperability.
 *
 * Implemented by all identifier types in the ZeroBoiler ecosystem.
 * This interface allows persistence, tenancy, and state-machine
 * packages to work with any identifier implementation uniformly.
 *
 * @see UuidIdentifier
 * @see StringIdentifier
 * @see IntegerIdentifier
 * @see \ZeroBoiler\Domain\Identifiers\Identifier
 */
interface Identifier extends Stringable
{
    /**
     * Create an instance from a string representation.
     */
    public static function fromString(string $value): static;

    /**
     * Get the string representation of the identifier.
     */
    public function toString(): string;

    /**
     * Check equality with another identifier of the same type.
     */
    public function equals(self $other): bool;
}
