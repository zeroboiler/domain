<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\Tests\Fixtures\AggregateWithInfoEvents;
use ZeroBoiler\Events\Domain\DomainEvent;

// ─── fromHistory() ─────────────────────────────────────────────────

it('reconstitutes aggregate from single event', function (): void {
    $id = AggregateRootId::generate();

    $events = [
        DomainEvent::occur('aggregate.created', [
            'id' => $id->toString(),
            'name' => 'Test Aggregate',
        ]),
    ];

    $aggregate = AggregateWithInfoEvents::fromHistory(...$events);

    expect($aggregate->id())->toBe($id->toString());
    expect($aggregate->name)->toBe('Test Aggregate');
    expect($aggregate->version())->toBe(1);
});

it('reconstitutes aggregate from multiple events', function (): void {
    $id = AggregateRootId::generate();

    $events = [
        DomainEvent::occur('aggregate.created', [
            'id' => $id->toString(),
            'name' => 'Initial',
        ]),
        // Only 'aggregate.created' has a handler — second event is informational
        DomainEvent::occur('aggregate.noted', [
            'id' => $id->toString(),
            'note' => 'some note',
        ]),
    ];

    $aggregate = AggregateWithInfoEvents::fromHistory(...$events);

    // applyAggregateCreated sets name; aggregate.noted has no handler (lenient skip)
    expect($aggregate->name)->toBe('Initial');
});

it('reconstitutes from aggregate_id key in payload', function (): void {
    $id = AggregateRootId::generate();

    $events = [
        DomainEvent::occur('aggregate.created', [
            'aggregate_id' => $id->toString(),
            'name' => 'Via Aggregate ID',
        ]),
    ];

    $aggregate = AggregateWithInfoEvents::fromHistory(...$events);

    expect($aggregate->id())->toBe($id->toString());
    expect($aggregate->name)->toBe('Via Aggregate ID');
});

it('throws when reconstituting from empty history', function (): void {
    AggregateWithInfoEvents::fromHistory();
})->throws(RuntimeException::class, 'Cannot reconstitute');

it('throws when first event has no id or aggregate_id', function (): void {
    $events = [
        DomainEvent::occur('aggregate.created', [
            'name' => 'No ID',
        ]),
    ];

    AggregateWithInfoEvents::fromHistory(...$events);
})->throws(RuntimeException::class, 'first event must contain an "id" or "aggregate_id"');

it('reconstituted aggregate has no uncommitted events', function (): void {
    $id = AggregateRootId::generate();

    $events = [
        DomainEvent::occur('aggregate.created', [
            'id' => $id->toString(),
            'name' => 'Test',
        ]),
    ];

    $aggregate = AggregateWithInfoEvents::fromHistory(...$events);

    expect($aggregate->hasUncommittedEvents())->toBeFalse();
});

it('reconstituted aggregate version reflects only handled events', function (): void {
    $id = AggregateRootId::generate();

    $events = [
        DomainEvent::occur('aggregate.created', [
            'id' => $id->toString(),
            'name' => 'V1',
        ]),
    ];

    $aggregate = AggregateWithInfoEvents::fromHistory(...$events);

    // Only one event with a handler — version increments per handled event
    expect($aggregate->version())->toBe(1);
});

// ─── applyEvent() ──────────────────────────────────────────────────

it('resolves handler by event type using camelCase convention', function (): void {
    $id = AggregateRootId::generate();

    $aggregate = AggregateWithInfoEvents::create($id, 'Test');
    $aggregate->clearEvents();

    // Event type 'aggregate.created' resolves to applyAggregateCreated()
    expect($aggregate->name)->toBe('Test');
});

it('splits event types on dots for method resolution', function (): void {
    // Verify: 'aggregate.created' → applyAggregateCreated
    // This is tested implicitly by fromHistory tests above.
    // Here we verify the convention explicitly via reflection.

    $reflection = new ReflectionClass(AggregateWithInfoEvents::class);

    expect($reflection->hasMethod('applyAggregateCreated'))->toBeTrue();
});

