# Changelog

All notable changes to the `zeroboiler/domain` package will be documented in this file.

## [1.48.0] - 2026-08-10

### Changed
- Bump package version to 1.48.0

### Added
- README: Domain Identifier Round-Trip (`fromArray`/`toArray`) section — covers UUID, ULID, String, and Integer identifier serialization with cache/queue job usage examples

## [1.47.0] - 2026-08-10

### Changed
- Bump package version to 1.47.0

### Fixed
- `InMemoryUnitOfWork::restoreAggregateState()` — guard against uninitialized properties (avoids `getValue()` error) and wrap each property restore in try/catch for Closure/resource properties that cannot survive clone

### Added
- `@since 1.0.0` tag to `MakeValueObjectCommand`

## [1.46.0] - 2026-08-10

### Changed
- Bump package version to 1.46.0

### Added
- `@since 1.0.0` tag to `MakeValueObjectCommand`

## [1.45.0] - 2026-08-09

### Fixed
- Entity docblock example: `quantity` property now uses `public readonly int` for immutability consistency

### Added
- `DomainProductionRoundTripAuditTest` — comprehensive round-trip serialization audit covering AggregateRootId, all identifier types, DomainEventCollection, DomainException hierarchy, Snapshot, and contract return types
- Custom domain exception example (`TestOrderShippedException`) for testing pattern documentation

### Added
- `hasUncommittedEvents()` to HasDomainEvents trait
- `peekDomainEvents()` non-destructive event inspection on AggregateRoot
- `@since` version tags to all contract interfaces for API tracking
- CHANGELOG.md for structured release history

### Changed
- Use `class_basename()` in `AggregateRoot::toArray()` for consistent type field

### Fixed
- N/A

## [1.41.0] - 2026-08-08

### Added
- `@implements` annotation to DomainException for IDE auto-completion
- Boundary invariants test for domain contract verification

### Fixed
- N/A

## [1.40.0] - 2026-08-07

### Added
- `fromArray()` round-trip serialization to DomainEventCollection
- `releaseEvents()` backward-compatible alias in HasDomainEvents trait

---

## Contract Reference

| Contract | Since | Purpose |
|---|---|---|
| `Contracts\Entity` | 1.0.0 | Domain entity with identity and equality |
| `Contracts\AggregateRoot` | 1.0.0 | Entity + versioning + domain events |
| `Contracts\Identifier` | 1.0.0 | String-based identifier contract |
| `Contracts\Repository` | 1.0.0 | Aggregate persistence with optimistic locking |
| `Contracts\UnitOfWork` | 1.0.0 | Transactional boundary with event queuing |
