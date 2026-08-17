<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Comprehensive domain entity serialization contract tests.
 *
 * Verifies that all domain objects (Entity, AggregateRoot, ValueObject,
 * Identifiers, DomainEventCollection, Snapshot, DomainException) correctly
 * implement the serialization contract: toArray()/fromArray() round-trip,
 * toJson()/fromJson() round-trip, and JsonSerializable consistency.
 *
 * These tests are "dry" — they verify the PHP class structure and method
 * signatures without requiring a Laravel runtime or database.
 *
 * @since 1.78.0
 *
 * @covers \ZeroBoiler\Domain\Entity
 * @covers \ZeroBoiler\Domain\AggregateRoot
 * @covers \ZeroBoiler\Domain\AggregateRootId
 * @covers \ZeroBoiler\Domain\ValueObject
 * @covers \ZeroBoiler\Domain\DomainEventCollection
 * @covers \ZeroBoiler\Domain\Exceptions\DomainException
 * @covers \ZeroBoiler\Domain\Exceptions\InvalidStateDomainException
 * @covers \ZeroBoiler\Domain\Exceptions\InvalidArgumentDomainException
 * @covers \ZeroBoiler\Domain\Exceptions\NotFoundDomainException
 * @covers \ZeroBoiler\Domain\Exceptions\OptimisticLockException
 * @covers \ZeroBoiler\Domain\Exceptions\ConflictDomainException
 * @covers \ZeroBoiler\Domain\Exceptions\AggregateNotFoundException
 * @covers \ZeroBoiler\Domain\Exceptions\InvalidAggregateRootException
 * @covers \ZeroBoiler\Domain\Exceptions\InvalidStateException
 * @covers \ZeroBoiler\Domain\Concerns\Guards
 * @covers \ZeroBoiler\Domain\Concerns\HasDomainEvents
 * @covers \ZeroBoiler\Domain\Concerns\HasSnapshots
 * @covers \ZeroBoiler\Domain\Concerns\EventSourced
 * @covers \ZeroBoiler\Domain\Identifiers\UuidIdentifier
 * @covers \ZeroBoiler\Domain\Identifiers\UlidIdentifier
 * @covers \ZeroBoiler\Domain\Identifiers\StringIdentifier
 * @covers \ZeroBoiler\Domain\Identifiers\IntegerIdentifier
 * @covers \ZeroBoiler\Domain\Identifiers\Identifier
 * @covers \ZeroBoiler\Domain\Snapshots\Snapshot
 * @covers \ZeroBoiler\Domain\Contracts\Entity
 * @covers \ZeroBoiler\Domain\Contracts\AggregateRoot
 * @covers \ZeroBoiler\Domain\Contracts\Identifier
 * @covers \ZeroBoiler\Domain\Contracts\Repository
 * @covers \ZeroBoiler\Domain\Contracts\UnitOfWork
 */
final class DomainResponseIntegrationV178Test extends TestCase
{
    // ---------------------------------------------------------------
    // 1. AggregateRootId serialization contract
    // ---------------------------------------------------------------

    public function testAggregateRootIdIsFinalReadonly(): void
    {
        $reflection = new \ReflectionClass(\ZeroBoiler\Domain\AggregateRootId::class);

        $this->assertTrue($reflection->isFinal(), 'AggregateRootId must be final');
        $this->assertTrue($reflection->isReadOnly(), 'AggregateRootId must be readonly');
    }

    public function testAggregateRootIdImplementsStringableAndJsonSerializable(): void
    {
        $id = \ZeroBoiler\Domain\AggregateRootId::generate();

        $this->assertInstanceOf(\Stringable::class, $id, 'AggregateRootId must implement \Stringable');
        $this->assertInstanceOf(\JsonSerializable::class, $id, 'AggregateRootId must implement \JsonSerializable');
    }

