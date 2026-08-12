<?php

declare(strict_types=1);

/**
 * Tests for the abstract AggregateRoot base class.
 *
 * Uses TestConcreteAggregate fixture (defined in bootstrap.php).
 *
 * @covers \ZeroBoiler\Domain\AggregateRoot
 */
describe('AggregateRoot', function (): void {
    it('constructs with AggregateRootId', function (): void {
        $id = \ZeroBoiler\Domain\AggregateRootId::generate();
        $aggregate = new TestConcreteAggregate($id, 'Order');

        expect($aggregate->id())->toBe($id->toString());
        expect($aggregate->aggregateId())->toBe($id);
        expect($aggregate->name)->toBe('Order');
    });

    it('starts at version 0', function (): void {
        $id = \ZeroBoiler\Domain\AggregateRootId::generate();
        $aggregate = new TestConcreteAggregate($id);
        expect($aggregate->version())->toBe(0);
    });

    it('compares equality by class and aggregate id', function (): void {
        $id = \ZeroBoiler\Domain\AggregateRootId::fromString('550e8400-e29b-41d4-a716-446655440000');
        $a = new TestConcreteAggregate($id);
        $b = new TestConcreteAggregate($id);
        $c = new TestConcreteAggregate(\ZeroBoiler\Domain\AggregateRootId::generate());

        expect($a->equals($b))->toBeTrue();
        expect($a->equals($c))->toBeFalse();
    });

    it('serializes to array with id, version, and type', function (): void {
        $id = \ZeroBoiler\Domain\AggregateRootId::generate();
        $aggregate = new TestConcreteAggregate($id, 'Order');

        $array = $aggregate->toArray();
        expect($array)->toHaveKeys(['id', 'version', 'type', 'name']);
        expect($array['id'])->toBe($id->toString());
        expect($array['version'])->toBe(0);
        expect($array['type'])->toBe('TestConcreteAggregate');
        expect($array['name'])->toBe('Order');
    });

    it('increments version via incrementVersion()', function (): void {
        $id = \ZeroBoiler\Domain\AggregateRootId::generate();
        $aggregate = new TestConcreteAggregate($id);

        $aggregate->incrementVersion();
        expect($aggregate->version())->toBe(1);

        $aggregate->incrementVersion();
        expect($aggregate->version())->toBe(2);
    });

    it('records and pulls domain events as typed collection', function (): void {
        $id = \ZeroBoiler\Domain\AggregateRootId::generate();
        $aggregate = new TestConcreteAggregate($id);

        $aggregate->testRecordThat(new TestDomainEvent('test.created'));
        $events = $aggregate->pullDomainEvents();

        expect($events)->toBeInstanceOf(\ZeroBoiler\Domain\DomainEventCollection::class);
        expect($events->count())->toBe(1);
        expect($events->first()->eventType)->toBe('test.created');

        // Events cleared after pull
        expect($aggregate->pullDomainEvents()->count())->toBe(0);
    });

    it('peeks at domain events without consuming them', function (): void {
        $id = \ZeroBoiler\Domain\AggregateRootId::generate();
        $aggregate = new TestConcreteAggregate($id);

        $aggregate->testRecordThat(new TestDomainEvent('test.peeked'));

        $peeked = $aggregate->peekDomainEvents();
        expect($peeked->count())->toBe(1);

        // Events still available for pull
        $pulled = $aggregate->pullDomainEvents();
        expect($pulled->count())->toBe(1);
    });

    it('clears domain events', function (): void {
        $id = \ZeroBoiler\Domain\AggregateRootId::generate();
        $aggregate = new TestConcreteAggregate($id);

        $aggregate->testRecordThat(new TestDomainEvent('test.cleared'));
        expect($aggregate->peekDomainEvents()->count())->toBe(1);

        $aggregate->clearDomainEvents();
        expect($aggregate->pullDomainEvents()->count())->toBe(0);
    });

    it('implements JsonSerializable', function (): void {
        $id = \ZeroBoiler\Domain\AggregateRootId::generate();
        $aggregate = new TestConcreteAggregate($id, 'Order');

        $json = json_encode($aggregate);
        expect($json)->toBeString()->toBeJson();
        $decoded = json_decode($json, true);
        expect($decoded['id'])->toBe($id->toString());
        expect($decoded['type'])->toBe('TestConcreteAggregate');
    });

    it('supports setVersion for repository hydration', function (): void {
        $id = \ZeroBoiler\Domain\AggregateRootId::generate();
        $aggregate = new TestConcreteAggregate($id);

        $aggregate->setVersion(5);
        expect($aggregate->version())->toBe(5);
    });
});
