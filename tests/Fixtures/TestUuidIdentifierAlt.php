<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Tests\Fixtures;

use ZeroBoiler\Domain\Identifiers\UuidIdentifier;

/**
 * Alternative UUID identifier fixture for equality type-safety tests.
 *
 * @since 1.47.0
 */
final readonly class TestUuidIdentifierAlt extends UuidIdentifier {}
