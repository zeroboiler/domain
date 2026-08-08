<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\Entity;
use ZeroBoiler\Domain\Tests\Fixtures\TestEntity;

it('serializes to array with id and type', function (): void {
    $entity = new TestEntity(42);

    expect($entity->toArray())->toBe([
        'id' => '42',
        'type' => 'TestEntity',
    ]);
});

it('serializes string ID to array', function (): void {
    $entity = new TestEntity('order-123');

    expect($entity->toArray())->toBe([
        'id' => 'order-123',
        'type' => 'TestEntity',
    ]);
});

it('serializes Stringable ID (AggregateRootId) to array', function (): void {
    $id = AggregateRootId::generate();
    $entity = new TestEntity($id);

    expect($entity->toArray())->toBe([
        'id' => $id->toString(),
        'type' => 'TestEntity',
    ]);
});

it('subclass toArray includes parent keys', function (): void {
    $entity = new class(99) extends Entity
    {
        public function __construct(
            int|string|\Stringable $id,
            public readonly string $name = 'test',
        ) {
            parent::__construct($id);
        }

        public function toArray(): array
        {
            return [
                ...parent::toArray(),
                'name' => $this->name,
            ];
        }
    };

    expect($entity->toArray())->toBe([
        'id' => '99',
        'type' => class_basename($entity::class),
        'name' => 'test',
    ]);
});

it('toArray satisfies EntityContract', function (): void {
    $entity = new TestEntity(1);

    expect($entity)
        ->toBeInstanceOf(\ZeroBoiler\Domain\Contracts\Entity::class)
        ->and($entity->toArray())
        ->toHaveKeys(['id', 'type']);
});

it('type key uses short class name', function (): void {
    $entity = new TestEntity(1);

    expect($entity->toArray()['type'])->toBe('TestEntity');
});

it('multiple entities have correct independent types', function (): void {
    $a = new TestEntity(1);
    $b = new class(2) extends Entity {};

    expect($a->toArray()['type'])->toBe('TestEntity')
        ->and($b->toArray()['type'])->toBe(class_basename($b::class));
});
