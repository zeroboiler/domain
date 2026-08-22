<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Context;

/**
 * A bounded context identity — the strategic DDD building block that groups
 * aggregates, handlers and services that share one domain model.
 *
 * Contexts are conventionally located under `app/Contexts/{Name}` with the
 * `App\Contexts\{Name}` namespace; `named()` resolves both from a bare name.
 *
 * @since 1.13.0
 *
 * @example
 * ```php
 * $identity = BoundedContext::named('Identity');
 * $identity->name();      // 'Identity'
 * $identity->namespace(); // 'App\Contexts\Identity'
 * $identity->path();      // 'app/Contexts/Identity'
 * ```
 */
final readonly class BoundedContext
{
    public function __construct(
        public string $name,
        public string $namespace,
        public string $path,
    ) {}

    /**
     * Create a context from its bare name using the app convention.
     *
     * @param  string  $name  Context name in PascalCase (e.g. 'Identity').
     * @param  string|null  $basePath  Optional base path override (defaults to 'app/Contexts').
     * @return self
     */
    public static function named(string $name, ?string $basePath = null): self
    {
        $base = $basePath ?? 'app/Contexts';

        return new self(
            name: $name,
            namespace: 'App\\Contexts\\' . $name,
            path: $base . '/' . $name,
        );
    }

    /**
     * Create a context with explicit namespace and path.
     *
     * @param  string  $name  Context name in PascalCase.
     * @param  string  $namespace  Root namespace of the context (e.g. 'App\Contexts\Identity').
     * @param  string  $path  Directory of the context relative to the app root.
     * @return self
     */
    public static function located(string $name, string $namespace, string $path): self
    {
        return new self($name, $namespace, $path);
    }

    /**
     * Check identity equality with another context.
     *
     * Two contexts are equal when they share the same name — the name is
     * the unique key inside a ContextRegistry.
     *
     * @param  BoundedContext  $other  The context to compare against.
     * @return bool True if both contexts carry the same name.
     */
    public function equals(BoundedContext $other): bool
    {
        return $this->name === $other->name;
    }

    /**
     * Build the fully-qualified class name of a context member.
     *
     * @param  string  $relative  Class path relative to the context namespace (e.g. 'Domain\User').
     * @return string The FQCN (e.g. 'App\Contexts\Identity\Domain\User').
     */
    public function fqcn(string $relative): string
    {
        return $this->namespace . '\\' . str_replace('/', '\\', $relative);
    }

    /**
     * Represent the context as an array for serialization and debugging.
     *
     * @return array{name: string, namespace: string, path: string}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'namespace' => $this->namespace,
            'path' => $this->path,
        ];
    }

    /**
     * Return the context name as its string representation.
     *
     * @return string The context name.
     */
    public function __toString(): string
    {
        return $this->name;
    }
}
