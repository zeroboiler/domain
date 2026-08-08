<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use ZeroBoiler\Domain\AggregateRoot;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\Contracts\Identifier as IdentifierContract;
use ZeroBoiler\Domain\Identifiers\IntegerIdentifier;
use ZeroBoiler\Domain\Identifiers\StringIdentifier;
use ZeroBoiler\Domain\Identifiers\UuidIdentifier;
use ZeroBoiler\Domain\Identifiers\Identifier as LegacyIdentifier;

#[Group('production')]
#[CoversClass(AggregateRoot::class)]
#[CoversClass(AggregateRootId::class)]
#[CoversClass(IdentifierContract::class)]
class DomainCrossPackageProductionAuditTest extends TestCase
{
    // ────────────────────────────────────────────
    // AggregateRootId: immutability + JSON + equality
    // ────────────────────────────────────────────

    public function test_aggregate_root_id_is_immutable(): void
    {
        $id = AggregateRootId::generate();
        $reflection = new \ReflectionClass($id);

        foreach ($reflection->getProperties() as $property) {
            if ($property->isReadOnly() || $property->isStatic()) {
                continue;
            }

            // All instance properties must be promoted readonly via constructor
            $this->fail("Non-readonly property {$property->getName()} found on AggregateRootId.");
        }
    }

    public function test_aggregate_root_id_json_round_trip(): void
    {
        $id = AggregateRootId::generate();
        $json = json_encode($id);
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        $this->assertIsString($decoded);
        $restored = AggregateRootId::fromString($decoded);
        $this->assertTrue($id->equals($restored));
    }

    public function test_aggregate_root_id_stringable_contract(): void
    {
        $id = AggregateRootId::generate();
        $this->assertInstanceOf(\Stringable::class, $id);
        $this->assertSame($id->toString(), (string) $id);
    }

    // ────────────────────────────────────────────
    // Identifier contract compliance
    // ────────────────────────────────────────────

    public function test_all_modern_identifiers_implement_identifier_contract(): void
    {
        $uuid = UuidIdentifier::generate();
        $this->assertInstanceOf(IdentifierContract::class, $uuid);

        $str = StringIdentifier::from('test');
        $this->assertInstanceOf(IdentifierContract::class, $str);

        $int = IntegerIdentifier::from(42);
        $this->assertInstanceOf(IdentifierContract::class, $int);
    }

    public function test_all_identifiers_implement_json_serializable(): void
    {
        $uuid = UuidIdentifier::generate();
        $this->assertInstanceOf(\JsonSerializable::class, $uuid);

        $str = StringIdentifier::from('test');
        $this->assertInstanceOf(\JsonSerializable::class, $str);

        $int = IntegerIdentifier::from(42);
        $this->assertInstanceOf(\JsonSerializable::class, $int);
    }

    public function test_identifier_cross_type_inequality(): void
    {
        $uuidValue = \Ramsey\Uuid\Uuid::uuid4()->toString();
        $a = new class($uuidValue) extends UuidIdentifier {};

        $strId = StringIdentifier::from($uuidValue);

        // Different identifier types are never equal
        $this->assertFalse($a->equals($strId));
        $this->assertFalse($strId->equals($a));
    }

    public function test_identifier_concrete_class_inequality(): void
    {
        $uuidValue = \Ramsey\Uuid\Uuid::uuid4()->toString();

        $orderId = new class($uuidValue) extends UuidIdentifier {};
        $productId = new class($uuidValue) extends UuidIdentifier {};

        // Same UUID, different concrete classes → not equal
        $this->assertFalse($orderId->equals($productId));
        $this->assertFalse($productId->equals($orderId));
    }

    public function test_identifier_concrete_class_equality(): void
    {
        $uuidValue = \Ramsey\Uuid\Uuid::uuid4()->toString();

        $id1 = new class($uuidValue) extends UuidIdentifier {};
        $id2 = new class($uuidValue) extends UuidIdentifier {};

        // Same UUID, same concrete class → equal
        $this->assertTrue($id1->equals($id2));
        $this->assertTrue($id2->equals($id1));
    }

