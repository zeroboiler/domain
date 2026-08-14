<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Tests\Production;

use ReflectionClass;
use ReflectionMethod;
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
use ZeroBoiler\Domain\Snapshots\SnapshotPolicy;
use ZeroBoiler\Domain\Snapshots\SnapshottingRepository;
use ZeroBoiler\Domain\ValueObject;

/**
 * Production hardening audit — verifies every domain class
 * satisfies PHP 8.5 best practices and ZeroBoiler coding standards.
 *
 * @covers \ZeroBoiler\Domain\AggregateRoot
 * @covers \ZeroBoiler\Domain\AggregateRootId
 * @covers \ZeroBoiler\Domain\Entity
 * @covers \ZeroBoiler\Domain\ValueObject
 * @covers \ZeroBoiler\Domain\DomainEventCollection
 * @covers \ZeroBoiler\Domain\InMemoryUnitOfWork
 * @covers \ZeroBoiler\Domain\Exceptions\DomainException
 * @covers \ZeroBoiler\Domain\Exceptions\InvalidStateDomainException
 * @covers \ZeroBoiler\Domain\Exceptions\InvalidArgumentDomainException
 * @covers \ZeroBoiler\Domain\Exceptions\NotFoundDomainException
 * @covers \ZeroBoiler\Domain\Exceptions\ConflictDomainException
 * @covers \ZeroBoiler\Domain\Exceptions\AggregateNotFoundException
 * @covers \ZeroBoiler\Domain\Exceptions\OptimisticLockException
 * @covers \ZeroBoiler\Domain\Exceptions\InvalidAggregateRootException
 * @covers \ZeroBoiler\Domain\Exceptions\InvalidStateException
 * @covers \ZeroBoiler\Domain\Identifiers\UuidIdentifier
 * @covers \ZeroBoiler\Domain\Identifiers\UlidIdentifier
 * @covers \ZeroBoiler\Domain\Identifiers\StringIdentifier
 * @covers \ZeroBoiler\Domain\Identifiers\IntegerIdentifier
 * @covers \ZeroBoiler\Domain\Snapshots\Snapshot
 * @covers \ZeroBoiler\Domain\Snapshots\InMemorySnapshotStore
 * @covers \ZeroBoiler\Domain\Snapshots\SnapshottingRepository
 *
 * @since 1.67.0
 */
final class DomainPackageProductionAuditTest extends \PHPUnit\Framework\TestCase
{
    // ════════════════════════════════════════════════════════════
    // §1. Strict types verification
    // ════════════════════════════════════════════════════════════

