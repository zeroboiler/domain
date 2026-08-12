<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Type safety contract test for the Domain package.
 *
 * Validates that all core domain classes enforce PHP 8.5 type safety:
 * - Strict types declaration
 * - Constructor parameter types
 * - Return type declarations on public methods
 * - Property types (readonly, typed)
 * - Interface contract compliance
 *
 * These tests use reflection to enforce compile-time-like guarantees
 * that PHPStan level 9 would catch, without requiring PHPStan.
 *
 * @covers \ZeroBoiler\Domain\AggregateRoot
 * @covers \ZeroBoiler\Domain\Entity
 * @covers \ZeroBoiler\Domain\ValueObject
 * @covers \ZeroBoiler\Domain\AggregateRootId
 * @covers \ZeroBoiler\Domain\DomainEventCollection
 * @covers \ZeroBoiler\Domain\InMemoryUnitOfWork
 * @covers \ZeroBoiler\Domain\Identifiers\UuidIdentifier
 * @covers \ZeroBoiler\Domain\Identifiers\UlidIdentifier
 * @covers \ZeroBoiler\Domain\Identifiers\StringIdentifier
 * @covers \ZeroBoiler\Domain\Identifiers\IntegerIdentifier
 * @covers \ZeroBoiler\Domain\Identifiers\Identifier
 * @covers \ZeroBoiler\Domain\Snapshots\Snapshot
 * @covers \ZeroBoiler\Domain\Snapshots\SnapshotPolicy
 * @covers \ZeroBoiler\Domain\Exceptions\DomainException
 */
final class DomainTypeSafetyContractTest extends TestCase
{
    // ─── Strict Types Declaration ──────────────────────────────────

    /**
     * Every source file must declare strict_types=1.
     *
     * @test
     */
    public function test_all_core_files_declare_strict_types(): void
    {
        $files = $this->getCoreSourceFiles();
        $violations = [];

        foreach ($files as $file) {
            $contents = file_get_contents($file);
            if ($contents === false || ! str_contains($contents, 'declare(strict_types=1)')) {
                $violations[] = $file;
            }
        }

        $this->assertEmpty(
            $violations,
            'Files missing declare(strict_types=1): ' . implode(', ', $violations),
        );
    }

    // ─── Constructor Type Safety ────────────────────────────────

    /**
     * AggregateRootId constructor must have typed parameters.
     *
     * @test
     */
    public function test_aggregate_root_id_has_typed_constructor(): void
    {
        $ref = new \ReflectionClass(\ZeroBoiler\Domain\AggregateRootId::class);
        $ctor = $ref->getConstructor();
        $this->assertNotNull($ctor);

        foreach ($ctor->getParameters() as $param) {
            $this->assertNotNull(
                $param->getType(),
                "AggregateRootId::\${$param->getName()} must have a type declaration.",
            );
        }
    }

    /**
     * DomainEventCollection constructor must have typed parameters.
     *
     * @test
     */
    public function test_domain_event_collection_has_typed_constructor(): void
    {
        $ref = new \ReflectionClass(\ZeroBoiler\Domain\DomainEventCollection::class);
        $ctor = $ref->getConstructor();
        $this->assertNotNull($ctor);

        foreach ($ctor->getParameters() as $param) {
            $this->assertNotNull(
                $param->getType(),
                "DomainEventCollection::\${$param->getName()} must have a type declaration.",
            );
        }
    }

    /**
     * Snapshot constructor must have typed parameters.
     *
     * @test
     */
    public function test_snapshot_has_typed_constructor(): void
    {
        $ref = new \ReflectionClass(\ZeroBoiler\Domain\Snapshots\Snapshot::class);
        $ctor = $ref->getConstructor();
        $this->assertNotNull($ctor);

        $params = $ctor->getParameters();
        $this->assertCount(5, $params);

        foreach ($params as $param) {
            $this->assertNotNull(
                $param->getType(),
                "Snapshot::\${$param->getName()} must have a type declaration.",
            );
        }
    }

    /**
     * SnapshotPolicy attribute constructor must have typed parameters.
     *
     * @test
     */
    public function test_snapshot_policy_has_typed_constructor(): void
    {
        $ref = new \ReflectionClass(\ZeroBoiler\Domain\Snapshots\SnapshotPolicy::class);
        $ctor = $ref->getConstructor();
        $this->assertNotNull($ctor);

        $params = $ctor->getParameters();
        $this->assertCount(1, $params);

        $every = $params[0];
        $this->assertNotNull($every->getType());
        $this->assertSame('int', (string) $every->getType());
        $this->assertSame(50, $every->getDefaultValue());
    }

    // ─── Return Type Declarations ───────────────────────────────

