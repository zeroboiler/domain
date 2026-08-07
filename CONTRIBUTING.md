# Contributing to ZeroBoiler Domain

## Code Standards

- **PHP 8.5+** — `declare(strict_types=1)` on every file
- **Final classes**: AggregateRootId, DomainEventCollection, InMemoryUnitOfWork, DomainServiceProvider, SnapshotPolicy, InMemorySnapshotStore, SnapshottingRepository, IntegerIdentifier, StringIdentifier, all commands, all exception classes
- **Abstract classes**: AggregateRoot (abstract), Entity (abstract), Identifier (abstract), DomainException (abstract), UuidIdentifier (abstract readonly), UlidIdentifier (abstract readonly), ValueObject (abstract)
- **Readonly classes**: AggregateRootId, DomainEventCollection, SnapshotPolicy, Identifier (deprecated), UuidIdentifier, UlidIdentifier, StringIdentifier, IntegerIdentifier, Snapshot
- **`:void` return types** on all constructors (PHP 8.5)
- **Return type declarations** on all public methods
- **`#[\Override]` attributes** on all interface implementations
- **Zero TODO/FIXME markers** in production source

## Quality Checks

```bash
composer test              # Run Pest test suite
composer lint              # Pint style check
composer lint:fix          # Pint style fix
composer analyse           # PHPStan static analysis
composer rector            # Rector automated refactoring
composer quality           # Run all checks (lint + analyse + rector + test)
```

## Architecture

```
Core:
  ├── AggregateRoot          ← Typed identity (UUID v4), domain events, versioning, optimistic locking
  ├── Entity                 ← Identity equality with flexible ID types (string, int, Stringable)
  ├── ValueObject            ← Domain-level equality via toArray() comparison
  ├── DomainEventCollection  ← Type-safe, readonly collection for DomainEvent objects
  └── InMemoryUnitOfWork     ← Transactional boundary with savepoints, event queuing, rollback snapshots

Contracts:
  ├── Entity                 ← id(): string, equals(): bool
  ├── AggregateRoot          ← version(), incrementVersion(), pullDomainEvents(), clearDomainEvents()
  ├── Identifier             ← fromString(), toString(), equals()
  ├── Repository             ← find(), save(), delete() with optimistic locking
  └── UnitOfWork             ← begin(), commit(), rollback(), run(), track()

Identifiers:
  ├── UuidIdentifier         ← Abstract readonly, UUID v4
  ├── UlidIdentifier         ← Abstract readonly, monotonic ULID
  ├── StringIdentifier       ← Final readonly, non-empty string
  └── IntegerIdentifier      ← Final readonly, integer

Traits:
  ├── HasDomainEvents        ← Event recording, release, clear
  ├── EventSourced           ← fromHistory(), applyEvent() replay
  └── HasSnapshots           ← toSnapshotState(), restoreFromSnapshot(), shouldSnapshot()

Snapshots:
  ├── Snapshot               ← Immutable aggregate state at a specific version
  ├── SnapshotStore          ← Interface for storage backends
  ├── SnapshotPolicy         ← #[Attribute] to configure snapshot interval
  ├── InMemorySnapshotStore  ← Default in-memory implementation
  └── SnapshottingRepository ← Decorator adding snapshot support to any Repository

Exceptions:
  ├── DomainException        ← Abstract base with errorCode(), toErrorArray(), JsonSerializable
  ├── InvalidArgumentDomainException
  ├── InvalidStateDomainException
  ├── NotFoundDomainException
  ├── ConflictDomainException
  ├── AggregateNotFoundException
  ├── InvalidAggregateRootException
  └── OptimisticLockException

Commands:
  ├── DomainAggregateCommand       ← Generate AggregateRoot class
  ├── DomainRepositoryCommand      ← Generate Repository interface + Eloquent implementation
  ├── DomainListCommand            ← List all domain classes
  ├── MakeValueObjectCommand       ← Generate ValueObject class
  └── SnapshotCommand              ← Inspect snapshot store
```

## Pull Requests

1. Fork the repository
2. Create a feature branch (`git checkout -b feat/my-feature`)
3. Ensure all quality checks pass
4. Commit with conventional prefix (`feat:`, `fix:`, `refactor:`, `docs:`, `test:`)
5. Push and open a pull request
