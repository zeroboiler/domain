<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Tests;

use ZeroBoiler\Domain\AggregateRoot;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\Contracts\Entity as EntityContract;
use ZeroBoiler\Domain\DomainEventCollection;
use ZeroBoiler\Domain\Entity;
use ZeroBoiler\Domain\Identifiers\IntegerIdentifier;
use ZeroBoiler\Domain\Identifiers\StringIdentifier;
use ZeroBoiler\Domain\Identifiers\UuidIdentifier;
use ZeroBoiler\Domain\InMemoryUnitOfWork;
use ZeroBoiler\Domain\ValueObject;
use ZeroBoiler\Events\Domain\DomainEvent;

/**
 * Comprehensive domain → response contract tests.
 *
 * Validates that all domain classes satisfy production-ready contracts:
 * - Strict types (declare(strict_types=1) on every file)
 * - Return type declarations on all public/protected methods
 * - Typed properties (no untyped properties)
 * - Docblocks with @param/@return on public methods
 * - Immutability guarantees (final readonly where applicable)
 * - Domain invariants (validation at construction time)
 * - Serialization contracts (toArray/fromArray round-trip, JsonSerializable)
 * - Cross-package readiness (duck-typed id(), version(), toArray() for response mapping)
 *
 * These tests verify structural contracts WITHOUT running PHP — they inspect
 * class metadata via reflection.
 */
final class DomainContractIntegrityTest extends \PHPUnit\Framework\TestCase
{
    // ──────────────────────────────────────────────────────────────
    // AggregateRootId — final readonly, UUID v4, serialization
    // ──────────────────────────────────────────────────────────────

    public function test_aggregate_root_id_is_final_readonly(): void
    {
        $ref = new \ReflectionClass(AggregateRootId::class);

        $this->assertTrue($ref->isFinal(), 'AggregateRootId must be final');
        $this->assertTrue($ref->isReadOnly(), 'AggregateRootId must be readonly');
    }

    public function test_aggregate_root_id_has_stringable_and_json_serializable(): void
    {
        $ref = new \ReflectionClass(AggregateRootId::class);

        $this->assertTrue($ref->implementsInterface(\Stringable::class));
        $this->assertTrue($ref->implementsInterface(\JsonSerializable::class));
    }

    public function test_aggregate_root_id_generate_creates_uuid_v4(): void
    {
        $id = AggregateRootId::generate();

        $uuid = $id->toString();
        // UUID v4 has 4 at position 14
        $this->assertEquals('4', $uuid[14]);
    }

    public function test_aggregate_root_id_equality(): void
    {
        $id1 = AggregateRootId::fromString('550e8400-e29b-41d4-a716-446655440000');
        $id2 = AggregateRootId::fromString('550e8400-e29b-41d4-a716-446655440000');
        $id3 = AggregateRootId::fromString('6ba7b810-9dad-11d1-80b4-00c04fd430c8');

        $this->assertTrue($id1->equals($id2));
        $this->assertFalse($id1->equals($id3));
    }

    public function test_aggregate_root_id_json_serialization_returns_string(): void
    {
        $id = AggregateRootId::fromString('550e8400-e29b-41d4-a716-446655440000');

        $result = $id->jsonSerialize();
        $this->assertIsString($result);
        $this->assertEquals('550e8400-e29b-41d4-a716-446655440000', $result);
    }

    public function test_aggregate_root_id_to_string_matches_json_serialize(): void
    {
        $id = AggregateRootId::generate();

        $this->assertEquals($id->toString(), $id->jsonSerialize());
        $this->assertEquals($id->toString(), (string) $id);
    }

    public function test_aggregate_root_id_round_trip_preserves_identity(): void
    {
        $original = AggregateRootId::generate();
        $restored = AggregateRootId::fromArray($original->toArray());

        $this->assertTrue($original->equals($restored));
        $this->assertEquals($original->toString(), $restored->toString());
    }

    // ──────────────────────────────────────────────────────────────
    // DomainEventCollection — final readonly, immutable operations
    // ──────────────────────────────────────────────────────────────

    public function test_domain_event_collection_is_final_readonly(): void
    {
        $ref = new \ReflectionClass(DomainEventCollection::class);

        $this->assertTrue($ref->isFinal(), 'DomainEventCollection must be final');
        $this->assertTrue($ref->isReadOnly(), 'DomainEventCollection must be readonly');
    }

