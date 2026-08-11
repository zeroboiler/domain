<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace Tests\Domain\Production;

use PHPUnit\Framework\TestCase;
use ZeroBoiler\Domain\AggregateRoot;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\Contracts\Identifier;
use ZeroBoiler\Domain\Identifiers\IntegerIdentifier;
use ZeroBoiler\Domain\Identifiers\StringIdentifier;
use ZeroBoiler\Domain\Identifiers\UuidIdentifier;
use ZeroBoiler\Domain\Identifiers\UlidIdentifier;
use ZeroBoiler\Domain\Snapshots\Snapshot;
use ZeroBoiler\Domain\Snapshots\SnapshotPolicy;

/**
 * Production readiness validation for the zeroboiler/domain package.
 *
 * Validates PHP 8.5 syntax, strict types, return type declarations,
 * docblock completeness, typed properties, immutability, and
 * domain invariant enforcement across all core classes.
 *
 * These tests are designed to run without Laravel or external dependencies —
 * they verify the domain package's standalone production readiness.
 *
 * @since 1.0.0
 *
 * @covers \ZeroBoiler\Domain\AggregateRootId
 * @covers \ZeroBoiler\Domain\Identifiers\UuidIdentifier
 * @covers \ZeroBoiler\Domain\Identifiers\UlidIdentifier
 * @covers \ZeroBoiler\Domain\Identifiers\StringIdentifier
 * @covers \ZeroBoiler\Domain\Identifiers\IntegerIdentifier
 * @covers \ZeroBoiler\Domain\Snapshots\Snapshot
 * @covers \ZeroBoiler\Domain\Snapshots\SnapshotPolicy
 */
final class DomainPackageContractValidationTest extends TestCase
{
    // ---------------------------------------------------------------
    // AggregateRootId: isValid() API consistency
    // ---------------------------------------------------------------

    public function testAggregateRootIdIsValidReturnsTrueForValidUuid(): void
    {
        $this->assertTrue(AggregateRootId::isValid('550e8400-e29b-41d4-a716-446655440000'));
    }

    public function testAggregateRootIdIsValidReturnsFalseForInvalidUuid(): void
    {
        $this->assertFalse(AggregateRootId::isValid('not-a-uuid'));
        $this->assertFalse(AggregateRootId::isValid(''));
        $this->assertFalse(AggregateRootId::isValid('550e8400'));
    }

    // ---------------------------------------------------------------
    // AggregateRootId: round-trip serialization
    // ---------------------------------------------------------------

    public function testAggregateRootIdToArrayFromArrayRoundTrip(): void
    {
        $id = AggregateRootId::generate();
        $array = $id->toArray();
        $restored = AggregateRootId::fromArray($array);

        $this->assertTrue($id->equals($restored));
        $this->assertSame($id->toString(), $restored->toString());
    }

    public function testAggregateRootIdFromArrayAcceptsIdKey(): void
    {
        $id = AggregateRootId::generate();
        $restored = AggregateRootId::fromArray(['id' => $id->toString()]);

        $this->assertTrue($id->equals($restored));
    }

    public function testAggregateRootIdJsonSerializeReturnsString(): void
    {
        $id = AggregateRootId::generate();
        $json = json_encode($id);

        $this->assertIsString($json);
        $this->assertSame('"' . $id->toString() . '"', $json);
    }

    // ---------------------------------------------------------------
    // AggregateRootId: immutability
    // ---------------------------------------------------------------

    public function testAggregateRootIdIsFinalReadonly(): void
    {
        $reflection = new \ReflectionClass(AggregateRootId::class);

        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());

