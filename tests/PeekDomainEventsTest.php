<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Tests;

use ZeroBoiler\Domain\AggregateRoot;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\DomainEventCollection;
use ZeroBoiler\Events\Domain\DomainEvent;

/**
 * Tests for non-destructive domain event peeking (peekDomainEvents / peekEvents).
 *
 * Verifies that peeking returns a copy of the event buffer without
 * consuming events, enabling logging, debugging, and inspection
 * without affecting the aggregate's event state.
 *
 * @covers \ZeroBoiler\Domain\AggregateRoot::peekDomainEvents
 * @covers \ZeroBoiler\Domain\Concerns\HasDomainEvents::peekEvents
 */
final class PeekDomainEventsTest extends DomainCoreTest
{
    // ──────────────────────────────────────────────────────────────
    // AggregateRoot::peekDomainEvents()
    // ──────────────────────────────────────────────────────────────

    public function test_peek_returns_empty_collection_when_no_events_recorded(): void
    {
        $aggregate = new class(AggregateRootId::generate()) extends AggregateRoot {
            public function __construct(AggregateRootId $id) { parent::__construct($id); }
        };

        $peeked = $aggregate->peekDomainEvents();

        $this->assertInstanceOf(DomainEventCollection::class, $peeked);
        $this->assertTrue($peeked->isEmpty());
        $this->assertCount(0, $peeked);
    }

    public function test_peek_returns_typed_collection_without_consuming_events(): void
    {
        $aggregate = new class(AggregateRootId::generate()) extends AggregateRoot {
            public function __construct(AggregateRootId $id) { parent::__construct($id); }
            public function recordTestEvent(): void
            {
                $this->recordThat(DomainEvent::occur('test.event', ['key' => 'value']));
            }
        };

        $aggregate->recordTestEvent();
        $aggregate->recordTestEvent();

        // Peek — should return 2 events
        $peeked = $aggregate->peekDomainEvents();
        $this->assertCount(2, $peeked);
        $this->assertEquals('test.event', $peeked->first()?->eventType);

        // Events should still be available via hasUncommittedEvents
        $this->assertTrue($aggregate->hasUncommittedEvents());

        // Peek again — should still return 2 events (not consumed)
        $peekedAgain = $aggregate->peekDomainEvents();
        $this->assertCount(2, $peekedAgain);

        // Pull — should return and consume the events
        $pulled = $aggregate->pullDomainEvents();
        $this->assertCount(2, $pulled);

        // After pull, peek should be empty
        $peekedAfterPull = $aggregate->peekDomainEvents();
        $this->assertTrue($peekedAfterPull->isEmpty());
    }

    public function test_peek_collection_supports_iteration(): void
    {
        $aggregate = new class(AggregateRootId::generate()) extends AggregateRoot {
            public function __construct(AggregateRootId $id) { parent::__construct($id); }
            public function recordEvents(string ...$types): void
            {
                foreach ($types as $type) {
                    $this->recordThat(DomainEvent::occur($type, []));
                }
            }
        };

        $aggregate->recordEvents('order.placed', 'order.paid', 'order.shipped');

        $peeked = $aggregate->peekDomainEvents();
        $types = $peeked->map(fn (DomainEvent $e, int $i): string => $e->eventType);

        $this->assertEquals(['order.placed', 'order.paid', 'order.shipped'], $types);
    }

    public function test_peek_collection_supports_filter(): void
    {
        $aggregate = new class(AggregateRootId::generate()) extends AggregateRoot {
            public function __construct(AggregateRootId $id) { parent::__construct($id); }
            public function recordEvents(string ...$types): void
            {
                foreach ($types as $type) {
                    $this->recordThat(DomainEvent::occur($type, []));
                }
            }
        };

        $aggregate->recordEvents('order.placed', 'order.item_added', 'order.paid');

        $peeked = $aggregate->peekDomainEvents();
        $paymentEvents = $peeked->filter(
            fn (DomainEvent $e): bool => str_contains($e->eventType, 'paid'),
        );

        $this->assertCount(1, $paymentEvents);
        $this->assertEquals('order.paid', $paymentEvents->first()?->eventType);

        // Original events should still be available
        $this->assertTrue($aggregate->hasUncommittedEvents());
        $this->assertCount(3, $aggregate->peekDomainEvents());
    }

    public function test_peek_preserves_version_increment_behavior(): void
    {
        $aggregate = new class(AggregateRootId::generate()) extends AggregateRoot {
            public function __construct(AggregateRootId $id) { parent::__construct($id); }
            public string $status = 'pending';
            public function place(): void
            {
                $this->apply(DomainEvent::occur('order.placed', ['status' => 'pending']));
            }
            protected function applyOrderPlaced(DomainEvent $event): void
            {
                $this->status = $event->payload['status'];
            }
        };

        $aggregate->place();

        // Peek before pull — events still present
        $this->assertCount(1, $aggregate->peekDomainEvents());
        $this->assertEquals(1, $aggregate->version());

        // Peek is non-destructive
        $this->assertCount(1, $aggregate->peekDomainEvents());

        // Pull consumes
        $pulled = $aggregate->pullDomainEvents();
        $this->assertCount(1, $pulled);
        $this->assertCount(0, $aggregate->peekDomainEvents());
    }

    // ──────────────────────────────────────────────────────────────
    // HasDomainEvents::peekEvents() (Entity-level)
    // ──────────────────────────────────────────────────────────────

    public function test_entity_peek_events_returns_array_without_consuming(): void
    {
        $entity = new TestEntityWithPeek('123');

        $entity->recordThat(DomainEvent::occur('entity.created', ['id' => '123']));

        // peekEvents returns plain array (not DomainEventCollection)
        $peeked = $entity->peekEvents();
        $this->assertIsArray($peeked);
        $this->assertCount(1, $peeked);

        // Non-destructive
        $peekedAgain = $entity->peekEvents();
        $this->assertCount(1, $peekedAgain);

        // releaseEvents consumes
        $released = $entity->releaseEvents();
        $this->assertCount(1, $released);

        // After release, peek is empty
        $this->assertCount(0, $entity->peekEvents());
    }
}

// Helper: simple Entity subclass with HasDomainEvents for testing peekEvents()
final class TestEntityWithPeek extends \ZeroBoiler\Domain\Entity
{
    use \ZeroBoiler\Domain\Concerns\HasDomainEvents;

    public function __construct(int|string|\Stringable $id)
    {
        parent::__construct($id);
    }
}
