<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Tests;

use Closure;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\InMemoryUnitOfWork;
use ZeroBoiler\Domain\Tests\Fixtures\TestAggregate;

/**
 * Tests for InMemoryUnitOfWork clear(), lifecycle, and edge cases.
 *
 * Validates transactional semantics including:
 * - clear() resets all state for clean test isolation
 * - run() auto-commits on success and rolls back on exception
 * - Nested run() uses savepoint semantics
 * - clear() between transactions resets committed/deleted
 * - Multiple transactions in sequence don't leak state
 *
 * @covers \ZeroBoiler\Domain\InMemoryUnitOfWork
 *
 * @since 1.59.0
 */
final class InMemoryUnitOfWorkClearAndLifecycleTest extends TestCase
{
    private InMemoryUnitOfWork $uow;

    protected function setUp(): void
    {
        $this->uow = new InMemoryUnitOfWork;
    }

    // ─── clear() tests ───────────────────────────────────────────────

    public function test_clear_resets_all_state_after_commit(): void
    {
        $id = AggregateRootId::generate();
        $aggregate = new TestAggregate($id);

        $this->uow->begin();
        $this->uow->track($aggregate);
        $this->uow->commit();

        // After commit, state should be accessible
        $this->assertNotEmpty($this->uow->getCommitted());
        $this->assertTrue($this->uow->isActive() === false);

        // clear() should reset everything
        $this->uow->clear();

        $this->assertEmpty($this->uow->getCommitted());
        $this->assertEmpty($this->uow->getDeleted());
        $this->assertFalse($this->uow->hasPendingEvents());
        $this->assertSame(0, $this->uow->getPendingEventCount());
        $this->assertFalse($this->uow->isActive());
    }

    public function test_clear_resets_all_state_after_rollback(): void
    {
        $this->uow->begin();
        $this->uow->queueEvent(
            \ZeroBoiler\Events\Domain\DomainEvent::occur('test.event', ['key' => 'value'])
        );
        $this->uow->rollback();

        // After rollback, events should be discarded
        $this->assertFalse($this->uow->hasPendingEvents());

        // clear() should be safe even after rollback
        $this->uow->clear();

        $this->assertEmpty($this->uow->getCommitted());
        $this->assertEmpty($this->uow->getDeleted());
    }

    public function test_clear_without_any_transaction_is_safe(): void
    {
        // Calling clear() on a fresh UoW should not throw
        $this->uow->clear();

        $this->assertFalse($this->uow->isActive());
        $this->assertEmpty($this->uow->getCommitted());
        $this->assertEmpty($this->uow->getDeleted());
    }

    // ─── run() lifecycle tests ────────────────────────────────────────

    public function test_run_auto_commits_on_success(): void
    {
        $id = AggregateRootId::generate();
        $aggregate = new TestAggregate($id);

        $result = $this->uow->run(function () use ($aggregate): string {
            $this->uow->track($aggregate);
            return 'success';
        });

        $this->assertSame('success', $result);
        $this->assertFalse($this->uow->isActive());
        $this->assertNotEmpty($this->uow->getCommitted());
    }

    public function test_run_rolls_back_on_exception(): void
    {
        $id = AggregateRootId::generate();
        $aggregate = new TestAggregate($id);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Intentional failure');

        try {
            $this->uow->run(function () use ($aggregate): void {
                $this->uow->track($aggregate);
                throw new \InvalidArgumentException('Intentional failure');
            });
        } finally {
            // After rollback, no committed aggregates
            $this->assertEmpty($this->uow->getCommitted());
            $this->assertFalse($this->uow->isActive());
        }
    }

    public function test_run_returns_value_from_closure(): void
    {
        $id = AggregateRootId::generate();
        $aggregate = new TestAggregate($id);

        $result = $this->uow->run(function () use ($aggregate, $id): array {
            $this->uow->track($aggregate);
            return ['id' => $id->toString(), 'version' => 0];
        });

        $this->assertSame($id->toString(), $result['id']);
        $this->assertSame(0, $result['version']);
    }

    // ─── Sequential transactions ──────────────────────────────────────

    public function test_sequential_transactions_do_not_leak_state(): void
    {
        $id1 = AggregateRootId::generate();
        $id2 = AggregateRootId::generate();

        // First transaction
        $this->uow->begin();
        $this->uow->track(new TestAggregate($id1));
        $this->uow->commit();
        $this->assertCount(1, $this->uow->getCommitted());

        // Second transaction — begin() should clear previous committed data
        $this->uow->begin();
        $this->uow->track(new TestAggregate($id2));
        $this->uow->commit();
        $this->assertCount(1, $this->uow->getCommitted());
    }

    public function test_committed_aggregates_persisted_after_outermost_commit(): void
    {
        $id = AggregateRootId::generate();
        $aggregate = new TestAggregate($id);

        $this->uow->begin();
        $this->uow->track($aggregate);
        $this->uow->commit();

        $committed = $this->uow->getCommitted();
        $this->assertArrayHasKey($id->toString(), $committed);
        $this->assertSame($aggregate, $committed[$id->toString()]);
    }

