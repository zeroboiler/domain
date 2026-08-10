<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Tests;

use PHPUnit\Framework\TestCase;
use ZeroBoiler\Domain\AggregateRoot;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\Entity;
use ZeroBoiler\Domain\Exceptions\AggregateNotFoundException;
use ZeroBoiler\Domain\Exceptions\ConflictDomainException;
use ZeroBoiler\Domain\Exceptions\InvalidArgumentDomainException;
use ZeroBoiler\Domain\Exceptions\InvalidStateDomainException;
use ZeroBoiler\Domain\Exceptions\InvalidAggregateRootException;
use ZeroBoiler\Domain\Exceptions\NotFoundDomainException;
use ZeroBoiler\Domain\Exceptions\OptimisticLockException;
use ZeroBoiler\Domain\Identifiers\IntegerIdentifier;
use ZeroBoiler\Domain\Identifiers\StringIdentifier;
use ZeroBoiler\Domain\Identifiers\UuidIdentifier;
use ZeroBoiler\Domain\Identifiers\UlidIdentifier;
use ZeroBoiler\Domain\Tests\Fixtures\TestAggregate;
use ZeroBoiler\Domain\Tests\Fixtures\TestEntity;
use ZeroBoiler\Domain\Tests\Fixtures\TestUuidIdentifier;
use ZeroBoiler\Domain\Tests\Fixtures\TestUlidIdentifier;
use ZeroBoiler\Domain\Tests\Fixtures\TestValueObject;
use ZeroBoiler\Domain\ValueObject;
use ZeroBoiler\Events\Domain\DomainEvent;

/**
 * Cross-package serialization contract tests for the domain package.
 *
 * Verifies that all domain primitives produce consistent, valid serialization
 * output suitable for API response bridging via zeroboiler/response's
 * DomainTransformer and DomainResponseFactory.
 *
 * Covers:
 * - AggregateRootId JSON serialization (extractId duck-typing)
 * - All identifier types: UUID, ULID, String, Integer JSON output
 * - Entity::toArray() produces id + type keys
 * - AggregateRoot::toArray() produces id + version + type keys
 * - AggregateRoot version incrementation after apply()
 * - DomainException::toErrorArray() RFC 9457 structure for all 7 types
 * - DomainException custom error code override
 * - DomainException jsonSerialize() matches toErrorArray()
 * - ValueObject equality symmetry and toArray() JSON safety
 * - Cross-identifier type inequality (different types never compare equal)
 *
 * @since 1.48.0
 */
final class DomainCrossPackageSerdeContractTest extends TestCase
{
    // ─────────────────────────────────────────────────────────
    // AggregateRootId serialization (used by DomainTransformer::extractId)
    // ─────────────────────────────────────────────────────────

    public function test_aggregate_root_id_serializes_to_string(): void
    {
        $id = AggregateRootId::generate();

        $serialized = json_encode($id);
        $this->assertIsString($serialized);
        $this->assertNotSame('""', $serialized);

        // Must be a valid UUID v4 format (wrapped in quotes by JSON)
        $decoded = json_decode($serialized, true);
        $this->assertStringMatchesFormat('%x-%x-%x-%x-%x', $decoded);
    }

    public function test_aggregate_root_id_round_trip_via_json(): void
    {
        $id = AggregateRootId::generate();
        $json = json_encode($id);

        $restored = AggregateRootId::fromString(json_decode($json, true));
        $this->assertTrue($id->equals($restored));
    }

    public function test_aggregate_root_id_to_string_matches_json_value(): void
    {
        $id = AggregateRootId::generate();
        $this->assertSame($id->toString(), json_decode(json_encode($id), true));
    }

    // ─────────────────────────────────────────────────────────
    // UuidIdentifier serialization (used by extractId duck-typing)
    // ─────────────────────────────────────────────────────────

    public function test_uuid_identifier_json_serialization(): void
    {
        $id = TestUuidIdentifier::generate();
        $json = json_encode($id);

        $this->assertIsString($json);
        $this->assertStringMatchesFormat('"%x-%x-%x-%x-%x"', $json);
    }

    // ─────────────────────────────────────────────────────────
    // UlidIdentifier serialization
    // ─────────────────────────────────────────────────────────

    public function test_ulid_identifier_json_serialization(): void
    {
        $id = TestUlidIdentifier::generate();
        $json = json_encode($id);

        $this->assertIsString($json);
        // ULIDs are 26 characters
        $value = json_decode($json, true);
        $this->assertSame(26, strlen($value));
    }

    // ─────────────────────────────────────────────────────────
    // StringIdentifier serialization
    // ─────────────────────────────────────────────────────────

