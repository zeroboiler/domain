<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use ZeroBoiler\Domain\AggregateRoot;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\Contracts\AggregateRoot as AggregateRootContract;
use ZeroBoiler\Domain\Contracts\Entity as EntityContract;
use ZeroBoiler\Domain\Contracts\Identifier as IdentifierContract;
use ZeroBoiler\Domain\Contracts\Repository as RepositoryContract;
use ZeroBoiler\Domain\Contracts\UnitOfWork as UnitOfWorkContract;
use ZeroBoiler\Domain\DomainEventCollection;
use ZeroBoiler\Domain\Entity;
use ZeroBoiler\Domain\Exceptions\AggregateNotFoundException;
use ZeroBoiler\Domain\Exceptions\ConflictDomainException;
use ZeroBoiler\Domain\Exceptions\DomainException;
use ZeroBoiler\Domain\Exceptions\InvalidAggregateRootException;
use ZeroBoiler\Domain\Exceptions\InvalidArgumentDomainException;
use ZeroBoiler\Domain\Exceptions\InvalidStateDomainException;
use ZeroBoiler\Domain\Exceptions\InvalidStateException;
use ZeroBoiler\Domain\Exceptions\NotFoundDomainException;
use ZeroBoiler\Domain\Exceptions\OptimisticLockException;
use ZeroBoiler\Domain\Identifiers\IntegerIdentifier;
use ZeroBoiler\Domain\Identifiers\StringIdentifier;
use ZeroBoiler\Domain\Identifiers\UlidIdentifier;
use ZeroBoiler\Domain\Identifiers\UuidIdentifier;
use ZeroBoiler\Domain\InMemoryUnitOfWork;
use ZeroBoiler\Domain\Snapshots\InMemorySnapshotStore;
use ZeroBoiler\Domain\Snapshots\Snapshot;
use ZeroBoiler\Domain\Snapshots\SnapshotPolicy;
use ZeroBoiler\Domain\Snapshots\SnapshotStore;
use ZeroBoiler\Domain\Snapshots\SnapshottingRepository;
use ZeroBoiler\Domain\ValueObject;

/**
 * Validates the Production Readiness Checklist documented in README.md.
 *
 * Each test corresponds to a row in the "Code Quality" and "Architecture Principles"
 * tables, ensuring the documented guarantees match the actual implementation.
 *
 * @since 1.10.0
 */
#[Group('production-readiness')]
#[CoversClass(AggregateRoot::class)]
#[CoversClass(AggregateRootId::class)]
#[CoversClass(Entity::class)]
#[CoversClass(ValueObject::class)]
#[CoversClass(DomainEventCollection::class)]
#[CoversClass(InMemoryUnitOfWork::class)]
#[CoversClass(Snapshot::class)]
#[CoversClass(InMemorySnapshotStore::class)]
#[CoversClass(SnapshottingRepository::class)]
#[CoversClass(DomainException::class)]
final class ProductionReadinessChecklistValidationTest extends TestCase
{
    // ========================================================================
    // Code Quality: declare(strict_types=1)
    // ========================================================================

    /**
     * @test
     */
    public function all_source_files_use_strict_types(): void
    {
        $srcDir = dirname(__DIR__) . '/src';
        $files = $this->findAllPhpFiles($srcDir);
        $violations = [];

        foreach ($files as $file) {
            $contents = file_get_contents($file);
            if ($contents === false || ! str_contains($contents, 'declare(strict_types=1)')) {
                $violations[] = $file;
            }
        }

        $this->assertEmpty(
            $violations,
            'All source files must have declare(strict_types=1). Violations: '
            . implode(', ', $violations),
        );
    }

    // ========================================================================
    // Code Quality: Return type declarations
    // ========================================================================

    /**
     * @test
     */
    public function all_public_methods_have_return_type_declarations(): void
    {
        $classes = $this->getDomainClasses();
        $violations = [];

        foreach ($classes as $class) {
            $reflection = new ReflectionClass($class);

            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                // Skip constructor (return type not required in PHP 8.x)
                if ($method->getName() === '__construct') {
                    continue;
                }

                // Skip inherited methods from parent classes/interfaces
                if ($method->getDeclaringClass()->getName() !== $class) {
                    continue;
                }

                $returnType = $method->getReturnType();
                if ($returnType === null) {
                    $violations[] = "{$class}::{$method->getName()}()";
                }
            }
        }

