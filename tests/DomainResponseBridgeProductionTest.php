<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Tests;

use PHPUnit\Framework\TestCase;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\Contracts\Identifier as IdentifierContract;
use ZeroBoiler\Domain\Identifiers\IntegerIdentifier;
use ZeroBoiler\Domain\Identifiers\StringIdentifier;
use ZeroBoiler\Domain\Identifiers\UuidIdentifier;
use ZeroBoiler\Domain\Identifiers\UlidIdentifier;
use ZeroBoiler\Domain\Exceptions\{
    AggregateNotFoundException,
    ConflictDomainException,
    DomainException,
    InvalidArgumentDomainException,
    InvalidStateDomainException,
    NotFoundDomainException,
    OptimisticLockException,
};

/**
 * Production verification tests for the domain → response bridge contract.
 *
 * Validates that domain layer outputs (identifiers, exceptions, entities)
 * produce predictable, well-typed structures suitable for API response mapping.
 * These tests ensure the response package can safely consume domain objects
 * via duck typing without compile-time coupling.
 *
 * @see \ZeroBoiler\Response\Transformers\DomainTransformer
 * @see \ZeroBoiler\Response\Transformers\DomainResponseFactory
 */
final class DomainResponseBridgeProductionTest extends TestCase
{
    // ─── Identifier JSON Serialization for API Responses ───────────

