<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Application;

use ZeroBoiler\Domain\Exceptions\ConflictDomainException;
use ZeroBoiler\Domain\Exceptions\NotFoundDomainException;

/**
 * Dispatches Query messages to their registered handler and returns results.
 *
 * Mirrors CommandBus routing semantics: one handler per query class,
 * duplicate registration rejected, resolver-provided handlers memoized.
 *
 * The resolver callable is stored as Closure — PHP forbids callable-typed
 * properties.
 *
 * @since 1.13.0
 *
 * @example
 * ```php
 * $bus = new QueryBus;
 * $bus->register(GetUserByEmail::class, new GetUserByEmailHandler($users));
 * $user = $bus->ask(new GetUserByEmail('jane@example.com'));
 * ```
 */
final class QueryBus
{
    /** @var array<class-string<Query>, QueryHandler> */
    private array $handlers = [];

    private readonly ?\Closure $resolver;

    /**
     * Create a bus with an optional handler resolver.
     *
     * The resolver is consulted for query classes with no registered
     * handler — typically a PSR-11 container lookup so applications wire
     * handlers through DI instead of manual registration.
     *
     * @param  (callable(class-string<Query>): (QueryHandler|null))|null  $resolver  Optional handler resolver.
     */
    public function __construct(?callable $resolver = null)
    {
        $this->resolver = $resolver === null ? null : \Closure::fromCallable($resolver);
    }

    /**
     * Register a handler for a query class.
     *
     * @param  class-string<Query>  $queryClass  The query the handler answers.
     * @param  QueryHandler  $handler  The handler instance.
     * @return self
     *
     * @throws ConflictDomainException When the query class already has a registered handler.
     */
    public function register(string $queryClass, QueryHandler $handler): self
    {
        if (isset($this->handlers[$queryClass])) {
            throw ConflictDomainException::because(
                sprintf('Query "%s" already has a registered handler.', $queryClass),
            );
        }

        $this->handlers[$queryClass] = $handler;

        return $this;
    }

    /**
     * Ask a query and return the handler's result.
     *
     * @template TResult
     *
     * @param  Query  $query  The query message to answer.
     * @return TResult The handler's answer.
     *
     * @throws NotFoundDomainException When no handler is registered and the resolver cannot provide one.
     */
    public function ask(Query $query): mixed
    {
        return $this->resolveHandler($query::class)->handle($query);
    }

    /**
     * Check whether a handler is available for a query class.
     *
     * Resolution results are memoized, so checking never builds the same
     * handler twice.
     *
     * @param  class-string<Query>  $queryClass  The query class to check.
     * @return bool True when a handler is registered or resolvable.
     */
    public function hasHandler(string $queryClass): bool
    {
        if (isset($this->handlers[$queryClass])) {
            return true;
        }

        if ($this->resolver === null) {
            return false;
        }

        return $this->tryResolve($queryClass) !== null;
    }

    /**
     * Resolve the handler for a query, memoizing resolver results.
     *
     * @param  class-string<Query>  $queryClass  The query class to resolve.
     * @return QueryHandler The resolved handler.
     *
     * @throws NotFoundDomainException When no handler can be resolved.
     */
    private function resolveHandler(string $queryClass): QueryHandler
    {
        return $this->handlers[$queryClass]
            ?? $this->tryResolve($queryClass)
            ?? throw NotFoundDomainException::because(
                sprintf('No handler available for query "%s".', $queryClass),
            );
    }

    /**
     * Attempt resolver lookup for a query class, memoizing on success.
     *
     * @param  class-string<Query>  $queryClass  The query class to resolve.
     * @return QueryHandler|null The memoized or resolver-provided handler, or null when unresolvable.
     */
    private function tryResolve(string $queryClass): ?QueryHandler
    {
        if ($this->resolver === null) {
            return null;
        }

        $handler = ($this->resolver)($queryClass);

        if ($handler === null) {
            return null;
        }

        $this->handlers[$queryClass] = $handler;

        return $handler;
    }
}
