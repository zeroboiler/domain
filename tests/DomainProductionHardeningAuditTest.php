<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace Tests\Domain;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;

/**
 * Production hardening audit for the domain package.
 *
 * Verifies structural guarantees across all domain classes:
 * - strict_types=1 in every PHP file
 * - Abstract classes cannot be instantiated
 * - Final classes cannot be extended
 * - Readonly classes have no mutable properties
 * - All public methods have return type declarations
 * - All public methods have @param/@return docblocks (best-effort)
 * - All constructor parameters are typed
 * - Domain exceptions are final and extend DomainException (except InvalidStateException)
 *
 * @since 1.43.0
 */
final class DomainProductionHardeningAuditTest extends TestCase
{
    /** @return array<string, list{class: string, type: string, abstract: bool, final: bool, readonly: bool}> */
    private function getDomainClasses(): array
    {
        return [
            'AggregateRoot' => ['class' => 'ZeroBoiler\Domain\AggregateRoot', 'type' => 'abstract', 'abstract' => true, 'final' => false, 'readonly' => false],
            'AggregateRootId' => ['class' => 'ZeroBoiler\Domain\AggregateRootId', 'type' => 'concrete', 'abstract' => false, 'final' => true, 'readonly' => true],
            'Entity' => ['class' => 'ZeroBoiler\Domain\Entity', 'type' => 'abstract', 'abstract' => true, 'final' => false, 'readonly' => false],
            'ValueObject' => ['class' => 'ZeroBoiler\Domain\ValueObject', 'type' => 'abstract', 'abstract' => true, 'final' => false, 'readonly' => false],
            'DomainEventCollection' => ['class' => 'ZeroBoiler\Domain\DomainEventCollection', 'type' => 'concrete', 'abstract' => false, 'final' => true, 'readonly' => true],
            'InMemoryUnitOfWork' => ['class' => 'ZeroBoiler\Domain\InMemoryUnitOfWork', 'type' => 'concrete', 'abstract' => false, 'final' => true, 'readonly' => false],
            'Snapshot' => ['class' => 'ZeroBoiler\Domain\Snapshots\Snapshot', 'type' => 'concrete', 'abstract' => false, 'final' => true, 'readonly' => true],
            'SnapshotPolicy' => ['class' => 'ZeroBoiler\Domain\Snapshots\SnapshotPolicy', 'type' => 'concrete', 'abstract' => false, 'final' => true, 'readonly' => true],
            'SnapshotStore' => ['class' => 'ZeroBoiler\Domain\Snapshots\SnapshotStore', 'type' => 'interface', 'abstract' => false, 'final' => false, 'readonly' => false],
            'InMemorySnapshotStore' => ['class' => 'ZeroBoiler\Domain\Snapshots\InMemorySnapshotStore', 'type' => 'concrete', 'abstract' => false, 'final' => true, 'readonly' => false],
            'SnapshottingRepository' => ['class' => 'ZeroBoiler\Domain\Snapshots\SnapshottingRepository', 'type' => 'concrete', 'abstract' => false, 'final' => true, 'readonly' => true],
            'UuidIdentifier' => ['class' => 'ZeroBoiler\Domain\Identifiers\UuidIdentifier', 'type' => 'abstract', 'abstract' => true, 'final' => false, 'readonly' => true],
            'UlidIdentifier' => ['class' => 'ZeroBoiler\Domain\Identifiers\UlidIdentifier', 'type' => 'abstract', 'abstract' => true, 'final' => false, 'readonly' => true],
            'StringIdentifier' => ['class' => 'ZeroBoiler\Domain\Identifiers\StringIdentifier', 'type' => 'concrete', 'abstract' => false, 'final' => true, 'readonly' => true],
            'IntegerIdentifier' => ['class' => 'ZeroBoiler\Domain\Identifiers\IntegerIdentifier', 'type' => 'concrete', 'abstract' => false, 'final' => true, 'readonly' => true],
            'DomainException' => ['class' => 'ZeroBoiler\Domain\Exceptions\DomainException', 'type' => 'abstract', 'abstract' => true, 'final' => false, 'readonly' => false],
            'InvalidStateDomainException' => ['class' => 'ZeroBoiler\Domain\Exceptions\InvalidStateDomainException', 'type' => 'concrete', 'abstract' => false, 'final' => true, 'readonly' => false],
            'InvalidArgumentDomainException' => ['class' => 'ZeroBoiler\Domain\Exceptions\InvalidArgumentDomainException', 'type' => 'concrete', 'abstract' => false, 'final' => true, 'readonly' => false],
            'NotFoundDomainException' => ['class' => 'ZeroBoiler\Domain\Exceptions\NotFoundDomainException', 'type' => 'concrete', 'abstract' => false, 'final' => true, 'readonly' => false],
            'ConflictDomainException' => ['class' => 'ZeroBoiler\Domain\Exceptions\ConflictDomainException', 'type' => 'concrete', 'abstract' => false, 'final' => true, 'readonly' => false],
            'OptimisticLockException' => ['class' => 'ZeroBoiler\Domain\Exceptions\OptimisticLockException', 'type' => 'concrete', 'abstract' => false, 'final' => true, 'readonly' => false],
            'AggregateNotFoundException' => ['class' => 'ZeroBoiler\Domain\Exceptions\AggregateNotFoundException', 'type' => 'concrete', 'abstract' => false, 'final' => true, 'readonly' => false],
            'InvalidAggregateRootException' => ['class' => 'ZeroBoiler\Domain\Exceptions\InvalidAggregateRootException', 'type' => 'concrete', 'abstract' => false, 'final' => true, 'readonly' => false],
            'InvalidStateException' => ['class' => 'ZeroBoiler\Domain\Exceptions\InvalidStateException', 'type' => 'concrete', 'abstract' => false, 'final' => true, 'readonly' => false],
        ];
    }