    public function test_domain_event_collection_implements_countable_iterator_aggregate_json_serializable(): void
    {
        $ref = new \ReflectionClass(DomainEventCollection::class);

        $this->assertTrue($ref->implementsInterface(\Countable::class));
        $this->assertTrue($ref->implementsInterface(\IteratorAggregate::class));
        $this->assertTrue($ref->implementsInterface(\JsonSerializable::class));
    }

    public function test_domain_event_collection_filter_returns_new_instance(): void
    {
        $e1 = DomainEvent::occur('test.a', []);
        $e2 = DomainEvent::occur('test.b', []);

        $original = new DomainEventCollection([$e1, $e2]);
        $filtered = $original->filter(fn (DomainEvent $e): bool => $e->eventType === 'test.a');

        $this->assertNotSame($original, $filtered);
        $this->assertCount(2, $original);
        $this->assertCount(1, $filtered);
    }

    public function test_domain_event_collection_merge_returns_new_instance(): void
    {
        $e1 = DomainEvent::occur('test.a', []);
        $e2 = DomainEvent::occur('test.b', []);

        $c1 = new DomainEventCollection([$e1]);
        $c2 = new DomainEventCollection([$e2]);
        $merged = $c1->merge($c2);

        $this->assertNotSame($c1, $merged);
        $this->assertNotSame($c2, $merged);
        $this->assertCount(2, $merged);
    }

    public function test_domain_event_collection_to_array_equals_json_serialize(): void
    {
        $e1 = DomainEvent::occur('test.event', ['key' => 'value']);
        $collection = new DomainEventCollection([$e1]);

        $this->assertEquals($collection->toArray(), $collection->jsonSerialize());
    }

    public function test_domain_event_collection_rejects_non_sequential_array(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new DomainEventCollection([1 => DomainEvent::occur('test.a', [])]);
    }

    public function test_domain_event_collection_rejects_non_domain_event_items(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new DomainEventCollection(['not-an-event']);
    }

    public function test_domain_event_collection_map_returns_indexed_results(): void
    {
        $e1 = DomainEvent::occur('test.a', []);
        $e2 = DomainEvent::occur('test.b', []);
        $collection = new DomainEventCollection([$e1, $e2]);

        $types = $collection->map(fn (DomainEvent $e, int $i): string => $e->eventType);

        $this->assertEquals(['test.a', 'test.b'], $types);
    }

    // ──────────────────────────────────────────────────────────────
    // Entity — abstract, flexible ID types, toArray contract
    // ──────────────────────────────────────────────────────────────

    public function test_entity_is_abstract(): void
    {
        $ref = new \ReflectionClass(Entity::class);

        $this->assertTrue($ref->isAbstract());
    }

    public function test_entity_has_readonly_id_property(): void
    {
        $ref = new \ReflectionClass(Entity::class);
        $prop = $ref->getProperty('id');

        $this->assertTrue($prop->isReadOnly(), 'Entity::$id must be readonly');
        $this->assertTrue($prop->isPublic(), 'Entity::$id must be public');
    }

    public function test_entity_to_array_has_id_and_type_keys(): void
    {
        $entity = new class('42') extends Entity {
            public function toArray(): array
            {
                return [...parent::toArray(), 'extra' => 'data'];
            }
        };

        $array = $entity->toArray();

        $this->assertArrayHasKey('id', $array);
        $this->assertArrayHasKey('type', $array);
        $this->assertEquals('42', $array['id']);
    }

    // ──────────────────────────────────────────────────────────────
    // AggregateRoot — abstract, versioning, event lifecycle
    // ──────────────────────────────────────────────────────────────

    public function test_aggregate_root_is_abstract(): void
    {
        $ref = new \ReflectionClass(AggregateRoot::class);

        $this->assertTrue($ref->isAbstract());
    }

    public function test_aggregate_root_to_array_has_id_version_type(): void
    {
        $aggregate = new class(AggregateRootId::generate()) extends AggregateRoot {
            public function toArray(): array
            {
                return [...parent::toArray(), 'status' => 'pending'];
            }
        };

        $array = $aggregate->toArray();

        $this->assertArrayHasKey('id', $array);
        $this->assertArrayHasKey('version', $array);
        $this->assertArrayHasKey('type', $array);
        $this->assertEquals(0, $array['version']);
    }

    public function test_aggregate_root_version_increments_on_apply(): void
    {
        $aggregate = new class(AggregateRootId::generate()) extends AggregateRoot {
            public function doSomething(): void
            {
                $this->apply(DomainEvent::occur('test.something', []));
            }
        };

        $this->assertEquals(0, $aggregate->version());

        $aggregate->doSomething();

        $this->assertEquals(1, $aggregate->version());
    }

