<?php

declare(strict_types=1);

use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\DomainEvent;
use ZeroBoiler\Domain\Tests\Fixtures\AggregateWithInfoEvents;

beforeEach(function (): void {
    $this->aggregateId = AggregateRootId::generate();
});

it('replays events in lenient mode when handler is missing', function (): void {
    $createdEvent = DomainEvent::occur('aggregate.created', [
        'id' => $this->aggregateId->toString(),
        'name' => 'Test',
    ]);

    $infoEvent = DomainEvent::occur('aggregate.noted', [
        'id' => $this->aggregateId->toString(),
        'note' => 'some info',
    ]);

    // fromHistory uses lenient mode — should not throw for missing handler
    $aggregate = AggregateWithInfoEvents::fromHistory($createdEvent, $infoEvent);

    expect($aggregate->name)->toBe('Test');
});

it('fromHistory silently skips multiple events without handlers', function (): void {
    $events = [
        DomainEvent::occur('aggregate.created', [
            'id' => $this->aggregateId->toString(),
            'name' => 'History',
        ]),
        DomainEvent::occur('aggregate.noted', [
            'id' => $this->aggregateId->toString(),
            'note' => 'informational only',
        ]),
        DomainEvent::occur('aggregate.tagged', [
            'id' => $this->aggregateId->toString(),
            'tag' => 'misc',
        ]),
    ];

    $aggregate = AggregateWithInfoEvents::fromHistory(...$events);

    expect($aggregate->name)->toBe('History');
    // Version should only be incremented for events that have handlers (1 event)
    expect($aggregate->getVersion())->toBe(1);
});

it('fromHistory still throws on empty history', function (): void {
    AggregateWithInfoEvents::fromHistory();
})->throws(RuntimeException::class);

it('fromHistory still throws on missing aggregate ID in first event', function (): void {
    $event = DomainEvent::occur('aggregate.created', [
        'name' => 'No ID',
    ]);

    AggregateWithInfoEvents::fromHistory($event);
})->throws(RuntimeException::class);
