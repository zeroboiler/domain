<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 *
 * No-op stub for the #[Trace] attribute when zeroboiler/observability is not installed.
 * The real attribute lives in ZeroBoiler\Observability\Trace and provides
 * auto-instrumentation for aggregate root operations.
 */

declare(strict_types=1);

namespace ZeroBoiler\Observability;

#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_CLASS)]
final readonly class Trace
{
    public function __construct(
        public string $operation = '',
    ) {}
}
