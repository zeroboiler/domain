<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Ramsey\Uuid\Uuid;
use ZeroBoiler\Domain\Identifiers\Identifier;

describe('Identifier (deprecated base) toJson/fromJson round-trip', function () {
    test('toJson serializes identifier to JSON string', function () {
        $concrete = new class(Uuid::uuid4()) extends Identifier {};

        $json = $concrete->toJson();

        expect($json)->toBeString();
        $decoded = json_decode($json, true);
        expect($decoded)->toBeArray();
        expect($decoded['value'])->toBe($concrete->value->toString());
    });

    test('fromJson restores identifier from JSON string', function () {
        $original = new class(Uuid::uuid4()) extends Identifier {};

        $json = $original->toJson();
        $restored = get_class($original)::fromJson($json);

        expect($restored)->toBeInstanceOf(Identifier::class);
        expect($restored->toString())->toBe($original->toString());
        expect($restored->equals($original))->toBeTrue();
    });

    test('toJson/fromJson round-trip is idempotent', function () {
        $concrete = new class(Uuid::uuid4()) extends Identifier {};

        $json1 = $concrete->toJson();
        $restored = get_class($concrete)::fromJson($json1);
        $json2 = $restored->toJson();

        expect($json1)->toBe($json2);
    });

    test('toJson respects JSON options', function () {
        $concrete = new class(Uuid::uuid4()) extends Identifier {};

        $json = $concrete->toJson();
        expect($json)->toContain('"value":');
    });

    test('fromJson with non-object JSON throws', function () {
        $concrete = new class(Uuid::uuid4()) extends Identifier {};

        expect(fn () => get_class($concrete)::fromJson('"not-an-object"'))->toThrow(
            InvalidArgumentException::class,
        );
    });

    test('toJson produces consistent output with toArray', function () {
        $concrete = new class(Uuid::uuid4()) extends Identifier {};

        $arrayJson = json_encode($concrete->toArray());
        $toJson = $concrete->toJson();

        expect($toJson)->toBe($arrayJson);
    });

    test('toJson includes all toArray fields', function () {
        $uuid = Uuid::fromString('550e8400-e29b-41d4-a716-446655440000');
        $concrete = new class($uuid) extends Identifier {};

        $json = $concrete->toJson();
        $decoded = json_decode($json, true);

        expect($decoded)->toHaveKey('value');
        expect($decoded['value'])->toBe('550e8400-e29b-41d4-a716-446655440000');
    });
});
