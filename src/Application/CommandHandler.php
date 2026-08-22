<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Application;

/**
 * Handles exactly one Command type.
 *
 * Handlers carry the use-case orchestration: load aggregates from their
 * repository, invoke domain behavior, persist. They never return data —
 * the command's success is observable only through queries and events.
 *
 * @template TCommand of Command
 *
 * @since 1.13.0
 *
 * @example
 * ```php
 * final class RegisterUserHandler implements CommandHandler
 * {
 *     /* @use HandlesCommands<RegisterUser> *\/
 *     use HandlesCommands;
 *
 *     public function __construct(
 *         private readonly UserRepository $users,
 *     ) {}
 *
 *     public function handle(RegisterUser $command): void
 *     {
 *         $user = User::register($command->email, $command->name);
 *         $this->users->save($user);
 *     }
 * }
 * ```
 */
interface CommandHandler
{
    /**
     * Execute the command.
     *
     * @param  Command  $command  The command message to execute.
     * @return void
     */
    public function handle(Command $command): void;
}