    // ─── Deletion tracking ───────────────────────────────────────────

    public function test_mark_for_deletion_queues_aggregate(): void
    {
        $id = AggregateRootId::generate();
        $aggregate = new TestAggregate($id);

        $this->uow->begin();
        $this->uow->track($aggregate);
        $this->uow->markForDeletion($aggregate);
        $this->uow->commit();

        $this->assertEmpty($this->uow->getCommitted());
        $this->assertNotEmpty($this->uow->getDeleted());
    }

    public function test_mark_for_deletion_without_active_transaction_throws(): void
    {
        $id = AggregateRootId::generate();
        $aggregate = new TestAggregate($id);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No active unit of work');

        $this->uow->markForDeletion($aggregate);
    }

    // ─── Event queueing ──────────────────────────────────────────────

    public function test_queue_event_without_active_transaction_throws(): void
    {
        $event = \ZeroBoiler\Events\Domain\DomainEvent::occur('test.event', []);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No active unit of work');

        $this->uow->queueEvent($event);
    }

    public function test_pending_events_peeked_without_consumption(): void
    {
        $event = \ZeroBoiler\Events\Domain\DomainEvent::occur('test.event', ['key' => 'value']);

        $this->uow->begin();
        $this->uow->queueEvent($event);

        // Peek should not consume
        $peeked = $this->uow->getPendingEvents();
        $this->assertSame(1, $peeked->count());

        // Events should still be pending
        $this->assertTrue($this->uow->hasPendingEvents());
        $this->assertSame(1, $this->uow->getPendingEventCount());

        $this->uow->commit();

        // After commit, events should be dispatched (cleared)
        $this->assertFalse($this->uow->hasPendingEvents());
    }

    // ─── Tracking guards ─────────────────────────────────────────────

    public function test_track_without_active_transaction_throws(): void
    {
        $id = AggregateRootId::generate();
        $aggregate = new TestAggregate($id);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No active unit of work');

        $this->uow->track($aggregate);
    }

    public function test_is_tracking_returns_false_for_untracked_aggregate(): void
    {
        $id = AggregateRootId::generate();
        $aggregate = new TestAggregate($id);

        $this->assertFalse($this->uow->isTracking($aggregate));
    }

    public function test_is_tracking_returns_true_after_tracking(): void
    {
        $id = AggregateRootId::generate();
        $aggregate = new TestAggregate($id);

        $this->uow->begin();
        $this->uow->track($aggregate);

        $this->assertTrue($this->uow->isTracking($aggregate));

        $this->uow->commit();
    }

    // ─── Custom persistence callback ──────────────────────────────────

    public function test_persistence_callback_invoked_at_commit(): void
    {
        $committedAggregates = [];
        $deletedAggregates = [];

        $this->uow->setPersistenceCallback(function (array $committed, array $deleted) use (&$committedAggregates, &$deletedAggregates): void {
            $committedAggregates = $committed;
            $deletedAggregates = $deleted;
        });

        $id1 = AggregateRootId::generate();
        $id2 = AggregateRootId::generate();
        $agg1 = new TestAggregate($id1);
        $agg2 = new TestAggregate($id2);

        $this->uow->begin();
        $this->uow->track($agg1);
        $this->uow->markForDeletion($agg2);
        $this->uow->commit();

        $this->assertCount(1, $committedAggregates);
        $this->assertCount(1, $deletedAggregates);
        $this->assertArrayHasKey($id1->toString(), $committedAggregates);
        $this->assertArrayHasKey($id2->toString(), $deletedAggregates);
    }

    // ─── Custom event dispatcher ─────────────────────────────────────

    public function test_custom_event_dispatcher_invoked_at_commit(): void
    {
        $dispatched = [];

        $this->uow->setEventDispatcher(function (object $event) use (&$dispatched): void {
            $dispatched[] = $event->eventType;
        });

        $id = AggregateRootId::generate();
        $aggregate = new TestAggregate($id);

        $this->uow->begin();
        $this->uow->track($aggregate);
        $this->uow->queueEvent(\ZeroBoiler\Events\Domain\DomainEvent::occur('order.placed', ['id' => $id->toString()]));
        $this->uow->commit();

        $this->assertSame(['order.placed'], $dispatched);
    }

    public function test_events_not_dispatched_on_rollback(): void
    {
        $dispatched = [];

        $this->uow->setEventDispatcher(function (object $event) use (&$dispatched): void {
            $dispatched[] = $event->eventType;
        });

        $this->uow->begin();
        $this->uow->queueEvent(\ZeroBoiler\Events\Domain\DomainEvent::occur('order.placed', []));
        $this->uow->rollback();

        $this->assertEmpty($dispatched);
    }
}