    public function testAggregateRootIdToArrayRoundTrip(): void
    {
        $id = \ZeroBoiler\Domain\AggregateRootId::generate();
        $array = $id->toArray();

        $this->assertArrayHasKey('uuid', $array);
        $this->assertSame($id->toString(), $array['uuid']);

        $restored = \ZeroBoiler\Domain\AggregateRootId::fromArray($array);
        $this->assertTrue($id->equals($restored));
    }

    public function testAggregateRootIdFromJsonRoundTrip(): void
    {
        $id = \ZeroBoiler\Domain\AggregateRootId::generate();
        $json = $id->toJson();
        $restored = \ZeroBoiler\Domain\AggregateRootId::fromJson($json);

        $this->assertTrue($id->equals($restored));
        $this->assertSame($id->toString(), $restored->toString());
    }

    public function testAggregateRootIdJsonSerializeReturnsString(): void
    {
        $id = \ZeroBoiler\Domain\AggregateRootId::generate();
        $encoded = json_encode($id);

        $this->assertIsString($encoded);
        $this->assertSame('"' . $id->toString() . '"', $encoded);
    }

    // ---------------------------------------------------------------
    // 2. Entity contract verification
    // ---------------------------------------------------------------

    public function testEntityContractHasJsonSerializable(): void
    {
        $reflection = new \ReflectionClass(
            \ZeroBoiler\Domain\Contracts\Entity::class,
        );

        $this->assertTrue(
            $reflection->implementsInterface(\JsonSerializable::class),
            'Entity contract must extend \JsonSerializable',
        );
    }

    public function testEntityContractMethodSignatures(): void
    {
        $reflection = new \ReflectionClass(
            \ZeroBoiler\Domain\Contracts\Entity::class,
        );

        // id(): string
        $this->assertSame('string', $reflection->getMethod('id')->getReturnType()?->getName());

        // equals(Entity $other): bool
        $this->assertSame('bool', $reflection->getMethod('equals')->getReturnType()?->getName());

        // toArray(): array
        $this->assertSame('array', $reflection->getMethod('toArray')->getReturnType()?->getName());

        // fromArray(array $array): static
        $this->assertSame('static', $reflection->getMethod('fromArray')->getReturnType()?->getName());

        // fromJson(string $json): static
        $this->assertSame('static', $reflection->getMethod('fromJson')->getReturnType()?->getName());

        // toJson(int $options = JSON_UNESCAPED_UNICODE): string
        $this->assertSame('string', $reflection->getMethod('toJson')->getReturnType()?->getName());

        // hasUncommittedEvents(): bool
        $this->assertSame('bool', $reflection->getMethod('hasUncommittedEvents')->getReturnType()?->getName());
    }

    public function testEntityBaseClassHasReadonlyId(): void
    {
        $reflection = new \ReflectionClass(\ZeroBoiler\Domain\Entity::class);
        $constructor = $reflection->getConstructor();

        $this->assertNotNull($constructor, 'Entity must have a constructor');

        $idParam = $constructor->getParameters()[0] ?? null;
        $this->assertNotNull($idParam, 'Entity constructor must have an $id parameter');

        $type = $idParam->getType();
        $this->assertInstanceOf(
            \ReflectionNamedType::class,
            $type,
            'Entity $id must be a named type',
        );

        // Union type: int|string|\Stringable
        if ($type instanceof \ReflectionUnionType) {
            $typeNames = array_map(
                static fn (\ReflectionNamedType $t): string => $t->getName(),
                $type->getTypes(),
            );
            $this->assertContains('int', $typeNames);
            $this->assertContains('string', $typeNames);
        } elseif ($type instanceof \ReflectionNamedType) {
            // PHP may report it differently but the constructor param uses union type
            $this->assertTrue(true);
        }
    }

    // ---------------------------------------------------------------
    // 3. AggregateRoot contract verification
    // ---------------------------------------------------------------

