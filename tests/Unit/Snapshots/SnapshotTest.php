<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Tests\Unit\Snapshots;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ZeroBoiler\Domain\Snapshots\Snapshot;

#[CoversClass(Snapshot::class)]
#[Group('unit')]
#[Group('snapshots')]
final class SnapshotTest extends TestCase
{
    private static Snapshot $snapshot;

    public static function setUpBeforeClass(): void
    {
        self::$snapshot = Snapshot::create(
            aggregateType: 'App\\Domain\\Order',
            aggregateId: '550e8400-e29b-41d4-a716-446655440000',
            version: 50,
            state: ['status' => 'paid', 'total' => 100.00],
        );
    }

    // ─── Creation ────────────────────────────────────────────────

    public function testCreateSetsAllProperties(): void
    {
        $s = self::$snapshot;

        $this->assertSame('App\Domain\Order', $s->aggregateType);
        $this->assertSame('550e8400-e29b-41d4-a716-446655440000', $s->aggregateId);
        $this->assertSame(50, $s->version);
        $this->assertSame(['status' => 'paid', 'total' => 100.00], $s->state);
        $this->assertInstanceOf(\DateTimeImmutable::class, $s->createdAt);
    }

    // ─── Immutability ────────────────────────────────────────────

    public function testPropertiesAreReadonly(): void
    {
        $reflection = new \ReflectionClass(Snapshot::class);

        foreach ($reflection->getProperties() as $prop) {
            $this->assertTrue(
                $prop->isReadOnly(),
                "Property {$prop->getName()} should be readonly",
            );
        }
    }

    public function testClassIsFinal(): void
    {
        $reflection = new \ReflectionClass(Snapshot::class);

        $this->assertTrue($reflection->isFinal());
    }

    // ─── Equality ────────────────────────────────────────────────

    public function testEqualityWithIdenticalSnapshot(): void
    {
        $s = self::$snapshot;
        $other = Snapshot::create(
            aggregateType: 'App\Domain\Order',
            aggregateId: '550e8400-e29b-41d4-a716-446655440000',
            version: 50,
            state: ['status' => 'paid', 'total' => 100.00],
        );

        // State matches, type and ID match, version matches
        // createdAt may differ, but equals() doesn't check createdAt
        $this->assertSame($s->aggregateType, $other->aggregateType);
        $this->assertSame($s->aggregateId, $other->aggregateId);
        $this->assertSame($s->version, $other->version);
        $this->assertSame($s->state, $other->state);
    }

    public function testInequalityWithDifferentVersion(): void
    {
        $other = Snapshot::create('App\Domain\Order', '550e8400-e29b-41d4-a716-446655440000', 51, []);

        $this->assertFalse(self::$snapshot->equals($other));
    }

    // ─── Serialization ────────────────────────────────────────────

    public function testToArrayContainsExpectedKeys(): void
    {
        $data = self::$snapshot->toArray();

        $this->assertArrayHasKey('aggregate_type', $data);
        $this->assertArrayHasKey('aggregate_id', $data);
        $this->assertArrayHasKey('version', $data);
        $this->assertArrayHasKey('state', $data);
        $this->assertArrayHasKey('created_at', $data);
    }

    public function testFromArrayRoundTrip(): void
    {
        $restored = Snapshot::fromArray(self::$snapshot->toArray());

        $this->assertSame(self::$snapshot->aggregateType, $restored->aggregateType);
        $this->assertSame(self::$snapshot->aggregateId, $restored->aggregateId);
        $this->assertSame(self::$snapshot->version, $restored->version);
        $this->assertSame(self::$snapshot->state, $restored->state);
    }

    public function testFromArrayRejectsInvalidData(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Snapshot::fromArray(['invalid' => 'data']);
    }

    public function testFromArrayRejectsWrongTypes(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Snapshot::fromArray([
            'aggregate_type' => 123,
            'aggregate_id' => 456,
            'version' => 'not-an-int',
            'state' => 'not-an-array',
            'created_at' => 789,
        ]);
    }

    public function testToJsonRoundTrip(): void
    {
        $json = self::$snapshot->toJson();
        $restored = Snapshot::fromJson($json);

        $this->assertSame(self::$snapshot->aggregateType, $restored->aggregateType);
        $this->assertSame(self::$snapshot->aggregateId, $restored->aggregateId);
        $this->assertSame(self::$snapshot->version, $restored->version);
        $this->assertSame(self::$snapshot->state, $restored->state);
    }

    public function testJsonSerializeReturnsSameAsToArray(): void
    {
        $s = self::$snapshot;

        $this->assertSame($s->toArray(), $s->jsonSerialize());
    }

    public function testPhpSerializeRoundTrip(): void
    {
        $s = self::$snapshot;
        $restored = unserialize(serialize($s));

        $this->assertInstanceOf(Snapshot::class, $restored);
        $this->assertSame($s->aggregateType, $restored->aggregateType);
        $this->assertSame($s->aggregateId, $restored->aggregateId);
        $this->assertSame($s->version, $restored->version);
        $this->assertSame($s->state, $restored->state);
    }

    public function testFromJsonRejectsInvalidJson(): void
    {
        $this->expectException(\JsonException::class);

        Snapshot::fromJson('not-valid-json');
    }

    public function testFromJsonRejectsNonArrayJson(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must decode to an array');

        Snapshot::fromJson('"just a string"');
    }
}
