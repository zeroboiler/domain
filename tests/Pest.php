<?php

/*
|--------------------------------------------------------------------------
| ZeroBoiler Domain — Test Bootstrap
|--------------------------------------------------------------------------
|
| Tests are PHP-only files designed for execution with Pest.
| They do NOT run on this machine (no PHP); they are committed
| as production-quality test artifacts for CI/CD pipelines.
|
*/

/**
 * The test directory is intentionally minimal because the domain package
 * is a pure-DDD foundation with no framework coupling in core classes.
 * Focus areas for tests:
 *
 * - Value Object equality and immutability
 * - Entity identity comparison
 * - AggregateRoot event recording and versioning
 * - AggregateRootId generation and equality
 * - Identifier types (UUID, ULID, String, Integer)
 * - DomainException hierarchy and serialization
 * - DomainEventCollection behavior
 * - InMemoryUnitOfWork tracking
 * - Snapshot creation and restoration
 * - HasSnapshots trait behavior
 */

// Pest configuration
expect()->extend('toBeEqualTo', function (object $other): void {
    expect($this->value->equals($other))->toBeTrue();
});
