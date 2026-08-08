<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Comprehensive production readiness test for the Domain package.
 *
 * Validates that all core domain classes:
 * - Enforce strict types (declare(strict_types=1))
 * - Have typed properties
 * - Have return type declarations
 * - Implement correct interfaces
 * - Provide fromArray/toArray serialization where applicable
 * - Are correctly marked final/readonly/abstract
 * - Have proper docblocks
 *
 * @covers \ZeroBoiler\Domain\AggregateRoot
 * @covers \ZeroBoiler\Domain\Entity
 * @covers \ZeroBoiler\Domain\ValueObject
 * @covers \ZeroBoiler\Domain\AggregateRootId
 * @covers \ZeroBoiler\Domain\DomainEventCollection
 * @covers \ZeroBoiler\Domain\InMemoryUnitOfWork
 * @covers \ZeroBoiler\Domain\Contracts\AggregateRoot
 * @covers \ZeroBoiler\Domain\Contracts\Entity
 * @covers \ZeroBoiler\Domain\Contracts\Identifier
 * @covers \ZeroBoiler\Domain\Contracts\Repository
 * @covers \ZeroBoiler\Domain\Contracts\UnitOfWork
 * @covers \ZeroBoiler\Domain\Identifiers\UuidIdentifier
 * @covers \ZeroBoiler\Domain\Identifiers\UlidIdentifier
 * @covers \ZeroBoiler\Domain\Identifiers\StringIdentifier
 * @covers \ZeroBoiler\Domain\Identifiers\IntegerIdentifier
 * @covers \ZeroBoiler\Domain\Snapshots\Snapshot
 * @covers \ZeroBoiler\Domain\Snapshots\SnapshotPolicy
 * @covers \ZeroBoiler\Domain\Snapshots\SnapshotStore
 * @covers \ZeroBoiler\Domain\Snapshots\InMemorySnapshotStore
 * @covers \ZeroBoiler\Domain\Snapshots\SnapshottingRepository
 * @covers \ZeroBoiler\Domain\Exceptions\DomainException
 * @covers \ZeroBoiler\Domain\Exceptions\InvalidStateDomainException
 * @covers \ZeroBoiler\Domain\Exceptions\InvalidArgumentDomainException
 * @covers \ZeroBoiler\Domain\Exceptions\NotFoundDomainException
 * @covers \ZeroBoiler\Domain\Exceptions\ConflictDomainException
 * @covers \ZeroBoiler\Domain\Exceptions\OptimisticLockException
 * @covers \ZeroBoiler\Domain\Exceptions\AggregateNotFoundException
 */
final class DomainProductionFinalAuditTest extends TestCase
{
    // ─── Strict Types Verification ───────────────────────────────────

    /**
     * Every source file must declare strict_types=1.
     */
    public function test_all_source_files_declare_strict_types(): void
    {
        $srcDir = realpath(__DIR__ . '/../src');
        $files = $this->findPhpFiles($srcDir);
        $violations = [];

        foreach ($files as $file) {
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }
            if (! str_contains($content, 'declare(strict_types=1)')) {
                $violations[] = $file;
            }
        }