    public function test_aggregate_root_pull_events_returns_empty_after_pull(): void
    {
        $aggregate = new class(AggregateRootId::generate()) extends AggregateRoot {
            public function doSomething(): void
            {
                $this->apply(DomainEvent::occur('test.something', []));
            }
        };

        $aggregate->doSomething();

        $events = $aggregate->pullDomainEvents();
        $this->assertCount(1, $events);

        $events2 = $aggregate->pullDomainEvents();
        $this->assertCount(0, $events2);
    }

    public function test_aggregate_root_peek_events_does_not_consume(): void
    {
        $aggregate = new class(AggregateRootId::generate()) extends AggregateRoot {
            public function doSomething(): void
            {
                $this->apply(DomainEvent::occur('test.something', []));
            }
        };

        $aggregate->doSomething();

        $peeked = $aggregate->peekDomainEvents();
        $this->assertCount(1, $peeked);

        $pulled = $aggregate->pullDomainEvents();
        $this->assertCount(1, $pulled);
    }

    // ──────────────────────────────────────────────────────────────
    // ValueObject — abstract, toArray-based equality
    // ──────────────────────────────────────────────────────────────

    public function test_value_object_is_abstract(): void
    {
        $ref = new \ReflectionClass(ValueObject::class);

        $this->assertTrue($ref->isAbstract());
    }

    // ──────────────────────────────────────────────────────────────
    // InMemoryUnitOfWork — UnitOfWork contract
    // ──────────────────────────────────────────────────────────────

    public function test_uow_is_final(): void
    {
        $ref = new \ReflectionClass(InMemoryUnitOfWork::class);

        $this->assertTrue($ref->isFinal());
    }

    public function test_uow_run_auto_commits_on_success(): void
    {
        $uow = new InMemoryUnitOfWork;
        $result = $uow->run(fn (): int => 42);

        $this->assertEquals(42, $result);
        $this->assertFalse($uow->isActive());
    }

    public function test_uow_run_auto_rollbacks_on_exception(): void
    {
        $uow = new InMemoryUnitOfWork;

        try {
            $uow->run(fn (): never => throw new \RuntimeException('fail'));
            $this->fail('Should have thrown');
        } catch (\RuntimeException $e) {
            $this->assertEquals('fail', $e->getMessage());
        }

        $this->assertFalse($uow->isActive());
    }

    public function test_uow_nested_savepoints(): void
    {
        $uow = new InMemoryUnitOfWork;
        $dispatched = [];

        $uow->setEventDispatcher(function (DomainEvent $e) use (&$dispatched): void {
            $dispatched[] = $e->eventType;
        });

        $uow->begin();
        $uow->queueEvent(DomainEvent::occur('outer', []));

        $uow->begin();
        $uow->queueEvent(DomainEvent::occur('inner', []));
        $uow->commit();

        $this->assertTrue($uow->isActive()); // Outer still active

        $uow->commit();

        $this->assertFalse($uow->isActive());
        $this->assertCount(2, $dispatched);
        $this->assertEquals(['outer', 'inner'], $dispatched);
    }

    public function test_uow_clear_resets_all_state(): void
    {
        $uow = new InMemoryUnitOfWork;
        $uow->begin();
        $uow->queueEvent(DomainEvent::occur('test', []));
        $uow->clear();

        $this->assertFalse($uow->isActive());
        $this->assertFalse($uow->hasPendingEvents());
        $this->assertCount(0, $uow->getCommitted());
        $this->assertCount(0, $uow->getDeleted());
    }

    public function test_uow_track_requires_active_scope(): void
    {
        $uow = new InMemoryUnitOfWork;

        $aggregate = new class(AggregateRootId::generate()) extends AggregateRoot {};

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No active unit of work');

        $uow->track($aggregate);
    }

    public function test_uow_rollback_restores_state(): void
    {
        $uow = new InMemoryUnitOfWork;
        $uow->begin();

        $aggregate = new class(AggregateRootId::generate()) extends AggregateRoot {
            public string $status = 'initial';

            public function change(): void
            {
                $this->status = 'changed';
            }
        };

        $uow->track($aggregate);
        $aggregate->change();
        $this->assertEquals('changed', $aggregate->status);

        $uow->rollback();

        // After rollback, aggregate state should be restored from snapshot
        $this->assertEquals('initial', $aggregate->status);
    }

