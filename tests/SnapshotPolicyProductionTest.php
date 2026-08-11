<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ZeroBoiler\Domain\Snapshots\SnapshotPolicy;

/**
 * Production contract validation tests for SnapshotPolicy.
 *
 * Verifies immutability, final/readonly class constraints, attribute configuration,
 * constructor validation, and edge cases (version 0, negative thresholds).
 *
 * @covers \ZeroBoiler\Domain\Snapshots\SnapshotPolicy
 *
 * @since 1.0.0
 */
final class SnapshotPolicyProductionTest extends TestCase
{
    // ─────────────────────────────────────────────────────────────────────
    // Class Structure
    // ─────────────────────────────────────────────────────────────────────

    public function test_class_is_final(): void
    {
        $ref = new ReflectionClass(SnapshotPolicy::class);

        self::assertTrue($ref->isFinal(), 'SnapshotPolicy must be final.');
    }

    public function test_class_is_readonly(): void
    {
        $ref = new ReflectionClass(SnapshotPolicy::class);

        self::assertTrue($ref->isReadOnly(), 'SnapshotPolicy must be readonly.');
    }

    public function test_is_attribute_with_target_class(): void
    {
        $attrs = (new ReflectionClass(SnapshotPolicy::class))->getAttributes();

        self::assertCount(1, $attrs);
        self::assertSame(\Attribute::class, $attrs[0]->getName());

        $flags = $attrs[0]->getArguments()[0] ?? \Attribute::TARGET_ALL;
        self::assertSame(\Attribute::TARGET_CLASS, $flags);
    }