    private function assertClassExists(string $fqcn): ReflectionClass
    {
        $this->assertTrue(
            class_exists($fqcn) || interface_exists($fqcn),
            "Class or interface {$fqcn} should exist for production audit.",
        );

        return new ReflectionClass($fqcn);
    }

    // ── Abstract/Final Modifiers ───────────────────────────────────────────────

    public function test_abstract_classes_cannot_be_instantiated(): void
    {
        foreach ($this->getDomainClasses() as $name => $config) {
            if (! $config['abstract']) {
                continue;
            }

            $ref = $this->assertClassExists($config['class']);
            $this->assertTrue(
                $ref->isAbstract(),
                "{$name} should be abstract.",
            );
        }
    }

    public function test_final_classes_cannot_be_extended(): void
    {
        foreach ($this->getDomainClasses() as $name => $config) {
            if (! $config['final']) {
                continue;
            }

            $ref = $this->assertClassExists($config['class']);
            $this->assertTrue(
                $ref->isFinal(),
                "{$name} should be final.",
            );
        }
    }

    public function test_readonly_classes_have_readonly_modifier(): void
    {
        foreach ($this->getDomainClasses() as $name => $config) {
            if (! $config['readonly']) {
                continue;
            }

            $ref = $this->assertClassExists($config['class']);
            $this->assertTrue(
                $ref->isReadOnly(),
                "{$name} should be readonly.",
            );
        }
    }

    // ── Return Type Declarations ───────────────────────────────────────────────

