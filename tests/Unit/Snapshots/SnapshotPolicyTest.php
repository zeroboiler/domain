<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Tests\Unit\Snapshots;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ZeroBoiler\Domain\Snapshots\SnapshotPolicy;

#[CoversClass(SnapshotPolicy::class)]
#[Group('unit')]
#[Group('snapshots')]
final class SnapshotPolicyTest extends TestCase
{
    public function testDefaultEveryIs50(): void
    {
        $policy = new SnapshotPolicy;

        $this->assertSame(50, $policy->every);
    }

    public function testCustomEveryValue(): void
    {
        $policy = new SnapshotPolicy(every: 100);

        $this->assertSame(100, $policy->every);
    }

    public function testZeroDisablesSnapshots(): void
    {
        $policy = new SnapshotPolicy(every: 0);

        $this->assertSame(0, $policy->every);
    }

    public function testIsAttribute(): void
    {
        $reflection = new \ReflectionClass(SnapshotPolicy::class);
        $attrs = $reflection->getAttributes(\Attribute::class);

        $this->assertCount(1, $attrs);
        $this->assertSame(
            \Attribute::TARGET_CLASS,
            $attrs[0]->newInstance()->flags,
        );
    }

    public function testIsReadonly(): void
    {
        $reflection = new \ReflectionClass(SnapshotPolicy::class);

        $this->assertTrue($reflection->isReadOnly());
    }

    public function testCanBeUsedOnClass(): void
    {
        $reflection = new \ReflectionClass(SnapshotPolicy::class);
        $attrs = $reflection->getAttributes(\Attribute::class);

        $this->assertTrue(
            (bool) ($attrs[0]->newInstance()->flags & \Attribute::TARGET_CLASS),
        );
    }
}