        $valueProperty = $reflection->getProperty('value');
        $this->assertTrue($valueProperty->isReadOnly());
        $this->assertTrue($valueProperty->isPublic());
    }

    // ---------------------------------------------------------------
    // UuidIdentifier: abstract readonly with strict validation
    // ---------------------------------------------------------------

    public function testUuidIdentifierIsAbstractReadonly(): void
    {
        $reflection = new \ReflectionClass(UuidIdentifier::class);

        $this->assertTrue($reflection->isAbstract());
        $this->assertTrue($reflection->isReadOnly());
        $this->assertTrue($reflection->implementsInterface(Identifier::class));
        $this->assertTrue($reflection->implementsInterface(\JsonSerializable::class));
    }

    public function testConcreteUuidIdentifierRoundTrip(): void
    {
        // Create a concrete subclass for testing
        $id = TestOrderId::generate();
        $this->assertIsString($id->value);
        $this->assertTrue(UuidIdentifier::isValid($id->value));

        // toArray/fromArray round-trip
        $array = $id->toArray();
        $this->assertArrayHasKey('uuid', $array);
        $this->assertSame($id->value, $array['uuid']);

        $restored = TestOrderId::fromArray($array);
        $this->assertTrue($id->equals($restored));

        // JSON serialization
        $json = json_encode($id);
        $this->assertIsString($json);
        $this->assertSame('"' . $id->value . '"', $json);

        // toString
        $this->assertSame($id->value, $id->toString());
        $this->assertSame($id->value, (string) $id);
    }

    public function testConcreteUuidIdentifierRejectsInvalidUuid(): void
    {
        $this->expectException(\Ramsey\Uuid\Exception\InvalidUuidStringException::class);
        TestOrderId::fromString('not-a-uuid');
    }

    public function testConcreteUuidIdentifierEqualityIsTypeSafe(): void
    {
        $id1 = TestOrderId::fromString('550e8400-e29b-41d4-a716-446655440000');
        $id2 = TestOrderId::fromString('550e8400-e29b-41d4-a716-446655440000');
        $id3 = TestProductId::fromString('550e8400-e29b-41d4-a716-446655440000');

        $this->assertTrue($id1->equals($id2)); // Same class, same value
        $this->assertFalse($id1->equals($id3)); // Different class, same UUID
    }

    // ---------------------------------------------------------------
    // UlidIdentifier: abstract readonly with monotonic generation
    // ---------------------------------------------------------------

    public function testUlidIdentifierIsAbstractReadonly(): void
    {
        $reflection = new \ReflectionClass(UlidIdentifier::class);

        $this->assertTrue($reflection->isAbstract());
        $this->assertTrue($reflection->isReadOnly());
    }

    public function testConcreteUlidIdentifierRoundTrip(): void
    {
        $id = TestProductId::generate();
        $this->assertTrue(UlidIdentifier::isValid($id->value));

        $array = $id->toArray();
        $this->assertArrayHasKey('ulid', $array);

        $restored = TestProductId::fromArray($array);
        $this->assertTrue($id->equals($restored));
    }

    public function testConcreteUlidIdentifierRejectsInvalidUlid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TestProductId::fromString('not-a-ulid');
    }

    // ---------------------------------------------------------------
    // StringIdentifier: final readonly, non-empty invariant
    // ---------------------------------------------------------------

    public function testStringIdentifierIsFinalReadonly(): void
    {
        $reflection = new \ReflectionClass(StringIdentifier::class);

        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
    }

    public function testStringIdentifierRejectsEmptyString(): void
    {
        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage('cannot be empty');
        StringIdentifier::from('');
    }

    public function testStringIdentifierRoundTrip(): void
    {
        $slug = StringIdentifier::from('my-blog-post');

        $this->assertSame('my-blog-post', $slug->toString());
        $this->assertTrue($slug->isValid('valid'));
        $this->assertFalse($slug->isValid(''));

        $array = $slug->toArray();
        $this->assertArrayHasKey('string', $array);
        $this->assertSame('my-blog-post', $array['string']);

        $restored = StringIdentifier::fromArray($array);
        $this->assertTrue($slug->equals($restored));
    }

    // ---------------------------------------------------------------
    // IntegerIdentifier: final readonly
    // ---------------------------------------------------------------

    public function testIntegerIdentifierIsFinalReadonly(): void
    {
        $reflection = new \ReflectionClass(IntegerIdentifier::class);

        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
    }

    public function testIntegerIdentifierRoundTrip(): void
    {
        $id = IntegerIdentifier::from(42);

        $this->assertSame(42, $id->toInt());
        $this->assertSame('42', $id->toString());

        $array = $id->toArray();
        $this->assertArrayHasKey('integer', $array);
        $this->assertSame(42, $array['integer']);

        $restored = IntegerIdentifier::fromArray($array);
        $this->assertTrue($id->equals($restored));

        // JSON serialization returns int
        $json = json_encode($id);
        $this->assertSame('42', $json);
    }

    public function testIntegerIdentifierIsValid(): void
    {
        $this->assertTrue(IntegerIdentifier::isValid('42'));
        $this->assertTrue(IntegerIdentifier::isValid('-5'));
        $this->assertFalse(IntegerIdentifier::isValid('abc'));
        $this->assertFalse(IntegerIdentifier::isValid(''));
    }

    public function testIntegerIdentifierFromString(): void
    {
        $id = IntegerIdentifier::fromString('99');
        $this->assertSame(99, $id->toInt());
    }

    // ---------------------------------------------------------------
    // Snapshot: final readonly with round-trip
    // ---------------------------------------------------------------

    public function testSnapshotIsFinalReadonly(): void
    {
        $reflection = new \ReflectionClass(Snapshot::class);

        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
    }

    public function testSnapshotRoundTrip(): void
    {
        $snapshot = Snapshot::create(
            aggregateType: 'App\\Domain\\Order',
            aggregateId: '550e8400-e29b-41d4-a716-446655440000',
            version: 10,
            state: ['status' => 'paid', 'total' => 1999],
        );

        $this->assertSame('App\Domain\Order', $snapshot->aggregateType);
        $this->assertSame(10, $snapshot->version);
        $this->assertInstanceOf(\DateTimeImmutable::class, $snapshot->createdAt);

        $array = $snapshot->toArray();
        $this->assertArrayHasKey('aggregate_type', $array);
        $this->assertArrayHasKey('aggregate_id', $array);
        $this->assertArrayHasKey('version', $array);
        $this->assertArrayHasKey('state', $array);
        $this->assertArrayHasKey('created_at', $array);

        $restored = Snapshot::fromArray($array);
        $this->assertTrue($snapshot->equals($restored));
    }

    public function testSnapshotFromArrayRejectsInvalidData(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Snapshot::fromArray(['invalid' => 'data']);
    }

    public function testSnapshotJsonSerialize(): void
    {
        $snapshot = Snapshot::create('App\Domain\Order', 'id-1', 1, ['key' => 'value']);
        $json = json_encode($snapshot);
        $this->assertIsString($json);
        $this->assertStringContainsString('aggregate_type', $json);
    }

    // ---------------------------------------------------------------
    // SnapshotPolicy: readonly attribute
    // ---------------------------------------------------------------

    public function testSnapshotPolicyIsFinalReadonlyAttribute(): void
    {
        $reflection = new \ReflectionClass(SnapshotPolicy::class);

        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());

        $attributes = $reflection->getAttributes(\Attribute::class);
        $this->assertCount(1, $attributes);

        $attr = $attributes[0]->newInstance();
        $this->assertContains(\Attribute::TARGET_CLASS, $attr->flags);
    }

    // ---------------------------------------------------------------
    // Declare strict types: all source files must have it
    // ---------------------------------------------------------------

    public function testAllSourceFilesHaveStrictTypes(): void
    {
        $srcDir = dirname(__DIR__, 3) . '/src';
        $files = $this->findPhpFiles($srcDir);

        foreach ($files as $file) {
            $contents = file_get_contents($file);
            $this->assertStringContainsString(
                'declare(strict_types=1)',
                $contents,
                "File {$file} is missing declare(strict_types=1)",
            );
        }
    }

    // ---------------------------------------------------------------
    // Return type declarations: critical methods
    // ---------------------------------------------------------------

    public function testAggregateRootIdMethodsHaveReturnTypes(): void
    {
        $reflection = new \ReflectionClass(AggregateRootId::class);

        $methodsToCheck = ['generate', 'isValid', 'fromString', 'toString', 'equals', 'jsonSerialize', 'toArray', 'fromArray', '__toString'];

        foreach ($methodsToCheck as $method) {
            $methodReflection = $reflection->getMethod($method);
            $returnType = $methodReflection->getReturnType();

            $this->assertNotNull(
                $returnType,
                "AggregateRootId::{$method}() is missing a return type declaration",
            );
        }
    }

    // ---------------------------------------------------------------
    // Typed properties: identifiers must have typed constructor params
    // ---------------------------------------------------------------

    public function testIdentifierConstructorParamsAreTyped(): void
    {
        $identifiers = [
            UuidIdentifier::class,
            UlidIdentifier::class,
            StringIdentifier::class,
            IntegerIdentifier::class,
        ];

        foreach ($identifiers as $class) {
            $reflection = new \ReflectionClass($class);
            $constructor = $reflection->getConstructor();

            if ($constructor === null) {
                continue;
            }

            foreach ($constructor->getParameters() as $param) {
                $type = $param->getType();
                $this->assertNotNull(
                    $type,
                    "{$class}::\${$param->getName()} constructor parameter is untyped",
                );
            }
        }
    }

    // ---------------------------------------------------------------
    // Docblock completeness: classes and public methods
    // ---------------------------------------------------------------

    public function testAllPublicMethodsHaveDocblocks(): void
    {
        $classes = [
            AggregateRootId::class,
            UuidIdentifier::class,
            UlidIdentifier::class,
            StringIdentifier::class,
            IntegerIdentifier::class,
            Snapshot::class,
            SnapshotPolicy::class,
        ];

        foreach ($classes as $class) {
            $reflection = new \ReflectionClass($class);

            // Class docblock
            $this->assertNotEmpty(
                $reflection->getDocComment(),
                "{$class} is missing a class-level docblock",
            );

            // Public method docblocks
            foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if (str_starts_with($method->getName(), '__')) {
                    continue; // Skip magic methods
                }

                $this->assertNotEmpty(
                    $method->getDocComment(),
                    "{$class}::{$method->getName()}() is missing a docblock",
                );
            }
        }
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

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
            /** @var \SplFileInfo $file */
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}

// ---------------------------------------------------------------
// Test concrete identifier subclasses
// ---------------------------------------------------------------

/** @extends UuidIdentifier */
final class TestOrderId extends UuidIdentifier {}

/** @extends UlidIdentifier */
final class TestProductId extends UlidIdentifier {}