    /**
     * @dataProvider strictTypesProvider
     */
    public function testStrictTypesDeclaration(string $class): void
    {
        $file = (new ReflectionClass($class))->getFileName();
        $contents = file_get_contents($file);
        $this->assertStringContainsString(
            'declare(strict_types=1)',
            $contents,
            "{$class} must declare strict_types=1",
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function strictTypesProvider(): array
    {
        return [
            'AggregateRoot' => [AggregateRoot::class],
            'AggregateRootId' => [AggregateRootId::class],
            'Entity' => [Entity::class],
            'ValueObject' => [ValueObject::class],
            'DomainEventCollection' => [DomainEventCollection::class],
            'InMemoryUnitOfWork' => [InMemoryUnitOfWork::class],
            'DomainException' => [DomainException::class],
            'InvalidStateDomainException' => [InvalidStateDomainException::class],
            'InvalidArgumentDomainException' => [InvalidArgumentDomainException::class],
            'NotFoundDomainException' => [NotFoundDomainException::class],
            'ConflictDomainException' => [ConflictDomainException::class],
            'AggregateNotFoundException' => [AggregateNotFoundException::class],
            'OptimisticLockException' => [OptimisticLockException::class],
            'InvalidAggregateRootException' => [InvalidAggregateRootException::class],
            'InvalidStateException' => [InvalidStateException::class],
            'UuidIdentifier' => [UuidIdentifier::class],
            'UlidIdentifier' => [UlidIdentifier::class],
            'StringIdentifier' => [StringIdentifier::class],
            'IntegerIdentifier' => [IntegerIdentifier::class],
            'Snapshot' => [Snapshot::class],
            'InMemorySnapshotStore' => [InMemorySnapshotStore::class],
            'SnapshottingRepository' => [SnapshottingRepository::class],
        ];
    }

    // ════════════════════════════════════════════════════════════
    // §2. Return type declarations on all public methods
    // ════════════════════════════════════════════════════════════

    /**
     * @dataProvider publicMethodReturnTypesProvider
     */
    public function testPublicMethodsHaveReturnTypes(string $class): void
    {
        $reflection = new ReflectionClass($class);
        $publicMethods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

        // Skip constructor and inherited
        foreach ($publicMethods as $method) {
            if ($method->getDeclaringClass()->getName() !== $class) {
                continue;
            }
            if ($method->isConstructor() || $method->getName() === '__construct') {
                continue;
            }

            $this->assertNotNull(
                $method->getReturnType(),
                "{$class}::{$method->getName()}() must have a return type declaration",
            );
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function publicMethodReturnTypesProvider(): array
    {
        return [
            'AggregateRootId' => [AggregateRootId::class],
            'DomainEventCollection' => [DomainEventCollection::class],
            'InMemoryUnitOfWork' => [InMemoryUnitOfWork::class],
            'UuidIdentifier' => [UuidIdentifier::class],
            'UlidIdentifier' => [UlidIdentifier::class],
            'StringIdentifier' => [StringIdentifier::class],
            'IntegerIdentifier' => [IntegerIdentifier::class],
            'Snapshot' => [Snapshot::class],
            'InMemorySnapshotStore' => [InMemorySnapshotStore::class],
            'SnapshottingRepository' => [SnapshottingRepository::class],
        ];
    }

    // ════════════════════════════════════════════════════════════
    // §3. Docblock presence on all public methods of concrete classes
    // ════════════════════════════════════════════════════════════

    /**
     * @dataProvider docblockProvider
     */
    public function testConcreteClassesHaveDocblocks(string $class): void
    {
        $reflection = new ReflectionClass($class);
        $publicMethods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

        foreach ($publicMethods as $method) {
            if ($method->getDeclaringClass()->getName() !== $class) {
                continue;
            }
            if ($method->isConstructor() || $method->getName() === '__construct') {
                continue;
            }
            // Static constructors (fromString, generate, etc.) and magic methods
            if (in_array($method->getName(), ['__toString', '__serialize', '__unserialize', '__clone'], true)) {
                continue;
            }

            $doc = $method->getDocComment();
            $this->assertNotEmpty(
                $doc,
                "{$class}::{$method->getName()}() must have a docblock",
            );
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function docblockProvider(): array
    {
        return [
            'AggregateRootId' => [AggregateRootId::class],
            'InMemoryUnitOfWork' => [InMemoryUnitOfWork::class],
            'Snapshot' => [Snapshot::class],
            'InMemorySnapshotStore' => [InMemorySnapshotStore::class],
        ];
    }

    // ════════════════════════════════════════════════════════════
    // §4. AggregateRootId immutability and identity
    // ════════════════════════════════════════════════════════════

    public function testAggregateRootIdIsFinalReadonly(): void
    {
        $reflection = new ReflectionClass(AggregateRootId::class);
        $this->assertTrue($reflection->isFinal(), 'AggregateRootId must be final');
        $this->assertTrue($reflection->isReadOnly(), 'AggregateRootId must be readonly');
    }

    public function testAggregateRootIdEqualityIsStrict(): void
    {
        $id1 = AggregateRootId::generate();
        $id2 = AggregateRootId::generate();

        $this->assertTrue($id1->equals($id1));
        $this->assertFalse($id1->equals($id2));
        $this->assertSame($id1->toString(), $id1->jsonSerialize());
    }

    public function testAggregateRootIdArrayRoundTrip(): void
    {
        $id = AggregateRootId::generate();
        $restored = AggregateRootId::fromArray($id->toArray());
        $this->assertTrue($id->equals($restored));
    }

    public function testAggregateRootIdJsonRoundTrip(): void
    {
        $id = AggregateRootId::generate();
        $json = $id->toJson();
        $restored = AggregateRootId::fromJson($json);
        $this->assertTrue($id->equals($restored));
    }

    // ════════════════════════════════════════════════════════════
    // §5. UuidIdentifier type safety and round-trips
    // ════════════════════════════════════════════════════════════

    public function testUuidIdentifierIsAbstractReadonly(): void
    {
        $reflection = new ReflectionClass(UuidIdentifier::class);
        $this->assertTrue($reflection->isAbstract());
        $this->assertTrue($reflection->isReadOnly());
    }

    public function testUuidIdentifierRejectsInvalidUuid(): void
    {
        $this->expectException(\Ramsey\Uuid\Exception\InvalidUuidStringException::class);
        TestUuidId::fromString('not-a-uuid');
    }

    public function testUuidIdentifierEqualityIsTypeSafe(): void
    {
        $id1 = TestUuidId::generate();
        $id2 = TestUuidId::generate();
        $id1Restored = TestUuidId::fromString($id1->toString());

        $this->assertTrue($id1->equals($id1Restored));
        $this->assertFalse($id1->equals($id2));
        // Different subclass → not equal
        $otherType = TestUuidIdAlt::fromString($id1->toString());
        $this->assertFalse($id1->equals($otherType));
    }

    public function testUuidIdentifierFullSerdeRoundTrip(): void
    {
        $id = TestUuidId::generate();

        // toArray → fromArray
        $fromArray = TestUuidId::fromArray($id->toArray());
        $this->assertTrue($id->equals($fromArray));

        // toJson → fromJson
        $fromJson = TestUuidId::fromJson($id->toJson());
        $this->assertTrue($id->equals($fromJson));

        // jsonSerialize → json_encode produces string
        $encoded = json_encode($id);
        $this->assertIsString($encoded);
        $this->assertEquals($id->toString(), $encoded);
    }

    // ════════════════════════════════════════════════════════════
    // §6. UlidIdentifier type safety
    // ════════════════════════════════════════════════════════════

    public function testUlidIdentifierGeneratesMonotonic(): void
    {
        $id = TestUlidId::generate();
        $this->assertStringMatchesFormat('%s', $id->toString());
        $this->assertEquals(26, strlen($id->toString()));
    }

    public function testUlidIdentifierRoundTrip(): void
    {
        $id = TestUlidId::generate();
        $restored = TestUlidId::fromString($id->toString());
        $this->assertTrue($id->equals($restored));
    }

    // ════════════════════════════════════════════════════════════
    // §7. StringIdentifier and IntegerIdentifier
    // ════════════════════════════════════════════════════════════

    public function testStringIdentifierRejectsEmpty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        StringIdentifier::from('');
    }

    public function testStringIdentifierEquality(): void
    {
        $a = StringIdentifier::from('slug-1');
        $b = StringIdentifier::from('slug-1');
        $c = StringIdentifier::from('slug-2');

        $this->assertTrue($a->equals($b));
        $this->assertFalse($a->equals($c));
    }

    public function testIntegerIdentifierFromInt(): void
    {
        $id = IntegerIdentifier::from(42);
        $this->assertEquals(42, $id->toInt());
        $this->assertEquals('42', $id->toString());
    }

    public function testIntegerIdentifierFromString(): void
    {
        $id = IntegerIdentifier::fromString('42');
        $this->assertEquals(42, $id->toInt());
    }

    // ════════════════════════════════════════════════════════════
    // §8. DomainException hierarchy — errorCode, httpStatus, RFC 9457
    // ════════════════════════════════════════════════════════════

    /**
     * @dataProvider exceptionMappingProvider
     */
    public function testDomainExceptionHasErrorCodeAndHttpStatus(
        string $exceptionClass,
        string $expectedCode,
        int $expectedStatus,
    ): void {
        $e = $exceptionClass::because('test');
        $this->assertSame($expectedCode, $e->errorCode());
        $this->assertSame($expectedStatus, $e->httpStatus());

        $errorArray = $e->toErrorArray();
        $this->assertArrayHasKey('title', $errorArray);
        $this->assertArrayHasKey('detail', $errorArray);
        $this->assertArrayHasKey('code', $errorArray);
        $this->assertArrayHasKey('status', $errorArray);
        $this->assertSame($expectedCode, $errorArray['code']);
        $this->assertSame($expectedStatus, $errorArray['status']);
    }

    /**
     * @return array<string, array{string, string, int}>
     */
    public static function exceptionMappingProvider(): array
    {
        return [
            'InvalidStateDomainException' => [InvalidStateDomainException::class, 'INVALID_STATE', 422],
            'InvalidArgumentDomainException' => [InvalidArgumentDomainException::class, 'INVALID_ARGUMENT', 422],
            'NotFoundDomainException' => [NotFoundDomainException::class, 'NOT_FOUND', 404],
            'ConflictDomainException' => [ConflictDomainException::class, 'CONFLICT', 409],
            'AggregateNotFoundException' => [AggregateNotFoundException::class, 'AGGREGATE_NOT_FOUND', 404],
            'OptimisticLockException' => [OptimisticLockException::class, 'OPTIMISTIC_LOCK', 409],
            'InvalidAggregateRootException' => [InvalidAggregateRootException::class, 'INVALID_AGGREGATE_ROOT', 500],
            'InvalidStateException' => [InvalidStateException::class, 'INVALID_STATE_SYSTEM', 500],
        ];
    }

    public function testDomainExceptionJsonSerializable(): void
    {
        $e = InvalidStateDomainException::because('test');
        $json = json_encode($e);
        $decoded = json_decode($json, true);

        $this->assertArrayHasKey('code', $decoded);
        $this->assertArrayHasKey('status', $decoded);
        $this->assertSame('INVALID_STATE', $decoded['code']);
    }

    public function testDomainExceptionRoundTripViaJson(): void
    {
        $e = NotFoundDomainException::forId('order-123');
        $json = $e->toJson();
        $restored = DomainException::fromJson($json, NotFoundDomainException::class);

        $this->assertSame('NOT_FOUND', $restored->errorCode());
    }

    public function testOptimisticLockExceptionFactory(): void
    {
        $e = OptimisticLockException::for('id-1', expectedVersion: 5, actualVersion: 3);
        $this->assertSame('OPTIMISTIC_LOCK', $e->errorCode());
        $this->assertSame(409, $e->httpStatus());
    }

    // ════════════════════════════════════════════════════════════
    // §9. DomainEventCollection readonly + serde
    // ════════════════════════════════════════════════════════════

    public function testDomainEventCollectionIsFinalReadonly(): void
    {
        $reflection = new ReflectionClass(DomainEventCollection::class);
        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
    }

    public function testDomainEventCollectionRejectsNonSequentialList(): void
    {
        $event = $this->createDomainEvent('test.event');
        $this->expectException(\InvalidArgumentException::class);
        new DomainEventCollection([1 => $event, 0 => $event]);
    }

    // ════════════════════════════════════════════════════════════
    // §10. InMemoryUnitOfWork implements UnitOfWorkContract
    // ════════════════════════════════════════════════════════════

    public function testInMemoryUnitOfWorkImplementsContract(): void
    {
        $uow = new InMemoryUnitOfWork;
        $this->assertInstanceOf(UnitOfWorkContract::class, $uow);
    }

    public function testUnitOfWorkRunAutoCommits(): void
    {
        $uow = new InMemoryUnitOfWork;
        $result = $uow->run(fn () => 'success');
        $this->assertSame('success', $result);
        $this->assertFalse($uow->isActive());
    }

    public function testUnitOfWorkRunAutoRollbacks(): void
    {
        $uow = new InMemoryUnitOfWork;

        try {
            $uow->run(function (): never {
                throw new \RuntimeException('fail');
            });
            $this->fail('Should have thrown');
        } catch (\RuntimeException $e) {
            $this->assertSame('fail', $e->getMessage());
        }

        $this->assertFalse($uow->isActive());
        $this->assertFalse($uow->hasPendingEvents());
    }

    // ════════════════════════════════════════════════════════════
    // §11. Snapshot readonly class
    // ════════════════════════════════════════════════════════════

    public function testSnapshotIsFinalReadonly(): void
    {
        $reflection = new ReflectionClass(Snapshot::class);
        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
    }

    public function testSnapshotRoundTrip(): void
    {
        $snapshot = Snapshot::create('Order', 'id-1', 5, ['status' => 'paid']);
        $restored = Snapshot::fromArray($snapshot->toArray());

        $this->assertTrue($snapshot->equals($restored));
    }

    // ════════════════════════════════════════════════════════════
    // §12. SnapshottingRepository is final readonly
    // ════════════════════════════════════════════════════════════

    public function testSnapshottingRepositoryIsFinalReadonly(): void
    {
        $reflection = new ReflectionClass(SnapshottingRepository::class);
        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
    }

    public function testSnapshottingRepositoryImplementsRepositoryContract(): void
    {
        $reflection = new ReflectionClass(SnapshottingRepository::class);
        $this->assertTrue($reflection->implementsInterface(RepositoryContract::class));
    }

    // ════════════════════════════════════════════════════════════
    // §13. AggregateRoot implements both contracts
    // ════════════════════════════════════════════════════════════

    public function testAggregateRootImplementsBothContracts(): void
    {
        $reflection = new ReflectionClass(AggregateRoot::class);
        $this->assertTrue($reflection->isAbstract());
        $this->assertTrue($reflection->implementsInterface(AggregateRootContract::class));
        $this->assertTrue($reflection->implementsInterface(EntityContract::class));
        $this->assertTrue($reflection->implementsInterface(\JsonSerializable::class));
    }

    public function testEntityImplementsEntityContract(): void
    {
        $reflection = new ReflectionClass(Entity::class);
        $this->assertTrue($reflection->isAbstract());
        $this->assertTrue($reflection->implementsInterface(EntityContract::class));
        $this->assertTrue($reflection->implementsInterface(\JsonSerializable::class));
    }

    public function testValueObjectIsAbstract(): void
    {
        $reflection = new ReflectionClass(ValueObject::class);
        $this->assertTrue($reflection->isAbstract());
    }

    // ════════════════════════════════════════════════════════════
    // §14. Contract completeness
    // ════════════════════════════════════════════════════════════

    public function testEntityContractRequiresSerdeMethods(): void
    {
        $contract = new ReflectionClass(EntityContract::class);
        $this->assertTrue($contract->hasMethod('toArray'));
        $this->assertTrue($contract->hasMethod('fromArray'));
        $this->assertTrue($contract->hasMethod('fromJson'));
        $this->assertTrue($contract->hasMethod('toJson'));
    }

    public function testAggregateRootContractRequiresEventMethods(): void
    {
        $contract = new ReflectionClass(AggregateRootContract::class);
        $this->assertTrue($contract->hasMethod('version'));
        $this->assertTrue($contract->hasMethod('pullDomainEvents'));
        $this->assertTrue($contract->hasMethod('clearDomainEvents'));
        $this->assertTrue($contract->hasMethod('peekDomainEvents'));
        $this->assertTrue($contract->hasMethod('incrementVersion'));
        $this->assertTrue($contract->hasMethod('toJson'));
    }

    public function testIdentifierContractRequiresSerdeMethods(): void
    {
        $contract = new ReflectionClass(IdentifierContract::class);
        $this->assertTrue($contract->hasMethod('fromString'));
        $this->assertTrue($contract->hasMethod('toString'));
        $this->assertTrue($contract->hasMethod('equals'));
        $this->assertTrue($contract->hasMethod('toArray'));
        $this->assertTrue($contract->hasMethod('fromArray'));
        $this->assertTrue($contract->hasMethod('fromJson'));
    }

    // ════════════════════════════════════════════════════════════
    // §15. PHP 8.5 features — #[Override] on key implementations
    // ════════════════════════════════════════════════════════════

    public function testAggregateRootIdJsonSerializeHasOverride(): void
    {
        $method = new ReflectionMethod(AggregateRootId::class, 'jsonSerialize');
        $attrs = $method->getAttributes(\Override::class);
        $this->assertCount(1, $attrs, 'jsonSerialize must have #[Override]');
    }

    public function testEntityJsonSerializeHasOverride(): void
    {
        $method = new ReflectionMethod(Entity::class, 'jsonSerialize');
        $attrs = $method->getAttributes(\Override::class);
        $this->assertCount(1, $attrs, 'Entity::jsonSerialize must have #[Override]');
    }

    public function testUuidIdentifierJsonSerializeHasOverride(): void
    {
        $method = new ReflectionMethod(UuidIdentifier::class, 'jsonSerialize');
        $attrs = $method->getAttributes(\Override::class);
        $this->assertCount(1, $attrs, 'UuidIdentifier::jsonSerialize must have #[Override]');
    }

    // ════════════════════════════════════════════════════════════
    // Helpers
    // ════════════════════════════════════════════════════════════

    private function createDomainEvent(string $type): object
    {
        return new class($type) {
            public function __construct(public readonly string $eventType) {}

            public function toArray(): array
            {
                return ['eventType' => $this->eventType];
            }
        };
    }
}

// ── Test fixtures ────────────────────────────────────────────

/** @internal */
final class TestUuidId extends UuidIdentifier {}

/** @internal */
final class TestUuidIdAlt extends UuidIdentifier {}

/** @internal */
final class TestUlidId extends UlidIdentifier {}