    public function test_aggregate_root_id_produces_string_for_json(): void
    {
        $id = AggregateRootId::generate();

        $json = json_encode($id);
        $decoded = json_decode($json, true);

        $this->assertIsString($decoded);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $decoded,
        );
    }

    public function test_all_identifier_types_are_json_safe(): void
    {
        $uuid = TestUuidIdentifier::generate();
        $ulid = TestUlidIdentifier::generate();
        $string = StringIdentifier::from('api-key-123');
        $integer = IntegerIdentifier::from(42);

        // All must serialize without error
        $this->assertNotEmpty(json_encode(['uuid' => $uuid, 'ulid' => $ulid, 'string' => $string, 'int' => $integer]));

        // Integer serializes as int, not string
        $this->assertSame(42, json_decode(json_encode($integer)));
    }

    public function test_identifier_equality_is_reflexive_symmetric_and_transitive(): void
    {
        $a = StringIdentifier::from('order-slug');
        $b = StringIdentifier::from('order-slug');
        $c = StringIdentifier::from('different-slug');

        // Reflexive
        $this->assertTrue($a->equals($a));

        // Symmetric
        $this->assertTrue($a->equals($b));
        $this->assertTrue($b->equals($a));

        // Transitive (a == b && b != c → a != c)
        $this->assertFalse($a->equals($c));
    }

    public function test_identifier_round_trip_preserves_identity(): void
    {
        $uuid = TestUuidIdentifier::generate();
        $ulid = TestUlidIdentifier::generate();
        $string = StringIdentifier::from('my-key');
        $integer = IntegerIdentifier::from(99);

        // UUID round-trip
        $restoredUuid = TestUuidIdentifier::fromString($uuid->toString());
        $this->assertTrue($uuid->equals($restoredUuid));

        // ULID round-trip
        $restoredUlid = TestUlidIdentifier::fromString($ulid->toString());
        $this->assertTrue($ulid->equals($restoredUlid));

        // String round-trip
        $restoredString = StringIdentifier::fromString($string->toString());
        $this->assertTrue($string->equals($restoredString));

        // Integer round-trip
        $restoredInt = IntegerIdentifier::fromString($integer->toString());
        $this->assertTrue($integer->equals($restoredInt));
    }

    // ─── Exception Error Arrays for API Bridge ───────────────────────

    public function test_domain_exception_to_error_array_has_required_keys(): void
    {
        $exceptions = [
            InvalidStateDomainException::because('Invalid state'),
            InvalidArgumentDomainException::because('Bad argument'),
            NotFoundDomainException::because('Not found'),
            ConflictDomainException::because('Conflict'),
            AggregateNotFoundException::for('Order', 'id-1'),
            OptimisticLockException::for('id-1', 5, 3),
        ];

        foreach ($exceptions as $exception) {
            $errorArray = $exception->toErrorArray();

            $this->assertArrayHasKey('title', $errorArray, get_class($exception) . ' missing title');
            $this->assertArrayHasKey('detail', $errorArray, get_class($exception) . ' missing detail');
            $this->assertArrayHasKey('code', $errorArray, get_class($exception) . ' missing code');
            $this->assertIsString($errorArray['title']);
            $this->assertIsString($errorArray['detail']);
            $this->assertIsString($errorArray['code']);
            $this->assertNotEmpty($errorArray['code']);
        }
    }

    public function test_domain_exception_error_codes_are_unique_per_type(): void
    {
        $codes = array_map(
            fn (string $class): string => (new $class('test'))->errorCode(),
            [
                InvalidStateDomainException::class,
                InvalidArgumentDomainException::class,
                NotFoundDomainException::class,
                ConflictDomainException::class,
                AggregateNotFoundException::class,
                OptimisticLockException::class,
            ],
        );

        $unique = array_unique($codes);
        $this->assertCount(count($codes), $unique, 'Error codes must be unique per exception type');
    }

    public function test_domain_exception_json_output_matches_to_error_array(): void
    {
        $exception = InvalidStateDomainException::because('Order must be pending', 'CUSTOM_CODE');

        $errorArray = $exception->toErrorArray();
        $jsonDecoded = json_decode(json_encode($exception), true);

        $this->assertSame($errorArray, $jsonDecoded);
        $this->assertSame('CUSTOM_CODE', $jsonDecoded['code']);
    }

    public function test_domain_exception_to_array_includes_debug_info(): void
    {
        $exception = NotFoundDomainException::because('User not found');

        $array = $exception->toArray();

        $this->assertArrayHasKey('error_code', $array);
        $this->assertArrayHasKey('message', $array);
        $this->assertArrayHasKey('file', $array);
        $this->assertArrayHasKey('line', $array);
        $this->assertIsInt($array['line']);
    }

    // ─── Aggregate toArray() Contract for DomainTransformer ─────────

    public function test_aggregate_to_array_provides_identity_and_type(): void
    {
        $aggregate = new TestAggregate;
        $array = $aggregate->toArray();

        // toArray() must provide the keys DomainTransformer.extractId() expects
        $this->assertArrayHasKey('id', $array);
        $this->assertArrayHasKey('version', $array);
        $this->assertArrayHasKey('type', $array);
        $this->assertIsString($array['id']);
        $this->assertIsInt($array['version']);
        $this->assertIsString($array['type']);
    }

    public function test_aggregate_id_method_returns_consistent_string(): void
    {
        $aggregate = new TestAggregate;

        // id() must always return a valid string (used by DomainResponseFactory.extractId())
        $id = $aggregate->id();
        $this->assertIsString($id);
        $this->assertNotEmpty($id);
        $this->assertSame($id, $aggregate->id()); // Idempotent
    }

    // ─── Entity duck-typing contract for response layer ─────────────

    public function test_entity_duck_type_interface_is_satisfied(): void
    {
        $entity = new class(AggregateRootId::generate()) extends \ZeroBoiler\Domain\Entity {};

        // Response layer checks method_exists($entity, 'id')
        $this->assertTrue(method_exists($entity, 'id'));
        $this->assertTrue(method_exists($entity, 'equals'));

        $id = $entity->id();
        $this->assertIsString($id);
    }

    public function test_aggregate_root_duck_type_interface_is_satisfied(): void
    {
        $aggregate = new TestAggregate;

        // Response layer checks method_exists($entity, 'version')
        $this->assertTrue(method_exists($aggregate, 'id'));
        $this->assertTrue(method_exists($aggregate, 'version'));
        $this->assertTrue(method_exists($aggregate, 'equals'));

        $id = $aggregate->id();
        $version = $aggregate->version();

        $this->assertIsString($id);
        $this->assertIsInt($version);
        $this->assertGreaterThanOrEqual(0, $version);
    }

    // ─── Domain Event Collection JSON for response mapping ──────────

    public function test_domain_event_collection_json_is_list_of_objects(): void
    {
        $e1 = \ZeroBoiler\Events\Domain\DomainEvent::occur('order.created', ['id' => 'abc']);
        $e2 = \ZeroBoiler\Events\Domain\DomainEvent::occur('order.paid', ['amount' => 100]);
        $e3 = \ZeroBoiler\Events\Domain\DomainEvent::occur('order.shipped', ['tracking' => 'XYZ']);

        $collection = new \ZeroBoiler\Domain\DomainEventCollection([$e1, $e2, $e3]);
        $json = json_encode($collection);
        $decoded = json_decode($json, true);

        // Must be a sequential list (array_is_list)
        $this->assertTrue(array_is_list($decoded));
        $this->assertCount(3, $decoded);
        $this->assertArrayHasKey('event_type', $decoded[0]);
        $this->assertArrayHasKey('payload', $decoded[0]);
    }

    public function test_empty_event_collection_serializes_to_empty_array(): void
    {
        $collection = new \ZeroBoiler\Domain\DomainEventCollection;

        $this->assertSame([], $collection->toArray());
        $this->assertSame([], json_decode(json_encode($collection), true));
    }

    // ─── InvalidStateException is NOT a DomainException ─────────────

    public function test_invalid_state_exception_is_general_not_domain(): void
    {
        $exception = \ZeroBoiler\Domain\Exceptions\InvalidStateException::because('Bad config');

        // Must NOT be a DomainException — used outside domain layer
        $this->assertNotInstanceOf(DomainException::class, $exception);
        $this->assertInstanceOf(\Exception::class, $exception);
    }

    public function test_invalid_aggregate_root_exception_provides_class_info(): void
    {
        $obj = new \stdClass;
        $exception = \ZeroBoiler\Domain\Exceptions\InvalidAggregateRootException::notAnAggregate($obj);

        $this->assertStringContainsString('stdClass', $exception->getMessage());
        $this->assertSame('INVALID_AGGREGATE_ROOT', $exception->errorCode());
    }
}