    /**
     * All public methods on AggregateRootId must have return types.
     *
     * @test
     */
    public function test_aggregate_root_id_methods_have_return_types(): void
    {
        $ref = new \ReflectionClass(\ZeroBoiler\Domain\AggregateRootId::class);
        $violations = [];

        foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== \ZeroBoiler\Domain\AggregateRootId::class) {
                continue;
            }
            if ($method->getName() === '__construct') {
                continue;
            }
            if ($method->hasReturnType() === false) {
                $violations[] = $method->getName() . '()';
            }
        }

        $this->assertEmpty(
            $violations,
            'AggregateRootId methods missing return types: ' . implode(', ', $violations),
        );
    }

    /**
     * All public methods on DomainEventCollection must have return types.
     *
     * @test
     */
    public function test_domain_event_collection_methods_have_return_types(): void
    {
        $ref = new \ReflectionClass(\ZeroBoiler\Domain\DomainEventCollection::class);
        $violations = [];

        foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== \ZeroBoiler\Domain\DomainEventCollection::class) {
                continue;
            }
            if ($method->getName() === '__construct') {
                continue;
            }
            if ($method->hasReturnType() === false) {
                $violations[] = $method->getName() . '()';
            }
        }

        $this->assertEmpty(
            $violations,
            'DomainEventCollection methods missing return types: ' . implode(', ', $violations),
        );
    }

    /**
     * All public methods on Snapshot must have return types.
     *
     * @test
     */
    public function test_snapshot_methods_have_return_types(): void
    {
        $ref = new \ReflectionClass(\ZeroBoiler\Domain\Snapshots\Snapshot::class);
        $violations = [];

        foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== \ZeroBoiler\Domain\Snapshots\Snapshot::class) {
                continue;
            }
            if ($method->getName() === '__construct') {
                continue;
            }
            if ($method->hasReturnType() === false) {
                $violations[] = $method->getName() . '()';
            }
        }

        $this->assertEmpty(
            $violations,
            'Snapshot methods missing return types: ' . implode(', ', $violations),
        );
    }

    // ─── Immutability Verification ─────────────────────────────

    /**
     * AggregateRootId must be final readonly.
     *
     * @test
     */
    public function test_aggregate_root_id_is_final_readonly(): void
    {
        $ref = new \ReflectionClass(\ZeroBoiler\Domain\AggregateRootId::class);
        $this->assertTrue($ref->isFinal(), 'AggregateRootId must be final.');
        $this->assertTrue($ref->isReadOnly(), 'AggregateRootId must be readonly.');
    }

    /**
     * DomainEventCollection must be final readonly.
     *
     * @test
     */
    public function test_domain_event_collection_is_final_readonly(): void
    {
        $ref = new \ReflectionClass(\ZeroBoiler\Domain\DomainEventCollection::class);
        $this->assertTrue($ref->isFinal(), 'DomainEventCollection must be final.');
        $this->assertTrue($ref->isReadOnly(), 'DomainEventCollection must be readonly.');
    }

    /**
     * Snapshot must be final readonly.
     *
     * @test
     */
    public function test_snapshot_is_final_readonly(): void
    {
        $ref = new \ReflectionClass(\ZeroBoiler\Domain\Snapshots\Snapshot::class);
        $this->assertTrue($ref->isFinal(), 'Snapshot must be final.');
        $this->assertTrue($ref->isReadOnly(), 'Snapshot must be readonly.');
    }

    /**
     * SnapshotPolicy must be final readonly.
     *
     * @test
     */
    public function test_snapshot_policy_is_final_readonly(): void
    {
        $ref = new \ReflectionClass(\ZeroBoiler\Domain\Snapshots\SnapshotPolicy::class);
        $this->assertTrue($ref->isFinal(), 'SnapshotPolicy must be final.');
        $this->assertTrue($ref->isReadOnly(), 'SnapshotPolicy must be readonly.');
    }

    /**
     * InMemoryUnitOfWork must be final (mutable — tracks transaction state).
     *
     * @test
     */
    public function test_in_memory_unit_of_work_is_final(): void
    {
        $ref = new \ReflectionClass(\ZeroBoiler\Domain\InMemoryUnitOfWork::class);
        $this->assertTrue($ref->isFinal(), 'InMemoryUnitOfWork must be final.');
    }

    /**
     * All identifier properties must be readonly.
     *
     * @test
     */
    public function test_all_identifier_value_properties_are_readonly(): void
    {
        $identifiers = [
            \ZeroBoiler\Domain\Identifiers\UuidIdentifier::class,
            \ZeroBoiler\Domain\Identifiers\UlidIdentifier::class,
            \ZeroBoiler\Domain\Identifiers\StringIdentifier::class,
            \ZeroBoiler\Domain\Identifiers\IntegerIdentifier::class,
        ];

        foreach ($identifiers as $class) {
            $ref = new \ReflectionClass($class);
            $prop = $ref->getProperty('value');
            $this->assertTrue(
                $prop->isReadOnly(),
                "{$class}::\$value must be readonly.",
            );
        }
    }

    // ─── Interface Contract Compliance ──────────────────────────

    /**
     * All identifiers must implement the Identifier contract.
     *
     * @test
     */
    public function test_all_identifiers_implement_identifier_contract(): void
    {
        $identifiers = [
            \ZeroBoiler\Domain\Identifiers\UuidIdentifier::class,
            \ZeroBoiler\Domain\Identifiers\UlidIdentifier::class,
            \ZeroBoiler\Domain\Identifiers\StringIdentifier::class,
            \ZeroBoiler\Domain\Identifiers\IntegerIdentifier::class,
            \ZeroBoiler\Domain\Identifiers\Identifier::class,
        ];

        $contract = \ZeroBoiler\Domain\Contracts\Identifier::class;

        foreach ($identifiers as $class) {
            $this->assertTrue(
                is_subclass_of($class, $contract),
                "{$class} must implement {$contract}.",
            );
        }
    }

    /**
     * All identifiers must implement Stringable.
     *
     * @test
     */
    public function test_all_identifiers_are_stringable(): void
    {
        $identifiers = [
            \ZeroBoiler\Domain\Identifiers\UuidIdentifier::class,
            \ZeroBoiler\Domain\Identifiers\UlidIdentifier::class,
            \ZeroBoiler\Domain\Identifiers\StringIdentifier::class,
            \ZeroBoiler\Domain\Identifiers\IntegerIdentifier::class,
        ];

        foreach ($identifiers as $class) {
            $this->assertTrue(
                is_subclass_of($class, \Stringable::class),
                "{$class} must be \\Stringable.",
            );
        }
    }

    /**
     * All identifiers must implement JsonSerializable.
     *
     * @test
     */
    public function test_all_identifiers_implement_json_serializable(): void
    {
        $identifiers = [
            \ZeroBoiler\Domain\Identifiers\UuidIdentifier::class,
            \ZeroBoiler\Domain\Identifiers\UlidIdentifier::class,
            \ZeroBoiler\Domain\Identifiers\StringIdentifier::class,
            \ZeroBoiler\Domain\Identifiers\IntegerIdentifier::class,
        ];

        foreach ($identifiers as $class) {
            $ref = new \ReflectionClass($class);
            $this->assertTrue(
                $ref->implementsInterface(\JsonSerializable::class),
                "{$class} must implement \\JsonSerializable.",
            );
        }
    }

    /**
     * DomainException must implement JsonSerializable.
     *
     * @test
     */
    public function test_domain_exception_implements_json_serializable(): void
    {
        $ref = new \ReflectionClass(\ZeroBoiler\Domain\Exceptions\DomainException::class);
        $this->assertTrue(
            $ref->implementsInterface(\JsonSerializable::class),
            'DomainException must implement \\JsonSerializable.',
        );
    }

    /**
     * All concrete domain exceptions must extend DomainException.
     *
     * @test
     */
    public function test_all_concrete_exceptions_extend_domain_exception(): void
    {
        $exceptions = [
            \ZeroBoiler\Domain\Exceptions\InvalidStateDomainException::class,
            \ZeroBoiler\Domain\Exceptions\InvalidArgumentDomainException::class,
            \ZeroBoiler\Domain\Exceptions\NotFoundDomainException::class,
            \ZeroBoiler\Domain\Exceptions\ConflictDomainException::class,
            \ZeroBoiler\Domain\Exceptions\OptimisticLockException::class,
            \ZeroBoiler\Domain\Exceptions\AggregateNotFoundException::class,
            \ZeroBoiler\Domain\Exceptions\InvalidAggregateRootException::class,
            \ZeroBoiler\Domain\Exceptions\InvalidStateException::class,
        ];

        foreach ($exceptions as $class) {
            $this->assertTrue(
                is_subclass_of($class, \ZeroBoiler\Domain\Exceptions\DomainException::class),
                "{$class} must extend DomainException.",
            );
        }
    }

    // ─── Serialization Contract ───────────────────────────────

    /**
     * AggregateRootId must have toArray and fromArray.
     *
     * @test
     */
    public function test_aggregate_root_id_has_serde_methods(): void
    {
        $ref = new \ReflectionClass(\ZeroBoiler\Domain\AggregateRootId::class);

        $this->assertTrue($ref->hasMethod('toArray'), 'AggregateRootId must have toArray().');
        $this->assertTrue($ref->hasMethod('fromArray'), 'AggregateRootId must have fromArray().');

        $toArray = $ref->getMethod('toArray');
        $this->assertTrue($toArray->hasReturnType(), 'toArray() must have a return type.');
        $this->assertSame('array', (string) $toArray->getReturnType());

        $fromArray = $ref->getMethod('fromArray');
        $this->assertTrue($fromArray->hasReturnType(), 'fromArray() must have a return type.');
        $this->assertTrue($fromArray->isStatic(), 'fromArray() must be static.');
    }

    /**
     * DomainEventCollection must have toArray and fromArray.
     *
     * @test
     */
    public function test_domain_event_collection_has_serde_methods(): void
    {
        $ref = new \ReflectionClass(\ZeroBoiler\Domain\DomainEventCollection::class);

        $this->assertTrue($ref->hasMethod('toArray'), 'DomainEventCollection must have toArray().');
        $this->assertTrue($ref->hasMethod('fromArray'), 'DomainEventCollection must have fromArray().');

        $toArray = $ref->getMethod('toArray');
        $this->assertTrue($toArray->hasReturnType(), 'toArray() must have a return type.');

        $fromArray = $ref->getMethod('fromArray');
        $this->assertTrue($fromArray->hasReturnType(), 'fromArray() must have a return type.');
        $this->assertTrue($fromArray->isStatic(), 'fromArray() must be static.');
    }

    /**
     * Snapshot must have toArray and fromArray.
     *
     * @test
     */
    public function test_snapshot_has_serde_methods(): void
    {
        $ref = new \ReflectionClass(\ZeroBoiler\Domain\Snapshots\Snapshot::class);

        $this->assertTrue($ref->hasMethod('toArray'), 'Snapshot must have toArray().');
        $this->assertTrue($ref->hasMethod('fromArray'), 'Snapshot must have fromArray().');

        $fromArray = $ref->getMethod('fromArray');
        $this->assertTrue($fromArray->isStatic(), 'fromArray() must be static.');
    }

    /**
     * DomainException must have toArray and fromArray.
     *
     * @test
     */
    public function test_domain_exception_has_serde_methods(): void
    {
        $ref = new \ReflectionClass(\ZeroBoiler\Domain\Exceptions\DomainException::class);

        $this->assertTrue($ref->hasMethod('toArray'), 'DomainException must have toArray().');
        $this->assertTrue($ref->hasMethod('fromArray'), 'DomainException must have fromArray().');
        $this->assertTrue($ref->hasMethod('toErrorArray'), 'DomainException must have toErrorArray().');
        $this->assertTrue($ref->hasMethod('errorCode'), 'DomainException must have errorCode().');
    }

    // ─── #[Override] Attribute Verification ─────────────────────

    /**
     * AggregateRoot::id() must have #[Override] attribute.
     *
     * @test
     */
    public function test_aggregate_root_id_method_has_override_attribute(): void
    {
        $ref = new \ReflectionClass(\ZeroBoiler\Domain\AggregateRoot::class);
        $method = $ref->getMethod('id');

        $attrs = $method->getAttributes(\Override::class);
        $this->assertNotEmpty(
            $attrs,
            'AggregateRoot::id() must have #[Override] attribute.',
        );
    }

    /**
     * AggregateRoot::equals() must have #[Override] attribute.
     *
     * @test
     */
    public function test_aggregate_root_equals_has_override_attribute(): void
    {
        $ref = new \ReflectionClass(\ZeroBoiler\Domain\AggregateRoot::class);
        $method = $ref->getMethod('equals');

        $attrs = $method->getAttributes(\Override::class);
        $this->assertNotEmpty(
            $attrs,
            'AggregateRoot::equals() must have #[Override] attribute.',
        );
    }

    /**
     * AggregateRoot::version() must have #[Override] attribute.
     *
     * @test
     */
    public function test_aggregate_root_version_has_override_attribute(): void
    {
        $ref = new \ReflectionClass(\ZeroBoiler\Domain\AggregateRoot::class);
        $method = $ref->getMethod('version');

        $attrs = $method->getAttributes(\Override::class);
        $this->assertNotEmpty(
            $attrs,
            'AggregateRoot::version() must have #[Override] attribute.',
        );
    }

    /**
     * AggregateRoot::jsonSerialize() must have #[Override] attribute.
     *
     * @test
     */
    public function test_aggregate_root_json_serialize_has_override_attribute(): void
    {
        $ref = new \ReflectionClass(\ZeroBoiler\Domain\AggregateRoot::class);
        $method = $ref->getMethod('jsonSerialize');

        $attrs = $method->getAttributes(\Override::class);
        $this->assertNotEmpty(
            $attrs,
            'AggregateRoot::jsonSerialize() must have #[Override] attribute.',
        );
    }

    // ─── Helper ────────────────────────────────────────────────

    /**
     * Get all PHP source files in the src/ directory.
     *
     * @return list<string>
     */
    private function getCoreSourceFiles(): array
    {
        $dir = __DIR__ . '/../src';
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
        );

        $files = [];
        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