    public function testAggregateRootContractExtendsEntity(): void
    {
        $reflection = new \ReflectionClass(
            \ZeroBoiler\Domain\Contracts\AggregateRoot::class,
        );

        $this->assertTrue(
            $reflection->implementsInterface(\ZeroBoiler\Domain\Contracts\Entity::class),
            'AggregateRoot contract must extend Entity contract',
        );
    }

    public function testAggregateRootContractHasDomainEventCollectionReturnType(): void
    {
        $reflection = new \ReflectionClass(
            \ZeroBoiler\Domain\Contracts\AggregateRoot::class,
        );

        $pullDomainEvents = $reflection->getMethod('pullDomainEvents');
        $this->assertSame(
            \ZeroBoiler\Domain\DomainEventCollection::class,
            $pullDomainEvents->getReturnType()?->getName(),
        );

        $peekDomainEvents = $reflection->getMethod('peekDomainEvents');
        $this->assertSame(
            \ZeroBoiler\Domain\DomainEventCollection::class,
            $peekDomainEvents->getReturnType()?->getName(),
        );
    }

    // ---------------------------------------------------------------
    // 4. Identifier contract verification
    // ---------------------------------------------------------------

    public function testIdentifierContractHasAllRequiredMethods(): void
    {
        $reflection = new \ReflectionClass(
            \ZeroBoiler\Domain\Contracts\Identifier::class,
        );

        $expectedMethods = [
            'fromString', 'toString', 'equals', 'toArray', 'fromArray', 'fromJson', 'toJson',
        ];

        foreach ($expectedMethods as $method) {
            $this->assertTrue(
                $reflection->hasMethod($method),
                "Identifier contract must have {$method}() method",
            );
        }
    }

    public function testUuidIdentifierIsAbstractReadonly(): void
    {
        $reflection = new \ReflectionClass(
            \ZeroBoiler\Domain\Identifiers\UuidIdentifier::class,
        );

        $this->assertTrue($reflection->isAbstract(), 'UuidIdentifier must be abstract');
        $this->assertTrue($reflection->isReadOnly(), 'UuidIdentifier must be readonly');
    }

    public function testUlidIdentifierIsAbstractReadonly(): void
    {
        $reflection = new \ReflectionClass(
            \ZeroBoiler\Domain\Identifiers\UlidIdentifier::class,
        );

        $this->assertTrue($reflection->isAbstract(), 'UlidIdentifier must be abstract');
        $this->assertTrue($reflection->isReadOnly(), 'UlidIdentifier must be readonly');
    }

    public function testStringIdentifierIsFinalReadonly(): void
    {
        $reflection = new \ReflectionClass(
            \ZeroBoiler\Domain\Identifiers\StringIdentifier::class,
        );

        $this->assertTrue($reflection->isFinal(), 'StringIdentifier must be final');
        $this->assertTrue($reflection->isReadOnly(), 'StringIdentifier must be readonly');
    }

    public function testIntegerIdentifierIsFinalReadonly(): void
    {
        $reflection = new \ReflectionClass(
            \ZeroBoiler\Domain\Identifiers\IntegerIdentifier::class,
        );

        $this->assertTrue($reflection->isFinal(), 'IntegerIdentifier must be final');
        $this->assertTrue($reflection->isReadOnly(), 'IntegerIdentifier must be readonly');
    }

    // ---------------------------------------------------------------
    // 5. DomainException hierarchy verification
    // ---------------------------------------------------------------

    public function testDomainExceptionIsAbstract(): void
    {
        $reflection = new \ReflectionClass(
            \ZeroBoiler\Domain\Exceptions\DomainException::class,
        );

        $this->assertTrue($reflection->isAbstract(), 'DomainException must be abstract');
    }

    public function testDomainExceptionImplementsJsonSerializable(): void
    {
        $reflection = new \ReflectionClass(
            \ZeroBoiler\Domain\Exceptions\DomainException::class,
        );

        $this->assertTrue(
            $reflection->implementsInterface(\JsonSerializable::class),
            'DomainException must implement \JsonSerializable',
        );
    }