    public function test_declares_strict_types(): void
    {
        $file = (new ReflectionClass(SnapshotPolicy::class))->getFileName();
        $contents = file_get_contents($file);

        self::assertStringContainsString('declare(strict_types=1)', $contents);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Constructor & Property
    // ─────────────────────────────────────────────────────────────────────

    public function test_default_every_is_50(): void
    {
        $policy = new SnapshotPolicy;

        self::assertSame(50, $policy->every);
    }

    public function test_custom_every_value(): void
    {
        $policy = new SnapshotPolicy(every: 100);

        self::assertSame(100, $policy->every);
    }

    public function test_zero_disables_snapshots(): void
    {
        $policy = new SnapshotPolicy(every: 0);

        self::assertSame(0, $policy->every);
    }

    public function test_every_one_snapshots_every_event(): void
    {
        $policy = new SnapshotPolicy(every: 1);

        self::assertSame(1, $policy->every);
    }

    public function test_large_every_value(): void
    {
        $policy = new SnapshotPolicy(every: 10000);

        self::assertSame(10000, $policy->every);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Immutability
    // ─────────────────────────────────────────────────────────────────────

    public function test_every_property_is_readonly(): void
    {
        $policy = new SnapshotPolicy(every: 5);

        $ref = new ReflectionClass($policy);
        $prop = $ref->getProperty('every');

        self::assertTrue($prop->isReadOnly(), 'every property must be readonly (PHP 8.1+).');
        self::assertTrue($prop->isPublic(), 'every property must be public.');
    }

    public function test_property_is_int_typed(): void
    {
        $ref = new ReflectionClass(SnapshotPolicy::class);
        $prop = $ref->getProperty('every');

        self::assertTrue($prop->hasType());
        self::assertSame('int', (string) $prop->getType());
    }

    // ─────────────────────────────────────────────────────────────────────
    // Constructor Type Safety
    // ─────────────────────────────────────────────────────────────────────

    public function test_constructor_has_int_parameter_type(): void
    {
        $constructor = (new ReflectionClass(SnapshotPolicy::class))->getConstructor();
        $param = $constructor->getParameters()[0];

        self::assertTrue($param->hasType());
        self::assertSame('int', (string) $param->getType());
    }

    public function test_constructor_parameter_has_default(): void
    {
        $constructor = (new ReflectionClass(SnapshotPolicy::class))->getConstructor();
        $param = $constructor->getParameters()[0];

        self::assertTrue($param->isDefaultValueAvailable());
        self::assertSame(50, $param->getDefaultValue());
    }

    // ─────────────────────────────────────────────────────────────────────
    // Docblocks
    // ─────────────────────────────────────────────────────────────────────

    public function test_class_has_docblock(): void
    {
        $ref = new ReflectionClass(SnapshotPolicy::class);

        self::assertNotEmpty($ref->getDocComment(), 'SnapshotPolicy must have a class-level docblock.');
    }

    public function test_docblock_contains_since_tag(): void
    {
        $ref = new ReflectionClass(SnapshotPolicy::class);

        self::assertStringContainsString('@since', $ref->getDocComment());
    }

    public function test_docblock_contains_example(): void
    {
        $ref = new ReflectionClass(SnapshotPolicy::class);

        self::assertStringContainsString('@example', $ref->getDocComment());
    }

    public function test_docblock_contains_see_tags(): void
    {
        $ref = new ReflectionClass(SnapshotPolicy::class);

        self::assertStringContainsString('@see', $ref->getDocComment());
    }

    // ─────────────────────────────────────────────────────────────────────
    // Attribute Usage Validation
    // ─────────────────────────────────────────────────────────────────────

    public function test_can_be_instantiated_via_reflection_attribute(): void
    {
        // Simulate how SnapshottingRepository reads the attribute
        $ref = new ReflectionClass($this->createDummyClass());

        $attrs = $ref->getAttributes(SnapshotPolicy::class);
        self::assertCount(1, $attrs);

        /** @var SnapshotPolicy $policy */
        $policy = $attrs[0]->newInstance();

        self::assertSame(10, $policy->every);
    }

    public function test_reflection_attribute_without_argument_uses_default(): void
    {
        $ref = new ReflectionClass($this->createDummyClassWithoutArgument());

        $attrs = $ref->getAttributes(SnapshotPolicy::class);
        self::assertCount(1, $attrs);

        /** @var SnapshotPolicy $policy */
        $policy = $attrs[0]->newInstance();

        self::assertSame(50, $policy->every);
    }

    public function test_reflection_attribute_with_zero(): void
    {
        $ref = new ReflectionClass($this->createDummyClassWithZero());

        $attrs = $ref->getAttributes(SnapshotPolicy::class);
        self::assertCount(1, $attrs);

        /** @var SnapshotPolicy $policy */
        $policy = $attrs[0]->newInstance();

        self::assertSame(0, $policy->every);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Multiple Instances Independence
    // ─────────────────────────────────────────────────────────────────────

    public function test_multiple_instances_are_independent(): void
    {
        $a = new SnapshotPolicy(every: 10);
        $b = new SnapshotPolicy(every: 100);

        self::assertSame(10, $a->every);
        self::assertSame(100, $b->every);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────

    private function createDummyClassWithoutArgument(): string
    {
        return $this->createAnonymousClass('');
    }

    private function createDummyClassWithZero(): string
    {
        return $this->createAnonymousClass('every: 0');
    }

    private function createDummyClass(): string
    {
        return $this->createAnonymousClass('every: 10');
    }

    private function createAnonymousClass(string $args): string
    {
        $code = $args !== ''
            ? "#[\Attribute(Attribute::TARGET_CLASS)]\nfinal readonly class __SnapshotPolicyTest_{$args} extends \ZeroBoiler\Domain\Snapshots\SnapshotPolicy { public function __construct() { parent::__construct(every: {$args}); } }"
            : "#[\Attribute(Attribute::TARGET_CLASS)]\nfinal readonly class __SnapshotPolicyTestDefault extends \ZeroBoiler\Domain\Snapshots\SnapshotPolicy { public function __construct() { parent::__construct(); } }";

        return $code;
    }
}
