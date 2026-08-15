<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use ZeroBoiler\Domain\AggregateRoot;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\Concerns\EventSourced;
use ZeroBoiler\Domain\Concerns\HasDomainEvents;
use ZeroBoiler\Domain\Contracts\AggregateRoot as AggregateRootContract;
use ZeroBoiler\Domain\Contracts\Entity as EntityContract;
use ZeroBoiler\Domain\Contracts\Identifier as IdentifierContract;
use ZeroBoiler\Domain\Identifiers\IntegerIdentifier;
use ZeroBoiler\Domain\Identifiers\StringIdentifier;
use ZeroBoiler\Domain\Identifiers\UuidIdentifier;
use ZeroBoiler\Domain\Identifiers\UlidIdentifier;
use ZeroBoiler\Domain\Entity;
use ZeroBoiler\Domain\Exceptions\ConflictDomainException;
use ZeroBoiler\Domain\Exceptions\InvalidArgumentDomainException;
use ZeroBoiler\Domain\Exceptions\InvalidStateDomainException;
use ZeroBoiler\Domain\Exceptions\NotFoundDomainException;
use ZeroBoiler\Domain\Exceptions\OptimisticLockException;
use ZeroBoiler\Domain\ValueObject;
use ZeroBoiler\Events\Domain\DomainEvent;

/**
 * Comprehensive domain package production integration test.
 *
 * Validates that all domain building blocks work together correctly:
 * - Entity identity and equality
 * - AggregateRoot lifecycle with events
 * - Identifier type safety and cross-type inequality
 * - ValueObject structural equality
 * - DomainException hierarchy and RFC 9457 error arrays
 * - Serialization contracts (toArray/fromArray/toJson/fromJson)
 * - Cross-cutting concerns (immutability, invariants, JSON consistency)
 *
 * @since 2.15.0
 *
 * @covers \ZeroBoiler\Domain\Entity
 * @covers \ZeroBoiler\Domain\AggregateRoot
 * @covers \ZeroBoiler\Domain\AggregateRootId
 * @covers \ZeroBoiler\Domain\ValueObject
 * @covers \ZeroBoiler\Domain\Identifiers\UuidIdentifier
 * @covers \ZeroBoiler\Domain\Identifiers\UlidIdentifier
 * @covers \ZeroBoiler\Domain\Identifiers\StringIdentifier
 * @covers \ZeroBoiler\Domain\Identifiers\IntegerIdentifier
 * @covers \ZeroBoiler\Domain\Exceptions\DomainException
 * @covers \ZeroBoiler\Domain\Exceptions\InvalidStateDomainException
 * @covers \ZeroBoiler\Domain\Exceptions\InvalidArgumentDomainException
 * @covers \ZeroBoiler\Domain\Exceptions\NotFoundDomainException
 * @covers \ZeroBoiler\Domain\Exceptions\ConflictDomainException
 * @covers \ZeroBoiler\Domain\Exceptions\OptimisticLockException
 */
final class DomainToResponseProductionIntegrationTest extends TestCase
{
    // ──────────────────────────────────────────────
    // 1. Entity Tests
    // ──────────────────────────────────────────────

    public function testEntityImplementsEntityContract(): void
    {
        $entity = new class(42) extends Entity {};

        $this->assertInstanceOf(EntityContract::class, $entity);
        $this->assertInstanceOf(\JsonSerializable::class, $entity);
    }

    public function testEntityIdentityWithIntId(): void
    {
        $entity = new class(42) extends Entity {};
        $other = new class(42) extends Entity {};

        $this->assertSame('42', $entity->id());
        $this->assertTrue($entity->equals($other));
    }

    public function testEntityIdentityWithStringId(): void
    {
        $entity = new class('order-123') extends Entity {};

        $this->assertSame('order-123', $entity->id());
    }

    public function testEntityIdentityWithStringableId(): void
    {
        $id = AggregateRootId::generate();
        $entity = new class($id) extends Entity {};
        $other = new class($id) extends Entity {};

        $this->assertSame($id->toString(), $entity->id());
        $this->assertTrue($entity->equals($other));
    }

    public function testEntityEqualityRequiresSameClass(): void
    {
        $a = new class(1) extends Entity {};
        $b = new class(1) extends Entity {};

        $this->assertFalse($a->equals($b));
    }

    public function testEntityToArrayContainsIdAndType(): void
    {
        $entity = new class(42) extends Entity {};

        $array = $entity->toArray();

        $this->assertSame('42', $array['id']);
        $this->assertArrayHasKey('type', $array);
    }

    public function testEntityJsonSerializeDelegatesToArray(): void
    {
        $entity = new class(42) extends Entity {};

        $this->assertSame($entity->toArray(), $entity->jsonSerialize());
    }

