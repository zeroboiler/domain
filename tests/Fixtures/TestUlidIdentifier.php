<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Tests\Fixtures;

use Symfony\Component\Uid\Ulid;
use ZeroBoiler\Domain\Identifiers\UlidIdentifier;

/**
 * Concrete ULID identifier fixture for testing.
 *
 * @internal Test-only class.
 */
final readonly class TestUlidIdentifier extends UlidIdentifier
{
    public function __construct(?string $value = null)
    {
        parent::__construct($value ?? (new Ulid)->toBase32());
    }
}