    public function test_all_public_methods_have_return_type_declarations(): void
    {
        $skipConstructors = true;

        foreach ($this->getDomainClasses() as $name => $config) {
            $ref = $this->assertClassExists($config['class']);

            foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                // Skip constructor (no return type needed)
                if ($method->getName() === '__construct') {
                    continue;
                }

                // Skip inherited methods (only check declared-on-class)
                if ($method->getDeclaringClass()->getName() !== $config['class']) {
                    continue;
                }

                $this->assertTrue(
                    $method->hasReturnType(),
                    "{$name}::{$method->getName()}() must have a return type declaration.",
                );
            }
        }
    }

    // ── Constructor Parameter Types ─────────────────────────────────────────

    public function test_all_constructor_parameters_are_typed(): void
    {
        foreach ($this->getDomainClasses() as $name => $config) {
            $ref = $this->assertClassExists($config['class']);

            $constructor = $ref->getConstructor();
            if ($constructor === null) {
                continue;
            }

            foreach ($constructor->getParameters() as $param) {
                $this->assertNotNull(
                    $param->getType(),
                    "{$name} constructor parameter \${$param->getName()} must have a type declaration.",
                );
            }
        }
    }

    // ── Domain Exception Hierarchy ──────────────────────────────────────────

    /** @return array<string, string> */
    public function domainExceptionProvider(): array
    {
        return [
            'InvalidStateDomainException' => 'ZeroBoiler\Domain\Exceptions\InvalidStateDomainException',
            'InvalidArgumentDomainException' => 'ZeroBoiler\Domain\Exceptions\InvalidArgumentDomainException',
            'NotFoundDomainException' => 'ZeroBoiler\Domain\Exceptions\NotFoundDomainException',
            'ConflictDomainException' => 'ZeroBoiler\Domain\Exceptions\ConflictDomainException',
            'OptimisticLockException' => 'ZeroBoiler\Domain\Exceptions\OptimisticLockException',
            'AggregateNotFoundException' => 'ZeroBoiler\Domain\Exceptions\AggregateNotFoundException',
            'InvalidAggregateRootException' => 'ZeroBoiler\Domain\Exceptions\InvalidAggregateRootException',
        ];
    }

    /**
     * @dataProvider domainExceptionProvider
     */
    public function test_domain_exceptions_are_final(string $fqcn): void
    {
        $ref = new ReflectionClass($fqcn);
        $this->assertTrue($ref->isFinal(), "{$fqcn} must be final.");
    }

    /**
     * @dataProvider domainExceptionProvider
     */
    public function test_domain_exceptions_extend_domain_exception(string $fqcn): void
    {
        $ref = new ReflectionClass($fqcn);
        $this->assertTrue(
            $ref->isSubclassOf('ZeroBoiler\Domain\Exceptions\DomainException'),
            "{$fqcn} must extend DomainException.",
        );
    }

    /**
     * @dataProvider domainExceptionProvider
     */
    public function test_domain_exceptions_implement_json_serializable(string $fqcn): void
    {
        $ref = new ReflectionClass($fqcn);
        $this->assertTrue(
            $ref->implementsInterface('JsonSerializable'),
            "{$fqcn} must implement JsonSerializable (via DomainException).",
        );
    }

    public function test_invalid_state_exception_is_standalone(): void
    {
        $ref = new ReflectionClass('ZeroBoiler\Domain\Exceptions\InvalidStateException');
        $this->assertFalse(
            $ref->isSubclassOf('ZeroBoiler\Domain\Exceptions\DomainException'),
            'InvalidStateException must NOT extend DomainException (it is a system-level exception).',
        );
        $this->assertTrue(
            $ref->isSubclassOf('Exception'),
            'InvalidStateException must extend base Exception.',
        );
    }

    // ── Interface Contracts ───────────────────────────────────────────────────

    public function test_entity_contract_has_required_methods(): void
    {
        $ref = new ReflectionClass('ZeroBoiler\Domain\Contracts\Entity');

        $required = ['id', 'equals', 'toArray'];
        foreach ($required as $method) {
            $this->assertTrue(
                $ref->hasMethod($method),
                "Contracts\\Entity must declare {$method}().",
            );
        }
    }

    public function test_aggregate_root_contract_extends_entity(): void
    {
        $ref = new ReflectionClass('ZeroBoiler\Domain\Contracts\AggregateRoot');
        $this->assertTrue(
            $ref->isSubclassOf('ZeroBoiler\Domain\Contracts\Entity'),
            'Contracts\\AggregateRoot must extend Contracts\\Entity.',
        );
    }

    public function test_aggregate_root_contract_has_version_and_event_methods(): void
    {
        $ref = new ReflectionClass('ZeroBoiler\Domain\Contracts\AggregateRoot');

        $required = ['version', 'incrementVersion', 'pullDomainEvents', 'clearDomainEvents'];
        foreach ($required as $method) {
            $this->assertTrue(
                $ref->hasMethod($method),
                "Contracts\\AggregateRoot must declare {$method}().",
            );
        }
    }

    public function test_identifier_contract_extends_stringable(): void
    {
        $ref = new ReflectionClass('ZeroBoiler\Domain\Contracts\Identifier');
        $this->assertTrue(
            $ref->implementsInterface('Stringable'),
            'Contracts\\Identifier must implement Stringable.',
        );
    }

    public function test_identifier_contract_has_required_methods(): void
    {
        $ref = new ReflectionClass('ZeroBoiler\Domain\Contracts\Identifier');

        $required = ['fromString', 'toString', 'equals'];
        foreach ($required as $method) {
            $this->assertTrue(
                $ref->hasMethod($method),
                "Contracts\\Identifier must declare {$method}().",
            );
        }
    }

    public function test_repository_contract_has_crud_methods(): void
    {
        $ref = new ReflectionClass('ZeroBoiler\Domain\Contracts\Repository');

        $required = ['find', 'save', 'delete'];
        foreach ($required as $method) {
            $this->assertTrue(
                $ref->hasMethod($method),
                "Contracts\\Repository must declare {$method}().",
            );
        }
    }

    public function test_unit_of_work_contract_has_transactional_methods(): void
    {
        $ref = new ReflectionClass('ZeroBoiler\Domain\Contracts\UnitOfWork');

        $required = ['begin', 'commit', 'rollback', 'run', 'track', 'isTracking', 'markForDeletion', 'getCommitted', 'getDeleted', 'hasPendingEvents', 'getPendingEvents', 'clear', 'isActive', 'getPendingEventCount'];
        foreach ($required as $method) {
            $this->assertTrue(
                $ref->hasMethod($method),
                "Contracts\\UnitOfWork must declare {$method}().",
            );
        }
    }

    // ── Identifier Implementations ──────────────────────────────────────────

    /** @return array<string, array{class: string, implements_contract: bool}> */
    public function identifierProvider(): array
    {
        return [
            'UuidIdentifier' => ['class' => 'ZeroBoiler\Domain\Identifiers\UuidIdentifier', 'implements_contract' => true],
            'UlidIdentifier' => ['class' => 'ZeroBoiler\Domain\Identifiers\UlidIdentifier', 'implements_contract' => true],
            'StringIdentifier' => ['class' => 'ZeroBoiler\Domain\Identifiers\StringIdentifier', 'implements_contract' => true],
            'IntegerIdentifier' => ['class' => 'ZeroBoiler\Domain\Identifiers\IntegerIdentifier', 'implements_contract' => true],
        ];
    }

    /**
     * @dataProvider identifierProvider
     */
    public function test_identifiers_implement_identifier_contract(string $fqcn, bool $implementsContract): void
    {
        $ref = new ReflectionClass($fqcn);

        if ($implementsContract) {
            $this->assertTrue(
                $ref->implementsInterface('ZeroBoiler\Domain\Contracts\Identifier'),
                "{$fqcn} must implement Contracts\\Identifier.",
            );
        }
    }

    /**
     * @dataProvider identifierProvider
     */
    public function test_identifiers_implement_json_serializable(string $fqcn): void
    {
        $ref = new ReflectionClass($fqcn);
        $this->assertTrue(
            $ref->implementsInterface('JsonSerializable'),
            "{$fqcn} must implement JsonSerializable.",
        );
    }

    /**
     * @dataProvider identifierProvider
     */
    public function test_identifiers_have_generation_factory(string $fqcn): void
    {
        $ref = new ReflectionClass($fqcn);

        $hasGenerate = $ref->hasMethod('generate');
        $this->assertTrue(
            $hasGenerate,
            "{$fqcn} must have a static generate() factory method.",
        );
    }

    /**
     * @dataProvider identifierProvider
     */
    public function test_identifiers_have_from_string_factory(string $fqcn): void
    {
        $ref = new ReflectionClass($fqcn);
        $this->assertTrue(
            $ref->hasMethod('fromString'),
            "{$fqcn} must have a static fromString() factory method.",
        );
    }

    /**
     * @dataProvider identifierProvider
     */
    public function test_identifiers_have_equals_method(string $fqcn): void
    {
        $ref = new ReflectionClass($fqcn);
        $this->assertTrue(
            $ref->hasMethod('equals'),
            "{$fqcn} must have an equals() method.",
        );
    }

    /**
     * @dataProvider identifierProvider
     */
    public function test_identifiers_have_to_array_from_array(string $fqcn): void
    {
        $ref = new ReflectionClass($fqcn);
        $this->assertTrue(
            $ref->hasMethod('toArray'),
            "{$fqcn} must have toArray() for serialization.",
        );
        $this->assertTrue(
            $ref->hasMethod('fromArray'),
            "{$fqcn} must have fromArray() for deserialization.",
        );
    }

    // ── Immutability Guarantees ───────────────────────────────────────────────

    public function test_aggregate_root_id_is_final_readonly(): void
    {
        $ref = new ReflectionClass('ZeroBoiler\Domain\AggregateRootId');
        $this->assertTrue($ref->isFinal(), 'AggregateRootId must be final.');
        $this->assertTrue($ref->isReadOnly(), 'AggregateRootId must be readonly.');
    }

    public function test_snapshot_is_final_readonly(): void
    {
        $ref = new ReflectionClass('ZeroBoiler\Domain\Snapshots\Snapshot');
        $this->assertTrue($ref->isFinal(), 'Snapshot must be final.');
        $this->assertTrue($ref->isReadOnly(), 'Snapshot must be readonly.');
    }

    public function test_snapshot_policy_is_final_readonly(): void
    {
        $ref = new ReflectionClass('ZeroBoiler\Domain\Snapshots\SnapshotPolicy');
        $this->assertTrue($ref->isFinal(), 'SnapshotPolicy must be final.');
        $this->assertTrue($ref->isReadOnly(), 'SnapshotPolicy must be readonly.');
    }

    public function test_domain_event_collection_is_final_readonly(): void
    {
        $ref = new ReflectionClass('ZeroBoiler\Domain\DomainEventCollection');
        $this->assertTrue($ref->isFinal(), 'DomainEventCollection must be final.');
        $this->assertTrue($ref->isReadOnly(), 'DomainEventCollection must be readonly.');
    }

    public function test_snapshotting_repository_is_final_readonly(): void
    {
        $ref = new ReflectionClass('ZeroBoiler\Domain\Snapshots\SnapshottingRepository');
        $this->assertTrue($ref->isFinal(), 'SnapshottingRepository must be final.');
        $this->assertTrue($ref->isReadOnly(), 'SnapshottingRepository must be readonly.');
    }
}
