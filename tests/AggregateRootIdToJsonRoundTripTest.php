<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Domain\AggregateRootId;

describe('AggregateRootId toJson/fromJson round-trip', function () {
    test('toJson serializes to JSON string', function () {
        $id = AggregateRootId::fromString('550e8400-e29b-41d4-a716-446655440000');

        $json = $id->toJson();

        expect($json)->toBeString();
        $decoded = json_decode($json, true);
        expect($decoded)->toBeArray();
        expect($decoded['uuid'])->toBe('550e8400-e29b-41d4-a716-446655440000');
    });

    test('fromJson restores from JSON string', function () {
        $original = AggregateRootId::fromString('550e8400-e29b-41d4-a716-446655440000');

        $json = $original->toJson();
        $restored = AggregateRootId::fromJson($json);

        expect($restored->equals($original))->toBeTrue();
    });

    test('round-trip is idempotent', function () {
        $id = AggregateRootId::generate();

        $json1 = $id->toJson();
        $restored = AggregateRootId::fromJson($json1);
        $json2 = $restored->toJson();

        expect($json1)->toBe($json2);
    });

    test('toJson uses toArray representation', function () {
        $id = AggregateRootId::fromString('550e8400-e29b-41d4-a716-446655440000');

        $arrayJson = json_encode($id->toArray());
        $toJson = $id->toJson();

        expect($toJson)->toBe($arrayJson);
    });

    test('toJson differs from json_encode($id) which returns plain string', function () {
        $id = AggregateRootId::fromString('550e8400-e29b-41d4-a716-446655440000');

        // jsonSerialize() returns plain string
        $plainJson = json_encode($id);

        // toJson() returns the toArray() object
        $objectJson = $id->toJson();

        expect($plainJson)->toBe('"550e8400-e29b-41d4-a716-446655440000"');
        expect($objectJson)->toBe('{"uuid":"550e8400-e29b-41d4-a716-446655440000"}');
        expect($plainJson)->not->toBe($objectJson);
    });

    test('fromJson with non-array JSON throws', function () {
        expect(fn () => AggregateRootId::fromJson('"not-an-object"'))->toThrow(
            InvalidArgumentException::class,
        );
    });

    test('fromArray round-trip still works', function () {
        $id = AggregateRootId::generate();

        $restored = AggregateRootId::fromArray($id->toArray());
        expect($restored->equals($id))->toBeTrue();
    });
});
