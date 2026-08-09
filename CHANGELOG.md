# Changelog

All notable changes to the `zeroboiler/domain` package will be documented in this file.

## [1.42.0] - 2026-08-09

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
