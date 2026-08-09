# Changelog

All notable changes to the `zeroboiler/domain` package will be documented in this file.

## [1.43.0] - 2026-08-09

### Changed
- `AggregateRoot::toArray()` now uses `class_basename()` instead of `ReflectionClass::getShortName()` for consistency with `Entity::toArray()` and improved performance (avoids ReflectionClass instantiation on every serialization)

### Added
- `ArraySerializationConsistencyTest` — verifies toArray() consistency across AggregateRoot and Entity hierarchies, class_basename usage, subclass overrides, and type naming

## [1.37.0] - 2026-08-08

### Added
- `DomainContractIntegrityTest` — 35 comprehensive contract integrity tests covering AggregateRootId (final readonly, UUID v4, equality, JSON serialization, round-trip), DomainEventCollection (final readonly, immutable operations, toArray/jsonSerialize consistency, input validation), Entity (abstract, readonly id, toArray contract), AggregateRoot (abstract, versioning, event lifecycle, pull/peek), ValueObject (abstract), InMemoryUnitOfWork (final, run auto-commit/rollback, nested savepoints, clear, track requires active, rollback restores state), strict types verification across all source files, domain → response duck-typing contract validation, and return type declaration verification for all public methods

## [1.36.0] - 2026-08-08

### Added
- `AggregateRootId::toArray()` — array serialization with `uuid` key for consistent round-trip
- `AggregateRootId::fromArray()` — reconstruction from `toArray()` output, accepts `uuid` or `id` key
- `InMemoryUnitOfWork::getPendingEvents()` — non-destructive inspection of pending events as `DomainEventCollection`
- `AggregateRootIdRoundTripTest` — comprehensive round-trip serialization tests
- `UnitOfWorkGetPendingEventsTest` — UoW pending event inspection tests

## [1.29.0] - 2026-08-08

### Changed
- Enriched `AggregateRoot::apply()` docblock with handler resolution semantics and cross-reference to `EventSourced::applyEvent()`
- Fixed `EventSourced::applyEvent()` defensive null-safe handling for `preg_split()` return value (using `?: []` instead of `?? []` on map result)
- Added `@see AggregateRoot::apply()` cross-reference to `EventSourced::applyEvent()` docblock

### Added
- Added `DomainResponseBridgeProductionTest` — comprehensive test suite validating domain→response bridge contract including identifier JSON serialization, exception error arrays, aggregate toArray() contract, and duck-typing interface satisfaction

## [1.28.0] - 2026-08-07

### Changed
- Full production readiness audit — all classes verified for PHP 8.5 syntax, strict types, typed properties, return type declarations, and comprehensive docblocks
- Bump minimum PHP version alignment (PHP ^8.5)
- Added cross-references between exception hierarchy classes for better IDE navigation

## [1.27.0] - 2026-08-07

### Changed
- Enriched `DomainRepositoryCommand::buildImplementationStub()` docblocks — added `@param` and `@return` for all 5 parameters
- Enriched `DomainListCommand::listDirectory()` docblock — added `@param` and `@return`

## [1.25.0] - 2026-08-07

### Added
- Added `CONTRIBUTING.md` with code standards, quality commands, and architecture overview
- Phase 2-3-4 production readiness audit — all 38 source files pass quality checks

### Changed
- Marked `StringIdentifier` as `final readonly`
- Added `#[\Override]` attributes to all `JsonSerializable::jsonSerialize()` implementations

## [1.24.0] - 2026-08-07

### Changed
- Removed stale top-level stubs directory (unused by commands)
- Added `@internal` annotations to `InMemoryUnitOfWork` private methods
- Enriched `SnapshotStore` docblocks with `@return` annotations
- `StringIdentifier` → `final readonly`

## [1.23.0] - 2026-08-07

### Added
- `DomainEventCollection::toArray()` for API consistency
- `MakeValueObjectCommand` Artisan generator
- `HasSnapshotsSerializationEdgeCasesTest` — DateTimeInterface/BackedEnum/UnitEnum/stdClass serialization
- `DomainProductionReadinessChecklistTest` — 32 structural quality checks

### Changed
- Added `#[\Override]` to all `JsonSerializable::jsonSerialize()` implementations
- `DomainProductionHardeningTest` — immutability, type safety, and cross-contract verification

## [1.0.0] - 2026-08-01

### Added
- AggregateRoot with typed UUID v4 identity, domain events, versioning, optimistic locking
- Entity base class with flexible identity types (string, int, Stringable)
- ValueObject base class extending zeroboiler/value-objects
- DomainEventCollection — type-safe, readonly collection
- Repository interface with optimistic locking support
- UnitOfWork with savepoints, event queuing, rollback snapshots
- EventSourced trait for aggregate reconstitution from history
- HasSnapshots trait with configurable #[SnapshotPolicy]
- Identifier types: UuidIdentifier, UlidIdentifier, StringIdentifier, IntegerIdentifier
- DomainException hierarchy with machine-readable error codes
- SnapshottingRepository decorator with configurable snapshot policy
- InMemorySnapshotStore for testing and development
- CLI generators: domain:aggregate, domain:repository, domain:value-object, domain:list, domain:snapshot
- DomainServiceProvider with optional Events/Observability integration
- No-op Trace stub for zeroboiler/observability fallback
