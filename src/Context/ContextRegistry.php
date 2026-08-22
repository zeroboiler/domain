<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Context;

use ZeroBoiler\Domain\Exceptions\NotFoundDomainException;

/**
 * Registry of bounded contexts in the application.
 *
 * Each context is registered exactly once by name; handlers, providers and
 * generators resolve their target context through this registry instead of
 * hardcoding paths, keeping discovery and registration decoupled from the
 * physical directory layout.
 *
 * @since 1.13.0
 *
 * @example
 * ```php
 * $registry = new ContextRegistry;
 * $registry->register(BoundedContext::named('Identity'));
 *
 * $registry->has('Identity');               // true
 * $registry->get('Identity')->namespace();  // 'App\Contexts\Identity'
 * $registry->names();                       // ['Identity']
 * ```
 */
final class ContextRegistry
{
    /** @var array<string, BoundedContext> */
    private array $contexts = [];

    /**
     * Register a bounded context.
     *
     * Silently ignores duplicate registration of the same name (first wins),
     * mirroring ModuleRegistry semantics.
     *
     * @param  BoundedContext  $context  The context to register.
     * @return void
     */
    public function register(BoundedContext $context): void
    {
        if (isset($this->contexts[$context->name])) {
            return;
        }

        $this->contexts[$context->name] = $context;
    }

    /**
     * Check whether a context with the given name is registered.
     *
     * @param  string  $name  Context name (e.g. 'Identity').
     * @return bool True if the context is registered.
     */
    public function has(string $name): bool
    {
        return isset($this->contexts[$name]);
    }

    /**
     * Get a registered context by name.
     *
     * @param  string  $name  Context name (e.g. 'Identity').
     * @return BoundedContext The registered context.
     *
     * @throws NotFoundDomainException When no context is registered under the name.
     */
    public function get(string $name): BoundedContext
    {
        return $this->contexts[$name]
            ?? throw NotFoundDomainException::because(sprintf('Bounded context "%s" is not registered.', $name));
    }

    /**
     * List all registered context names in registration order.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->contexts);
    }

    /**
     * List all registered contexts in registration order.
     *
     * @return list<BoundedContext>
     */
    public function all(): array
    {
        return array_values($this->contexts);
    }

    /**
     * Remove all registered contexts.
     *
     * Used by long-running workers (Octane) to avoid stale state between jobs.
     *
     * @return void
     */
    public function flush(): void
    {
        $this->contexts = [];
    }

    /**
     * Serialize the registry state for debugging and cache payloads.
     *
     * @return array<string, array{name: string, namespace: string, path: string}>
     */
    public function toArray(): array
    {
        $out = [];

        foreach ($this->contexts as $name => $context) {
            $out[$name] = $context->toArray();
        }

        return $out;
    }
}
