<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace Tests\Domain\Production;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ZeroBoiler\Domain\AggregateRoot;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\Concerns\EventSourced;
use ZeroBoiler\Domain\Concerns\Guards;
use ZeroBoiler\Domain\Concerns\HasDomainEvents;
use ZeroBoiler\Domain\Concerns\HasSnapshots;
use ZeroBoiler\Domain\Contracts\AggregateRoot as AggregateRootContract;
use ZeroBoiler\Domain\Contracts\Entity as EntityContract;
use ZeroBoiler\Domain\Contracts\Identifier as IdentifierContract;
use ZeroBoiler\Domain\Contracts\Repository as RepositoryContract;
use ZeroBoiler\Domain\Contracts\UnitOfWork as UnitOfWorkContract;
use ZeroBoiler\Domain\DomainEventCollection;
use ZeroBoiler\Domain\Entity;
use ZeroBoiler\Domain\Exceptions\ConflictDomainException;
use ZeroBoiler\Domain\Exceptions\DomainException;
use ZeroBoiler\Domain\Exceptions\InvalidArgumentDomainException;
use ZeroBoiler\Domain\Exceptions\InvalidStateDomainException;
use ZeroBoiler\Domain\Exceptions\NotFoundDomainException;
use ZeroBoiler\Domain\Exceptions\OptimisticLockException;
use ZeroBoiler\Domain\Identifiers\IntegerIdentifier;
use ZeroBoiler\Domain\Identifiers\StringIdentifier;
use ZeroBoiler\Domain\Identifiers\UlidIdentifier;
use ZeroBoiler\Domain\Identifiers\UuidIdentifier;
use ZeroBoiler\Domain\InMemoryUnitOfWork;
use ZeroBoiler\Domain\Snapshots\Snapshot;
use ZeroBoiler\Domain\Snapshots\SnapshotPolicy;
use ZeroBoiler\Domain\Snapshots\SnapshotStore;
use ZeroBoiler\Domain\ValueObject;

/**
 * Final production audit — v1.80.
 *
 * Validates structural, typing, and contract compliance for the entire
 * domain package source tree. Runs as a single focused test class
 * covering all production-readiness dimensions.
 *
 * @Group production
 */
#[Group('production')]
final class DomainPackageFinalProductionAuditV180Test extends TestCase
{
    // ---------------------------------------------------------------
    // 1. Strict Types
    // ---------------------------------------------------------------

    /** @test */
    public function every_source_file_declares_strict_types(): void
    {
        $srcDir = dirname(__DIR__, 2) . '/src';
        $files = glob($srcDir . '/**/*.php');

        $violations = [];
        foreach ($files as $file) {
            $contents = file_get_contents($file);
            if (! str_contains($contents, 'declare(strict_types=1)')) {
                $violations[] = str_replace($srcDir . '/', '', $file);
            }
        }

        $this->assertEmpty(
            $violations,
            'Files missing declare(strict_types=1): ' . implode(', ', $violations),
        );
    }

    // ---------------------------------------------------------------
    // 2. Abstract / Concrete Class Hierarchy
    // ---------------------------------------------------------------

    /** @test */
    public function entity_is_abstract_and_implements_contract(): void
    {
        $ref = new ReflectionClass(Entity::class);

        $this->assertTrue($ref->isAbstract(), 'Entity must be abstract');
        $this->assertTrue($ref->implementsInterface(EntityContract::class));
        $this->assertTrue($ref->implementsInterface(\JsonSerializable::class));
    }

    /** @test */
    public function aggregate_root_extends_entity_and_implements_contract(): void
    {
        $ref = new ReflectionClass(AggregateRoot::class);

        $this->assertTrue($ref->isAbstract(), 'AggregateRoot must be abstract');
        $this->assertTrue($ref->isSubclassOf(Entity::class));
        $this->assertTrue($ref->implementsInterface(AggregateRootContract::class));
    }

    /** @test */
    public function value_object_is_abstract(): void
    {
        $this->assertTrue(
            (new ReflectionClass(ValueObject::class))->isAbstract(),
            'ValueObject must be abstract',
        );
    }

    /** @test */
    public function aggregate_root_id_is_final_readonly(): void
    {
        $ref = new ReflectionClass(AggregateRootId::class);

        $this->assertTrue($ref->isFinal());
        $this->assertTrue($ref->isReadOnly());
    }

    /** @test */
    public function domain_event_collection_is_final_readonly(): void
    {
        $ref = new ReflectionClass(DomainEventCollection::class);

        $this->assertTrue($ref->isFinal());
        $this->assertTrue($ref->isReadOnly());
    }

