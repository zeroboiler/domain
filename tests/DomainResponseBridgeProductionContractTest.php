<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace Tests\Domain\DomainResponseBridgeProductionContractTest;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use ZeroBoiler\Domain\AggregateRoot;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\Concerns\EventSourced;
use ZeroBoiler\Domain\Concerns\HasDomainEvents;
use ZeroBoiler\Domain\Concerns\HasSnapshots;
use ZeroBoiler\Domain\Contracts\Entity as EntityContract;
use ZeroBoiler\Domain\Contracts\Identifier as IdentifierContract;
use ZeroBoiler\Domain\Identifiers\StringIdentifier;
use ZeroBoiler\Domain\Identifiers\UuidIdentifier;
use ZeroBoiler\Domain\Identifiers\UlidIdentifier;
use ZeroBoiler\Domain\Identifiers\IntegerIdentifier;
use ZeroBoiler\Domain\Identifiers\Identifier as LegacyIdentifier;
use ZeroBoiler\Domain\Snapshots\SnapshotPolicy;
use ZeroBoiler\Domain\ValueObject;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Response\Concerns\ExtractsDomainId;
use ZeroBoiler\Response\Transformers\DomainResponseFactory;
use ZeroBoiler\Response\Transformers\DomainTransformer;
use ZeroBoiler\Response\ViewModel;

/**
 * Production contract tests for the domain → response bridge.
 *
 * Verifies that domain entities, identifiers, and exceptions correctly
 * integrate with the response package's DomainTransformer, DomainResponseFactory,
 * and ExtractsDomainId trait — all via duck typing (no compile-time dependency).
 *
 * These tests validate the cross-package contract without requiring both packages
 * to be in the same composer.json — they use interface/duck-typing contracts only.
 *
 * @internal
 *
 * @since 1.56.0
 */
#[CoversClass(ExtractsDomainId::class)]
#[CoversClass(DomainResponseFactory::class)]
#[CoversClass(DomainTransformer::class)]
#[Group('production')]
#[Group('cross-package')]
final class DomainResponseBridgeProductionContractTest extends \PHPUnit\Framework\TestCase
{
    // ─── ExtractsDomainId Contract Tests ─────────────────────────────