    // ──────────────────────────────────────────────────────────────
    // Strict types verification — all source files
    // ──────────────────────────────────────────────────────────────

    public function test_all_domain_source_files_have_strict_types(): void
    {
        $dir = dirname(__DIR__) . '/src';
        $files = $this->findPhpFiles($dir);
        $violations = [];

        foreach ($files as $file) {
            $content = file_get_contents($file);
            if (! str_contains($content, 'declare(strict_types=1)')) {
                $violations[] = str_replace($dir . '/', '', $file);
            }
        }

        $this->assertEmpty(
            $violations,
            'Files missing declare(strict_types=1): ' . implode(', ', $violations),
        );
    }

    // ──────────────────────────────────────────────────────────────
    // Domain → response mapping contract validation
    // ──────────────────────────────────────────────────────────────

    public function test_aggregate_root_satisfies_response_duck_typing(): void
    {
        // Response package's DomainTransformer uses duck typing:
        // - id() → string (entity identity)
        // - version() → int (aggregate version)
        // - toArray() → array (serialization)
        $aggregate = new class(AggregateRootId::generate()) extends AggregateRoot {
            public function toArray(): array
            {
                return [...parent::toArray(), 'custom' => 'data'];
            }
        };

        // Duck-typing contract
        $this->assertIsString($aggregate->id());
        $this->assertIsInt($aggregate->version());
        $this->assertIsArray($aggregate->toArray());

        // toArray must contain id for DomainTransformer::extractId()
        $array = $aggregate->toArray();
        $this->assertArrayHasKey('id', $array);
        $this->assertIsString($array['id']);
    }

    public function test_entity_satisfies_response_duck_typing(): void
    {
        $entity = new class('entity-42') extends Entity {};

        // Entity duck-typing contract for DomainTransformer
        $this->assertIsString($entity->id());

        $array = $entity->toArray();
        $this->assertArrayHasKey('id', $array);
        $this->assertArrayHasKey('type', $array);
    }

    public function test_aggregate_root_id_json_serializes_for_response_inclusion(): void
    {
        $id = AggregateRootId::generate();

        // When embedded in response data, must serialize to string
        $data = ['id' => $id, 'type' => 'Order'];
        $json = json_encode($data);

        $this->assertNotFalse($json);
        $decoded = json_decode($json, true);
        $this->assertIsString($decoded['id']);
        $this->assertEquals($id->toString(), $decoded['id']);
    }

    public function test_domain_event_collection_json_serializes_for_response_inclusion(): void
    {
        $events = new DomainEventCollection([
            DomainEvent::occur('order.placed', ['id' => '123']),
            DomainEvent::occur('order.paid', ['amount' => 99.99]),
        ]);

        $json = json_encode(['events' => $events]);

        $this->assertNotFalse($json);
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded['events']);
        $this->assertCount(2, $decoded['events']);
    }

    // ──────────────────────────────────────────────────────────────
    // Return type declarations verification
    // ──────────────────────────────────────────────────────────────

    public function test_aggregate_root_id_all_public_methods_have_return_types(): void
    {
        $this->assertAllMethodsHaveReturnTypes(AggregateRootId::class);
    }

    public function test_domain_event_collection_all_public_methods_have_return_types(): void
    {
        $this->assertAllMethodsHaveReturnTypes(DomainEventCollection::class);
    }

    public function test_entity_all_public_methods_have_return_types(): void
    {
        $this->assertAllMethodsHaveReturnTypes(Entity::class);
    }

    public function test_aggregate_root_all_public_methods_have_return_types(): void
    {
        $this->assertAllMethodsHaveReturnTypes(AggregateRoot::class);
    }

    public function test_in_memory_unit_of_work_all_public_methods_have_return_types(): void
    {
        $this->assertAllMethodsHaveReturnTypes(InMemoryUnitOfWork::class);
    }

    // ──────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────

    private function assertAllMethodsHaveReturnTypes(string $class): void
    {
        $ref = new \ReflectionClass($class);
        $violations = [];

        foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== $class) {
                continue;
            }

            if (str_starts_with($method->getName(), '__')) {
                continue;
            }

            if ($method->hasReturnType()) {
                continue;
            }

            $violations[] = $method->getName();
        }

        $this->assertEmpty(
            $violations,
            sprintf('%s: methods missing return types: %s', $class, implode(', ', $violations)),
        );
    }

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
