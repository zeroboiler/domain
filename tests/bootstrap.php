<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Bootstrap — ZeroBoiler Domain Package Tests
|--------------------------------------------------------------------------
|
| This bootstrap file is included by Pest before any test file runs.
| It sets up autoloading and test helper fixtures.
*/

// Require composer autoloader (exists in CI, not on this dev machine)
$autoloader = dirname(__DIR__) . '/vendor/autoload.php';
if (file_exists($autoloader)) {
    require_once $autoloader;
}

// --- Test fixtures (concrete implementations for abstract classes) ---

if (! class_exists(\TestConcreteEntity::class)) {
    /**
     * Concrete entity for testing the abstract Entity base class.
     * Exposes recordThat() for testability.
     */
    class TestConcreteEntity extends \ZeroBoiler\Domain\Entity
    {
        use \ZeroBoiler\Domain\Concerns\HasDomainEvents;

        public function __construct(
            int|string|\Stringable $id,
            public readonly string $name = '',
        ) {
            parent::__construct($id);
        }

        public function toArray(): array
        {
            return [
                ...parent::toArray(),
                'name' => $this->name,
            ];
        }

        /** @api Test helper — exposes protected recordThat(). */
        public function testRecordThat(\ZeroBoiler\Events\Domain\DomainEvent $event): void
        {
            $this->recordThat($event);
        }
    }
}

if (! class_exists(\TestConcreteAggregate::class)) {
    /**
     * Concrete aggregate root for testing the abstract AggregateRoot class.
     * Exposes recordThat() for testability.
     */
    class TestConcreteAggregate extends \ZeroBoiler\Domain\AggregateRoot
    {
        public string $name;

        public function __construct(
            \ZeroBoiler\Domain\AggregateRootId $id,
            string $name = 'Test',
        ) {
            parent::__construct($id);
            $this->name = $name;
        }

        public function rename(string $newName): void
        {
            $this->name = $newName;
        }

        public function toArray(): array
        {
            return [
                ...parent::toArray(),
                'name' => $this->name,
            ];
        }

        /** @api Test helper — exposes protected recordThat(). */
        public function testRecordThat(\ZeroBoiler\Events\Domain\DomainEvent $event): void
        {
            $this->recordThat($event);
        }
    }
}

if (! class_exists(\TestDomainEvent::class)) {
    /**
     * Minimal domain event for testing event recording.
     */
    class TestDomainEvent extends \ZeroBoiler\Events\Domain\DomainEvent
    {
        public function __construct(
            string $eventType = 'test.event',
            array $payload = [],
        ) {
            parent::__construct($eventType, $payload);
        }
    }
}

// --- Concrete Identifier subclasses for testing abstract base classes ---

if (! class_exists(\TestUuidIdentifier::class)) {
    /**
     * Concrete UuidIdentifier subclass for testing.
     */
    final readonly class TestUuidIdentifier extends \ZeroBoiler\Domain\Identifiers\UuidIdentifier {}
}

if (! class_exists(\TestUlidIdentifier::class)) {
    /**
     * Concrete UlidIdentifier subclass for testing.
     */
    final readonly class TestUlidIdentifier extends \ZeroBoiler\Domain\Identifiers\UlidIdentifier {}
}
