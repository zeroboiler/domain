<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\InMemoryUnitOfWork;
use ZeroBoiler\Domain\Tests\Fixtures\TestAggregate;
use ZeroBoiler\Events\Domain\DomainEvent;

describe('InMemoryUnitOfWork getPendingEvents', function () {
    it('returns empty collection when no events are pending', function () {
        $uow = new InMemoryUnitOfWork;

        $events = $uow->getPendingEvents();

        expect($events)->toBeInstanceOf(\ZeroBoiler\Domain\DomainEventCollection::class);
        expect($events->count())->toBe(0);
        expect($events->isEmpty())->toBeTrue();
    });

    it('returns typed DomainEventCollection with pending events', function () {
        $uow = new InMemoryUnitOfWork;
        $uow->begin();

        $event1 = DomainEvent::occur('test.event_one', ['key' => 'value1']);
        $event2 = DomainEvent::occur('test.event_two', ['key' => 'value2']);
        $uow->queueEvent($event1);
        $uow->queueEvent($event2);

        $events = $uow->getPendingEvents();

        expect($events->count())->toBe(2);
        expect($events->isEmpty())->toBeFalse();
        expect($events->first()?->eventType)->toBe('test.event_one');
        expect($events->last()?->eventType)->toBe('test.event_two');
    });

    it('does not consume events (non-destructive)', function () {
        $uow = new InMemoryUnitOfWork;
        $uow->begin();

        $uow->queueEvent(DomainEvent::occur('test.event', ['data' => 1]));

        // Peek twice — both should return the same events
        $peek1 = $uow->getPendingEvents();
        $peek2 = $uow->getPendingEvents();

        expect($peek1->count())->toBe(1);
        expect($peek2->count())->toBe(1);
        expect($uow->getPendingEventCount())->toBe(1);
    });

    it('includes events from tracked aggregates', function () {
        $uow = new InMemoryUnitOfWork;
        $uow->begin();

        $id = AggregateRootId::generate();
        $aggregate = TestAggregate::create($id);
        $aggregate->doSomething();
        $uow->track($aggregate);

        $events = $uow->getPendingEvents();

        expect($events->count())->toBeGreaterThan(0);
    });

    it('preserves event order across nested scopes', function () {
        $uow = new InMemoryUnitOfWork;
        $uow->begin();

        $uow->queueEvent(DomainEvent::occur('outer.first', []));

        $uow->begin(); // nested
        $uow->queueEvent(DomainEvent::occur('inner.first', []));
        $uow->queueEvent(DomainEvent::occur('inner.second', []));

        $events = $uow->getPendingEvents();

        expect($events->count())->toBe(3);
        expect($events->get(0)?->eventType)->toBe('outer.first');
        expect($events->get(1)?->eventType)->toBe('inner.first');
        expect($events->get(2)?->eventType)->toBe('inner.second');
    });

    it('returns DomainEventCollection that supports iteration', function () {
        $uow = new InMemoryUnitOfWork;
        $uow->begin();

        $uow->queueEvent(DomainEvent::occur('test.a', []));
        $uow->queueEvent(DomainEvent::occur('test.b', []));

        $events = $uow->getPendingEvents();
        $types = [];

        foreach ($events as $event) {
            $types[] = $event->eventType;
        }

        expect($types)->toBe(['test.a', 'test.b']);
    });

    it('returns DomainEventCollection that supports filter', function () {
        $uow = new InMemoryUnitOfWork;
        $uow->begin();

        $uow->queueEvent(DomainEvent::occur('test.match', []));
        $uow->queueEvent(DomainEvent::occur('test.skip', []));
        $uow->queueEvent(DomainEvent::occur('test.match', []));

        $events = $uow->getPendingEvents();
        $filtered = $events->filter(fn (DomainEvent $e): bool => $e->eventType === 'test.match');

        expect($filtered->count())->toBe(2);
    });

    it('returns DomainEventCollection that supports map', function () {
        $uow = new InMemoryUnitOfWork;
        $uow->begin();

        $uow->queueEvent(DomainEvent::occur('test.event', ['value' => 42]));

        $events = $uow->getPendingEvents();
        $types = $events->map(fn (DomainEvent $e, int $i): string => $e->eventType);

        expect($types)->toBe(['test.event']);
    });

    it('returns DomainEventCollection that is JSON serializable', function () {
        $uow = new InMemoryUnitOfWork;
        $uow->begin();

        $uow->queueEvent(DomainEvent::occur('test.event', ['key' => 'value']));

        $events = $uow->getPendingEvents();
        $json = json_encode($events);

        expect($json)->toBeJson();
    });

    it('events remain pending after peek (can still commit)', function () {
        $uow = new InMemoryUnitOfWork;
        $uow->begin();

        $uow->queueEvent(DomainEvent::occur('test.event', []));

        // Peek should not consume
        $peeked = $uow->getPendingEvents();
        expect($peeked->count())->toBe(1);
        expect($uow->hasPendingEvents())->toBeTrue();

        // Commit should still work
        $dispatched = false;
        $uow->setEventDispatcher(function (object $event) use (&$dispatched): void {
            $dispatched = true;
        });

        $uow->commit();

        expect($dispatched)->toBeTrue();
        expect($uow->hasPendingEvents())->toBeFalse();
    });
});
