<?php

declare(strict_types=1);

use ZeroBoiler\Domain\Tests\Fixtures\TestEntity;
use ZeroBoiler\Domain\Identifiers\Identifier;

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

    $otherEntity = new class($this->id) extends \ZeroBoiler\Domain\Entity {
        public function __construct(public Identifier $id) {}
    };

    expect($entity->equals($otherEntity))->toBeFalse();
});