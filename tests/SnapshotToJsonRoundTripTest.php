<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Domain\Snapshots\Snapshot;

describe('Snapshot toJson/fromJson round-trip', function () {
    test('toJson serializes snapshot to JSON string', function () {
        $snapshot = Snapshot::create(
            'App\\Domain\\Order',
            '550e8400-e29b-41d4-a716-446655440000',
            42,
            ['status' => 'pending', 'total' => 1999],
        );

        $json = $snapshot->toJson();

        expect($json)->toBeString();
        $decoded = json_decode($json, true);
        expect($decoded)->toBeArray();
        expect($decoded['aggregate_type'])->toBe('App\\Domain\\Order');
        expect($decoded['aggregate_id'])->toBe('550e8400-e29b-41d4-a716-446655440000');
        expect($decoded['version'])->toBe(42);
        expect($decoded['state'])->toBe(['status' => 'pending', 'total' => 1999]);
    });

    test('fromJson restores snapshot from JSON string', function () {
        $original = Snapshot::create(
            'App\\Domain\\Order',
            '550e8400-e29b-41d4-a716-446655440000',
            42,
            ['status' => 'pending', 'total' => 1999],
        );

        $json = $original->toJson();
        $restored = Snapshot::fromJson($json);

        expect($restored->aggregateType)->toBe($original->aggregateType);
        expect($restored->aggregateId)->toBe($original->aggregateId);
        expect($restored->version)->toBe($original->version);
        expect($restored->state)->toBe($original->state);
        expect($restored->equals($original))->toBeTrue();
    });

    test('toJson/fromJson round-trip is idempotent', function () {
        $snapshot = Snapshot::create(
            'App\\Domain\\User',
            '123e4567-e89b-12d3-a456-426614174000',
            1,
            ['name' => 'John', 'email' => 'john@example.com'],
        );

        $json1 = $snapshot->toJson();
        $restored = Snapshot::fromJson($json1);
        $json2 = $restored->toJson();

        expect($json1)->toBe($json2);
    });

    test('toJson throws on invalid JSON', function () {
        $snapshot = Snapshot::create('Test', 'id', 1, []);

        $json = $snapshot->toJson();
        $brokenJson = substr($json, 0, 5) . '{invalid';

        expect(fn () => Snapshot::fromJson($brokenJson))->toThrow(JsonException::class);
    });

    test('toJson respects JSON options', function () {
        $snapshot = Snapshot::create('Test', 'id', 1, ['name' => 'Ömer']);

        $json = $snapshot->toJson();
        expect($json)->toContain('Ömer');
    });

    test('fromJson with empty JSON object throws', function () {
        expect(fn () => Snapshot::fromJson('{}'))->toThrow(InvalidArgumentException::class);
    });

    test('fromJson with non-object JSON throws', function () {
        expect(fn () => Snapshot::fromJson('"not-an-object"'))->toThrow(InvalidArgumentException::class);
    });
});
