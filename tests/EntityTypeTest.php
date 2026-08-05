<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Domain\AggregateRoot;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\Identifiers\IntegerIdentifier;
use ZeroBoiler\Domain\Identifiers\StringIdentifier;
use ZeroBoiler\Domain\Identifiers\UlidIdentifier;
use ZeroBoiler\Domain\Identifiers\UuidIdentifier;
use ZeroBoiler\Domain\Tests\Fixtures\TestEntity;

// ===========================================================================
//  Entity type system — production-ready tests
// ===========================================================================

describe('Entity type system', function (): void {
    it('accepts integer IDs', function (): void {
        $entity = new TestEntity(42);

        expect($entity->id())->toBe('42');
    });

    it('accepts string IDs', function (): void {
        $entity = new TestEntity('order-123');

        expect($entity->id())->toBe('order-123');
    });

    it('accepts AggregateRootId (Stringable) IDs', function (): void {
        $rootId = AggregateRootId::generate();
        $entity = new TestEntity($rootId);

        expect($entity->id())->toBe($rootId->toString());
    });

    it('accepts UuidIdentifier IDs', function (): void {
        $uuidId = UuidIdentifier::generate();
        $entity = new TestEntity($uuidId);

        expect($entity->id())->toBe($uuidId->toString());
    });

    it('accepts UlidIdentifier IDs', function (): void {
        $ulidId = new class extends UlidIdentifier {};
        $entity = new TestEntity($ulidId);

        expect($entity->id())->toBe($ulidId->toString());
    });

    it('accepts StringIdentifier IDs', function (): void {
        $stringId = StringIdentifier::from('my-slug');
        $entity = new TestEntity($stringId);

        expect($entity->id())->toBe('my-slug');
    });

    it('accepts IntegerIdentifier IDs', function (): void {
        $intId = IntegerIdentifier::from(99);
        $entity = new TestEntity($intId);

        expect($entity->id())->toBe('99');
    });

    it('id() always returns string regardless of input type', function (): void {
        $intEntity = new TestEntity(123);
        $stringEntity = new TestEntity('abc');
        $uuidEntity = new TestEntity(AggregateRootId::generate());

        expect($intEntity->id())->toBeString()
            ->and($stringEntity->id())->toBeString()
            ->and($uuidEntity->id())->toBeString();
    });

    it('zero ID is handled correctly', function (): void {
        $entity = new TestEntity(0);

        expect($entity->id())->toBe('0');
    });

    it('empty string ID is handled correctly', function (): void {
        $entity = new TestEntity('');

        expect($entity->id())->toBe('');
    });

    it('equals uses string comparison for int IDs', function (): void {
        $e1 = new TestEntity(1);
        $e2 = new TestEntity(1);
        $e3 = new TestEntity(2);

        expect($e1->equals($e2))->toBeTrue()
            ->and($e1->equals($e3))->toBeFalse();
    });
});

describe('AggregateRoot identity', function (): void {
    it('uses AggregateRootId consistently', function (): void {
        $root = new class extends AggregateRoot
        {
            public function __construct()
            {
                parent::__construct(AggregateRootId::generate());
            }
        };

        expect($root->id())->toBe($root->aggregateId()->toString())
            ->and($root->id())->toBe((string) $root->aggregateId());
    });

    it('equals compares aggregate IDs', function (): void {
        $id = AggregateRootId::generate();

        $root1 = new class($id) extends AggregateRoot
        {
            public function __construct(AggregateRootId $id)
            {
                parent::__construct($id);
            }
        };

        $root2 = new class($id) extends AggregateRoot
        {
            public function __construct(AggregateRootId $id)
            {
                parent::__construct($id);
            }
        };

        $root3 = new class extends AggregateRoot
        {
            public function __construct()
            {
                parent::__construct(AggregateRootId::generate());
            }
        };

        expect($root1->equals($root2))->toBeTrue()
            ->and($root1->equals($root3))->toBeFalse();
    });
});