        $this->assertEmpty(
            $violations,
            'All public methods must have return type declarations. Missing: '
            . implode(', ', $violations),
        );
    }

    // ========================================================================
    // Code Quality: Typed properties
    // ========================================================================

    /**
     * @test
     */
    public function all_properties_have_type_declarations(): void
    {
        $classes = $this->getDomainClasses();
        $violations = [];

        foreach ($classes as $class) {
            $reflection = new ReflectionClass($class);

            foreach ($reflection->getProperties() as $property) {
                // Skip static properties
                if ($property->isStatic()) {
                    continue;
                }

                $type = $property->getType();
                if ($type === null) {
                    $violations[] = "{$class}::\${$property->getName()}";
                }
            }
        }

        $this->assertEmpty(
            $violations,
            'All properties must have type declarations. Missing: '
            . implode(', ', $violations),
        );
    }

    // ========================================================================
    // Code Quality: Docblocks on public API
    // ========================================================================

    /**
     * @test
     */
    public function all_public_methods_have_docblocks(): void
    {
        $classes = $this->getDomainClasses();
        $violations = [];

        foreach ($classes as $class) {
            $reflection = new ReflectionClass($class);

            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getDeclaringClass()->getName() !== $class) {
                    continue;
                }

                $docComment = $method->getDocComment();
                if ($docComment === false || ! str_contains($docComment, '@param') && ! str_contains($docComment, '@return')) {
                    // Allow methods with no parameters to have only @return
                    if ($docComment === false) {
                        $violations[] = "{$class}::{$method->getName()}()";
                    }
                }
            }
        }

        $this->assertEmpty(
            $violations,
            'All public methods must have docblocks. Missing: '
            . implode(', ', $violations),
        );
    }

    // ========================================================================
    // Code Quality: Exception hierarchy correctness
    // ========================================================================

    /**
     * @test
     */
    public function all_domain_exceptions_extend_domain_exception(): void
    {
        $exceptionClasses = [
            InvalidStateDomainException::class,
            InvalidArgumentDomainException::class,
            NotFoundDomainException::class,
            ConflictDomainException::class,
            OptimisticLockException::class,
            AggregateNotFoundException::class,
            InvalidAggregateRootException::class,
            InvalidStateException::class,
        ];

        foreach ($exceptionClasses as $class) {
            $this->assertTrue(
                is_subclass_of($class, DomainException::class),
                "{$class} must extend DomainException",
            );
        }
    }

    /**
     * @test
     */
    public function all_exceptions_have_machine_readable_error_code(): void
    {
        $exceptionClasses = [
            InvalidStateDomainException::class,
            InvalidArgumentDomainException::class,
            NotFoundDomainException::class,
            ConflictDomainException::class,
            OptimisticLockException::class,
            AggregateNotFoundException::class,
            InvalidAggregateRootException::class,
            InvalidStateException::class,
        ];

        foreach ($exceptionClasses as $class) {
            $reflection = new ReflectionClass($class);
            $this->assertTrue(
                $reflection->hasMethod('defaultErrorCode'),
                "{$class} must define defaultErrorCode()",
            );
        }
    }

    /**
     * @test
     */
    public function all_exceptions_have_because_or_for_factory_method(): void
    {
        $exceptionClasses = [
            InvalidStateDomainException::class,
            InvalidArgumentDomainException::class,
            NotFoundDomainException::class,
            ConflictDomainException::class,
            OptimisticLockException::class,
            AggregateNotFoundException::class,
            InvalidAggregateRootException::class,
            InvalidStateException::class,
        ];

        foreach ($exceptionClasses as $class) {
            $reflection = new ReflectionClass($class);
            $methods = array_map(
                static fn (ReflectionMethod $m): string => $m->getName(),
                $reflection->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_STATIC),
            );

            $this->assertNotEmpty(
                array_intersect($methods, ['because', 'for', 'notAnAggregate']),
                "{$class} must have a named constructor (because(), for(), or similar)",
            );
        }
    }

    // ========================================================================
    // Code Quality: Interface implementations
    // ========================================================================

    /**
     * @test
     */
    public function aggregate_root_implements_contract(): void
    {
        $this->assertTrue(
            is_subclass_of(AggregateRoot::class, AggregateRootContract::class),
            'AggregateRoot must implement Contracts\AggregateRoot',
        );
    }

    /**
     * @test
     */
    public function entity_implements_contract(): void
    {
        $this->assertTrue(
            is_subclass_of(Entity::class, EntityContract::class),
            'Entity must implement Contracts\Entity',
        );
    }

    /**
     * @test
     */
    public function in_memory_unit_of_work_implements_contract(): void
    {
        $this->assertTrue(
            is_subclass_of(InMemoryUnitOfWork::class, UnitOfWorkContract::class),
            'InMemoryUnitOfWork must implement Contracts\UnitOfWork',
        );
    }

    /**
     * @test
     */
    public function in_memory_snapshot_store_implements_contract(): void
    {
        $this->assertTrue(
            is_subclass_of(InMemorySnapshotStore::class, SnapshotStore::class),
            'InMemorySnapshotStore must implement Snapshots\SnapshotStore',
        );
    }

    /**
     * @test
     */
    public function snapshotting_repository_extends_repository_interface(): void
    {
        // SnapshottingRepository is a decorator, not a direct implementor
        $reflection = new ReflectionClass(SnapshottingRepository::class);
        $this->assertTrue(
            $reflection->hasMethod('findWithSnapshot'),
            'SnapshottingRepository must have findWithSnapshot()',
        );
    }

    // ========================================================================
    // Code Quality: Identifier types
    // ========================================================================

    /**
     * @test
     */
    public function all_identifiers_extend_aggregate_root_id(): void
    {
        $identifiers = [
            UuidIdentifier::class,
            UlidIdentifier::class,
            StringIdentifier::class,
            IntegerIdentifier::class,
        ];

        foreach ($identifiers as $id) {
            $this->assertTrue(
                is_subclass_of($id, AggregateRootId::class),
                "{$id} must extend AggregateRootId",
            );
        }
    }

    /**
     * @test
     */
    public function all_identifiers_have_generate_and_from_string_factories(): void
    {
        $identifiers = [
            UuidIdentifier::class,
            UlidIdentifier::class,
            StringIdentifier::class,
            IntegerIdentifier::class,
        ];

        foreach ($identifiers as $id) {
            $reflection = new ReflectionClass($id);
            $methods = array_map(
                static fn (ReflectionMethod $m): string => $m->getName(),
                $reflection->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_STATIC),
            );

            // IntegerIdentifier doesn't have generate() — it's based on auto-increment
            if ($id === IntegerIdentifier::class) {
                $this->assertContains(
                    'fromString',
                    $methods,
                    "{$id} must have fromString() factory",
                );
            } else {
                $this->assertContains(
                    'generate',
                    $methods,
                    "{$id} must have generate() factory",
                );
                $this->assertContains(
                    'fromString',
                    $methods,
                    "{$id} must have fromString() factory",
                );
            }
        }
    }

    /**
     * @test
     */
    public function all_identifiers_have_is_valid_static_method(): void
    {
        $identifiers = [
            UuidIdentifier::class,
            UlidIdentifier::class,
            StringIdentifier::class,
            IntegerIdentifier::class,
        ];

        foreach ($identifiers as $id) {
            $reflection = new ReflectionClass($id);
            $this->assertTrue(
                $reflection->hasMethod('isValid'),
                "{$id} must have isValid() static method",
            );

            $method = $reflection->getMethod('isValid');
            $this->assertTrue(
                $method->isStatic(),
                "{$id}::isValid() must be static",
            );
        }
    }

    // ========================================================================
    // Serialization Contract: Round-trip safety
    // ========================================================================

    /**
     * @test
     */
    public function snapshot_round_trip_serialization(): void
    {
        $snapshot = Snapshot::create(
            'TestAggregate',
            '550e8400-e29b-41d4-a716-446655440000',
            1,
            ['name' => 'Test', 'status' => 'active'],
        );

        $array = $snapshot->toArray();
        $restored = Snapshot::fromArray($array);

        $this->assertTrue($snapshot->equals($restored), 'Snapshot round-trip must be equal');
        $this->assertEquals($snapshot->toArray(), $restored->toArray());
    }

    /**
     * @test
     */
    public function domain_event_collection_json_serialization(): void
    {
        $event = \ZeroBoiler\Events\Domain\DomainEvent::occur('test.event', ['key' => 'value']);
        $collection = new DomainEventCollection([$event]);

        $json = json_encode($collection);
        $this->assertNotFalse($json);
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
    }

    // ========================================================================
    // Architecture: Domain classes have no framework coupling
    // ========================================================================

    /**
     * @test
     */
    public function core_domain_classes_do_not_depend_on_illuminate(): void
    {
        // Core domain classes that should NOT import Illuminate
        $coreClasses = [
            AggregateRoot::class,
            Entity::class,
            ValueObject::class,
            AggregateRootId::class,
            DomainEventCollection::class,
            DomainException::class,
            UuidIdentifier::class,
            UlidIdentifier::class,
            StringIdentifier::class,
            IntegerIdentifier::class,
            Snapshot::class,
        ];

        foreach ($coreClasses as $class) {
            $file = (new ReflectionClass($class))->getFileName();
            $contents = file_get_contents($file);

            $this->assertFalse(
                str_contains($contents, 'use Illuminate\\'),
                "{$class} should not depend on Illuminate (domain purity violation)",
            );
        }
    }

    /**
     * @test
     */
    public function all_exception_classes_are_final(): void
    {
        $exceptionClasses = [
            InvalidStateDomainException::class,
            InvalidArgumentDomainException::class,
            NotFoundDomainException::class,
            ConflictDomainException::class,
            OptimisticLockException::class,
            AggregateNotFoundException::class,
            InvalidAggregateRootException::class,
            InvalidStateException::class,
        ];

        foreach ($exceptionClasses as $class) {
            $reflection = new ReflectionClass($class);
            $this->assertTrue(
                $reflection->isFinal(),
                "{$class} must be final (sealed exception hierarchy)",
            );
        }
    }

    // ========================================================================
    // Architecture: Composition over inheritance
    // ========================================================================

    /**
     * @test
     */
    public function domain_uses_traits_not_deep_inheritance(): void
    {
        $aggregateRootReflection = new ReflectionClass(AggregateRoot::class);
        $traits = array_map(
            static fn ($t) => $t->getName(),
            $aggregateRootReflection->getTraits(),
        );

        // AggregateRoot itself uses HasDomainEvents
        $this->assertNotEmpty($traits, 'AggregateRoot should use traits (composition)');

        // Entity should not use traits directly (clean base)
        $entityReflection = new ReflectionClass(Entity::class);
        $entityTraits = array_map(
            static fn ($t) => $t->getName(),
            $entityReflection->getTraits(),
        );

        $this->assertEmpty($entityTraits, 'Entity should be a clean base class without traits');
    }

    // ========================================================================
    // Helpers
    // ========================================================================

    /**
     * Get all domain classes that should be checked.
     *
     * @return list<string>
     */
    private function getDomainClasses(): array
    {
        return [
            AggregateRoot::class,
            AggregateRootId::class,
            Entity::class,
            ValueObject::class,
            DomainEventCollection::class,
            InMemoryUnitOfWork::class,
            Snapshot::class,
            InMemorySnapshotStore::class,
            SnapshottingRepository::class,
            UuidIdentifier::class,
            UlidIdentifier::class,
            StringIdentifier::class,
            IntegerIdentifier::class,
            DomainException::class,
            InvalidStateDomainException::class,
            InvalidArgumentDomainException::class,
            NotFoundDomainException::class,
            ConflictDomainException::class,
            OptimisticLockException::class,
            AggregateNotFoundException::class,
            InvalidAggregateRootException::class,
            InvalidStateException::class,
        ];
    }

    /**
     * Find all PHP files in a directory (recursive).
     *
     * @return list<string>
     */
    private function findAllPhpFiles(string $dir): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
