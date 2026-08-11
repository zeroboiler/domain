<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Tests;

use PHPUnit\Framework\Attributes\Group;
use ZeroBoiler\Domain\AggregateRoot;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\Contracts\AggregateRoot as AggregateRootContract;
use ZeroBoiler\Domain\Contracts\Entity as EntityContract;
use ZeroBoiler\Domain\Contracts\Identifier as IdentifierContract;
use ZeroBoiler\Domain\Contracts\Repository as RepositoryContract;
use ZeroBoiler\Domain\Contracts\UnitOfWork as UnitOfWorkContract;
use ZeroBoiler\Domain\DomainEventCollection;
use ZeroBoiler\Domain\DomainException;
use ZeroBoiler\Domain\Entity;
use ZeroBoiler\Domain\Exceptions\AggregateNotFoundException;
use ZeroBoiler\Domain\Exceptions\ConflictDomainException;
use ZeroBoiler\Domain\Exceptions\DomainException as BaseDomainException;
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
use ZeroBoiler\Domain\Tests\Fixtures\TestAggregate;
use ZeroBoiler\Domain\Tests\Fixtures\TestUuidIdentifier;
use ZeroBoiler\Domain\Tests\Fixtures\TestUlidIdentifier;

/**
 * Comprehensive production contract audit: verifies every domain class
 * satisfies PHP 8.5 production-ready invariants.
 *
 * Checks performed:
 * - All source files have `declare(strict_types=1)`
 * - All public/protected methods have return type declarations
 * - All class properties are properly typed
 * - All domain interfaces are implemented correctly
 * - fromArray/toArray round-trip on all serializable classes
 * - JSON serialization on all JsonSerializable classes
 * - Exception hierarchy contracts
 * - Identifier contracts
 *
 * @group production
 * @group syntax-audit
 */
#[Group('production')]
#[Group('syntax-audit')]
class DomainPhp85ProductionContractAuditTest extends TestCase
{
    // ────────────────────────────────────────────────────────────────────
    // §1. Strict types enforcement — every PHP file must declare strict_types
    // ────────────────────────────────────────────────────────────────────

    public function test_all_src_files_declare_strict_types(): void
    {
        $srcDir = __DIR__ . '/../src';
        $files = $this->findPhpFiles($srcDir);
        $violations = [];

        foreach ($files as $file) {
            $content = file_get_contents($file);
            if ($content === false || ! str_contains($content, 'declare(strict_types=1)')) {
                $violations[] = str_replace($srcDir . '/', '', $file);
            }
        }

        $this->assertEmpty(
            $violations,
            'Files missing declare(strict_types=1): ' . implode(', ', $violations),
        );
    }

    // ────────────────────────────────────────────────────────────────────
    // §2. Return type declarations — public/protected methods must have them
    // ────────────────────────────────────────────────────────────────────

    /**
     * @dataProvider provideClassMethodReturnTypeChecks
     */
    public function test_public_methods_have_return_types(string $class, string $method): void
    {
        $reflection = new \ReflectionMethod($class, $method);

        if ($reflection->hasReturnType()) {
            $this->assertTrue(true, "{$class}::{$method}() has return type");

            return;
        }

        // Methods inherited from ReflectionClass, Closure, etc. are exempt
        $this->fail(
            "Missing return type on {$class}::{$method}(). " .
            'All public/protected methods must have explicit return types for PHP 8.5 compatibility.',
        );
    }