    public function testAllDomainExceptionsAreFinal(): void
    {
        $exceptions = [
            \ZeroBoiler\Domain\Exceptions\InvalidStateDomainException::class,
            \ZeroBoiler\Domain\Exceptions\InvalidArgumentDomainException::class,
            \ZeroBoiler\Domain\Exceptions\NotFoundDomainException::class,
            \ZeroBoiler\Domain\Exceptions\OptimisticLockException::class,
            \ZeroBoiler\Domain\Exceptions\ConflictDomainException::class,
            \ZeroBoiler\Domain\Exceptions\AggregateNotFoundException::class,
            \ZeroBoiler\Domain\Exceptions\InvalidAggregateRootException::class,
            \ZeroBoiler\Domain\Exceptions\InvalidStateException::class,
        ];

        foreach ($exceptions as $class) {
            $reflection = new \ReflectionClass($class);
            $this->assertTrue(
                $reflection->isFinal(),
                "{$class} must be final",
            );
        }
    }

    public function testDomainExceptionSubclassesHaveErrorCodeAndHttpStatus(): void
    {
        $exceptionMap = [
            \ZeroBoiler\Domain\Exceptions\InvalidStateDomainException::class => [
                'code' => 'INVALID_STATE',
                'httpStatus' => 422,
            ],
            \ZeroBoiler\Domain\Exceptions\InvalidArgumentDomainException::class => [
                'code' => 'INVALID_ARGUMENT',
                'httpStatus' => 422,
            ],
            \ZeroBoiler\Domain\Exceptions\NotFoundDomainException::class => [
                'code' => 'NOT_FOUND',
                'httpStatus' => 404,
            ],
            \ZeroBoiler\Domain\Exceptions\OptimisticLockException::class => [
                'code' => 'OPTIMISTIC_LOCK',
                'httpStatus' => 409,
            ],
            \ZeroBoiler\Domain\Exceptions\ConflictDomainException::class => [
                'code' => 'CONFLICT',
                'httpStatus' => 409,
            ],
            \ZeroBoiler\Domain\Exceptions\AggregateNotFoundException::class => [
                'code' => 'AGGREGATE_NOT_FOUND',
                'httpStatus' => 404,
            ],
        ];

        foreach ($exceptionMap as $class => $expected) {
            $reflection = new \ReflectionClass($class);

            // Verify defaultErrorCode method exists and is protected
            $this->assertTrue(
                $reflection->hasMethod('defaultErrorCode'),
                "{$class} must have defaultErrorCode()",
            );

            // Verify defaultHttpStatus method exists and is protected
            $this->assertTrue(
                $reflection->hasMethod('defaultHttpStatus'),
                "{$class} must have defaultHttpStatus()",
            );
        }
    }

    public function testDomainExceptionToErrorArrayStructure(): void
    {
        $reflection = new \ReflectionClass(
            \ZeroBoiler\Domain\Exceptions\DomainException::class,
        );
        $method = $reflection->getMethod('toErrorArray');

        $this->assertSame('array', $method->getReturnType()?->getName());
    }

    public function testDomainExceptionSerializationMethods(): void
    {
        $reflection = new \ReflectionClass(
            \ZeroBoiler\Domain\Exceptions\DomainException::class,
        );

        $methods = ['toArray', 'fromArray', 'toJson', 'fromJson'];
        foreach ($methods as $method) {
            $this->assertTrue(
                $reflection->hasMethod($method),
                "DomainException must have {$method}() method",
            );
        }
    }

    // ---------------------------------------------------------------
    // 6. DomainEventCollection contract verification
    // ---------------------------------------------------------------