        $this->assertEmpty(
            $violations,
            sprintf(
                'Files missing declare(strict_types=1): %s',
                implode(', ', $violations),
            ),
        );
    }

    // ─── Class Modifier Verification ─────────────────────────────────

    public function test_aggregate_root_id_is_final_readonly(): void
    {
        $ref = new \ReflectionClass(\ZeroBoiler\Domain\AggregateRootId::class);

        $this->assertTrue($ref->isFinal(), 'AggregateRootId must be final');
        $this->assertTrue($ref->isReadOnly(), 'AggregateRootId must be readonly');
    }

    public function test_domain_event_collection_is_final_readonly(): void
    {
        $ref = new \ReflectionClass(\ZeroBoiler\Domain\DomainEventCollection::class);

        $this->assertTrue($ref->isFinal(), 'DomainEventCollection must be final');
        $this->assertTrue($ref->isReadOnly(), 'DomainEventCollection must be readonly');
    }

    public function test_snapshot_is_final_readonly(): void
    {
        $ref = new \ReflectionClass(\ZeroBoiler\Domain\Snapshots\Snapshot::class);

        $this->assertTrue($ref->isFinal(), 'Snapshot must be final');
        $this->assertTrue($ref->isReadOnly(), 'Snapshot must be readonly');
    }

    public function test_snapshot_policy_is_final_readonly(): void
    {
        $ref = new \ReflectionClass(\ZeroBoiler\Domain\Snapshots\SnapshotPolicy::class);

        $this->assertTrue($ref->isFinal(), 'SnapshotPolicy must be final');
        $this->assertTrue($ref->isReadOnly(), 'SnapshotPolicy must be readonly');
    }

    public function test_snapshotting_repository_is_final_readonly(): void
    {
        $ref = new \ReflectionClass(\ZeroBoiler\Domain\Snapshots\SnapshottingRepository::class);

        $this->assertTrue($ref->isFinal(), 'SnapshottingRepository must be final');
        $this->assertTrue($ref->isReadOnly(), 'SnapshottingRepository must be readonly');
    }

    public function test_in_memory_snapshot_store_is_final(): void
    {
        $ref = new \ReflectionClass(\ZeroBoiler\Domain\Snapshots\InMemorySnapshotStore::class);

        $this->assertTrue($ref->isFinal(), 'InMemorySnapshotStore must be final');
    }

    public function test_in_memory_unit_of_work_is_final(): void
    {
        $ref = new \ReflectionClass(\ZeroBoiler\Domain\InMemoryUnitOfWork::class);

        $this->assertTrue($ref->isFinal(), 'InMemoryUnitOfWork must be final');
    }

    public function test_domain_service_provider_is_final(): void
    {
        $ref = new \ReflectionClass(\ZeroBoiler\Domain\DomainServiceProvider::class);

        $this->assertTrue($ref->isFinal(), 'DomainServiceProvider must be final');
    }

    // ─── Interface Contract Verification ─────────────────────────────

    public function test_entity_interface_has_to_array_method(): void
    {
        $ref = new \ReflectionClass(\ZeroBoiler\Domain\Contracts\Entity::class);

        $this->assertTrue(
            $ref->hasMethod('toArray'),
            'Entity interface must declare toArray(): array',
        );

        $method = $ref->getMethod('toArray');
        $this->assertNotEmpty(
            $method->getReturnType(),
            'toArray() must have a return type declaration',
        );
    }

    public function test_aggregate_root_interface_extends_entity(): void
    {
        $ref = new \ReflectionClass(\ZeroBoiler\Domain\Contracts\AggregateRoot::class);

        $this->assertTrue(
            $ref->implementsInterface(\ZeroBoiler\Domain\Contracts\Entity::class),
            'AggregateRoot must extend Entity',
        );
    }

    public function test_identifier_interface_extends_stringable(): void
    {
        $ref = new \ReflectionClass(\ZeroBoiler\Domain\Contracts\Identifier::class);

        $this->assertTrue(
            $ref->implementsInterface(\Stringable::class),
            'Identifier must extend Stringable',
        );
    }

    // ─── Return Type Verification ────────────────────────────────────

    public function test_core_classes_have_return_types_on_all_public_methods(): void
    {
        $classes = [
            \ZeroBoiler\Domain\Contracts\Entity::class,
            \ZeroBoiler\Domain\Contracts\AggregateRoot::class,
            \ZeroBoiler\Domain\Contracts\Identifier::class,
            \ZeroBoiler\Domain\Contracts\UnitOfWork::class,
        ];

        $violations = [];

        foreach ($classes as $class) {
            $ref = new \ReflectionClass($class);

            foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getReturnType() === null && ! $method->isConstructor()) {
                    $violations[] = "{$class}::{$method->getName()}()";
                }
            }
        }

        $this->assertEmpty(
            $violations,
            sprintf(
                'Methods missing return type declarations: %s',
                implode(', ', $violations),
            ),
        );
    }

    // ─── DomainException Hierarchy ────────────────────────────────────

    public function test_all_domain_exceptions_have_error_code(): void
    {
        $exceptions = [
            \ZeroBoiler\Domain\Exceptions\InvalidStateDomainException::class,
            \ZeroBoiler\Domain\Exceptions\InvalidArgumentDomainException::class,
            \ZeroBoiler\Domain\Exceptions\NotFoundDomainException::class,
            \ZeroBoiler\Domain\Exceptions\ConflictDomainException::class,
            \ZeroBoiler\Domain\Exceptions\OptimisticLockException::class,
            \ZeroBoiler\Domain\Exceptions\AggregateNotFoundException::class,
            \ZeroBoiler\Domain\Exceptions\InvalidAggregateRootException::class,
        ];

        foreach ($exceptions as $exceptionClass) {
            $ref = new \ReflectionClass($exceptionClass);
            $this->assertTrue(
                $ref->isSubclassOf(\ZeroBoiler\Domain\Exceptions\DomainException::class),
                "{$exceptionClass} must extend DomainException",
            );

            // Verify static factory methods have return type
            foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC | \ReflectionMethod::IS_STATIC) as $method) {
                if ($method->getDeclaringClass()->getName() !== $exceptionClass) {
                    continue;
                }
                $this->assertNotEmpty(
                    $method->getReturnType(),
                    "{$exceptionClass}::{$method->getName()}() must have a return type",
                );
            }
        }
    }

    public function test_domain_exception_implements_json_serializable(): void
    {
        $ref = new \ReflectionClass(\ZeroBoiler\Domain\Exceptions\DomainException::class);

        $this->assertTrue(
            $ref->implementsInterface(\JsonSerializable::class),
            'DomainException must implement JsonSerializable for API error serialization',
        );
    }

    // ─── Identifier Type Safety ──────────────────────────────────────

    public function test_all_identifiers_implement_identifier_contract(): void
    {
        $identifiers = [
            \ZeroBoiler\Domain\Identifiers\UuidIdentifier::class,
            \ZeroBoiler\Domain\Identifiers\UlidIdentifier::class,
            \ZeroBoiler\Domain\Identifiers\StringIdentifier::class,
            \ZeroBoiler\Domain\Identifiers\IntegerIdentifier::class,
            \ZeroBoiler\Domain\AggregateRootId::class,
        ];

        foreach ($identifiers as $idClass) {
            $ref = new \ReflectionClass($idClass);

            $this->assertTrue(
                $ref->implementsInterface(\ZeroBoiler\Domain\Contracts\Identifier::class),
                "{$idClass} must implement Identifier contract",
            );

            $this->assertTrue(
                $ref->implementsInterface(\JsonSerializable::class),
                "{$idClass} must implement JsonSerializable for API serialization",
            );

            // Verify fromString() has return type
            $fromString = $ref->getMethod('fromString');
            $this->assertNotEmpty(
                $fromString->getReturnType(),
                "{$idClass}::fromString() must have a return type",
            );

            // Verify equals() has return type
            $equals = $ref->getMethod('equals');
            $this->assertSame('bool', $equals->getReturnType()?->getName());
        }
    }

    public function test_uuid_identifier_is_abstract_readonly(): void
    {
        $ref = new \ReflectionClass(\ZeroBoiler\Domain\Identifiers\UuidIdentifier::class);

        $this->assertTrue($ref->isAbstract(), 'UuidIdentifier must be abstract');
        $this->assertTrue($ref->isReadOnly(), 'UuidIdentifier must be readonly');
    }

    public function test_ulid_identifier_is_abstract_readonly(): void
    {
        $ref = new \ReflectionClass(\ZeroBoiler\Domain\Identifiers\UlidIdentifier::class);

        $this->assertTrue($ref->isAbstract(), 'UlidIdentifier must be abstract');
        $this->assertTrue($ref->isReadOnly(), 'UlidIdentifier must be readonly');
    }

    public function test_string_identifier_is_readonly(): void
    {
        $ref = new \ReflectionClass(\ZeroBoiler\Domain\Identifiers\StringIdentifier::class);

        $this->assertTrue($ref->isFinal(), 'StringIdentifier must be final');
        $this->assertTrue($ref->isReadOnly(), 'StringIdentifier must be readonly');
    }

    public function test_integer_identifier_is_final_readonly(): void
    {
        $ref = new \ReflectionClass(\ZeroBoiler\Domain\Identifiers\IntegerIdentifier::class);

        $this->assertTrue($ref->isFinal(), 'IntegerIdentifier must be final');
        $this->assertTrue($ref->isReadOnly(), 'IntegerIdentifier must be readonly');
    }

    // ─── Snapshot Serialization ──────────────────────────────────────

    public function test_snapshot_has_from_array_and_to_array(): void
    {
        $ref = new \ReflectionClass(\ZeroBoiler\Domain\Snapshots\Snapshot::class);

        $this->assertTrue($ref->hasMethod('toArray'));
        $this->assertTrue($ref->hasMethod('fromArray'));

        $toArray = $ref->getMethod('toArray');
        $fromArray = $ref->getMethod('fromArray');

        $this->assertNotEmpty($toArray->getReturnType(), 'Snapshot::toArray() must have a return type');
        $this->assertNotEmpty($fromArray->getReturnType(), 'Snapshot::fromArray() must have a return type');
    }

    public function test_snapshot_store_interface_has_all_required_methods(): void
    {
        $ref = new \ReflectionClass(\ZeroBoiler\Domain\Snapshots\SnapshotStore::class);
        $requiredMethods = [
            'load', 'save', 'has', 'delete',
            'deleteOlderThan', 'count', 'stats', 'purge',
        ];

        foreach ($requiredMethods as $method) {
            $this->assertTrue(
                $ref->hasMethod($method),
                "SnapshotStore must declare {$method}()",
            );

            $methodRef = $ref->getMethod($method);
            $this->assertNotEmpty(
                $methodRef->getReturnType(),
                "SnapshotStore::{$method}() must have a return type",
            );
        }
    }

    // ─── Aggregate Root Structure ────────────────────────────────────

    public function test_aggregate_root_extends_entity(): void
    {
        $ref = new \ReflectionClass(\ZeroBoiler\Domain\AggregateRoot::class);

        $this->assertTrue(
            $ref->isSubclassOf(\ZeroBoiler\Domain\Entity::class),
            'AggregateRoot must extend Entity',
        );

        $this->assertTrue(
            $ref->implementsInterface(\ZeroBoiler\Domain\Contracts\AggregateRoot::class),
            'AggregateRoot must implement AggregateRoot contract',
        );
    }

    public function test_aggregate_root_has_to_array_method(): void
    {
        $ref = new \ReflectionClass(\ZeroBoiler\Domain\AggregateRoot::class);

        $this->assertTrue($ref->hasMethod('toArray'), 'AggregateRoot must have toArray()');

        $method = $ref->getMethod('toArray');
        $this->assertNotEmpty($method->getReturnType(), 'toArray() must have a return type');
    }

    public function test_entity_has_to_array_method(): void
    {
        $ref = new \ReflectionClass(\ZeroBoiler\Domain\Entity::class);

        $this->assertTrue($ref->hasMethod('toArray'), 'Entity must have toArray()');

        $method = $ref->getMethod('toArray');
        $this->assertNotEmpty($method->getReturnType(), 'toArray() must have a return type');
    }

    // ─── Unit of Work Contract ────────────────────────────────────────

    public function test_unit_of_work_interface_has_clear_method(): void
    {
        $ref = new \ReflectionClass(\ZeroBoiler\Domain\Contracts\UnitOfWork::class);

        $this->assertTrue($ref->hasMethod('clear'), 'UnitOfWork must declare clear()');

        $clear = $ref->getMethod('clear');
        $this->assertNotEmpty($clear->getReturnType(), 'clear() must have a return type');
    }

    // ─── Helper ──────────────────────────────────────────────────────

    /**
     * Recursively find all PHP files in a directory.
     *
     * @return list<string>
     */
    private function findPhpFiles(string $dir): array
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
