<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Tests\Production;

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
use ZeroBoiler\Domain\Identifiers\UuidIdentifier;
use ZeroBoiler\Domain\Identifiers\UlidIdentifier;
use ZeroBoiler\Domain\InMemoryUnitOfWork;
use ZeroBoiler\Domain\Snapshots\InMemorySnapshotStore;
use ZeroBoiler\Domain\Snapshots\Snapshot;
use ZeroBoiler\Domain\Snapshots\SnapshotStore;
use ZeroBoiler\Domain\Snapshots\SnapshottingRepository;
use ZeroBoiler\Domain\ValueObject;
use PHPUnit\Framework\TestCase;

/**
 * Comprehensive structural audit of the domain package.
 *
 * Verifies PHP 8.5 production readiness across all core classes:
 * - strict_types declaration
 * - final/readonly modifiers
 * - return type declarations
 * - typed properties
 * - interface implementations
 * - exception hierarchy
 * - serialization contract (toArray/fromArray/toJson/fromJson)
 *
 * @since 1.73.0
 */
final class DomainPackageStructuralAuditTest extends TestCase
{
    // ── Strict Types Verification ──────────────────────────────────────

    /**
     * @test
     */
    public function all_source_files_declare_strict_types(): void
    {
        $srcDir = dirname(__DIR__, 3) . '/src';
        $files = $this->findPhpFiles($srcDir);
        $violations = [];

        foreach ($files as $file) {
            $content = file_get_contents($file);
            if (! str_contains($content, 'declare(strict_types=1)')) {
                $violations[] = $file;
            }
        }

        $this->assertEmpty(
            $violations,
            'Files missing declare(strict_types=1): ' . implode(', ', $violations),
        );
    }

    // ── Class Modifier Audit ───────────────────────────────────────────

    /**
     * @test
     */
    public function aggregate_root_id_is_final_readonly(): void
    {
        $ref = new ReflectionClass(AggregateRootId::class);

        $this->assertTrue($ref->isFinal(), 'AggregateRootId must be final');
        $this->assertTrue($ref->isReadOnly(), 'AggregateRootId must be readonly');
    }

    /**
     * @test
     */
    public function domain_event_collection_is_final_readonly(): void
    {
        $ref = new ReflectionClass(DomainEventCollection::class);

        $this->assertTrue($ref->isFinal(), 'DomainEventCollection must be final');
        $this->assertTrue($ref->isReadOnly(), 'DomainEventCollection must be readonly');
    }

    /**
     * @test
     */
    public function snapshot_is_final_readonly(): void
    {
        $ref = new ReflectionClass(Snapshot::class);

        $this->assertTrue($ref->isFinal(), 'Snapshot must be final');
        $this->assertTrue($ref->isReadOnly(), 'Snapshot must be readonly');
    }

    /**
     * @test
     */
    public function integer_identifier_is_final_readonly(): void
    {
        $ref = new ReflectionClass(IntegerIdentifier::class);

        $this->assertTrue($ref->isFinal(), 'IntegerIdentifier must be final');
        $this->assertTrue($ref->isReadOnly(), 'IntegerIdentifier must be readonly');
    }

    /**
     * @test
     */
    public function uuid_identifier_is_abstract_readonly(): void
    {
        $ref = new ReflectionClass(UuidIdentifier::class);

        $this->assertTrue($ref->isAbstract(), 'UuidIdentifier must be abstract');
        $this->assertTrue($ref->isReadOnly(), 'UuidIdentifier must be readonly');
    }

    /**
     * @test
     */
    public function ulid_identifier_is_abstract_readonly(): void
    {
        $ref = new ReflectionClass(UlidIdentifier::class);

        $this->assertTrue($ref->isAbstract(), 'UlidIdentifier must be abstract');
        $this->assertTrue($ref->isReadOnly(), 'UlidIdentifier must be readonly');
    }

    // ── Interface Implementation Contracts ──────────────────────────────

    /**
     * @test
     */
    public function aggregate_root_implements_contract(): void
    {
        $ref = new ReflectionClass(AggregateRoot::class);

        $this->assertTrue(
            $ref->implementsInterface(AggregateRootContract::class),
            'AggregateRoot must implement AggregateRootContract',
        );
        $this->assertTrue(
            $ref->implementsInterface(EntityContract::class),
            'AggregateRoot must implement EntityContract',
        );
    }

    /**
     * @test
     */
    public function entity_implements_contract(): void
    {
        $ref = new ReflectionClass(Entity::class);

        $this->assertTrue(
            $ref->implementsInterface(EntityContract::class),
            'Entity must implement EntityContract',
        );
    }

    /**
     * @test
     */
    public function aggregate_root_id_is_stringable(): void
    {
        $ref = new ReflectionClass(AggregateRootId::class);

        $this->assertTrue(
            $ref->implementsInterface(\Stringable::class),
            'AggregateRootId must implement \\Stringable',
        );
        $this->assertTrue(
            $ref->implementsInterface(\JsonSerializable::class),
            'AggregateRootId must implement \\JsonSerializable',
        );
    }