    public function testEntityFromJsonRoundTrip(): void
    {
        $entity = new class('order-123') extends Entity {};
        $json = $entity->toJson();

        $restored = (new class('x') extends Entity {
        })::fromJson($json);

        // Different anonymous class → not equal, but same data
        $this->assertSame($entity->toArray()['id'], $restored->toArray()['id']);
    }

    public function testEntityFromJsonInvalidJsonThrowsJsonException(): void
    {
        $this->expectException(\JsonException::class);

        (new class(1) extends Entity {})::fromJson('not-json');
    }

    // ──────────────────────────────────────────────
    // 2. AggregateRoot Tests
    // ──────────────────────────────────────────────

    public function testAggregateRootImplementsContract(): void
    {
        $root = TestAggregate::create();

        $this->assertInstanceOf(AggregateRootContract::class, $root);
        $this->assertInstanceOf(EntityContract::class, $root);
    }

    public function testAggregateRootGeneratesUuidId(): void
    {
        $root = TestAggregate::create();

        $this->assertNotEmpty($root->id());
        $this->assertTrue(AggregateRootId::isValid($root->id()));
    }

    public function testAggregateRootVersionStartsAtZero(): void
    {
        $root = TestAggregate::create();

        $this->assertSame(0, $root->version());
    }

    public function testAggregateRootRecordsDomainEvents(): void
    {
        $root = TestAggregate::create();
        $root->doSomething();

        $this->assertTrue($root->hasUncommittedEvents());

        $events = $root->pullDomainEvents();
        $this->assertSame(1, $events->count());
        $this->assertFalse($root->hasUncommittedEvents());
    }

    public function testAggregateRootPeekDomainEventsPreservesState(): void
    {
        $root = TestAggregate::create();
        $root->doSomething();

        $peeked = $root->peekDomainEvents();
        $this->assertSame(1, $peeked->count());

        // Events still available after peek
        $pulled = $root->pullDomainEvents();
        $this->assertSame(1, $pulled->count());
    }

    public function testAggregateRootClearDomainEvents(): void
    {
        $root = TestAggregate::create();
        $root->doSomething();
        $root->clearDomainEvents();

        $this->assertFalse($root->hasUncommittedEvents());
    }

    public function testAggregateRootVersionIncrementsOnApply(): void
    {
        $root = TestAggregate::create();
        $root->doSomething();

        $this->assertSame(1, $root->version());
    }

    public function testAggregateRootToArrayContainsVersion(): void
    {
        $root = TestAggregate::create();

        $array = $root->toArray();

        $this->assertArrayHasKey('id', $array);
        $this->assertArrayHasKey('version', $array);
        $this->assertArrayHasKey('type', $array);
    }

    public function testAggregateRootEquality(): void
    {
        $id = AggregateRootId::generate();
        $a = TestAggregate::withId($id);
        $b = TestAggregate::withId($id);

        $this->assertTrue($a->equals($b));
    }

    // ──────────────────────────────────────────────
    // 3. Identifier Tests
    // ──────────────────────────────────────────────

    public function testAggregateRootIdIsFinalReadonly(): void
    {
        $reflection = new \ReflectionClass(AggregateRootId::class);

        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
    }

    public function testAggregateRootIdGenerateCreatesValidUuid(): void
    {
        $id = AggregateRootId::generate();

        $this->assertTrue(AggregateRootId::isValid($id->toString()));
        $this->assertSame(36, strlen($id->toString()));
    }

    public function testAggregateRootIdFromStringRoundTrip(): void
    {
        $original = AggregateRootId::generate();
        $restored = AggregateRootId::fromString($original->toString());

        $this->assertTrue($original->equals($restored));
    }

    public function testAggregateRootIdFromArrayRoundTrip(): void
    {
        $original = AggregateRootId::generate();
        $restored = AggregateRootId::fromArray($original->toArray());

        $this->assertTrue($original->equals($restored));
    }

    public function testAggregateRootIdFromJsonRoundTrip(): void
    {
        $original = AggregateRootId::generate();
        $json = $original->toJson();
        $restored = AggregateRootId::fromJson($json);

        $this->assertTrue($original->equals($restored));
    }

    public function testAggregateRootIdJsonSerializeReturnsString(): void
    {
        $id = AggregateRootId::generate();

        $this->assertIsString($id->jsonSerialize());
        $this->assertSame($id->toString(), $id->jsonSerialize());
    }

    public function testUuidIdentifierTypeSafety(): void
    {
        $a = TestUuidIdentifier::generate();
        $b = TestUuidIdentifierAlt::generate();

        // Different subclass → not equal
        $this->assertFalse($a->equals($b));
    }

