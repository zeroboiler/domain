<?php

declare(strict_types=1);

use ZeroBoiler\Domain\Tests\Fixtures\TestValueObject;

it('has value equality', function (): void {
    $vo1 = TestValueObject::from('test-value');
    $vo2 = TestValueObject::from('test-value');

    expect($vo1->equals($vo2))->toBeTrue();
});

it('does not equal different values', function (): void {
    $vo1 = TestValueObject::from('test-value-1');
    $vo2 = TestValueObject::from('test-value-2');

    expect($vo1->equals($vo2))->toBeFalse();
});

it('can convert to array', function (): void {
    $vo = TestValueObject::from('test-value');

    expect($vo->toArray())->toBe([
        'value' => 'test-value',
    ]);
});

it('can convert to JSON', function (): void {
    $vo = TestValueObject::from('test-value');

    expect($vo->toJson())->toBe('{"value":"test-value"}');
});

it('can convert to string', function (): void {
    $vo = TestValueObject::from('test-value');

    expect((string) $vo)->toBe('test-value');
});