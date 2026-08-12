<?php

declare(strict_types=1);

/**
 * Tests for the abstract Entity base class.
 *
 * Uses TestConcreteEntity fixture (defined in bootstrap.php).
 *
 * @covers \ZeroBoiler\Domain\Entity
 */
describe('Entity', function (): void {
    it('stores identity via constructor', function (): void {
        $entity = new TestConcreteEntity(id: 'entity-123', name: 'Test');
        expect($entity->id())->toBe('entity-123');
        expect($entity->name)->toBe('Test');
    });

    it('compares equality by class and id', function (): void {
        $a = new TestConcreteEntity(id: 'entity-123');
        $b = new TestConcreteEntity(id: 'entity-123');
        $c = new TestConcreteEntity(id: 'entity-456');

        expect($a->equals($b))->toBeTrue();
        expect($a->equals($c))->toBeFalse();
    });

    it('is not equal to different entity class with same id', function (): void {
        if (! class_exists(\OtherTestEntity::class)) {
            eval('class OtherTestEntity extends \ZeroBoiler\Domain\Entity {
                use \ZeroBoiler\Domain\Concerns\HasDomainEvents;
                public function __construct(int|string|\Stringable $id) { parent::__construct($id); }
            }');
        }
        $entity = new TestConcreteEntity(id: 'entity-123');
        $other = new \OtherTestEntity(id: 'entity-123');
        expect($entity->equals($other))->toBeFalse();
    });

    it('serializes to array with id and type', function (): void {
        $entity = new TestConcreteEntity(id: 'entity-123', name: 'Order');
        $array = $entity->toArray();

        expect($array)->toHaveKey('id');
        expect($array)->toHaveKey('type');
        expect($array['id'])->toBe('entity-123');
        expect($array['type'])->toBe('TestConcreteEntity');
        expect($array['name'])->toBe('Order');
    });

    it('round-trips through fromArray/toArray', function (): void {
        $original = new TestConcreteEntity(id: 'entity-123', name: 'Test');
        $restored = TestConcreteEntity::fromArray($original->toArray());

        expect($restored->id())->toBe($original->id());
        expect($restored->name)->toBe($original->name);
    });

    it('supports int identity', function (): void {
        $entity = new TestConcreteEntity(id: 42);
        expect($entity->id())->toBe('42');
    });

    it('supports Stringable identity', function (): void {
        $aggregateId = \ZeroBoiler\Domain\AggregateRootId::generate();
        $entity = new TestConcreteEntity(id: $aggregateId);
        expect($entity->id())->toBe($aggregateId->toString());
    });

    it('implements JsonSerializable', function (): void {
        $entity = new TestConcreteEntity(id: 'entity-123', name: 'Test');
        $json = json_encode($entity);

        expect($json)->toBeString()->toBeJson();
        $decoded = json_decode($json, true);
        expect($decoded['id'])->toBe('entity-123');
    });

    it('records and releases domain events', function (): void {
        $entity = new TestConcreteEntity(id: 'entity-123');
        expect($entity->releaseEvents())->toBeEmpty();
        expect($entity->hasUncommittedEvents())->toBeFalse();

        $entity->testRecordThat(new TestDomainEvent('test.created'));
        expect($entity->hasUncommittedEvents())->toBeTrue();

        $events = $entity->releaseEvents();
        expect(count($events))->toBe(1);
        expect($events[0]->eventType)->toBe('test.created');

        // After release, events are cleared
        expect($entity->releaseEvents())->toBeEmpty();
        expect($entity->hasUncommittedEvents())->toBeFalse();
    });

    it('peeks at events without consuming', function (): void {
        $entity = new TestConcreteEntity(id: 'entity-123');
        $entity->testRecordThat(new TestDomainEvent('test.peeked'));

        $peeked = $entity->peekEvents();
        expect(count($peeked))->toBe(1);

        // Events still available for release
        expect($entity->hasUncommittedEvents())->toBeTrue();
    });

    it('clears events without dispatching', function (): void {
        $entity = new TestConcreteEntity(id: 'entity-123');
        $entity->testRecordThat(new TestDomainEvent('test.cleared'));
        expect($entity->hasUncommittedEvents())->toBeTrue();

        $entity->clearEvents();
        expect($entity->hasUncommittedEvents())->toBeFalse();
    });
});