    /**
     * @test
     */
    public function in_memory_unit_of_work_implements_contract(): void
    {
        $ref = new ReflectionClass(InMemoryUnitOfWork::class);

        $this->assertTrue(
            $ref->implementsInterface(UnitOfWorkContract::class),
            'InMemoryUnitOfWork must implement UnitOfWorkContract',
        );
    }

    /**
     * @test
     */
    public function in_memory_snapshot_store_implements_contract(): void
    {
        $ref = new ReflectionClass(InMemorySnapshotStore::class);

        $this->assertTrue(
            $ref->implementsInterface(SnapshotStore::class),
            'InMemorySnapshotStore must implement SnapshotStore',
        );
    }

    // ── Exception Hierarchy ────────────────────────────────────────────

    /**
     * @test
     */
    public function all_domain_exceptions_extend_domain_exception_except_invalid_state(): void
    {
        $domainExceptions = [
            InvalidStateDomainException::class => DomainException::class,
            InvalidArgumentDomainException::class => DomainException::class,
            NotFoundDomainException::class => DomainException::class,
            ConflictDomainException::class => DomainException::class,
            OptimisticLockException::class => DomainException::class,
            AggregateNotFoundException::class => DomainException::class,
            InvalidAggregateRootException::class => DomainException::class,
            // InvalidStateException is standalone (infrastructure-level)
            InvalidStateException::class => \RuntimeException::class,
        ];

        foreach ($domainExceptions as $exceptionClass => $expectedParent) {
            $ref = new ReflectionClass($exceptionClass);
            $this->assertTrue(
                $ref->isFinal(),
                "{$exceptionClass} must be final",
            );
            $this->assertTrue(
                $ref->isSubclassOf($expectedParent),
                "{$exceptionClass} must extend {$expectedParent}",
            );
        }
    }

    /**
     * @test
     */
    public function all_domain_exceptions_have_unique_default_error_codes(): void
    {
        $exceptions = [
            InvalidStateDomainException::because('test'),
            InvalidArgumentDomainException::because('test'),
            NotFoundDomainException::because('test'),
            ConflictDomainException::because('test'),
            OptimisticLockException::for('id', 1, 2),
            AggregateNotFoundException::for('Type', 'id'),
            InvalidAggregateRootException::notAnAggregate(new \stdClass),
        ];

        $codes = array_map(
            fn (DomainException $e): string => $e->errorCode(),
            $exceptions,
        );

        $unique = array_unique($codes);
        $this->assertCount(
            count($codes),
            $unique,
            'All domain exceptions must have unique default error codes. Got: ' . implode(', ', $codes),
        );
    }