    public function test_string_identifier_json_serialization(): void
    {
        $id = StringIdentifier::from('my-slug');
        $this->assertSame('"my-slug"', json_encode($id));
    }

    // ─────────────────────────────────────────────────────────
    // IntegerIdentifier serialization
    // ─────────────────────────────────────────────────────────

    public function test_integer_identifier_json_serialization(): void
    {
        $id = IntegerIdentifier::from(42);
        $this->assertSame('42', json_encode($id));
    }

    // ─────────────────────────────────────────────────────────
    // Entity toArray (used by DomainTransformer::extractBaseArray)
    // ─────────────────────────────────────────────────────────

    public function test_entity_to_array_has_id_and_type(): void
    {
        $entity = new TestEntity('123');

        $array = $entity->toArray();

        $this->assertArrayHasKey('id', $array);
        $this->assertArrayHasKey('type', $array);
        $this->assertSame('123', $array['id']);
        $this->assertSame('TestEntity', $array['type']);
    }

    public function test_entity_to_array_is_json_serializable(): void
    {
        $entity = new TestEntity('456');

        $json = json_encode($entity->toArray());
        $decoded = json_decode($json, true);

        $this->assertSame('456', $decoded['id']);
        $this->assertSame('TestEntity', $decoded['type']);
    }

    public function test_entity_to_array_with_int_id(): void
    {
        $entity = new TestEntity(789);

        $array = $entity->toArray();
        $this->assertSame('789', $array['id']);
    }

    // ─────────────────────────────────────────────────────────
    // AggregateRoot toArray (includes version)
    // ─────────────────────────────────────────────────────────

    public function test_aggregate_root_to_array_has_id_version_and_type(): void
    {
        $aggregate = TestAggregate::create(AggregateRootId::generate());

        $array = $aggregate->toArray();

        $this->assertArrayHasKey('id', $array);
        $this->assertArrayHasKey('version', $array);
        $this->assertArrayHasKey('type', $array);
        $this->assertSame('TestAggregate', $array['type']);
    }

    public function test_aggregate_root_version_increments_after_apply(): void
    {
        $id = AggregateRootId::generate();
        $aggregate = TestAggregate::create($id);

        $initialVersion = $aggregate->version();
        $aggregate->rename('New Name');

        $this->assertSame($initialVersion + 1, $aggregate->version());
    }

    public function test_aggregate_root_to_array_version_reflects_current(): void
    {
        $id = AggregateRootId::generate();
        $aggregate = TestAggregate::create($id);

        $v0 = $aggregate->toArray()['version'];
        $aggregate->rename('Updated');
        $v1 = $aggregate->toArray()['version'];

        $this->assertSame($v0 + 1, $v1);
    }

    // ─────────────────────────────────────────────────────────
    // DomainException RFC 9457 serialization (used by
    // DomainResponseFactory::error())
    // ─────────────────────────────────────────────────────────

