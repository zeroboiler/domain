<?php

/**
 * Stub: No-op #[Trace] attribute for ZeroBoiler\Observability\Trace.
 *
 * This file is loaded by SnapshottingRepository when the
 * zeroboiler/observability package is not installed.
 * It allows the codebase to compile without the optional dependency
 * while still supporting auto-instrumentation when the package IS present.
 *
 * @internal This is a compile-time stub, not a replacement for the real package.
 */

declare(strict_types=1);

namespace ZeroBoiler\Observability;

#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_CLASS)]
final readonly class Trace
{
    /**
     * @param  string  $operation  The operation name (e.g. 'domain.aggregate.find').
     */
    public function __construct(
        public string $operation = '',
    ) {}
}
