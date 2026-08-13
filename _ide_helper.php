<?php

/**
 * IDE Helper for ZeroBoiler Domain Package.
 *
 * This file is NOT autoloaded and should NOT be included in production.
 * It exists solely to provide IDE autocomplete hints for types that
 * are resolved at runtime (e.g., from service container bindings,
 * reflection-based instantiation, and duck-typed interfaces).
 *
 * @see https://zeroboiler.dev/docs/domain
 *
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

// phpcs:disable PSR1.Files.SideEffects
// phpcs:disable Squiz.Classes.ValidClassName.NotCamelCaps

namespace ZeroBoiler\Domain {

    use ZeroBoiler\Domain\Contracts\AggregateRoot as AggregateRootContract;
    use ZeroBoiler\Domain\Contracts\Entity as EntityContract;
    use ZeroBoiler\Domain\Contracts\Identifier as IdentifierContract;
    use ZeroBoiler\Domain\Contracts\Repository as RepositoryContract;
    use ZeroBoiler\Domain\Contracts\UnitOfWork as UnitOfWorkContract;
    use ZeroBoiler\Domain\Identifiers\IntegerIdentifier;
    use ZeroBoiler\Domain\Identifiers\StringIdentifier;
    use ZeroBoiler\Domain\Identifiers\UuidIdentifier;
    use ZeroBoiler\Domain\Identifiers\UlidIdentifier;
    use ZeroBoiler\Domain\Identifiers\Identifier;
    use ZeroBoiler\Domain\Snapshots\Snapshot;
    use ZeroBoiler\Domain\Snapshots\SnapshotStore;

    /**
     * Facade-style static access to common domain operations.
     *
     * This helper provides IDE hints for the service container bindings
     * registered by DomainServiceProvider. Not a real class — used only
     * for IDE autocompletion.
     *
     * @mixin \ZeroBoiler\Domain\InMemoryUnitOfWork
     */
    final class Domain
    {
        /**
         * Resolve the UnitOfWork from the container.
         *
         * @return UnitOfWorkContract&\ZeroBoiler\Domain\InMemoryUnitOfWork
         */
        public static function uow(): UnitOfWorkContract {}

        /**
         * Resolve the SnapshotStore from the container.
         *
         * @return SnapshotStore&\ZeroBoiler\Domain\Snapshots\InMemorySnapshotStore
         */
        public static function snapshotStore(): SnapshotStore {}
    }
}

namespace ZeroBoiler\Domain\Contracts {

    /**
     * @method string id() Get the unique identity of the entity.
     * @method bool equals(self $other) Check equality with another entity.
     */
    interface Entity {}
}

namespace ZeroBoiler\Domain\Contracts {

    /**
     * @method string toString() Get the string representation of the identifier.
     * @method bool equals(self $other) Check structural equality with another identifier.
     * @method string __toString() String casting shortcut for toString().
     */
    interface Identifier {}
}

namespace ZeroBoiler\Domain\Identifiers {

    /**
     * @method static self generate() Generate a new random UUID v4 identifier.
     * @method static self fromString(string $value) Create from an existing UUID string.
     * @method static bool isValid(string $value) Validate a UUID string without throwing.
     * @method string value() Get the underlying UUID string value.
     * @method string toString() Get the string representation.
     * @method bool equals(IdentifierContract $other) Check structural equality.
     * @method array{value: string} toArray() Serialize to array.
     * @method static self fromArray(array{value: string} $data) Restore from array.
     * @method string jsonSerialize() JSON serialize (returns UUID string).
     */
    abstract class UuidIdentifier implements \ZeroBoiler\Domain\Contracts\Identifier, \JsonSerializable {}
}

namespace ZeroBoiler\Domain\Identifiers {