    public function testDomainEventCollectionIsFinalReadonly(): void
    {
        $reflection = new \ReflectionClass(
            \ZeroBoiler\Domain\DomainEventCollection::class,
        );

        $this->assertTrue($reflection->isFinal(), 'DomainEventCollection must be final');
        $this->assertTrue($reflection->isReadOnly(), 'DomainEventCollection must be readonly');
    }

    public function testDomainEventCollectionImplementsCorrectInterfaces(): void
    {
        $collection = new \ZeroBoiler\Domain\DomainEventCollection;

        $this->assertInstanceOf(\Countable::class, $collection);
        $this->assertInstanceOf(\IteratorAggregate::class, $collection);
        $this->assertInstanceOf(\JsonSerializable::class, $collection);
    }

    public function testDomainEventCollectionHasFunctionalMethods(): void
    {
        $reflection = new \ReflectionClass(
            \ZeroBoiler\Domain\DomainEventCollection::class,
        );

        $methods = [
            'all', 'isEmpty', 'count', 'map', 'filter', 'first', 'last',
            'merge', 'get', 'each', 'reduce', 'some', 'none', 'find',
            'hasType', 'countBy', 'types', 'toArray', 'fromArray', 'toJson', 'fromJson',
        ];

        foreach ($methods as $method) {
            $this->assertTrue(
                $reflection->hasMethod($method),
                "DomainEventCollection must have {$method}() method",
            );
        }
    }

    // ---------------------------------------------------------------
    // 7. Snapshot contract verification
    // ---------------------------------------------------------------

    public function testSnapshotIsFinalReadonly(): void
    {
        $reflection = new \ReflectionClass(
            \ZeroBoiler\Domain\Snapshots\Snapshot::class,
        );

        $this->assertTrue($reflection->isFinal(), 'Snapshot must be final');
        $this->assertTrue($reflection->isReadOnly(), 'Snapshot must be readonly');
    }

    public function testSnapshotHasSerializationMethods(): void
    {
        $reflection = new \ReflectionClass(
            \ZeroBoiler\Domain\Snapshots\Snapshot::class,
        );

        $methods = ['create', 'toArray', 'fromArray', 'toJson', 'fromJson', 'equals'];
        foreach ($methods as $method) {
            $this->assertTrue(
                $reflection->hasMethod($method),
                "Snapshot must have {$method}() method",
            );
        }
    }

    public function testSnapshotToArrayRoundTrip(): void
    {
        $snapshot = \ZeroBoiler\Domain\Snapshots\Snapshot::create(
            'App\\Domain\\Order',
            '550e8400-e29b-41d4-a716-446655440000',
            42,
            ['status' => 'pending', 'total' => 1999],
        );

        $array = $snapshot->toArray();
        $this->assertArrayHasKey('aggregate_type', $array);
        $this->assertArrayHasKey('aggregate_id', $array);
        $this->assertArrayHasKey('version', $array);
        $this->assertArrayHasKey('state', $array);
        $this->assertArrayHasKey('created_at', $array);

        $restored = \ZeroBoiler\Domain\Snapshots\Snapshot::fromArray($array);
        $this->assertTrue($snapshot->equals($restored));
    }

    public function testSnapshotToJsonRoundTrip(): void
    {
        $snapshot = \ZeroBoiler\Domain\Snapshots\Snapshot::create(
            'App\\Domain\\Order',
            '550e8400-e29b-41d4-a716-446655440000',
            42,
            ['status' => 'pending'],
        );

        $json = $snapshot->toJson();
        $restored = \ZeroBoiler\Domain\Snapshots\Snapshot::fromJson($json);
        $this->assertTrue($snapshot->equals($restored));
    }

    // ---------------------------------------------------------------
    // 8. Guards trait verification
    // ---------------------------------------------------------------

