<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Application;

use ZeroBoiler\Domain\Exceptions\ConflictDomainException;
use ZeroBoiler\Domain\Exceptions\NotFoundDomainException;

/**
 * Dispatches Command messages to their registered handler.
 *
 * Handlers are registered per command class name. A command maps to exactly
 * one handler; registering a second handler for the same command is rejected
 * so routing stays explicit and predictable. Handlers resolved through the
 * optional resolver are memoized per command class.
 *
 * The resolver callable is stored as Closure — PHP forbids callable-typed
 * properties.
 *
 * @since 1.13.0
 *
 * @example
 * ```php
 * $bus = new CommandBus;
 * $bus->register(RegisterUser::class, new RegisterUserHandler($users));
 * $bus->dispatch(new RegisterUser('jane@example.com', 'Jane'));
 * ```
 */
final class CommandBus
{
    /** @var array<class-string<Command>, CommandHandler> */
    private array $handlers = [];

    private readonly ?\Closure $resolver;

    /**
     * Create a bus with an optional handler resolver.
     *
     * The resolver is consulted for command classes with no registered
     * handler — typically a PSR-11 container lookup so applications wire
     * handlers through DI instead of manual registration.
     *
     * @param  (callable(class-string<Command>): (CommandHandler|null))|null  $resolver  Optional handler resolver.
     */
    public function __construct(?callable $resolver = null)
    {
        $this->resolver = $resolver === null ? null : \Closure::fromCallable($resolver);
    }

    /**
     * Register a handler for a command class.
     *
     * @param  class-string<Command>  $commandClass  The command the handler executes.
     * @param  CommandHandler  $handler  The handler instance.
     * @return self
     *
     * @throws ConflictDomainException When the command class already has a registered handler.
     */
    public function register(string $commandClass, CommandHandler $handler): self
    {
        if (isset($this->handlers[$commandClass])) {
            throw ConflictDomainException::because(
                sprintf('Command "%s" already has a registered handler.', $commandClass),
            );
        }

        $this->handlers[$commandClass] = $handler;

        return $this;
    }

    /**
     * Dispatch a command to its handler.
     *
     * @param  Command  $command  The command message to execute.
     * @return void
     *
     * @throws NotFoundDomainException When no handler is registered and the resolver cannot provide one.
     */
    public function dispatch(Command $command): void
    {
        $this->resolveHandler($command::class)->handle($command);
    }

    /**
     * Check whether a handler is available for a command class.
     *
     * Resolution results are memoized, so checking never builds the same
     * handler twice.
     *
     * @param  class-string<Command>  $commandClass  The command class to check.
     * @return bool True when a handler is registered or resolvable.
     */
    public function hasHandler(string $commandClass): bool
    {
        if (isset($this->handlers[$commandClass])) {
            return true;
        }

        if ($this->resolver === null) {
            return false;
        }

        return $this->tryResolve($commandClass) !== null;
    }

    /**
     * Resolve the handler for a command, memoizing resolver results.
     *
     * @param  class-string<Command>  $commandClass  The command class to resolve.
     * @return CommandHandler The resolved handler.
     *
     * @throws NotFoundDomainException When no handler can be resolved.
     */
    private function resolveHandler(string $commandClass): CommandHandler
    {
        return $this->handlers[$commandClass]
            ?? $this->tryResolve($commandClass)
            ?? throw NotFoundDomainException::because(
                sprintf('No handler available for command "%s".', $commandClass),
            );
    }

    /**
     * Attempt resolver lookup for a command class, memoizing on success.
     *
     * @param  class-string<Command>  $commandClass  The command class to resolve.
     * @return CommandHandler|null The memoized or resolver-provided handler, or null when unresolvable.
     */
    private function tryResolve(string $commandClass): ?CommandHandler
    {
        if ($this->resolver === null) {
            return null;
        }

        $handler = ($this->resolver)($commandClass);

        if ($handler === null) {
            return null;
        }

        $this->handlers[$commandClass] = $handler;

        return $handler;
    }
}