    /**
     * @dataProvider exceptionTypeProvider
     */
    public function test_domain_exception_to_error_array_has_required_keys(string $exceptionClass, string $expectedCode): void
    {
        $exception = match ($exceptionClass) {
            InvalidStateDomainException::class => InvalidStateDomainException::because('test'),
            InvalidArgumentDomainException::class => InvalidArgumentDomainException::because('test'),
            NotFoundDomainException::class => NotFoundDomainException::because('test'),
            ConflictDomainException::class => ConflictDomainException::because('test'),
            OptimisticLockException::class => OptimisticLockException::for('id', 1, 2),
            AggregateNotFoundException::class => AggregateNotFoundException::for('Type', 'id'),
            InvalidAggregateRootException::class => InvalidAggregateRootException::notAnAggregate(new \stdClass),
            default => throw new \LogicException("Unknown exception: {$exceptionClass}"),
        };

        $errorArray = $exception->toErrorArray();

        $this->assertArrayHasKey('title', $errorArray);
        $this->assertArrayHasKey('detail', $errorArray);
        $this->assertArrayHasKey('code', $errorArray);
        $this->assertSame($expectedCode, $errorArray['code']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function exceptionTypeProvider(): iterable
    {
        yield 'InvalidStateDomainException' => [InvalidStateDomainException::class, 'INVALID_STATE'];
        yield 'InvalidArgumentDomainException' => [InvalidArgumentDomainException::class, 'INVALID_ARGUMENT'];
        yield 'NotFoundDomainException' => [NotFoundDomainException::class, 'NOT_FOUND'];
        yield 'AggregateNotFoundException' => [AggregateNotFoundException::class, 'AGGREGATE_NOT_FOUND'];
        yield 'ConflictDomainException' => [ConflictDomainException::class, 'CONFLICT'];
        yield 'OptimisticLockException' => [OptimisticLockException::class, 'OPTIMISTIC_LOCK'];
        yield 'InvalidAggregateRootException' => [InvalidAggregateRootException::class, 'INVALID_AGGREGATE_ROOT'];
    }

    public function test_domain_exception_custom_error_code_overrides_default(): void
    {
        $exception = InvalidStateDomainException::because('test', code: 'CUSTOM_CODE');
        $this->assertSame('CUSTOM_CODE', $exception->errorCode());

        $errorArray = $exception->toErrorArray();
        $this->assertSame('CUSTOM_CODE', $errorArray['code']);
    }

    public function test_domain_exception_json_serialize_matches_to_error_array(): void
    {
        $exception = InvalidStateDomainException::because('Order must be pending');

        $jsonEncoded = json_encode($exception);
        $decoded = json_decode($jsonEncoded, true);

        $this->assertSame('InvalidStateDomainException', $decoded['title']);
        $this->assertSame('Order must be pending', $decoded['detail']);
        $this->assertSame('INVALID_STATE', $decoded['code']);
    }

    public function test_all_domain_exceptions_have_unique_default_codes(): void
    {
        $codes = array_map(
            static fn (string $class): string => match ($class) {
                InvalidStateDomainException::class => (InvalidStateDomainException::because('x'))->errorCode(),
                InvalidArgumentDomainException::class => (InvalidArgumentDomainException::because('x'))->errorCode(),
                NotFoundDomainException::class => (NotFoundDomainException::because('x'))->errorCode(),
                AggregateNotFoundException::class => AggregateNotFoundException::for('T', 'id')->errorCode(),
                ConflictDomainException::class => (ConflictDomainException::because('x'))->errorCode(),
                OptimisticLockException::class => OptimisticLockException::for('id', 1, 2)->errorCode(),
                InvalidAggregateRootException::class => InvalidAggregateRootException::notAnAggregate(new \stdClass)->errorCode(),
                default => throw new \LogicException("Unknown: {$class}"),
            },
            [
                InvalidStateDomainException::class,
                InvalidArgumentDomainException::class,
                NotFoundDomainException::class,
                AggregateNotFoundException::class,
                ConflictDomainException::class,
                OptimisticLockException::class,
                InvalidAggregateRootException::class,
            ],
        );

        $unique = array_unique($codes);
        $this->assertCount(count($codes), $unique, 'All domain exception error codes must be unique');
    }

    // ─────────────────────────────────────────────────────────
    // ValueObject serialization consistency
    // ─────────────────────────────────────────────────────────

    public function test_value_object_equality_is_symmetric(): void
    {
        $vo1 = new TestValueObject('hello');
        $vo2 = new TestValueObject('hello');
        $vo3 = new TestValueObject('world');

        $this->assertTrue($vo1->equals($vo2));
        $this->assertTrue($vo2->equals($vo1));
        $this->assertFalse($vo1->equals($vo3));
        $this->assertFalse($vo3->equals($vo1));
    }

    public function test_value_object_to_array_is_json_safe(): void
    {
        $vo = new TestValueObject('test');
        $json = json_encode($vo->toArray());

        $this->assertNotFalse($json);
        $decoded = json_decode($json, true);
        $this->assertSame('test', $decoded['value']);
    }

    public function test_value_object_to_string_works(): void
    {
        $vo = new TestValueObject('my-value');
        $this->assertSame('my-value', (string) $vo);
    }

    // ─────────────────────────────────────────────────────────
    // Cross-identifier equality (must never cross-compare)
    // ─────────────────────────────────────────────────────────

    public function test_different_identifier_types_are_never_equal(): void
    {
        $uuid = TestUuidIdentifier::generate();
        $ulid = TestUlidIdentifier::generate();
        $stringId = StringIdentifier::from('test');
        $intId = IntegerIdentifier::from(1);

        $this->assertFalse($uuid->equals($ulid));
        $this->assertFalse($stringId->equals($intId));
    }

    public function test_same_identifier_type_with_same_value_is_equal(): void
    {
        $id1 = StringIdentifier::from('same-value');
        $id2 = StringIdentifier::from('same-value');

        $this->assertTrue($id1->equals($id2));
    }

    public function test_same_identifier_type_with_different_value_is_not_equal(): void
    {
        $id1 = StringIdentifier::from('value-a');
        $id2 = StringIdentifier::from('value-b');

        $this->assertFalse($id1->equals($id2));
    }
}