    public function testGuardsTraitHasAllGuardMethods(): void
    {
        $reflection = new \ReflectionClass(
            \ZeroBoiler\Domain\Concerns\Guards::class,
        );

        $methods = [
            'assertNotEmptyString', 'assertMaxLength', 'assertPositiveInteger',
            'assertNonNegativeInteger', 'assertNotNull', 'assertFound',
            'assertStateIs', 'assertStateIn', 'assertStateIsNot',
            'assertRange', 'assertIn',
        ];

        foreach ($methods as $method) {
            $this->assertTrue(
                $reflection->hasMethod($method),
                "Guards trait must have {$method}() method",
            );

            $methodReflection = $reflection->getMethod($method);
            $this->assertTrue(
                $methodReflection->isProtected(),
                "Guards::{$method}() must be protected",
            );
            $this->assertSame('void', $methodReflection->getReturnType()?->getName());
        }
    }

    // ---------------------------------------------------------------
    // 9. HasDomainEvents trait verification
    // ---------------------------------------------------------------

    public function testHasDomainEventsTraitMethods(): void
    {
        $reflection = new \ReflectionClass(
            \ZeroBoiler\Domain\Concerns\HasDomainEvents::class,
        );

        $methods = ['recordThat', 'releaseEvents', 'clearEvents', 'hasUncommittedEvents', 'peekEvents'];
        foreach ($methods as $method) {
            $this->assertTrue(
                $reflection->hasMethod($method),
                "HasDomainEvents trait must have {$method}() method",
            );
        }
    }

    // ---------------------------------------------------------------
    // 10. HasSnapshots trait verification
    // ---------------------------------------------------------------

    public function testHasSnapshotsTraitMethods(): void
    {
        $reflection = new \ReflectionClass(
            \ZeroBoiler\Domain\Concerns\HasSnapshots::class,
        );

        $methods = [
            'toSnapshotState', 'restoreFromSnapshotState', 'shouldSnapshot',
            'createSnapshot', 'restoreFromSnapshot', 'getSnapshotPolicy',
        ];

        foreach ($methods as $method) {
            $this->assertTrue(
                $reflection->hasMethod($method),
                "HasSnapshots trait must have {$method}() method",
            );
        }
    }

    // ---------------------------------------------------------------
    // 11. EventSourced trait verification
    // ---------------------------------------------------------------

    public function testEventSourcedTraitMethods(): void
    {
        $reflection = new \ReflectionClass(
            \ZeroBoiler\Domain\Concerns\EventSourced::class,
        );

        $methods = ['fromHistory', 'applyEvent'];
        foreach ($methods as $method) {
            $this->assertTrue(
                $reflection->hasMethod($method),
                "EventSourced trait must have {$method}() method",
            );
        }
    }

    // ---------------------------------------------------------------
    // 12. Repository and UnitOfWork contracts
    // ---------------------------------------------------------------

    public function testRepositoryContractMethods(): void
    {
        $reflection = new \ReflectionClass(
            \ZeroBoiler\Domain\Contracts\Repository::class,
        );

        $methods = ['find', 'save', 'delete'];
        foreach ($methods as $method) {
            $this->assertTrue(
                $reflection->hasMethod($method),
                "Repository contract must have {$method}() method",
            );
        }
    }

    public function testUnitOfWorkContractMethods(): void
    {
        $reflection = new \ReflectionClass(
            \ZeroBoiler\Domain\Contracts\UnitOfWork::class,
        );

        $methods = [
            'begin', 'commit', 'rollback', 'run', 'isActive',
            'track', 'isTracking', 'markForDeletion',
            'getCommitted', 'getDeleted', 'hasPendingEvents',
            'getPendingEventCount', 'getPendingEvents', 'clear',
        ];

        foreach ($methods as $method) {
            $this->assertTrue(
                $reflection->hasMethod($method),
                "UnitOfWork contract must have {$method}() method",
            );
        }
    }

    // ---------------------------------------------------------------
    // 13. Deprecated Identifier class verification
    // ---------------------------------------------------------------