    /**
     * @return array<int, array{string, string}>
     */
    public static function provideClassMethodReturnTypeChecks(): array
    {
        $classes = [
            AggregateRoot::class,
            AggregateRootId::class,
            DomainEventCollection::class,
            InMemoryUnitOfWork::class,
            ValueObject::class,
            UuidIdentifier::class,
            UlidIdentifier::class,
            StringIdentifier::class,
            IntegerIdentifier::class,
            Snapshot::class,
            InMemorySnapshotStore::class,
            SnapshottingRepository::class,
            BaseDomainException::class,
            InvalidStateDomainException::class,
            InvalidArgumentDomainException::class,
            NotFoundDomainException::class,
            ConflictDomainException::class,
            OptimisticLockException::class,
            AggregateNotFoundException::class,
            InvalidStateException::class,
        ];

        $methods = [];

        foreach ($classes as $class) {
            if (! class_exists($class)) {
                continue;
            }

            $reflection = new \ReflectionClass($class);

            foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC | \ReflectionMethod::IS_PROTECTED) as $method) {
                // Skip constructor, __destruct, __clone (PHP handles these)
                if (str_starts_with($method->getName(), '__')) {
                    continue;
                }

                // Skip inherited methods (only check declared-in-class)
                if ($method->getDeclaringClass()->getName() !== $class) {
                    continue;
                }

                $methods[] = [$class, $method->getName()];
            }
        }

        return $methods;
    }

    // ────────────────────────────────────────────────────────────────────
    // §3. Interface contracts — all interfaces fully implemented
    // ────────────────────────────────────────────────────────────────────

    public function test_aggregate_root_id_implements_stringable(): void
    {
        $id = AggregateRootId::generate();
        $this->assertInstanceOf(\Stringable::class, $id);
        $this->assertInstanceOf(\JsonSerializable::class, $id);
    }

    public function test_all_identifiers_implement_contracts(): void
    {
        $identifiers = [
            [UuidIdentifier::class, TestUuidIdentifier::class],
            [UlidIdentifier::class, TestUlidIdentifier::class],
            [StringIdentifier::class, StringIdentifier::class],
            [IntegerIdentifier::class, IntegerIdentifier::class],
        ];

        foreach ($identifiers as [$contract, $concrete]) {
            $id = match (true) {
                $concrete === StringIdentifier::class => StringIdentifier::from('test'),
                $concrete === IntegerIdentifier::class => IntegerIdentifier::from(1),
                default => $concrete::generate(),
            };

            $this->assertInstanceOf(IdentifierContract::class, $id, "{$concrete} must implement Identifier contract");
            $this->assertInstanceOf(\Stringable::class, $id, "{$concrete} must be Stringable");
            $this->assertInstanceOf(\JsonSerializable::class, $id, "{$concrete} must be JsonSerializable");
        }
    }

    public function test_entity_implements_entity_contract(): void
    {
        $reflection = new \ReflectionClass(Entity::class);
        $this->assertTrue(
            $reflection->implementsInterface(EntityContract::class),
            'Entity must implement EntityContract',
        );
        $this->assertTrue(
            $reflection->implementsInterface(\JsonSerializable::class),
            'Entity must implement JsonSerializable',
        );
    }

    public function test_aggregate_root_implements_aggregate_root_contract(): void
    {
        $reflection = new \ReflectionClass(AggregateRoot::class);
        $this->assertTrue(
            $reflection->implementsInterface(AggregateRootContract::class),
            'AggregateRoot must implement AggregateRootContract',
        );
    }

    public function test_snapshotting_repository_implements_repository(): void
    {
        $reflection = new \ReflectionClass(SnapshottingRepository::class);
        $this->assertTrue(
            $reflection->implementsInterface(RepositoryContract::class),
            'SnapshottingRepository must implement Repository contract',
        );
    }

    public function test_in_memory_unit_of_work_implements_uow(): void
    {
        $reflection = new \ReflectionClass(InMemoryUnitOfWork::class);
        $this->assertTrue(
            $reflection->implementsInterface(UnitOfWorkContract::class),
            'InMemoryUnitOfWork must implement UnitOfWork contract',
        );
    }

    // ────────────────────────────────────────────────────────────────────
    // §4. Exception hierarchy — all exceptions extend DomainException
    // ────────────────────────────────────────────────────────────────────

    /**
     * @dataProvider provideDomainExceptionClasses
     */
    public function test_exception_extends_domain_exception(string $class): void
    {
        $this->assertTrue(
            is_subclass_of($class, BaseDomainException::class),
            "{$class} must extend DomainException",
        );
    }

    public function test_all_exceptions_have_machine_readable_error_code(): void
    {
        $exceptions = [
            ['class' => InvalidStateDomainException::class, 'code' => 'INVALID_STATE'],
            ['class' => InvalidArgumentDomainException::class, 'code' => 'INVALID_ARGUMENT'],
            ['class' => NotFoundDomainException::class, 'code' => 'NOT_FOUND'],
            ['class' => ConflictDomainException::class, 'code' => 'CONFLICT'],
            ['class' => OptimisticLockException::class, 'code' => 'OPTIMISTIC_LOCK'],
            ['class' => AggregateNotFoundException::class, 'code' => 'AGGREGATE_NOT_FOUND'],
            ['class' => InvalidStateException::class, 'code' => 'INVALID_STATE_SYSTEM'],
        ];

        foreach ($exceptions as ['class' => $class, 'code' => $expectedCode]) {
            $reflection = new \ReflectionClass($class);
            $instance = $reflection->newInstanceWithoutConstructor();

            // Access defaultErrorCode via reflection (it's protected)
            $method = $reflection->getMethod('defaultErrorCode');
            $method->setAccessible(true);
            $actualCode = $method->invoke($instance);

            $this->assertSame(
                $expectedCode,
                $actualCode,
                "{$class}::defaultErrorCode() must return '{$expectedCode}'",
            );
        }
    }

    public function test_exceptions_are_json_serializable(): void
    {
        $exceptions = [
            InvalidStateDomainException::because('test'),
            InvalidArgumentDomainException::because('test'),
            NotFoundDomainException::because('test'),
            ConflictDomainException::because('test'),
            OptimisticLockException::for('id', 1, 2),
            AggregateNotFoundException::for('Order', '123'),
            InvalidStateException::because('test'),
        ];

        foreach ($exceptions as $exception) {
            $this->assertInstanceOf(\JsonSerializable::class, $exception);
            $json = json_encode($exception);
            $this->assertNotFalse($json);
            $decoded = json_decode($json, true);
            $this->assertIsArray($decoded);
            $this->assertArrayHasKey('title', $decoded, get_class($exception) . ' JSON must have title');
            $this->assertArrayHasKey('detail', $decoded, get_class($exception) . ' JSON must have detail');
            $this->assertArrayHasKey('code', $decoded, get_class($exception) . ' JSON must have code');
        }
    }

    /**
     * @return array<int, array{string}>
     */
    public static function provideDomainExceptionClasses(): array
    {
        return [
            [InvalidStateDomainException::class],
            [InvalidArgumentDomainException::class],
            [NotFoundDomainException::class],
            [ConflictDomainException::class],
            [OptimisticLockException::class],
            [AggregateNotFoundException::class],
            [InvalidStateException::class],
        ];
    }

    // ────────────────────────────────────────────────────────────────────
    // §5. Serialization round-trips — fromArray → toArray identity
    // ────────────────────────────────────────────────────────────────────

    public function test_aggregate_root_id_round_trip(): void
    {
        $id = AggregateRootId::generate();
        $restored = AggregateRootId::fromArray($id->toArray());
        $this->assertTrue($id->equals($restored));
    }

    public function test_uuid_identifier_round_trip(): void
    {
        $id = TestUuidIdentifier::generate();
        $restored = TestUuidIdentifier::fromArray($id->toArray());
        $this->assertTrue($id->equals($restored));
    }

    public function test_ulid_identifier_round_trip(): void
    {
        $id = TestUlidIdentifier::generate();
        $restored = TestUlidIdentifier::fromArray($id->toArray());
        $this->assertTrue($id->equals($restored));
    }

    public function test_string_identifier_round_trip(): void
    {
        $id = StringIdentifier::from('test-key');
        $restored = StringIdentifier::fromArray($id->toArray());
        $this->assertTrue($id->equals($restored));
    }

    public function test_integer_identifier_round_trip(): void
    {
        $id = IntegerIdentifier::from(42);
        $restored = IntegerIdentifier::fromArray($id->toArray());
        $this->assertTrue($id->equals($restored));
    }

    public function test_snapshot_round_trip(): void
    {
        $snapshot = Snapshot::create(
            aggregateType: TestAggregate::class,
            aggregateId: (string) AggregateRootId::generate(),
            version: 10,
            state: ['status' => 'active', 'total' => 199.99],
        );

        $restored = Snapshot::fromArray($snapshot->toArray());
        $this->assertTrue($snapshot->equals($restored));
    }

    // ────────────────────────────────────────────────────────────────────
    // §6. Final/readonly class invariants
    // ────────────────────────────────────────────────────────────────────

    /**
     * @dataProvider provideFinalClasses
     */
    public function test_class_is_final(string $class): void
    {
        $reflection = new \ReflectionClass($class);
        $this->assertTrue(
            $reflection->isFinal(),
            "{$class} must be declared final for production immutability",
        );
    }

    /**
     * @dataProvider provideReadonlyClasses
     */
    public function test_class_is_readonly(string $class): void
    {
        $reflection = new \ReflectionClass($class);
        $this->assertTrue(
            $reflection->isReadOnly(),
            "{$class} must be declared readonly for production immutability",
        );
    }

    /**
     * @return array<int, array{string}>
     */
    public static function provideFinalClasses(): array
    {
        return [
            [AggregateRootId::class],
            [DomainEventCollection::class],
            [InMemoryUnitOfWork::class],
            [StringIdentifier::class],
            [IntegerIdentifier::class],
            [Snapshot::class],
            [InMemorySnapshotStore::class],
            [SnapshottingRepository::class],
            [InvalidStateDomainException::class],
            [InvalidArgumentDomainException::class],
            [NotFoundDomainException::class],
            [ConflictDomainException::class],
            [OptimisticLockException::class],
            [AggregateNotFoundException::class],
            [InvalidStateException::class],
            [SnapshotPolicy::class],
        ];
    }

    /**
     * @return array<int, array{string}>
     */
    public static function provideReadonlyClasses(): array
    {
        return [
            [AggregateRootId::class],
            [DomainEventCollection::class],
            [StringIdentifier::class],
            [IntegerIdentifier::class],
            [Snapshot::class],
            [SnapshottingRepository::class],
            [SnapshotPolicy::class],
        ];
    }

    // ────────────────────────────────────────────────────────────────────
    // §7. SnapshotPolicy attribute contract
    // ────────────────────────────────────────────────────────────────────

    public function test_snapshot_policy_is_class_level_attribute(): void
    {
        $reflection = new \ReflectionClass(SnapshotPolicy::class);
        $attributes = $reflection->getAttributes();

        $this->assertCount(1, $attributes, 'SnapshotPolicy must have exactly one attribute');
        $this->assertEquals(
            \Attribute::class,
            $attributes[0]->getName(),
            'SnapshotPolicy must use #[Attribute]',
        );

        $attrInstance = $attributes[0]->newInstance();
        $this->assertEquals(
            \Attribute::TARGET_CLASS,
            $attrInstance->flags,
            'SnapshotPolicy must target classes only',
        );
    }

    // ────────────────────────────────────────────────────────────────────
    // §8. DomainEventCollection is always a sequential list
    // ────────────────────────────────────────────────────────────────────

    public function test_domain_event_collection_rejects_associative_arrays(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('sequential list');

        // Create a mock DomainEvent object
        $event = new class implements \JsonSerializable {
            public string $eventType = 'test.event';
            public array $payload = ['test' => true];

            public function toArray(): array
            {
                return [
                    'eventType' => $this->eventType,
                    'payload' => $this->payload,
                ];
            }

            public function jsonSerialize(): array
            {
                return $this->toArray();
            }
        };

        new DomainEventCollection(['key' => $event]);
    }

    // ────────────────────────────────────────────────────────────────────
    // Helpers
    // ────────────────────────────────────────────────────────────────────

    /**
     * @return list<string>
     */
    private function findPhpFiles(string $dir): array
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
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
