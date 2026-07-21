<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\Entity;
use ZeroBoiler\Domain\Identifiers\Identifier;
use ZeroBoiler\Domain\Tests\Fixtures\TestEntity;

beforeEach(function (): void {
    $this->id = new class extends Identifier {};
});

it('has identity equality', function (): void {
    $entity1 = new TestEntity($this->id);
    $entity2 = new TestEntity($this->id);

    expect($entity1->equals($entity2))->toBeTrue();
});

it('does not equal entities with different IDs', function (): void {
    $entity1 = new TestEntity($this->id);
    $entity2 = new TestEntity(new class extends Identifier {});

    expect($entity1->equals($entity2))->toBeFalse();
});

it('does not equal entities of different types', function (): void {
    $entity = new TestEntity($this->id);

    $otherEntity = new class($this->id) extends Entity {};

    expect($entity->equals($otherEntity))->toBeFalse();
});

it('equals returns true for same-class entities with same int ID', function (): void {
    // TestEntity uses the parent Entity::equals() which now casts
    // both IDs to string for safe comparison.
    $entity1 = new TestEntity(42);
    $entity2 = new TestEntity(42);
    $entity3 = new TestEntity(43);

    expect($entity1->equals($entity2))->toBeTrue()
        ->and($entity1->equals($entity3))->toBeFalse();
});

it('equals handles Stringable ID objects correctly', function (): void {
    $id = AggregateRootId::generate();
    $entity1 = new TestEntity($id);
    $entity2 = new TestEntity($id);

    expect($entity1->equals($entity2))->toBeTrue();
});

it('returns false when comparing entity with null-like IDs', function (): void {
    $entity1 = new class(0) extends Entity
    {
        public function id(): string
        {
            return (string) $this->id;
        }
    };
    $entity2 = new class(1) extends Entity
    {
        public function id(): string
        {
            return (string) $this->id;
        }
    };

    expect($entity1->equals($entity2))->toBeFalse();
});