    // ────────────────────────────────────────────
    // Legacy Identifier: consistent equals() semantics
    // ────────────────────────────────────────────

    public function test_legacy_identifier_concrete_class_inequality(): void
    {
        $uuid = \Ramsey\Uuid\Uuid::uuid4();

        $orderId = new class($uuid) extends LegacyIdentifier {};
        $productId = new class($uuid) extends LegacyIdentifier {};

        $this->assertFalse($orderId->equals($productId));
        $this->assertFalse($productId->equals($orderId));
    }

    public function test_legacy_identifier_concrete_class_equality(): void
    {
        $uuid = \Ramsey\Uuid\Uuid::uuid4();

        $id1 = new class($uuid) extends LegacyIdentifier {};
        $id2 = new class($uuid) extends LegacyIdentifier {};

        $this->assertTrue($id1->equals($id2));
    }

    public function test_legacy_identifier_cross_type_inequality_with_modern(): void
    {
        $uuidValue = \Ramsey\Uuid\Uuid::uuid4()->toString();
        $uuid = \Ramsey\Uuid\Uuid::fromString($uuidValue);

        $legacy = new class($uuid) extends LegacyIdentifier {};
        $modern = new class($uuidValue) extends UuidIdentifier {};

        $this->assertFalse($legacy->equals($modern));
        $this->assertFalse($modern->equals($legacy));
    }

    // ────────────────────────────────────────────
    // AggregateRoot: toArray + versioning
    // ────────────────────────────────────────────

    public function test_aggregate_root_to_array_contains_required_keys(): void
    {
        $id = AggregateRootId::generate();

        $order = new class($id) extends AggregateRoot {
            public string $status = 'pending';
        };

        $array = $order->toArray();

        $this->assertArrayHasKey('id', $array);
        $this->assertArrayHasKey('version', $array);
        $this->assertArrayHasKey('type', $array);
        $this->assertSame($id->toString(), $array['id']);
        $this->assertSame(0, $array['version']);
        $this->assertSame('class@anonymous', $array['type']);
    }

    public function test_aggregate_root_versioning_semantics(): void
    {
        $id = AggregateRootId::generate();
        $aggregate = new class($id) extends AggregateRoot {};

        $this->assertSame(0, $aggregate->version());

        $aggregate->incrementVersion();
        $this->assertSame(1, $aggregate->version());

        $aggregate->setVersion(10);
        $this->assertSame(10, $aggregate->version());
    }

    // ────────────────────────────────────────────
    // StringIdentifier validation
    // ────────────────────────────────────────────

    public function test_string_identifier_rejects_empty(): void
    {
        $this->expectException(\ValueError::class);
        StringIdentifier::from('');
    }

    public function test_string_identifier_is_valid_guard(): void
    {
        $this->assertTrue(StringIdentifier::isValid('hello'));
        $this->assertFalse(StringIdentifier::isValid(''));
    }

    // ────────────────────────────────────────────
    // IntegerIdentifier edge cases
    // ────────────────────────────────────────────

    public function test_integer_identifier_json_serializes_as_int(): void
    {
        $id = IntegerIdentifier::from(42);
        $json = json_encode($id);
        $this->assertSame('42', $json);

        $this->assertSame(42, $id->jsonSerialize());
    }

    public function test_integer_identifier_from_string_negative(): void
    {
        $id = IntegerIdentifier::fromString('-5');
        $this->assertSame(-5, $id->toInt());
        $this->assertSame('-5', $id->toString());
    }

    // ────────────────────────────────────────────
    // Domain exception hierarchy: all have error codes
    // ────────────────────────────────────────────