    #[\PHPUnit\Framework\Attributes\Test]
    public function extract_id_resolves_from_entity_with_id_method(): void
    {
        $entity = $this->createStubEntity(id: 'order-123');

        $result = ExtractsDomainId::extractId($entity);

        $this->assertSame('order-123', $result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function extract_id_resolves_from_identifier_with_to_string(): void
    {
        $id = UuidIdentifier::generate();

        $result = ExtractsDomainId::extractId($id);

        $this->assertSame($id->toString(), $result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function extract_id_resolves_from_stringable_object(): void
    {
        $entity = new class ('abc') implements \Stringable {
            public function __construct(public readonly string $value) {}
            public function __toString(): string { return $this->value; }
        };

        $result = ExtractsDomainId::extractId($entity);

        $this->assertSame('abc', $result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function extract_id_falls_back_to_spl_object_id(): void
    {
        $entity = new \stdClass;

        $result = ExtractsDomainId::extractId($entity);

        $this->assertSame((string) spl_object_id($entity), $result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function extract_id_prioritizes_id_method_over_stringable(): void
    {
        $entity = new class implements \Stringable {
            public function id(): string { return 'from-id-method'; }
            public function __toString(): string { return 'from-tostring'; }
        };

        $result = ExtractsDomainId::extractId($entity);

        $this->assertSame('from-id-method', $result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function extract_id_prioritizes_to_string_over_stringable(): void
    {
        $entity = new class implements \Stringable {
            public function toString(): string { return 'from-toString'; }
            public function __toString(): string { return 'from-magic'; }
        }

        $result = ExtractsDomainId::extractId($entity);

        $this->assertSame('from-toString', $result);
    }

    // ─── Identifier → Response Bridge Tests ──────────────────────────

    #[\PHPUnit\Framework\Attributes\Test]
    public function uuid_identifier_extracts_correctly_via_trait(): void
    {
        $id = UuidIdentifier::generate();

        $this->assertSame($id->toString(), ExtractsDomainId::extractId($id));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function ulid_identifier_extracts_correctly_via_trait(): void
    {
        $id = UlidIdentifier::generate();

        $this->assertSame($id->toString(), ExtractsDomainId::extractId($id));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function string_identifier_extracts_correctly_via_trait(): void
    {
        $id = new class extends StringIdentifier {
            public function __construct() { parent::__construct('order-42'); }
        };

        $this->assertSame('order-42', ExtractsDomainId::extractId($id));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function integer_identifier_extracts_correctly_via_trait(): void
    {
        $id = new class extends IntegerIdentifier {
            public function __construct() { parent::__construct(42); }
        };

        $this->assertSame('42', ExtractsDomainId::extractId($id));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function all_identifier_types_implement_identifier_contract(): void
    {
        $this->assertInstanceOf(IdentifierContract::class, UuidIdentifier::generate());
        $this->assertInstanceOf(IdentifierContract::class, UlidIdentifier::generate());
        $this->assertInstanceOf(IdentifierContract::class, new class extends StringIdentifier { public function __construct() { parent::__construct('x'); } });
        $this->assertInstanceOf(IdentifierContract::class, new class extends IntegerIdentifier { public function __construct() { parent::__construct(1); } });
    }

    // ─── Entity Identity Contract Tests ───────────────────────────────

    #[\PHPUnit\Framework\Attributes\Test]
    public function entity_with_string_id_returns_string_identity(): void
    {
        $entity = $this->createStubEntity(id: 'entity-123');

        $this->assertSame('entity-123', $entity->id());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function entity_equality_is_class_and_id_specific(): void
    {
        $a = $this->createStubEntity(id: 'same-id');
        $b = $this->createStubEntity(id: 'same-id');
        $c = $this->createStubEntity(id: 'different-id');

        $this->assertTrue($a->equals($b));
        $this->assertFalse($a->equals($c));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function entity_to_array_includes_id_and_type(): void
    {
        $entity = $this->createStubEntity(id: 'test-1');

        $array = $entity->toArray();

        $this->assertArrayHasKey('id', $array);
        $this->assertArrayHasKey('type', $array);
        $this->assertSame('test-1', $array['id']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function entity_is_json_serializable(): void
    {
        $entity = $this->createStubEntity(id: 'json-test');

        $json = json_encode($entity);

        $this->assertNotFalse($json);
        $decoded = json_decode($json, true);
        $this->assertSame('json-test', $decoded['id']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function entity_from_array_round_trip(): void
    {
        $original = $this->createStubEntity(id: 'round-trip');
        $array = $original->toArray();

        $restored = StubEntity::fromArray(['id' => $array['id']]);

        $this->assertSame($original->id(), $restored->id());
    }

    // ─── AggregateRoot Contract Tests ─────────────────────────────────

    #[\PHPUnit\Framework\Attributes\Test]
    public function aggregate_root_id_is_stringable(): void
    {
        $id = AggregateRootId::generate();

        $this->assertInstanceOf(\Stringable::class, $id);
        $this->assertSame($id->toString(), (string) $id);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function aggregate_root_id_json_serializes_to_string(): void
    {
        $id = AggregateRootId::generate();

        $this->assertSame($id->toString(), $id->jsonSerialize());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function aggregate_root_id_to_array_round_trip(): void
    {
        $original = AggregateRootId::generate();
        $array = $original->toArray();

        $restored = AggregateRootId::fromArray($array);

        $this->assertSame($original->toString(), $restored->toString());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function aggregate_root_id_equals_is_type_specific(): void
    {
        $id1 = AggregateRootId::fromString('00000000-0000-0000-0000-000000000001');
        $id2 = AggregateRootId::fromString('00000000-0000-0000-0000-000000000001');
        $id3 = AggregateRootId::fromString('00000000-0000-0000-0000-000000000002');

        $this->assertTrue($id1->equals($id2));
        $this->assertFalse($id1->equals($id3));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function aggregate_root_implements_entity_contract(): void
    {
        $this->assertInstanceOf(EntityContract::class, StubAggregate::reconstituteWithoutConstructor());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function aggregate_root_to_array_includes_id_version_and_type(): void
    {
        $aggregate = StubAggregate::reconstituteWithoutConstructor();

        $array = $aggregate->toArray();

        $this->assertArrayHasKey('id', $array);
        $this->assertArrayHasKey('version', $array);
        $this->assertArrayHasKey('type', $array);
        $this->assertSame(0, $array['version']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function aggregate_root_version_increments_on_apply(): void
    {
        $aggregate = StubAggregate::reconstituteWithoutConstructor();

        $initialVersion = $aggregate->version();
        $aggregate->applyDomainEvent();
        $this->assertSame($initialVersion + 1, $aggregate->version());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function aggregate_root_pull_domain_events_returns_collection(): void
    {
        $aggregate = StubAggregate::reconstituteWithoutConstructor();
        $aggregate->applyDomainEvent();

        $events = $aggregate->pullDomainEvents();

        $this->assertCount(1, $events);
        // After pulling, events should be cleared
        $this->assertCount(0, $aggregate->peekDomainEvents());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function aggregate_root_peek_domain_events_does_not_clear(): void
    {
        $aggregate = StubAggregate::reconstituteWithoutConstructor();
        $aggregate->applyDomainEvent();

        $peeked = $aggregate->peekDomainEvents();
        $peekedAgain = $aggregate->peekDomainEvents();

        $this->assertCount(1, $peeked);
        $this->assertCount(1, $peekedAgain);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function aggregate_root_clear_domain_events(): void
    {
        $aggregate = StubAggregate::reconstituteWithoutConstructor();
        $aggregate->applyDomainEvent();
        $aggregate->clearDomainEvents();

        $this->assertCount(0, $aggregate->peekDomainEvents());
    }

    // ─── ValueObject Contract Tests ──────────────────────────────────

    #[\PHPUnit\Framework\Attributes\Test]
    public function value_object_equality_is_structural(): void
    {
        $vo1 = StubValueObject::create(street: '123 Main', city: 'NYC');
        $vo2 = StubValueObject::create(street: '123 Main', city: 'NYC');
        $vo3 = StubValueObject::create(street: '456 Oak', city: 'LA');

        $this->assertTrue($vo1->equals($vo2));
        $this->assertFalse($vo1->equals($vo3));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function value_object_is_json_serializable(): void
    {
        $vo = StubValueObject::create(street: '123 Main', city: 'NYC');

        $json = json_encode($vo);

        $this->assertNotFalse($json);
        $decoded = json_decode($json, true);
        $this->assertSame('123 Main', $decoded['street']);
    }

    // ─── Domain Exception → Response Bridge ──────────────────────────

    #[\PHPUnit\Framework\Attributes\Test]
    public function domain_exception_to_error_array_has_required_keys(): void
    {
        $exception = \ZeroBoiler\Domain\Exceptions\InvalidStateDomainException::because('Test error');

        $errorArray = $exception->toErrorArray();

        $this->assertArrayHasKey('title', $errorArray);
        $this->assertArrayHasKey('detail', $errorArray);
        $this->assertArrayHasKey('code', $errorArray);
        $this->assertSame('INVALID_STATE', $errorArray['code']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function not_found_exception_produces_correct_error_code(): void
    {
        $exception = \ZeroBoiler\Domain\Exceptions\NotFoundDomainException::because('Not found');

        $this->assertSame('NOT_FOUND', $exception->errorCode());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function conflict_exception_produces_correct_error_code(): void
    {
        $exception = \ZeroBoiler\Domain\Exceptions\ConflictDomainException::because('Conflict');

        $this->assertSame('CONFLICT', $exception->errorCode());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function optimistic_lock_exception_includes_version_info(): void
    {
        $exception = \ZeroBoiler\Domain\Exceptions\OptimisticLockException::for(
            aggregateId: 'order-123',
            expectedVersion: 5,
            actualVersion: 3,
        );

        $this->assertSame('OPTIMISTIC_LOCK', $exception->errorCode());
        $this->assertStringContainsString('5', $exception->getMessage());
        $this->assertStringContainsString('3', $exception->getMessage());
    }

    // ─── DomainResponseFactory Integration ───────────────────────────

    #[\PHPUnit\Framework\Attributes\Test]
    public function domain_response_factory_entity_includes_id(): void
    {
        $entity = $this->createStubEntity(id: 'entity-42');

        $response = DomainResponseFactory::entity($entity, ['name' => 'Test']);

        $data = $response->getData();
        $this->assertSame('entity-42', $data['_id'] ?? null);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function domain_response_factory_entity_includes_self_link(): void
    {
        $entity = $this->createStubEntity(id: 'entity-42');

        $response = DomainResponseFactory::entity($entity, ['name' => 'Test']);

        // Response includes links (accessible via toArray)
        $array = $response->toArray();
        $this->assertArrayHasKey('links', $array);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function domain_response_factory_created_returns_201_status(): void
    {
        $entity = $this->createStubEntity(id: 'new-entity');

        $response = DomainResponseFactory::created($entity, ['id' => 'new-entity']);

        $this->assertSame(201, $response->getStatus());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function domain_response_factory_deleted_returns_204_status(): void
    {
        $response = DomainResponseFactory::deleted();

        $this->assertSame(204, $response->getStatus());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function domain_response_factory_collection_nests_under_items(): void
    {
        $response = DomainResponseFactory::collection(
            [['id' => 1], ['id' => 2]],
            ['total' => 100],
        );

        $data = $response->getData();
        $this->assertArrayHasKey('items', $data);
        $this->assertCount(2, $data['items']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function domain_response_factory_error_from_exception(): void
    {
        $exception = \ZeroBoiler\Domain\Exceptions\InvalidStateDomainException::because('Cannot pay');

        $response = DomainResponseFactory::fromException($exception, status: 409);

        $this->assertSame(409, $response->getStatus());
        $this->assertNotEmpty($response->getErrors());
    }

    // ─── DomainTransformer Integration ────────────────────────────

    #[\PHPUnit\Framework\Attributes\Test]
    public function domain_transformer_extract_base_array_uses_to_array(): void
    {
        $entity = $this->createStubEntity(id: 'base-1');
        $transformer = new StubDomainTransformer;

        $result = $transformer->transform($entity);

        $this->assertSame('base-1', $result['id']);
        $this->assertSame('StubEntity', $result['type']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function domain_transformer_transform_collection(): void
    {
        $entities = [
            $this->createStubEntity(id: 'col-1'),
            $this->createStubEntity(id: 'col-2'),
        ];
        $transformer = new StubDomainTransformer;

        $results = $transformer->transformCollection($entities);

        $this->assertCount(2, $results);
        $this->assertSame('col-1', $results[0]['id']);
        $this->assertSame('col-2', $results[1]['id']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function domain_transformer_should_include_checks_context(): void
    {
        $transformer = new StubDomainTransformer;

        // Without includes in context
        $this->assertFalse($transformer->testShouldInclude('items', []));

        // With includes in context
        $this->assertTrue($transformer->testShouldInclude('items', ['includes' => ['items']]));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function domain_transformer_extract_version_from_aggregate(): void
    {
        $aggregate = StubAggregate::reconstituteWithoutConstructor();
        $aggregate->applyDomainEvent(); // version = 1
        $aggregate->applyDomainEvent(); // version = 2

        $transformer = new class extends DomainTransformer {
            protected function mapDomainFields(object $entity, array $context = []): array
            {
                return ['version' => $this->extractVersion($entity)];
            }
        };

        $result = $transformer->transform($aggregate);

        $this->assertSame(2, $result['version']);
    }

    // ─── ViewModel Integration ──────────────────────────────────────

    #[\PHPUnit\Framework\Attributes\Test]
    public function view_model_from_array_creates_instance(): void
    {
        $vm = StubViewModel::fromArray(['name' => 'John', 'email' => 'john@example.com']);

        $this->assertSame('John', $vm->toArray()['name']);
        $this->assertSame('john@example.com', $vm->toArray()['email']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function view_model_from_json_creates_instance(): void
    {
        $vm = StubViewModel::fromJson('{"name": "Jane", "email": "jane@example.com"}');

        $this->assertSame('Jane', $vm->toArray()['name']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function view_model_collection_creates_multiple_instances(): void
    {
        $vms = StubViewModel::collection([
            ['name' => 'A', 'email' => 'a@test.com'],
            ['name' => 'B', 'email' => 'b@test.com'],
        ]);

        $this->assertCount(2, $vms);
        $this->assertSame('A', $vms[0]->toArray()['name']);
        $this->assertSame('B', $vms[1]->toArray()['name']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function view_model_with_merges_additional_data(): void
    {
        $vm = StubViewModel::fromArray(['name' => 'John', 'email' => 'john@example.com'])
            ->with('role', 'admin')
            ->with(['active' => true, 'verified' => true]);

        $data = $vm->toArray();
        $this->assertSame('admin', $data['role']);
        $this->assertTrue($data['active']);
        $this->assertTrue($data['verified']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function view_model_with_when_conditional_data(): void
    {
        $vm = StubViewModel::fromArray(['name' => 'John', 'email' => 'john@example.com'])
            ->withWhen(fn () => true, 'flag_true', 'yes')
            ->withWhen(fn () => false, 'flag_false', 'no');

        $data = $vm->toArray();
        $this->assertSame('yes', $data['flag_true']);
        $this->assertArrayNotHasKey('flag_false', $data);
    }

    // ─── Immutable Identifier Contract ──────────────────────────────

    #[\PHPUnit\Framework\Attributes\Test]
    public function uuid_identifier_is_immutable(): void
    {
        $reflection = new \ReflectionClass(UuidIdentifier::class);

        $this->assertTrue($reflection->isReadOnly());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function ulid_identifier_is_immutable(): void
    {
        $reflection = new \ReflectionClass(UlidIdentifier::class);

        $this->assertTrue($reflection->isReadOnly());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function string_identifier_is_immutable(): void
    {
        $reflection = new \ReflectionClass(StringIdentifier::class);

        $this->assertTrue($reflection->isReadOnly());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function integer_identifier_is_immutable(): void
    {
        $reflection = new \ReflectionClass(IntegerIdentifier::class);

        $this->assertTrue($reflection->isReadOnly());
    }

    // ─── Serialization Round-Trip ───────────────────────────────────

    #[\PHPUnit\Framework\Attributes\Test]
    public function uuid_identifier_json_round_trip(): void
    {
        $original = UuidIdentifier::generate();
        $json = json_encode($original);
        $array = json_decode($json, true);
        $restored = UuidIdentifier::fromString($array);

        $this->assertSame($original->toString(), $restored->toString());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function ulid_identifier_json_round_trip(): void
    {
        $original = UlidIdentifier::generate();
        $json = json_encode($original);
        $array = json_decode($json, true);
        $restored = UlidIdentifier::fromString($array);

        $this->assertSame($original->toString(), $restored->toString());
    }

    // ─── Strict Types Verification ───────────────────────────────────

    #[\PHPUnit\Framework\Attributes\Test]
    public function all_domain_classes_use_strict_types(): void
    {
        $classes = [
            AggregateRoot::class,
            AggregateRootId::class,
            \ZeroBoiler\Domain\Entity::class,
            ValueObject::class,
            UuidIdentifier::class,
            UlidIdentifier::class,
            StringIdentifier::class,
            IntegerIdentifier::class,
            \ZeroBoiler\Domain\DomainEventCollection::class,
        ];

        foreach ($classes as $class) {
            $reflection = new \ReflectionClass($class);
            $file = $reflection->getFileName();
            $contents = file_get_contents($file);
            $this->assertStringContainsString(
                'declare(strict_types=1)',
                $contents,
                "{$class} must use declare(strict_types=1)",
            );
        }
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function all_response_classes_use_strict_types(): void
    {
        $classes = [
            \ZeroBoiler\Response\ApiResponse::class,
            \ZeroBoiler\Response\InertiaResponse::class,
            \ZeroBoiler\Response\ResponseManager::class,
            \ZeroBoiler\Response\Transformer::class,
            \ZeroBoiler\Response\ViewModel::class,
            DomainTransformer::class,
            DomainResponseFactory::class,
        ];

        foreach ($classes as $class) {
            $reflection = new \ReflectionClass($class);
            $file = $reflection->getFileName();
            $contents = file_get_contents($file);
            $this->assertStringContainsString(
                'declare(strict_types=1)',
                $contents,
                "{$class} must use declare(strict_types=1)",
            );
        }
    }

    // ─── Return Type Declarations ───────────────────────────────────

    #[\PHPUnit\Framework\Attributes\Test]
    public function domain_public_methods_have_return_types(): void
    {
        $classesToCheck = [
            AggregateRoot::class,
            \ZeroBoiler\Domain\Entity::class,
            ValueObject::class,
        ];

        foreach ($classesToCheck as $class) {
            $reflection = new \ReflectionClass($class);
            $methods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);

            foreach ($methods as $method) {
                if ($method->getDeclaringClass()->getName() !== $class) {
                    continue; // Skip inherited methods
                }
                if (str_starts_with($method->getName(), '__')) {
                    continue; // Skip magic methods
                }
                $this->assertNotNull(
                    $method->getReturnType(),
                    "{$class}::{$method->getName()}() must have a return type declaration",
                );
            }
        }
    }

    // ─── File Header Verification ────────────────────────────────────

    #[\PHPUnit\Framework\Attributes\Test]
    public function domain_source_files_have_license_header(): void
    {
        $dir = dirname((new \ReflectionClass(AggregateRoot::class))->getFileName());
        $files = glob($dir . '/{,*/,*/*/}*.php', GLOB_BRACE);

        foreach ($files as $file) {
            if (str_contains($file, '_ide_helper')) {
                continue;
            }

            $contents = file_get_contents($file);
            $this->assertStringContainsString(
                'This file is part of ZeroBoiler',
                $contents,
                "{$file} must have the ZeroBoiler license header",
            );
        }
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function response_source_files_have_license_header(): void
    {
        $dir = dirname((new \ReflectionClass(\ZeroBoiler\Response\ApiResponse::class))->getFileName());
        $files = glob($dir . '/{,*/,*/*/}*.php', GLOB_BRACE);

        foreach ($files as $file) {
            if (str_contains($file, '_ide_helper')) {
                continue;
            }

            $contents = file_get_contents($file);
            $this->assertStringContainsString(
                'This file is part of ZeroBoiler',
                $contents,
                "{$file} must have the ZeroBoiler license header",
            );
        }
    }

    // ─── Helper Methods ─────────────────────────────────────────────

    private function createStubEntity(string $id): object
    {
        return new StubEntity($id);
    }
}

// ─── Test Fixtures ───────────────────────────────────────────────────

/** @internal */
final class StubEntity extends \ZeroBoiler\Domain\Entity
{
    public function __construct(
        int|string|\Stringable $id,
        public readonly ?string $name = null,
    ) {
        parent::__construct($id);
    }

    public function toArray(): array
    {
        return [
            ...parent::toArray(),
            ...($this->name !== null ? ['name' => $this->name] : []),
        ];
    }
}

/** @internal */
final class StubAggregate extends AggregateRoot
{
    use EventSourced;
    use HasSnapshots;

    #[SnapshotPolicy(every: 10)]
    public string $status = 'pending';

    public static function reconstituteWithoutConstructor(): self
    {
        return \ReflectionClass::newInstanceWithoutConstructor(self::class);
    }

    public function applyDomainEvent(): void
    {
        $event = new DomainEvent(
            eventType: 'stub.activated',
            payload: ['id' => $this->id()],
        );
        $this->apply($event);
    }

    protected function applyStubActivated(DomainEvent $event): void
    {
        $this->status = 'activated';
    }
}

/** @internal */
final class StubValueObject extends ValueObject
{
    private function __construct(
        public readonly string $street,
        public readonly string $city,
    ) {}

    public static function create(string $street, string $city): self
    {
        return new self(street: $street, city: $city);
    }

    public static function fromArray(array $data): static
    {
        return new self(street: $data['street'], city: $data['city']);
    }

    public function toArray(): array
    {
        return [
            'street' => $this->street,
            'city' => $this->city,
        ];
    }
}

/** @internal */
final class StubDomainTransformer extends DomainTransformer
{
    protected function mapDomainFields(object $entity, array $context = []): array
    {
        return $this->extractBaseArray($entity);
    }

    public function testShouldInclude(string $relation, array $context): bool
    {
        return $this->shouldInclude($relation, $context);
    }
}

/** @internal */
final class StubViewModel extends \ZeroBoiler\Response\ViewModel
{
    public function __construct(
        public readonly string $name = '',
        public readonly string $email = '',
    ) {}

    protected function handle(): array
    {
        return [
            'name' => $this->name,
            'email' => $this->email,
        ];
    }
}
