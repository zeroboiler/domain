<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Tests\Fixtures;

use Ramsey\Uuid\Uuid;
use ZeroBoiler\Domain\Identifiers\UuidIdentifier;

/**
 * Concrete UUID identifier fixture for testing.
 *
 * @internal Test-only class.
 */
final readonly class TestUuidIdentifier extends UuidIdentifier
{
    public function __construct(?string $value = null)
    {
        parent::__construct($value ?? Uuid::uuid4()->toString());
    }
}
