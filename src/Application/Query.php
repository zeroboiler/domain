<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Application;

/**
 * Marker interface for query messages — intent to read domain state.
 *
 * Queries are side-effect-free, dispatched through a QueryBus and answered
 * by exactly one QueryHandler. Pair commands and queries (CQS) so write and
 * read paths evolve independently.
 *
 * @since 1.13.0
 *
 * @example
 * ```php
 * final readonly class GetUserByEmail implements Query
 * {
 *     public function __construct(
 *         public string $email,
 *     ) {}
 * }
 * ```
 */
interface Query
{
}
