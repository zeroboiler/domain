<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Application;

/**
 * Marker interface for command messages — intent to change domain state.
 *
 * Commands are named with an imperative verb (RegisterUser, CancelOrder),
 * dispatched through a CommandBus and handled by exactly one CommandHandler.
 * They must not return data; read the resulting state through queries.
 *
 * @since 1.13.0
 *
 * @example
 * ```php
 * final readonly class RegisterUser implements Command
 * {
 *     public function __construct(
 *         public string $email,
 *         public string $name,
 *     ) {}
 * }
 * ```
 */
interface Command
{
}