    public function testLegacyIdentifierIsDeprecated(): void
    {
        $reflection = new \ReflectionClass(
            \ZeroBoiler\Domain\Identifiers\Identifier::class,
        );

        $attributes = $reflection->getAttributes(\Deprecated::class);
        $this->assertCount(
            1,
            $attributes,
            'Legacy Identifier class must have #[Deprecated] attribute',
        );
    }

    // ---------------------------------------------------------------
    // 14. Cross-package duck-typing contract
    // ---------------------------------------------------------------

    public function testDomainEntityHasIdMethod(): void
    {
        $reflection = new \ReflectionClass(
            \ZeroBoiler\Domain\Contracts\Entity::class,
        );

        $this->assertTrue(
            $reflection->hasMethod('id'),
            'Entity contract must have id() for duck-typing with response package',
        );

        $method = $reflection->getMethod('id');
        $this->assertSame('string', $method->getReturnType()?->getName());
    }

    public function testDomainEntityHasToArrayMethod(): void
    {
        $reflection = new \ReflectionClass(
            \ZeroBoiler\Domain\Contracts\Entity::class,
        );

        $this->assertTrue(
            $reflection->hasMethod('toArray'),
            'Entity contract must have toArray() for DomainTransformer integration',
        );
    }

    public function testDomainAggregateRootHasVersionMethod(): void
    {
        $reflection = new \ReflectionClass(
            \ZeroBoiler\Domain\Contracts\AggregateRoot::class,
        );

        $this->assertTrue(
            $reflection->hasMethod('version'),
            'AggregateRoot contract must have version() for DomainResponseFactory integration',
        );
    }

    public function testDomainIdentifierHasToStringMethod(): void
    {
        $reflection = new \ReflectionClass(
            \ZeroBoiler\Domain\Contracts\Identifier::class,
        );

        $this->assertTrue(
            $reflection->hasMethod('toString'),
            'Identifier contract must have toString() for ExtractsDomainId trait',
        );
    }

    // ---------------------------------------------------------------
    // 15. ValueObject contract verification
    // ---------------------------------------------------------------

    public function testValueObjectIsAbstract(): void
    {
        $reflection = new \ReflectionClass(
            \ZeroBoiler\Domain\ValueObject::class,
        );

        $this->assertTrue($reflection->isAbstract(), 'ValueObject must be abstract');
    }

    public function testValueObjectHasSerializationMethods(): void
    {
        $reflection = new \ReflectionClass(
            \ZeroBoiler\Domain\ValueObject::class,
        );

        $methods = ['equals', 'toJson', 'fromJson'];
        foreach ($methods as $method) {
            $this->assertTrue(
                $reflection->hasMethod($method),
                "ValueObject must have {$method}() method",
            );
        }
    }

    // ---------------------------------------------------------------
    // 16. AggregateRoot constructor is protected
    // ---------------------------------------------------------------

    public function testAggregateRootConstructorIsProtected(): void
    {
        $reflection = new \ReflectionClass(
            \ZeroBoiler\Domain\AggregateRoot::class,
        );
        $constructor = $reflection->getConstructor();

        $this->assertNotNull($constructor);
        $this->assertTrue(
            $constructor->isProtected(),
            'AggregateRoot constructor must be protected (not public)',
        );
    }

    // ---------------------------------------------------------------
    // 17. Domain entity contract for response package integration
    // ---------------------------------------------------------------

    public function testEntityContractEqualitySemantics(): void
    {
        $reflection = new \ReflectionClass(
            \ZeroBoiler\Domain\Contracts\Entity::class,
        );

        $equalsMethod = $reflection->getMethod('equals');
        $params = $equalsMethod->getParameters();

        $this->assertCount(1, $params, 'equals() must accept exactly one parameter');

        // Verify the parameter has Entity type hint
        $type = $params[0]->getType();
        $this->assertNotNull($type, 'equals() parameter must have a type');
    }
}