it('splits event types on underscores and hyphens', function (): void {
    $id = AggregateRootId::generate();

    $events = [
        DomainEvent::occur('aggregate-created', [
            'id' => $id->toString(),
            'name' => 'Hyphen',
        ]),
    ];

    $aggregate = AggregateWithInfoEvents::fromHistory(...$events);

    // 'aggregate-created' should resolve to applyAggregateCreated (hyphen split)
    expect($aggregate->name)->toBe('Hyphen');
});

// ─── fromArray() edge cases ────────────────────────────────────────

it('reconstructs event from array preserving eventId and occurredAt', function (): void {
    $original = DomainEvent::occur('test.event', ['key' => 'value']);

    $data = $original->toArray();

    $reconstructed = DomainEvent::fromArray($data);

    expect($reconstructed->eventType)->toBe('test.event');
    expect($reconstructed->payload)->toBe(['key' => 'value']);
    expect($reconstructed->eventId->toString())->toBe($original->eventId->toString());
    expect($reconstructed->occurredAt->format(DateTimeInterface::ATOM))
        ->toBe($original->occurredAt->format(DateTimeInterface::ATOM));
});

it('fromArray handles missing eventId gracefully', function (): void {
    $data = [
        'eventType' => 'test.event',
        'payload' => ['key' => 'value'],
        'occurredAt' => (new DateTimeImmutable)->format(DateTimeInterface::ATOM),
    ];

    $event = DomainEvent::fromArray($data);

    expect($event->eventType)->toBe('test.event');
    expect($event->eventId)->not->toBeNull();
});

it('fromArray handles missing occurredAt gracefully', function (): void {
    $data = [
        'eventType' => 'test.event',
        'payload' => [],
        'eventId' => '00000000-0000-4000-8000-000000000000',
    ];

    $event = DomainEvent::fromArray($data);

    expect($event->eventType)->toBe('test.event');
    expect($event->occurredAt)->not->toBeNull();
});

it('fromArray handles empty payload', function (): void {
    $data = [
        'eventType' => 'empty.event',
        'payload' => [],
    ];

    $event = DomainEvent::fromArray($data);

    expect($event->payload)->toBe([]);
});

it('toArray and fromArray are round-trip safe', function (): void {
    $original = DomainEvent::occur('round.trip', [
        'nested' => ['data' => [1, 2, 3]],
        'string' => 'value',
        'bool' => true,
    ]);

    $reconstructed = DomainEvent::fromArray($original->toArray());

    expect($reconstructed->eventType)->toBe($original->eventType);
    expect($reconstructed->payload)->toBe($original->payload);
    expect($reconstructed->eventId->toString())->toBe($original->eventId->toString());
});

// ─── DomainEvent::occur() ──────────────────────────────────────────

it('occur creates event with auto-generated UUID and timestamp', function (): void {
    $event = DomainEvent::occur('test.type');

    expect($event->eventType)->toBe('test.type');
    expect($event->payload)->toBe([]);
    expect($event->eventId)->not->toBeNull();
    expect($event->occurredAt)->not->toBeNull();
});

it('occur creates event with payload', function (): void {
    $event = DomainEvent::occur('user.registered', [
        'userId' => 123,
        'email' => 'test@example.com',
    ]);

    expect($event->payload)->toBe([
        'userId' => 123,
        'email' => 'test@example.com',
    ]);
});

// ─── EventSourced with informational events (lenient mode) ─────────

it('skips events without apply handler in lenient mode during reconstitution', function (): void {
    $id = AggregateRootId::generate();

    $events = [
        DomainEvent::occur('aggregate.created', [
            'id' => $id->toString(),
            'name' => 'Created',
        ]),
        // This event has no handler — purely informational
        DomainEvent::occur('aggregate.noted', [
            'id' => $id->toString(),
            'note' => 'some info',
        ]),
    ];

    // Should not throw — lenient mode skips events without handlers
    $aggregate = AggregateWithInfoEvents::fromHistory(...$events);

    expect($aggregate->name)->toBe('Created');
    // Version increments only for handled events (applyAggregateCreated)
    // aggregate.noted has no handler in lenient mode — skipped entirely
    expect($aggregate->version())->toBe(1);
});
