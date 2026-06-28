<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

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