    /** @test */
    public function snapshot_is_final_readonly(): void
    {
        $ref = new ReflectionClass(Snapshot::class);

        $this->assertTrue($ref->isFinal());
        $this->assertTrue($ref->isReadOnly());
    }

    /** @test */
    public function in_memory_unit_of_work_is_final(): void
    {
        $this->assertTrue(
            (new ReflectionClass(InMemoryUnitOfWork::class))->isFinal(),
        );
    }

    // ---------------------------------------------------------------
    // 3. Identifier Hierarchy
    // ---------------------------------------------------------------

    /** @test */
    public function all_identifiers_implement_identifier_contract(): void
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
                $ref->implementsInterface(IdentifierContract::class),
                $class . ' must implement Identifier contract',
            );
        }
    }

    /** @test */
    public function concrete_identifiers_are_readonly(): void
    {
        $concrete = [StringIdentifier::class, IntegerIdentifier::class];

        foreach ($concrete as $class) {
            $ref = new ReflectionClass($class);
            $this->assertTrue($ref->isReadOnly(), $class . ' must be readonly');
        }
    }

    /** @test */
    public function uuid_identifier_is_abstract_readonly(): void
    {
        $ref = new ReflectionClass(UuidIdentifier::class);

        $this->assertTrue($ref->isAbstract());
        $this->assertTrue($ref->isReadOnly());
    }

    // ---------------------------------------------------------------
    // 4. Exception Hierarchy
    // ---------------------------------------------------------------

    /** @test */
    public function domain_exception_is_abstract_and_json_serializable(): void
    {
        $ref = new ReflectionClass(DomainException::class);

        $this->assertTrue($ref->isAbstract());
        $this->assertTrue($ref->implementsInterface(\JsonSerializable::class));
    }

    /** @test */
    public function all_concrete_exceptions_are_final(): void
    {
        $exceptions = [
            InvalidStateDomainException::class,
            InvalidArgumentDomainException::class,
            NotFoundDomainException::class,
            ConflictDomainException::class,
            OptimisticLockException::class,
        ];

        foreach ($exceptions as $class) {
            $this->assertTrue(
                (new ReflectionClass($class))->isFinal(),
                $class . ' must be final',
            );
        }
    }

    /** @test */
    public function exceptions_have_because_named_constructor(): void
    {
        $exceptions = [
            InvalidStateDomainException::class,
            InvalidArgumentDomainException::class,
            NotFoundDomainException::class,
            ConflictDomainException::class,
        ];

        foreach ($exceptions as $class) {
            $this->assertTrue(
                (new ReflectionClass($class))->hasMethod('because'),
                $class . ' must have a ::because() named constructor',
            );
        }
    }

    /** @test */
    public function exceptions_have_http_status_mapping(): void
    {
        $expected = [
            InvalidStateDomainException::class => 422,
            InvalidArgumentDomainException::class => 422,
            NotFoundDomainException::class => 404,
            ConflictDomainException::class => 409,
            OptimisticLockException::class => 409,
        ];

        foreach ($expected as $class => $status) {
            $instance = $class::because('test');
            $this->assertSame(
                $status,
                $instance->httpStatus(),
                $class . '::httpStatus() should return ' . $status,
            );
        }
    }

    // ---------------------------------------------------------------
    // 5. Serialization Round-Trips
    // ---------------------------------------------------------------

    /** @test */
    public function aggregate_root_id_round_trip(): void
    {
        $id = AggregateRootId::generate();
        $restored = AggregateRootId::fromArray($id->toArray());

        $this->assertTrue($id->equals($restored));
        $this->assertSame($id->toString(), $restored->toString());
    }

    /** @test */
    public function aggregate_root_id_json_round_trip(): void
    {
        $id = AggregateRootId::generate();
        $json = $id->toJson();
        $restored = AggregateRootId::fromJson($json);

        $this->assertTrue($id->equals($restored));
    }

    /** @test */
    public function snapshot_round_trip(): void
    {
        $original = Snapshot::create('Order', 'uuid-123', 42, ['status' => 'paid']);
        $restored = Snapshot::fromArray($original->toArray());

        $this->assertTrue($original->equals($restored));
        $this->assertSame(42, $restored->version);
    }

    /** @test */
    public function snapshot_json_round_trip(): void
    {
        $original = Snapshot::create('Order', 'uuid-123', 10, ['total' => 99.99]);
        $json = $original->toJson();
        $restored = Snapshot::fromJson($json);

        $this->assertTrue($original->equals($restored));
    }

    /** @test */
    public function domain_exception_round_trip_via_to_array(): void
    {
        $original = InvalidStateDomainException::because('test message', code: 'CUSTOM_CODE');
        $array = $original->toArray();
        $restored = DomainException::fromArray($array, InvalidStateDomainException::class);

        $this->assertSame('test message', $restored->getMessage());
        $this->assertSame('CUSTOM_CODE', $restored->errorCode());
    }

    /** @test */
    public function domain_exception_json_round_trip(): void
    {
        $original = NotFoundDomainException::forAggregate('Order', 'uuid-1');
        $json = $original->toJson();
        $restored = DomainException::fromJson($json, NotFoundDomainException::class);

        $this->assertSame('NOT_FOUND', $restored->errorCode());
        $this->assertSame(404, $restored->httpStatus());
    }

    // ---------------------------------------------------------------
    // 6. Domain Invariant Guards
    // ---------------------------------------------------------------

    /** @test */
    public function guards_trait_provides_all_guard_methods(): void
    {
        $ref = new ReflectionClass(Guards::class);

        $expected = [
            'assertNotEmptyString',
            'assertMaxLength',
            'assertPositiveInteger',
            'assertNonNegativeInteger',
            'assertNotNull',
            'assertFound',
            'assertStateIs',
            'assertStateIn',
            'assertStateIsNot',
            'assertRange',
            'assertIn',
        ];

        foreach ($expected as $method) {
            $this->assertTrue(
                $ref->hasMethod($method),
                "Guards trait must have {$method}()",
            );
        }
    }

    /** @test */
    public function guard_methods_are_protected(): void
    {
        $ref = new ReflectionClass(Guards::class);

        foreach ($ref->getMethods(ReflectionMethod::IS_PROTECTED) as $method) {
            $this->assertTrue(
                $method->isProtected(),
                "Guards::{$method->getName()}() must be protected",
            );
        }
    }

    // ---------------------------------------------------------------
    // 7. Unit of Work Contract
    // ---------------------------------------------------------------

    /** @test */
    public function unit_of_work_implements_contract(): void
    {
        $ref = new ReflectionClass(InMemoryUnitOfWork::class);

        $this->assertTrue($ref->implementsInterface(UnitOfWorkContract::class));
        $this->assertTrue($ref->isFinal());
    }

    /** @test */
    public function unit_of_work_has_json_serializable(): void
    {
        $ref = new ReflectionClass(InMemoryUnitOfWork::class);

        $this->assertTrue($ref->implementsInterface(\JsonSerializable::class));
    }

    /** @test */
    public function unit_of_work_serializes_safely(): void
    {
        $uow = new InMemoryUnitOfWork;
        $uow->begin();

        $array = $uow->toArray();
        $json = $uow->toJson();

        $this->assertArrayHasKey('is_active', $array);
        $this->assertArrayHasKey('nesting_depth', $array);
        $this->assertTrue($array['is_active']);
        $this->assertSame(1, $array['nesting_depth']);

        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);

        $uow->commit();
    }

    // ---------------------------------------------------------------
    // 8. Snapshot Infrastructure
    // ---------------------------------------------------------------

    /** @test */
    public function snapshot_policy_is_an_attribute(): void
    {
        $ref = new ReflectionClass(SnapshotPolicy::class);
        $this->assertTrue($ref->isAttribute());
    }

    /** @test */
    public function snapshot_store_is_an_interface(): void
    {
        $this->assertTrue(
            (new ReflectionClass(SnapshotStore::class))->isInterface(),
        );
    }

    // ---------------------------------------------------------------
    // 9. Event Sourcing Trait
    // ---------------------------------------------------------------

    /** @test */
    public function event_sourced_trait_has_from_history(): void
    {
        $ref = new ReflectionClass(EventSourced::class);

        $this->assertTrue($ref->hasMethod('fromHistory'));
        $this->assertTrue($ref->hasMethod('applyEvent'));
    }

    // ---------------------------------------------------------------
    // 10. HasDomainEvents Trait
    // ---------------------------------------------------------------

    /** @test */
    public function has_domain_events_trait_provides_core_methods(): void
    {
        $ref = new ReflectionClass(HasDomainEvents::class);

        $expected = ['recordThat', 'releaseEvents', 'clearEvents', 'hasUncommittedEvents', 'peekEvents'];

        foreach ($expected as $method) {
            $this->assertTrue($ref->hasMethod($method), "HasDomainEvents must have {$method}()");
        }
    }

    // ---------------------------------------------------------------
    // 11. Return Type Coverage (static analysis)
    // ---------------------------------------------------------------

    /** @test */
    public function all_public_methods_have_return_types(): void
    {
        $srcDir = dirname(__DIR__, 2) . '/src';
        $files = glob($srcDir . '/**/*.php');

        $violations = [];

        foreach ($files as $file) {
            $tokens = token_get_all(file_get_contents($file));

            // Skip interfaces — they declare return types in the signature
            foreach ($tokens as $token) {
                if (is_array($token) && $token[0] === T_INTERFACE) {
                    continue 2;
                }
            }

            // Extract FQCN from file
            $fqcns = $this->parseFqcnFromFile(file_get_contents($file));

            foreach ($fqcns as $fqcn) {
                if (! class_exists($fqcn) && ! trait_exists($fqcn)) {
                    continue;
                }

                try {
                    $ref = new ReflectionClass($fqcn);
                } catch (\ReflectionException) {
                    continue;
                }

                foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                    $name = $method->getName();

                    // Skip magic methods
                    if (str_starts_with($name, '__')) {
                        continue;
                    }

                    // Only check methods declared in our package
                    $declaringClass = $method->getDeclaringClass()->getName();
                    if (! str_starts_with($declaringClass, 'ZeroBoiler\\Domain')) {
                        continue;
                    }

                    if ($method->getReturnType() === null && $method->hasReturnType() === false) {
                        $shortName = $method->getDeclaringClass()->getShortName();
                        $violations[] = $shortName . '::' . $name . '()';
                    }
                }
            }
        }

        $this->assertEmpty(
            $violations,
            'Public methods without return types: ' . implode(', ', $violations),
        );
    }

    /**
     * @return list<string>
     */
    private function parseFqcnFromFile(string $contents): array
    {
        $tokens = token_get_all($contents);
        $namespace = '';
        $classes = [];
        $isInterface = false;
        $i = 0;
        $count = count($tokens);

        while ($i < $count) {
            $token = $tokens[$i];

            if (is_array($token)) {
                if ($token[0] === T_NAMESPACE) {
                    $namespace = '';
                    $i++;
                    while ($i < $count) {
                        $t = $tokens[$i];
                        if (is_array($t) && ($t[0] === T_STRING || $t[0] === T_NAME_QUALIFIED)) {
                            $namespace = $namespace . $t[1];
                        } elseif (is_array($t) && $t[0] === T_NS_SEPARATOR) {
                            $namespace = $namespace . chr(92);
                        } elseif (is_string($t) && $t === ';') {
                            break;
                        }
                        $i++;
                    }
                }

                if ($token[0] === T_INTERFACE) {
                    $isInterface = true;
                }

                if ($token[0] === T_CLASS || $token[0] === T_TRAIT) {
                    // Look ahead for the class name
                    $j = $i + 1;
                    while ($j < $count) {
                        $t = $tokens[$j];
                        if (is_array($t) && $t[0] === T_STRING) {
                            if (! $isInterface) {
                                $fqn = $namespace . chr(92) . $t[1];
                                $classes[] = ltrim($fqn, chr(92));
                            }
                            break;
                        }
                        $j++;
                    }
                    $isInterface = false;
                }
            }

            $i++;
        }

        return $classes;
    }

    // ---------------------------------------------------------------
    // 12. JsonSerializable on Contracts
    // ---------------------------------------------------------------

    /** @test */
    public function entity_contract_requires_json_serializable(): void
    {
        $ref = new ReflectionClass(EntityContract::class);

        $this->assertTrue(
            $ref->implementsInterface(\JsonSerializable::class),
            'Entity contract must extend JsonSerializable',
        );
    }

    /** @test */
    public function identifier_contract_requires_json_serializable(): void
    {
        $ref = new ReflectionClass(IdentifierContract::class);

        $this->assertTrue(
            $ref->implementsInterface(\JsonSerializable::class),
            'Identifier contract must extend JsonSerializable',
        );
    }

    // ---------------------------------------------------------------
    // 13. RFC 9457 Error Array Structure
    // ---------------------------------------------------------------

    /** @test */
    public function to_error_array_has_required_rfc9457_keys(): void
    {
        $e = InvalidStateDomainException::because('test');
        $error = $e->toErrorArray();

        $this->assertArrayHasKey('title', $error);
        $this->assertArrayHasKey('detail', $error);
        $this->assertArrayHasKey('code', $error);
        $this->assertArrayHasKey('status', $error);
        $this->assertIsString($error['title']);
        $this->assertIsString($error['detail']);
        $this->assertIsString($error['code']);
        $this->assertIsInt($error['status']);
    }

    // ---------------------------------------------------------------
    // 14. Aggregate Root Contract Methods
    // ---------------------------------------------------------------

    /** @test */
    public function aggregate_root_contract_has_required_methods(): void
    {
        $ref = new ReflectionClass(AggregateRootContract::class);

        $required = [
            'version', 'incrementVersion', 'pullDomainEvents',
            'clearDomainEvents', 'hasUncommittedEvents', 'peekDomainEvents', 'toJson',
        ];

        foreach ($required as $method) {
            $this->assertTrue(
                $ref->hasMethod($method),
                "AggregateRoot contract must have {$method}()",
            );
        }
    }

    // ---------------------------------------------------------------
    // 15. Typed Properties on Core Classes
    // ---------------------------------------------------------------

    /** @test */
    public function entity_id_property_is_readonly(): void
    {
        $ref = new ReflectionClass(Entity::class);
        $prop = $ref->getProperty('id');

        $this->assertTrue($prop->isReadOnly(), 'Entity::$id must be readonly');
        $this->assertTrue($prop->isPublic(), 'Entity::$id must be public');
    }

    /** @test */
    public function aggregate_root_aggregate_id_property_is_readonly(): void
    {
        $ref = new ReflectionClass(AggregateRoot::class);
        $prop = $ref->getProperty('aggregateId');

        $this->assertTrue($prop->isReadOnly(), 'AggregateRoot::$aggregateId must be readonly');
    }

    /** @test */
    public function aggregate_root_version_property_is_protected(): void
    {
        $ref = new ReflectionClass(AggregateRoot::class);
        $prop = $ref->getProperty('version');

        $this->assertTrue($prop->isProtected(), 'AggregateRoot::$version must be protected');
    }

    // ---------------------------------------------------------------
    // 16. HasSnapshots Trait Contract
    // ---------------------------------------------------------------

    /** @test */
    public function has_snapshots_provides_core_methods(): void
    {
        $ref = new ReflectionClass(HasSnapshots::class);

        $expected = [
            'toSnapshotState', 'restoreFromSnapshotState', 'shouldSnapshot',
            'createSnapshot', 'restoreFromSnapshot', 'getSnapshotPolicy',
        ];

        foreach ($expected as $method) {
            $this->assertTrue(
                $ref->hasMethod($method),
                "HasSnapshots must have {$method}()",
            );
        }
    }

    // ---------------------------------------------------------------
    // 17. Repository Contract
    // ---------------------------------------------------------------

    /** @test */
    public function repository_contract_is_an_interface(): void
    {
        $this->assertTrue(
            (new ReflectionClass(RepositoryContract::class))->isInterface(),
        );
    }

    /** @test */
    public function unit_of_work_contract_is_an_interface(): void
    {
        $this->assertTrue(
            (new ReflectionClass(UnitOfWorkContract::class))->isInterface(),
        );
    }

    // ---------------------------------------------------------------
    // 18. AggregateRootId Serialization Keys
    // ---------------------------------------------------------------

    /** @test */
    public function aggregate_root_id_to_array_has_uuid_key(): void
    {
        $id = AggregateRootId::generate();
        $array = $id->toArray();

        $this->assertArrayHasKey('uuid', $array);
        $this->assertIsString($array['uuid']);
    }

    /** @test */
    public function aggregate_root_id_from_array_accepts_uuid_or_id_key(): void
    {
        $id = AggregateRootId::generate();
        $uuid = $id->toString();

        // From 'uuid' key
        $fromUuid = AggregateRootId::fromArray(['uuid' => $uuid]);
        $this->assertSame($uuid, $fromUuid->toString());

        // From 'id' key (fallback)
        $fromId = AggregateRootId::fromArray(['id' => $uuid]);
        $this->assertSame($uuid, $fromId->toString());
    }

    // ---------------------------------------------------------------
    // 19. DomainException errorCode() priority
    // ---------------------------------------------------------------

    /** @test */
    public function explicit_domain_code_takes_priority(): void
    {
        $e = InvalidStateDomainException::because('test', code: 'OVERRIDE');
        $this->assertSame('OVERRIDE', $e->errorCode());
    }

    /** @test */
    public function default_domain_code_is_used_when_empty(): void
    {
        $e = InvalidStateDomainException::because('test');
        $this->assertSame('INVALID_STATE', $e->errorCode());
    }
}