    public function testUlidIdentifierGenerateCreatesValidUlid(): void
    {
        $id = TestUlidIdentifier::generate();

        $this->assertTrue(TestUlidIdentifier::isValid($id->toString()));
    }

    public function testStringIdentifierRejectsEmpty(): void
    {
        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage('cannot be empty');

        StringIdentifier::from('');
    }

    public function testIntegerIdentifierFromInt(): void
    {
        $id = IntegerIdentifier::from(42);

        $this->assertSame(42, $id->toInt());
        $this->assertSame('42', $id->toString());
    }

    public function testIntegerIdentifierJsonSerializeReturnsInt(): void
    {
        $id = IntegerIdentifier::from(42);

        $this->assertSame(42, $id->jsonSerialize());
    }

    public function testAllIdentifiersImplementIdentifierContract(): void
    {
        $uuidId = TestUuidIdentifier::generate();
        $ulidId = TestUlidIdentifier::generate();
        $strId = StringIdentifier::from('test');
        $intId = IntegerIdentifier::from(1);
        $rootId = AggregateRootId::generate();

        $this->assertInstanceOf(IdentifierContract::class, $uuidId);
        $this->assertInstanceOf(IdentifierContract::class, $ulidId);
        $this->assertInstanceOf(IdentifierContract::class, $strId);
        $this->assertInstanceOf(IdentifierContract::class, $intId);
        $this->assertInstanceOf(IdentifierContract::class, $rootId);
    }

    public function testAllIdentifiersHaveSerdeRoundTrip(): void
    {
        $identifiers = [
            TestUuidIdentifier::generate(),
            TestUlidIdentifier::generate(),
            StringIdentifier::from('slug-test'),
            IntegerIdentifier::from(99),
            AggregateRootId::generate(),
        ];

        foreach ($identifiers as $original) {
            $json = $original->toJson();
            $class = $original::class;
            $restored = $class::fromJson($json);
            $this->assertTrue(
                $original->equals($restored),
                "Round-trip failed for {$class}",
            );
        }
    }

    public function testAllIdentifiersAreJsonSerializable(): void
    {
        $identifiers = [
            TestUuidIdentifier::generate(),
            TestUlidIdentifier::generate(),
            StringIdentifier::from('slug'),
            IntegerIdentifier::from(1),
            AggregateRootId::generate(),
        ];

        foreach ($identifiers as $id) {
            $this->assertInstanceOf(\JsonSerializable::class, $id);
            // Ensure json_encode works without error
            $encoded = json_encode($id);
            $this->assertNotFalse($encoded);
            $this->assertNotEmpty($encoded);
        }
    }

    // ──────────────────────────────────────────────
    // 4. ValueObject Tests
    // ──────────────────────────────────────────────

    public function testValueObjectStructuralEquality(): void
    {
        $a = TestAddress::fromArray(['street' => '123 Main', 'city' => 'NYC', 'country' => 'US']);
        $b = TestAddress::fromArray(['street' => '123 Main', 'city' => 'NYC', 'country' => 'US']);
        $c = TestAddress::fromArray(['street' => '456 Oak', 'city' => 'LA', 'country' => 'US']);

        $this->assertTrue($a->equals($b));
        $this->assertFalse($a->equals($c));
    }

    public function testValueObjectFromJsonRoundTrip(): void
    {
        $original = TestAddress::fromArray(['street' => '123 Main', 'city' => 'NYC', 'country' => 'US']);
        $json = $original->toJson();
        $restored = TestAddress::fromJson($json);

        $this->assertTrue($original->equals($restored));
    }

    // ──────────────────────────────────────────────
    // 5. DomainException Hierarchy Tests
    // ──────────────────────────────────────────────

    public function testExceptionHierarchyReturnsCorrectErrorCodes(): void
    {
        $exceptions = [
            [InvalidStateDomainException::because('test'), 'INVALID_STATE', 422],
            [InvalidArgumentDomainException::because('test'), 'INVALID_ARGUMENT', 422],
            [NotFoundDomainException::forAggregate('Order', '123'), 'NOT_FOUND', 404],
            [ConflictDomainException::because('Concurrent modification'), 'CONFLICT', 409],
            [OptimisticLockException::for('Order-123', 5, 3), 'OPTIMISTIC_LOCK', 409],
        ];

        foreach ($exceptions as [$exception, $expectedCode, $expectedStatus]) {
            $this->assertSame($expectedCode, $exception->errorCode(), "Wrong code for " . $exception::class);
            $this->assertSame($expectedStatus, $exception->httpStatus(), "Wrong status for " . $exception::class);
        }
    }