    /**
     * @method static self generate() Generate a new random ULID identifier.
     * @method static self fromString(string $value) Create from an existing ULID string.
     * @method static bool isValid(string $value) Validate a ULID string without throwing.
     * @method string value() Get the underlying ULID string value.
     * @method string toString() Get the string representation.
     * @method bool equals(IdentifierContract $other) Check structural equality.
     * @method array{value: string} toArray() Serialize to array.
     * @method static self fromArray(array{value: string} $data) Restore from array.
     * @method string jsonSerialize() JSON serialize (returns ULID string).
     */
    abstract class UlidIdentifier implements \ZeroBoiler\Domain\Contracts\Identifier, \JsonSerializable {}
}

namespace ZeroBoiler\Domain\Identifiers {

    /**
     * @method string value() Get the underlying string value.
     * @method string toString() Get the string representation.
     * @method bool equals(IdentifierContract $other) Check structural equality.
     * @method array{value: string} toArray() Serialize to array.
     * @method static self fromArray(array{value: string} $data) Restore from array.
     * @method string jsonSerialize() JSON serialize.
     */
    final class StringIdentifier implements \ZeroBoiler\Domain\Contracts\Identifier, \JsonSerializable {}
}

namespace ZeroBoiler\Domain\Identifiers {

    /**
     * @method int value() Get the underlying integer value.
     * @method string toString() Get the string representation.
     * @method bool equals(IdentifierContract $other) Check structural equality.
     * @method array{value: int} toArray() Serialize to array.
     * @method static self fromArray(array{value: int} $data) Restore from array.
     * @method string jsonSerialize() JSON serialize.
     */
    final class IntegerIdentifier implements \ZeroBoiler\Domain\Contracts\Identifier, \JsonSerializable {}
}

namespace ZeroBoiler\Domain\Exceptions {

    /**
     * @method string errorCode() Get the machine-readable error code.
     * @method array{title: string, detail: string, code: string} toErrorArray() Serialize for API responses.
     * @method array{title: string, detail: string, code: string} toArray() Alias for toErrorArray().
     * @method static self because(string $reason, string $code = '') Create with human-readable reason.
     */
    abstract class DomainException extends \RuntimeException implements \JsonSerializable {}
}

namespace ZeroBoiler\Domain\Snapshots {

    /**
     * @property-read string $aggregateType The FQCN of the aggregate.
     * @property-read string $aggregateId The aggregate's unique identifier.
     * @property-read int $version The aggregate version at snapshot time.
     * @property-read array<string, mixed> $state The serialized aggregate state.
     *
     * @method static self create(string $aggregateType, string $aggregateId, int $version, array $state) Create a snapshot.
     * @method bool equals(self $other) Check structural equality.
     * @method array{aggregate_type: string, aggregate_id: string, version: int, state: array<string, mixed>} toArray() Serialize.
     * @method static self fromArray(array<string, mixed> $data) Restore from array.
     */
    final readonly class Snapshot implements \JsonSerializable {}
}

namespace ZeroBoiler\Domain {

    /**
     * @method int version() Get the current aggregate version.
     * @method DomainEventCollection pullDomainEvents() Pull and return all raised domain events.
     * @method DomainEventCollection releaseDomainEvents() Release events without clearing.
     * @method void clearDomainEvents() Clear all pending domain events.
     * @method bool hasUncommittedEvents() Check if uncommitted events exist.
     * @method int getPendingEventCount() Get count of pending events.
     * @method void recordThat(\ZeroBoiler\Events\Domain\DomainEvent $event) Record a domain event.
     * @method void apply(\ZeroBoiler\Events\Domain\DomainEvent $event) Apply an event to mutate state.
     * @method static self fromHistory(\ZeroBoiler\Events\Domain\DomainEvent ...$events) Reconstitute from events.
     * @method static self reconstituteFromSnapshot(Snapshot $snapshot, array $events) Reconstitute from snapshot + events.
     */
    abstract class AggregateRoot implements AggregateRootContract {}
}

// phpcs:enable