    /**
     * @test
     */
    public function all_domain_exceptions_implement_json_serializable(): void
    {
        $exceptionClasses = [
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

        foreach ($exceptionClasses as $class) {
            $ref = new ReflectionClass($class);
            $this->assertTrue(
                $ref->implementsInterface(\JsonSerializable::class),
                "{$class} must implement \\JsonSerializable",
            );
        }
    }

    /**
     * @test
     */
    public function domain_exception_to_error_array_returns_rfc9457_structure(): void
    {
        $exception = InvalidStateDomainException::because('Test error');
        $errorArray = $exception->toErrorArray();

        $this->assertArrayHasKey('title', $errorArray);
        $this->assertArrayHasKey('detail', $errorArray);
        $this->assertArrayHasKey('code', $errorArray);
        $this->assertSame('INVALID_STATE', $errorArray['code']);
        $this->assertSame('Test error', $errorArray['detail']);
    }

    /**
     * @test
     */
    public function custom_error_code_overrides_default(): void
    {
        $exception = InvalidStateDomainException::because('test', code: 'CUSTOM_CODE');
        $this->assertSame('CUSTOM_CODE', $exception->errorCode());
    }

    // ── AggregateRoot Contract Methods ───────────────────────────────────

    /**
     * @test
     */
    public function aggregate_root_contract_has_required_methods(): void
    {
        $ref = new ReflectionClass(AggregateRootContract::class);
        $requiredMethods = [
            'version',
            'incrementVersion',
            'pullDomainEvents',
            'clearDomainEvents',
            'hasUncommittedEvents',
            'peekDomainEvents',
        ];

        foreach ($requiredMethods as $method) {
            $this->assertTrue(
                $ref->hasMethod($method),
                "AggregateRootContract must have method: {$method}",
            );
        }
    }

    /**
     * @test
     */
    public function aggregate_root_contract_methods_have_return_types(): void
    {
        $ref = new ReflectionClass(AggregateRootContract::class);
        $methods = $ref->getMethods(ReflectionMethod::IS_PUBLIC);

        foreach ($methods as $method) {
            $this->assertNotNull(
                $method->getReturnType(),
                "AggregateRootContract::{$method->getName()}() must have a return type",
            );
        }
    }

    // ── Repository Contract Methods ─────────────────────────────────────

    /**
     * @test
     */
    public function repository_contract_has_required_methods(): void
    {
        $ref = new ReflectionClass(RepositoryContract::class);
        $requiredMethods = ['find', 'save', 'delete'];

        foreach ($requiredMethods as $method) {
            $this->assertTrue(
                $ref->hasMethod($method),
                "RepositoryContract must have method: {$method}",
            );
        }
    }

    // ── Serialization Contract Verification ─────────────────────────────

    /**
     * @test
     */
    public function aggregate_root_id_has_full_serialization_contract(): void
    {
        $ref = new ReflectionClass(AggregateRootId::class);
        $requiredMethods = ['toArray', 'fromArray', 'toJson', 'fromJson', 'jsonSerialize'];

        foreach ($requiredMethods as $method) {
            $this->assertTrue(
                $ref->hasMethod($method),
                "AggregateRootId must have method: {$method}",
            );
        }
    }

    /**
     * @test
     */
    public function snapshot_has_full_serialization_contract(): void
    {
        $ref = new ReflectionClass(Snapshot::class);
        $requiredMethods = ['toArray', 'fromArray', 'toJson', 'fromJson', 'jsonSerialize'];

        foreach ($requiredMethods as $method) {
            $this->assertTrue(
                $ref->hasMethod($method),
                "Snapshot must have method: {$method}",
            );
        }
    }

    /**
     * @test
     */
    public function domain_event_collection_has_full_serialization_contract(): void
    {
        $ref = new ReflectionClass(DomainEventCollection::class);
        $requiredMethods = ['toArray', 'fromArray', 'toJson', 'fromJson', 'jsonSerialize'];

        foreach ($requiredMethods as $method) {
            $this->assertTrue(
                $ref->hasMethod($method),
                "DomainEventCollection must have method: {$method}",
            );
        }
    }

    /**
     * @test
     */
    public function all_identifiers_implement_json_serializable(): void
    {
        $identifiers = [
            UuidIdentifier::class,
            UlidIdentifier::class,
            StringIdentifier::class,
            IntegerIdentifier::class,
        ];

        foreach ($identifiers as $class) {
            $ref = new ReflectionClass($class);
            $this->assertTrue(
                $ref->implementsInterface(\JsonSerializable::class),
                "{$class} must implement \\JsonSerializable",
            );
        }
    }

    /**
     * @test
     */
    public function all_identifiers_have_isvalid_static_method(): void
    {
        $identifiers = [
            UuidIdentifier::class,
            UlidIdentifier::class,
            StringIdentifier::class,
            IntegerIdentifier::class,
        ];

        foreach ($identifiers as $class) {
            $this->assertTrue(
                (new ReflectionClass($class))->hasMethod('isValid'),
                "{$class} must have static method isValid()",
            );
        }
    }

    // ── SnapshottingRepository Decorator Pattern ───────────────────────

    /**
     * @test
     */
    public function snapshotting_repository_is_final_readonly(): void
    {
        $ref = new ReflectionClass(SnapshottingRepository::class);

        $this->assertTrue($ref->isFinal(), 'SnapshottingRepository must be final');
        $this->assertTrue($ref->isReadOnly(), 'SnapshottingRepository must be readonly');
    }

    // ── UnitOfWork Contract Methods ────────────────────────────────────

    /**
     * @test
     */
    public function unit_of_work_contract_has_required_methods(): void
    {
        $ref = new ReflectionClass(UnitOfWorkContract::class);
        $requiredMethods = [
            'begin',
            'commit',
            'rollback',
            'run',
            'track',
            'queueEvent',
            'clear',
            'getCommitted',
            'getDeleted',
            'getPendingEvents',
            'markForDeletion',
            'isActive',
            'isTracking',
        ];

        foreach ($requiredMethods as $method) {
            $this->assertTrue(
                $ref->hasMethod($method),
                "UnitOfWorkContract must have method: {$method}",
            );
        }
    }

    /**
     * @test
     */
    public function unit_of_work_contract_methods_have_return_types(): void
    {
        $ref = new ReflectionClass(UnitOfWorkContract::class);
        $methods = $ref->getMethods(ReflectionMethod::IS_PUBLIC);

        foreach ($methods as $method) {
            $this->assertNotNull(
                $method->getReturnType(),
                "UnitOfWorkContract::{$method->getName()}() must have a return type",
            );
        }
    }

    // ── Value Object Base Class ────────────────────────────────────────

    /**
     * @test
     */
    public function value_object_is_abstract(): void
    {
        $ref = new ReflectionClass(ValueObject::class);
        $this->assertTrue($ref->isAbstract(), 'ValueObject must be abstract');
    }

    // ── Helper Methods ──────────────────────────────────────────────────

    /**
     * Recursively find all PHP files in a directory.
     *
     * @return list<string>
     */
    private function findPhpFiles(string $directory): array
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );

        $files = [];
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