    public function testExceptionToErrorArrayIsRfc9457Compatible(): void
    {
        $e = InvalidStateDomainException::because('Order must be pending');
        $error = $e->toErrorArray();

        $this->assertArrayHasKey('title', $error);
        $this->assertArrayHasKey('detail', $error);
        $this->assertArrayHasKey('code', $error);
        $this->assertArrayHasKey('status', $error);
        $this->assertSame('InvalidStateDomainException', $error['title']);
        $this->assertSame('Order must be pending', $error['detail']);
        $this->assertSame('INVALID_STATE', $error['code']);
        $this->assertSame(422, $error['status']);
    }

    public function testExceptionJsonSerializeReturnsErrorArray(): void
    {
        $e = NotFoundDomainException::forAggregate('Order', '123');
        $this->assertSame($e->toErrorArray(), $e->jsonSerialize());
    }

    public function testExceptionFromJsonRoundTrip(): void
    {
        $original = InvalidStateDomainException::because('test state', 'CUSTOM_CODE');
        $json = $original->toJson();
        $restored = InvalidStateDomainException::fromJson($json, InvalidStateDomainException::class);

        $this->assertSame($original->getMessage(), $restored->getMessage());
        $this->assertSame($original->errorCode(), $restored->errorCode());
    }

    // ──────────────────────────────────────────────
    // 6. Serialization Consistency Tests
    // ──────────────────────────────────────────────

    public function testAllCoreClassesHaveToArrayAndFromArray(): void
    {
        $classes = [
            AggregateRootId::class,
            TestUuidIdentifier::class,
            TestUlidIdentifier::class,
            StringIdentifier::class,
            IntegerIdentifier::class,
            TestAddress::class,
        ];

        foreach ($classes as $class) {
            $this->assertTrue(
                method_exists($class, 'toArray'),
                "{$class} missing toArray()",
            );
            $this->assertTrue(
                method_exists($class, 'fromArray'),
                "{$class} missing fromArray()",
            );
        }
    }

    public function testAllCoreClassesHaveToJsonAndFromJson(): void
    {
        $classes = [
            AggregateRootId::class,
            TestUuidIdentifier::class,
            TestUlidIdentifier::class,
            StringIdentifier::class,
            IntegerIdentifier::class,
            TestAddress::class,
        ];

        foreach ($classes as $class) {
            $this->assertTrue(
                method_exists($class, 'toJson'),
                "{$class} missing toJson()",
            );
            $this->assertTrue(
                method_exists($class, 'fromJson'),
                "{$class} missing fromJson()",
            );
        }
    }

    // ──────────────────────────────────────────────
    // 7. PHP 8.5 Syntax Verification
    // ──────────────────────────────────────────────

    public function testDomainClassesUseOverrideAttribute(): void
    {
        $this->assertTrue(
            method_exists(AggregateRoot::class, 'pullDomainEvents'),
        );

        $method = new \ReflectionMethod(AggregateRoot::class, 'pullDomainEvents');
        $attrs = $method->getAttributes(\Override::class);
        $this->assertNotEmpty($attrs, 'AggregateRoot::pullDomainEvents() should have #[Override]');
    }

    public function testDomainClassesUseDeprecatedAttribute(): void
    {
        $method = new \ReflectionMethod(AggregateRoot::class, 'getVersion');
        $attrs = $method->getAttributes(\Deprecated::class);
        $this->assertNotEmpty($attrs, 'AggregateRoot::getVersion() should have #[Deprecated]');
    }
}

// ──────────────────────────────────────────────
// Test Fixtures
// ──────────────────────────────────────────────

/** @internal Test aggregate for integration tests. */
final class TestAggregate extends AggregateRoot
{
    use HasDomainEvents;
    use EventSourced;

    public static function create(): self
    {
        return new self(AggregateRootId::generate());
    }

    public static function withId(AggregateRootId $id): self
    {
        return new self($id);
    }

    public function doSomething(): void
    {
        $event = DomainEvent::occur('test.something', [
            'id' => $this->id(),
        ]);

        $this->apply($event);
    }

    protected function applyTestSomething(DomainEvent $event): void {}
}

/** @internal Test value object for integration tests. */
final class TestAddress extends ValueObject
{
    public function __construct(
        public readonly string $street,
        public readonly string $city,
        public readonly string $country,
    ) {}

    public static function fromArray(array $data): static
    {
        return new static(
            street: $data['street'],
            city: $data['city'],
            country: $data['country'],
        );
    }

    public function toArray(): array
    {
        return [
            'street' => $this->street,
            'city' => $this->city,
            'country' => $this->country,
        ];
    }
}
