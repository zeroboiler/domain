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

it('fromHistory resets version to 0 before replaying via setVersion (BUG-3 R33)', function (): void {
    $id = $this->aggregateId->toString();

    $createdEvent = DomainEvent::occur('aggregate.created', [
        'id' => $id,
        'name' => 'Test',
    ]);

    // fromHistory should reset version to 0 then replay events.
    // With 1 event that has a handler, version should be 1 (not 2).
    // If reflection was used incorrectly and the constructor incremented version,
    // the final version could be wrong. This test verifies setVersion is used.
    $aggregate = AggregateWithInfoEvents::fromHistory($createdEvent);

    // 1 event replayed → version should be exactly 1
    expect($aggregate->getVersion())->toBe(1);
});

it('fromHistory does not use ReflectionClass (BUG-3 R33)', function (): void {
    // Verify the EventSourced trait no longer imports or uses ReflectionClass.
    // The trait should use the public setVersion() method instead.
    $source = file_get_contents(__DIR__ . '/../src/Concerns/EventSourced.php');

    expect($source)->not->toContain('ReflectionClass')
        ->and($source)->not->toContain('use ReflectionClass')
        ->and($source)->toContain('setVersion(0)');
});
