<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Domain\Tests\Fixtures\TestValueObject;

describe('ValueObject — toJson/fromJson Round-Trip', function (): void {
    test('toJson() produces valid JSON string', function (): void {
        $vo = TestValueObject::from('hello-world');
        $json = $vo->toJson();

        expect($json)->toBeString()
            ->and(json_validate($json))->toBeTrue();
    });

    test('toJson() output matches json_encode(toArray())', function (): void {
        $vo = TestValueObject::from('test-value');

        expect($vo->toJson())->toBe(json_encode($vo->toArray(), JSON_UNESCAPED_UNICODE));
    });

    test('fromJson() reconstructs value object from JSON string', function (): void {
        $original = TestValueObject::from('my-value');
        $json = $original->toJson();
        $restored = TestValueObject::fromJson($json);

        expect($original->equals($restored))->toBeTrue();
    });

    test('fromJson() throws JsonException on invalid JSON', function (): void {
        expect(fn (): mixed => TestValueObject::fromJson('not-json'))
            ->toThrow(\JsonException::class);
    });

    test('fromJson() throws InvalidArgumentException on non-object JSON', function (): void {
        expect(fn (): mixed => TestValueObject::fromJson('"just-a-string"'))
            ->toThrow(\InvalidArgumentException::class);
    });

    test('fromJson() throws on JSON array without required keys', function (): void {
        expect(fn (): mixed => TestValueObject::fromJson('[1,2,3]'))
            ->toThrow(\ArgumentCountError::class);
    });

    test('full round-trip: fromArray → toJson → fromJson → equals', function (): void {
        $original = TestValueObject::fromArray(['value' => 'round-trip-test']);
        $json = $original->toJson();
        $restored = TestValueObject::fromJson($json);

        expect($restored->toArray())->toBe($original->toArray());
    });

    test('toJson() with JSON_PRETTY_PRINT option', function (): void {
        $vo = TestValueObject::from('pretty-print-test');
        $json = $vo->toJson(JSON_PRETTY_PRINT);

        expect($json)->toContain("\n")
            ->toContain('  ');
    });

    test('fromJson() from toArray() JSON matches original', function (): void {
        $original = TestValueObject::from('serde-test');
        $arrayJson = json_encode($original->toArray());
        $restored = TestValueObject::fromJson($arrayJson);

        expect($restored->equals($original))->toBeTrue();
    });

    test('different value objects are not equal after round-trip', function (): void {
        $a = TestValueObject::from('alpha');
        $b = TestValueObject::from('beta');

        expect($a->equals($b))->toBeFalse();
    });
});
