# Changelog

All notable changes to the `zeroboiler/domain` package will be documented in this file.

## [1.26.0] - 2026-08-07

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