    /**
     * @return array<string, array{class: class-string<\ZeroBoiler\Domain\Exceptions\DomainException>, expectedCode: string}>
     */
    public static function exceptionErrorCodeProvider(): array
    {
        return [
            'InvalidStateDomainException' => [
                'class' => \ZeroBoiler\Domain\Exceptions\InvalidStateDomainException::class,
                'expectedCode' => 'INVALID_STATE',
            ],
            'InvalidArgumentDomainException' => [
                'class' => \ZeroBoiler\Domain\Exceptions\InvalidArgumentDomainException::class,
                'expectedCode' => 'INVALID_ARGUMENT',
            ],
            'NotFoundDomainException' => [
                'class' => \ZeroBoiler\Domain\Exceptions\NotFoundDomainException::class,
                'expectedCode' => 'NOT_FOUND',
            ],
            'ConflictDomainException' => [
                'class' => \ZeroBoiler\Domain\Exceptions\ConflictDomainException::class,
                'expectedCode' => 'CONFLICT',
            ],
            'OptimisticLockException' => [
                'class' => \ZeroBoiler\Domain\Exceptions\OptimisticLockException::class,
                'expectedCode' => 'OPTIMISTIC_LOCK',
            ],
            'AggregateNotFoundException' => [
                'class' => \ZeroBoiler\Domain\Exceptions\AggregateNotFoundException::class,
                'expectedCode' => 'AGGREGATE_NOT_FOUND',
            ],
            'InvalidAggregateRootException' => [
                'class' => \ZeroBoiler\Domain\Exceptions\InvalidAggregateRootException::class,
                'expectedCode' => 'INVALID_AGGREGATE_ROOT',
            ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('exceptionErrorCodeProvider')]
    public function test_domain_exception_has_stable_error_code(string $class, string $expectedCode): void
    {
        $exception = $class::because('test message');
        $this->assertSame($expectedCode, $exception->errorCode());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('exceptionErrorCodeProvider')]
    public function test_domain_exception_to_error_array_has_required_keys(string $class): void
    {
        $exception = $class::because('test message');
        $array = $exception->toErrorArray();

        $this->assertArrayHasKey('title', $array);
        $this->assertArrayHasKey('detail', $array);
        $this->assertArrayHasKey('code', $array);
        $this->assertIsString($array['title']);
        $this->assertIsString($array['detail']);
        $this->assertIsString($array['code']);
        $this->assertNotEmpty($array['code']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('exceptionErrorCodeProvider')]
    public function test_domain_exception_json_serializable(string $class): void
    {
        $exception = $class::because('test message');
        $this->assertInstanceOf(\JsonSerializable::class, $exception);

        $json = json_encode($exception);
        $this->assertNotFalse($json);
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        $this->assertArrayHasKey('title', $decoded);
        $this->assertArrayHasKey('detail', $decoded);
        $this->assertArrayHasKey('code', $decoded);
    }

    public function test_domain_exception_custom_code_overrides_default(): void
    {
        $exception = \ZeroBoiler\Domain\Exceptions\InvalidStateDomainException::because(
            reason: 'custom reason',
            code: 'CUSTOM_CODE',
        );

        $this->assertSame('CUSTOM_CODE', $exception->errorCode());
    }

    // ────────────────────────────────────────────
    // declare(strict_types=1) enforcement check
    // ────────────────────────────────────────────

    /**
     * Verify that all domain source files have strict types declaration.
     */
    public function test_all_domain_source_files_have_strict_types(): void
    {
        $srcDir = __DIR__ . '/../src';
        $files = glob($srcDir . '/**/*.php', recursive: true);

        foreach ($files as $file) {
            // Skip stubs (not production code)
            if (str_contains($file, '/stubs/')) {
                continue;
            }

            $contents = file_get_contents($file);
            $this->assertStringContainsString(
                'declare(strict_types=1)',
                $contents,
                "File {$file} is missing declare(strict_types=1).",
            );
        }
    }
}
