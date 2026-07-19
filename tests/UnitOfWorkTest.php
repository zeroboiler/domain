<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Domain\InMemoryUnitOfWork;
use ZeroBoiler\Domain\Tests\Fixtures\TestAggregate;

beforeEach(function (): void {
    $this->uow = new InMemoryUnitOfWork;
});

// ─── Basic Lifecycle ───────────────────────────────────────────────

it('starts inactive', function (): void {
    expect($this->uow->isActive())->toBeFalse();
});

it('activates on begin', function (): void {
    $this->uow->begin();

    expect($this->uow->isActive())->toBeTrue();
});

it('deactivates on commit', function (): void {
    $this->uow->begin();
    $this->uow->commit();

    expect($this->uow->isActive())->toBeFalse();
});

it('deactivates on rollback', function (): void {
    $this->uow->begin();
    $this->uow->rollback();

    expect($this->uow->isActive())->toBeFalse();
});

it('throws when committing without active scope', function (): void {
    $this->uow->commit();
})->throws(RuntimeException::class, 'No active unit of work');

it('throws when rolling back without active scope', function (): void {
    $this->uow->rollback();
})->throws(RuntimeException::class, 'No active unit of work');

it('throws when tracking without active scope', function (): void {
    $aggregate = TestAggregate::create(AggregateRootId::generate());
    $aggregate->clearEvents();

    $this->uow->track($aggregate);
})->throws(RuntimeException::class, 'No active unit of work');

// ─── Aggregate Tracking ────────────────────────────────────────────

it('tracks aggregates', function (): void {
    $aggregate = TestAggregate::create(AggregateRootId::generate());

    $this->uow->begin();
    $this->uow->track($aggregate);

    expect($this->uow->isTracking($aggregate))->toBeTrue();
});

it('does not track aggregates outside uow', function (): void {
    $aggregate = TestAggregate::create(AggregateRootId::generate());
    $aggregate->clearEvents();

    expect($this->uow->isTracking($aggregate))->toBeFalse();
});

it('tracks multiple aggregates', function (): void {
    $a1 = TestAggregate::create(AggregateRootId::generate());
    $a2 = TestAggregate::create(AggregateRootId::generate());

    $this->uow->begin();
    $this->uow->track($a1);
    $this->uow->track($a2);

    expect($this->uow->isTracking($a1))->toBeTrue();
    expect($this->uow->isTracking($a2))->toBeTrue();
});

it('does not double-track same aggregate', function (): void {
    $aggregate = TestAggregate::create(AggregateRootId::generate());

    $this->uow->begin();
    $this->uow->track($aggregate);
    $this->uow->track($aggregate); // idempotent

    expect($this->uow->isTracking($aggregate))->toBeTrue();
});

it('moves tracked to committed on commit', function (): void {
    $aggregate = TestAggregate::create(AggregateRootId::generate());

    $this->uow->begin();
    $this->uow->track($aggregate);
    $this->uow->commit();

    $committed = $this->uow->getCommitted();

    expect($committed)->toHaveCount(1);
    expect(array_first($committed))->toBe($aggregate);
});

it('discards tracked on rollback', function (): void {
    $aggregate = TestAggregate::create(AggregateRootId::generate());

    $this->uow->begin();
    $this->uow->track($aggregate);
    $this->uow->rollback();

    expect($this->uow->getCommitted())->toBeEmpty();
});

it('supports mark for deletion', function (): void {
    $aggregate = TestAggregate::create(AggregateRootId::generate());

    $this->uow->begin();
    $this->uow->markForDeletion($aggregate);
    $this->uow->commit();

    expect($this->uow->getDeleted())->toHaveCount(1);
});

// ─── Domain Event Queueing ─────────────────────────────────────────

it('collects domain events from tracked aggregates', function (): void {
    $aggregate = TestAggregate::create(AggregateRootId::generate());

    $this->uow->begin();
    $this->uow->track($aggregate); // pulls events from aggregate

    expect($this->uow->hasPendingEvents())->toBeTrue();
    expect($this->uow->getPendingEventCount())->toBe(1); // TestAggregateCreated
});

it('clears aggregate events on track', function (): void {
    $aggregate = TestAggregate::create(AggregateRootId::generate());

    $this->uow->begin();
    $this->uow->track($aggregate);

    expect($aggregate->hasUncommittedEvents())->toBeFalse();
});

it('queues manual events via queueEvent', function (): void {
    $event = DomainEvent::occur('test.event', ['key' => 'value']);

    $this->uow->begin();
    $this->uow->queueEvent($event);

    expect($this->uow->hasPendingEvents())->toBeTrue();
    expect($this->uow->getPendingEventCount())->toBe(1);
});

it('throws when queueing event without active scope', function (): void {
    $this->uow->queueEvent(DomainEvent::occur('test'));
})->throws(RuntimeException::class, 'No active unit of work');

it('discards events on rollback', function (): void {
    $aggregate = TestAggregate::create(AggregateRootId::generate());

    $this->uow->begin();
    $this->uow->track($aggregate);
    $this->uow->queueEvent(DomainEvent::occur('manual.event'));

    expect($this->uow->hasPendingEvents())->toBeTrue();

    $this->uow->rollback();

    expect($this->uow->hasPendingEvents())->toBeFalse();
    expect($this->uow->getPendingEventCount())->toBe(0);
});

it('events are not dispatched until commit', function (): void {
    $dispatched = [];
    $this->uow->setEventDispatcher(function (DomainEvent $event) use (&$dispatched): void {
        $dispatched[] = $event->eventType;
    });

    $this->uow->begin();
    $this->uow->queueEvent(DomainEvent::occur('event.one'));
    $this->uow->queueEvent(DomainEvent::occur('event.two'));

    expect($dispatched)->toBeEmpty();

    $this->uow->commit();

    expect($dispatched)->toBe(['event.one', 'event.two']);
});

it('events dispatch in order they were raised', function (): void {
    $dispatched = [];
    $this->uow->setEventDispatcher(function (DomainEvent $event) use (&$dispatched): void {
        $dispatched[] = $event->eventType;
    });

    $this->uow->begin();
    $this->uow->queueEvent(DomainEvent::occur('first'));
    $this->uow->queueEvent(DomainEvent::occur('second'));
    $this->uow->queueEvent(DomainEvent::occur('third'));
    $this->uow->commit();

    expect($dispatched)->toBe(['first', 'second', 'third']);
});

it('rollback discards events without dispatch', function (): void {
    $dispatched = [];
    $this->uow->setEventDispatcher(function (DomainEvent $event) use (&$dispatched): void {
        $dispatched[] = $event->eventType;
    });

    $this->uow->begin();
    $this->uow->queueEvent(DomainEvent::occur('should.never.dispatch'));
    $this->uow->rollback();

    expect($dispatched)->toBeEmpty();
});

it('commit dispatches events from tracked aggregates', function (): void {
    $dispatched = [];
    $this->uow->setEventDispatcher(function (DomainEvent $event) use (&$dispatched): void {
        $dispatched[] = $event->eventType;
    });

    $aggregate = TestAggregate::create(AggregateRootId::generate());

    $this->uow->begin();
    $this->uow->track($aggregate);
    $this->uow->commit();

    expect($dispatched)->toBe(['TestAggregateCreated']);
});

// ─── IMP-1-R39: Auto-collect events at commit ─────────────────────

it('auto collects events raised after track at commit time', function (): void {
    $dispatched = [];
    $this->uow->setEventDispatcher(function (DomainEvent $event) use (&$dispatched): void {
        $dispatched[] = $event->eventType;
    });

    $aggregate = TestAggregate::create(AggregateRootId::generate());

    $this->uow->begin();
    $this->uow->track($aggregate); // pulls TestAggregateCreated

    // Raise more events after track — no manual queueEvent needed!
    $aggregate->rename('Updated Name');

    $this->uow->commit();

    // IMP-1-R39: commit() should auto-collect the rename event
    expect($dispatched)->toBe(['TestAggregateCreated', 'TestAggregateRenamed']);
});

it('auto collects multiple post-track events', function (): void {
    $dispatched = [];
    $this->uow->setEventDispatcher(function (DomainEvent $event) use (&$dispatched): void {
        $dispatched[] = $event->eventType;
    });

    $aggregate = TestAggregate::create(AggregateRootId::generate());

    $this->uow->begin();
    $this->uow->track($aggregate);

    $aggregate->rename('First');
    $aggregate->rename('Second');

    $this->uow->commit();

    expect($dispatched)->toBe([
        'TestAggregateCreated',
        'TestAggregateRenamed',
        'TestAggregateRenamed',
    ]);
});

// ─── run() Helper ──────────────────────────────────────────────────

it('run commits on success', function (): void {
    $result = $this->uow->run(fn (): string => 'success');

    expect($result)->toBe('success');
    expect($this->uow->isActive())->toBeFalse();
});

it('run rollbacks on exception', function (): void {
    $dispatched = [];
    $this->uow->setEventDispatcher(function (DomainEvent $event) use (&$dispatched): void {
        $dispatched[] = $event->eventType;
    });

    try {
        $this->uow->run(function (): never {
            $this->uow->queueEvent(DomainEvent::occur('doomed'));

            throw new RuntimeException('boom');
        });
    } catch (RuntimeException $runtimeException) {
        expect($runtimeException->getMessage())->toBe('boom');
    }

    expect($this->uow->isActive())->toBeFalse();
    expect($dispatched)->toBeEmpty(); // events discarded
});

it('run tracks aggregates and dispatches events', function (): void {
    $dispatched = [];
    $this->uow->setEventDispatcher(function (DomainEvent $event) use (&$dispatched): void {
        $dispatched[] = $event->eventType;
    });

    $aggregate = TestAggregate::create(AggregateRootId::generate());

    $this->uow->run(function () use ($aggregate): void {
        $this->uow->track($aggregate);
    });

    expect($dispatched)->toBe(['TestAggregateCreated']);
    expect($this->uow->getCommitted())->toHaveCount(1);
});

it('run returns callback result', function (): void {
    $result = $this->uow->run(fn (): int => 42);

    expect($result)->toBe(42);
});

// ─── Nested Transactions (Savepoints) ──────────────────────────────

it('supports nested run calls', function (): void {
    $dispatched = [];
    $this->uow->setEventDispatcher(function (DomainEvent $event) use (&$dispatched): void {
        $dispatched[] = $event->eventType;
    });

    $this->uow->run(function (): void {
        $this->uow->queueEvent(DomainEvent::occur('outer'));

        $this->uow->run(function (): void {
            $this->uow->queueEvent(DomainEvent::occur('inner'));
        });
    });

    // Events only dispatch when outermost commits
    expect($dispatched)->toBe(['outer', 'inner']);
});

it('inner rollback does not discard outer events', function (): void {
    $dispatched = [];
    $this->uow->setEventDispatcher(function (DomainEvent $event) use (&$dispatched): void {
        $dispatched[] = $event->eventType;
    });

    $this->uow->run(function (): void {
        $this->uow->queueEvent(DomainEvent::occur('outer'));

        try {
            $this->uow->run(function (): never {
                $this->uow->queueEvent(DomainEvent::occur('inner'));

                throw new RuntimeException('inner fail');
            });
        } catch (RuntimeException) {
            // swallow inner failure
        }

        $this->uow->queueEvent(DomainEvent::occur('after-inner'));
    });

    expect($dispatched)->toBe(['outer', 'after-inner']);
});

it('outer rollback discards all events', function (): void {
    $dispatched = [];
    $this->uow->setEventDispatcher(function (DomainEvent $event) use (&$dispatched): void {
        $dispatched[] = $event->eventType;
    });

    try {
        $this->uow->run(function (): void {
            $this->uow->queueEvent(DomainEvent::occur('outer'));

            $this->uow->run(function (): void {
                $this->uow->queueEvent(DomainEvent::occur('inner-committed'));
            });
            // inner committed, but outer will rollback

            throw new RuntimeException('outer fail');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect($dispatched)->toBeEmpty();
});

it('nested begin/commit works without run', function (): void {
    $dispatched = [];
    $this->uow->setEventDispatcher(function (DomainEvent $event) use (&$dispatched): void {
        $dispatched[] = $event->eventType;
    });

    $this->uow->begin();
    $this->uow->queueEvent(DomainEvent::occur('outer'));

    $this->uow->begin(); // savepoint
    $this->uow->queueEvent(DomainEvent::occur('inner'));
    $this->uow->commit(); // inner commit

    $this->uow->commit(); // outer commit — dispatches all

    expect($dispatched)->toBe(['outer', 'inner']);
});

it('nested rollback restores to savepoint', function (): void {
    $dispatched = [];
    $this->uow->setEventDispatcher(function (DomainEvent $event) use (&$dispatched): void {
        $dispatched[] = $event->eventType;
    });

    $this->uow->begin();
    $this->uow->queueEvent(DomainEvent::occur('outer'));

    $this->uow->begin(); // savepoint
    $this->uow->queueEvent(DomainEvent::occur('inner'));
    $this->uow->rollback(); // rollback to savepoint — discard inner

    $this->uow->queueEvent(DomainEvent::occur('after-rollback'));
    $this->uow->commit();

    expect($dispatched)->toBe(['outer', 'after-rollback']);
});

// ─── Multi-Aggregate Integration ───────────────────────────────────

it('handles multi-aggregate transaction with auto-collect at commit', function (): void {
    $dispatched = [];
    $this->uow->setEventDispatcher(function (DomainEvent $event) use (&$dispatched): void {
        $dispatched[] = $event->eventType;
    });

    $order = TestAggregate::create(AggregateRootId::generate());
    $customer = TestAggregate::create(AggregateRootId::generate());

    $this->uow->run(function () use ($order, $customer): void {
        $this->uow->track($order);
        $this->uow->track($customer);

        // Raise more events after tracking — IMP-1-R39: auto-collected at commit
        $order->rename('Order #1');
        $customer->rename('John Doe');
    });

    // 4 events: 2 created + 2 renamed (auto-collected at commit)
    expect($dispatched)->toHaveCount(4);
    expect($dispatched[0])->toBe('TestAggregateCreated');
    expect($dispatched[1])->toBe('TestAggregateCreated');
    expect($dispatched[2])->toBe('TestAggregateRenamed');
    expect($dispatched[3])->toBe('TestAggregateRenamed');

    expect($this->uow->getCommitted())->toHaveCount(2);
});

it('multi-aggregate rollback discards all events', function (): void {
    $dispatched = [];
    $this->uow->setEventDispatcher(function (DomainEvent $event) use (&$dispatched): void {
        $dispatched[] = $event->eventType;
    });

    $order = TestAggregate::create(AggregateRootId::generate());
    $customer = TestAggregate::create(AggregateRootId::generate());

    try {
        $this->uow->run(function () use ($order, $customer): void {
            $this->uow->track($order);
            $this->uow->track($customer);

            throw new RuntimeException('simulated failure');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect($dispatched)->toBeEmpty();
    expect($this->uow->getCommitted())->toBeEmpty();
});

// ─── Edge Cases ────────────────────────────────────────────────────

it('clear resets all state', function (): void {
    $aggregate = TestAggregate::create(AggregateRootId::generate());

    $this->uow->begin();
    $this->uow->track($aggregate);
    $this->uow->commit();

    expect($this->uow->getCommitted())->toHaveCount(1);

    $this->uow->clear();

    expect($this->uow->getCommitted())->toBeEmpty();
    expect($this->uow->getDeleted())->toBeEmpty();
    expect($this->uow->isActive())->toBeFalse();
    expect($this->uow->hasPendingEvents())->toBeFalse();
});

it('multiple sequential transactions work independently', function (): void {
    $dispatched = [];
    $this->uow->setEventDispatcher(function (DomainEvent $event) use (&$dispatched): void {
        $dispatched[] = $event->eventType;
    });

    // First transaction
    $this->uow->begin();
    $this->uow->queueEvent(DomainEvent::occur('tx1.event'));
    $this->uow->commit();

    // Second transaction
    $this->uow->begin();
    $this->uow->queueEvent(DomainEvent::occur('tx2.event'));
    $this->uow->commit();

    expect($dispatched)->toBe(['tx1.event', 'tx2.event']);
});

it('aggregate events pulled once on track', function (): void {
    $aggregate = TestAggregate::create(AggregateRootId::generate());

    $this->uow->begin();
    $this->uow->track($aggregate);

    // Events pulled during track
    $initialCount = $this->uow->getPendingEventCount();
    expect($initialCount)->toBe(1);

    // Track again — should be idempotent, no duplicate events
    $this->uow->track($aggregate);
    expect($this->uow->getPendingEventCount())->toBe(1);
});

it('no dispatch when no event dispatcher set', function (): void {
    // No dispatcher set — events should be silently discarded
    $this->uow->begin();
    $this->uow->queueEvent(DomainEvent::occur('orphan'));
    $this->uow->commit();

    // No exception, no errors
    expect($this->uow->isActive())->toBeFalse();
});

it('getPendingEventCount returns 0 when inactive', function (): void {
    expect($this->uow->getPendingEventCount())->toBe(0);
});

it('hasPendingEvents returns false when inactive', function (): void {
    expect($this->uow->hasPendingEvents())->toBeFalse();
});

// ─── BUG-1-R39: Aggregate re-usable after rollback ─────────────────

it('aggregate can raise new events after rollback in new transaction', function (): void {
    $dispatched = [];
    $this->uow->setEventDispatcher(function (DomainEvent $event) use (&$dispatched): void {
        $dispatched[] = $event->eventType;
    });

    $aggregate = TestAggregate::create(AggregateRootId::generate());

    // First transaction: track then rollback
    $this->uow->begin();
    $this->uow->track($aggregate); // pulls TestAggregateCreated
    $this->uow->rollback();

    // Events from the first transaction are discarded
    expect($dispatched)->toBeEmpty();

    // The aggregate's events were pulled during track().
    // This is expected behavior: the UoW took ownership of dispatch.
    // After rollback, those specific events are lost (by design).
    // The aggregate itself can still be used in new transactions.
    expect($aggregate->hasUncommittedEvents())->toBeFalse();

    // New transaction: aggregate raises NEW events
    $this->uow->begin();
    $aggregate->rename('New Name');
    $this->uow->track($aggregate); // pulls TestAggregateRenamed
    $this->uow->commit();

    expect($dispatched)->toBe(['TestAggregateRenamed']);
});

it('manual queueEvent still works alongside auto-collect', function (): void {
    $dispatched = [];
    $this->uow->setEventDispatcher(function (DomainEvent $event) use (&$dispatched): void {
        $dispatched[] = $event->eventType;
    });

    $aggregate = TestAggregate::create(AggregateRootId::generate());

    $this->uow->begin();
    $this->uow->track($aggregate); // pulls TestAggregateCreated
    $this->uow->queueEvent(DomainEvent::occur('manual.event')); // manual queue
    $aggregate->rename('Auto'); // auto-collected at commit
    $this->uow->commit();

    expect($dispatched)->toBe([
        'TestAggregateCreated',
        'manual.event',
        'TestAggregateRenamed',
    ]);
});

// ─── ISSUE-#7: Snapshot-based rollback ─────────────────────────────

it('rollback restores aggregate mutable state from snapshot', function (): void {
    $aggregate = TestAggregate::create(AggregateRootId::generate());
    $aggregate->clearEvents();

    $this->uow->begin();
    $this->uow->track($aggregate);

    // Mutate after tracking
    $aggregate->rename('Changed Name');
    expect($aggregate->name)->toBe('Changed Name');

    $this->uow->rollback();

    // State should be restored to snapshot (name = null)
    expect($aggregate->name)->toBeNull();
    expect($aggregate->nameChanged)->toBeFalse();
});

it('rollback restores aggregate version from snapshot', function (): void {
    $aggregate = TestAggregate::create(AggregateRootId::generate());
    $aggregate->clearEvents();
    $versionBeforeTrack = $aggregate->version();

    $this->uow->begin();
    $this->uow->track($aggregate);
    $aggregate->rename('Version Bump'); // increments version
    expect($aggregate->version())->toBeGreaterThan($versionBeforeTrack);

    $this->uow->rollback();

    expect($aggregate->version())->toBe($versionBeforeTrack);
});

it('commit does not restore snapshot (mutations persist)', function (): void {
    $aggregate = TestAggregate::create(AggregateRootId::generate());
    $aggregate->clearEvents();

    $this->uow->begin();
    $this->uow->track($aggregate);
    $aggregate->rename('Committed Name');
    $this->uow->commit();

    expect($aggregate->name)->toBe('Committed Name');
});

it('inner rollback does not restore state (snapshot is per-track not per-begin)', function (): void {
    // This test documents the design: snapshots are taken in track(),
    // not in begin(). Inner savepoints that don't call track() will
    // not capture/restore aggregate state on rollback.
    $aggregate = TestAggregate::create(AggregateRootId::generate());
    $aggregate->clearEvents();

    $this->uow->begin();
    $this->uow->track($aggregate); // snapshot taken here with name=null
    $aggregate->rename('Outer');

    // Inner savepoint — no track() call, so no snapshot
    $this->uow->begin();
    $aggregate->rename('Inner');
    $this->uow->rollback(); // inner rollback discards inner events only

    // State is NOT restored to 'Outer' because inner scope had no snapshot.
    // State restoration only happens for the scope where track() was called.
    expect($aggregate->name)->toBe('Inner');

    $this->uow->rollback(); // outer rollback restores to snapshot (name=null)
    expect($aggregate->name)->toBeNull();
});

it('snapshot taken at track time captures pre-mutation state', function (): void {
    $aggregate = TestAggregate::create(AggregateRootId::generate());
    $aggregate->rename('Initial');
    $aggregate->clearEvents();

    $this->uow->begin();
    $this->uow->track($aggregate); // snapshot taken here with name='Initial'
    $aggregate->rename('After Track');
    $this->uow->rollback();

    expect($aggregate->name)->toBe('Initial');
});

it('multiple aggregates restored independently on rollback', function (): void {
    $a1 = TestAggregate::create(AggregateRootId::generate());
    $a2 = TestAggregate::create(AggregateRootId::generate());
    $a1->clearEvents();
    $a2->clearEvents();

    $this->uow->begin();
    $this->uow->track($a1);
    $this->uow->track($a2);
    $a1->rename('A1 Changed');
    $a2->rename('A2 Changed');
    $this->uow->rollback();

    expect($a1->name)->toBeNull();
    expect($a2->name)->toBeNull();
});

it('aggregate can be tracked in new transaction after rollback with state restored', function (): void {
    $aggregate = TestAggregate::create(AggregateRootId::generate());
    $aggregate->clearEvents();

    // First transaction: track, mutate, rollback
    $this->uow->begin();
    $this->uow->track($aggregate);
    $aggregate->rename('Rolled Back');
    $this->uow->rollback();

    expect($aggregate->name)->toBeNull();

    // Second transaction: track again, mutate, commit
    $dispatched = [];
    $this->uow->setEventDispatcher(function (DomainEvent $event) use (&$dispatched): void {
        $dispatched[] = $event->eventType;
    });

    $this->uow->begin();
    $aggregate->rename('Committed');
    $this->uow->track($aggregate);
    $this->uow->commit();

    expect($aggregate->name)->toBe('Committed');
    expect($dispatched)->toBe(['TestAggregateRenamed']);
});

// ─── ISSUE-#7: Persistence callback ────────────────────────────────

it('invokes persistence callback on commit with committed and deleted aggregates', function (): void {
    $persisted = null;
    $deletedData = null;
    $this->uow->setPersistenceCallback(function (array $committed, array $deleted) use (&$persisted, &$deletedData): void {
        $persisted = $committed;
        $deletedData = $deleted;
    });

    $aggregate = TestAggregate::create(AggregateRootId::generate());

    $this->uow->begin();
    $this->uow->track($aggregate);
    $this->uow->commit();

    expect($persisted)->toHaveCount(1);
    expect(array_first($persisted))->toBe($aggregate);
    expect($deletedData)->toBeEmpty();
});

it('invokes persistence callback with deleted aggregates', function (): void {
    $persisted = [];
    $deletedData = [];
    $this->uow->setPersistenceCallback(function (array $committed, array $deleted) use (&$persisted, &$deletedData): void {
        $persisted = $committed;
        $deletedData = $deleted;
    });

    $aggregate = TestAggregate::create(AggregateRootId::generate());

    $this->uow->begin();
    $this->uow->track($aggregate);
    $this->uow->commit();

    // Now delete in a second transaction
    $this->uow->begin();
    $this->uow->markForDeletion($aggregate);
    $this->uow->commit();

    expect($deletedData)->toHaveCount(1);
    expect(array_first($deletedData))->toBe($aggregate);
});

it('does not invoke persistence callback on rollback', function (): void {
    $called = false;
    $this->uow->setPersistenceCallback(function () use (&$called): void {
        $called = true;
    });

    $aggregate = TestAggregate::create(AggregateRootId::generate());

    $this->uow->begin();
    $this->uow->track($aggregate);
    $this->uow->rollback();

    expect($called)->toBeFalse();
});

it('persistence callback receives aggregates from all committed scopes', function (): void {
    $persistedCount = 0;
    $this->uow->setPersistenceCallback(function (array $committed) use (&$persistedCount): void {
        $persistedCount = count($committed);
    });

    $a1 = TestAggregate::create(AggregateRootId::generate());
    $a2 = TestAggregate::create(AggregateRootId::generate());

    $this->uow->run(function () use ($a1, $a2): void {
        $this->uow->track($a1);

        $this->uow->run(function () use ($a2): void {
            $this->uow->track($a2);
        });
    });

    expect($persistedCount)->toBe(2);
});

it('persistence callback fires before event dispatch', function (): void {
    $order = [];
    $this->uow->setPersistenceCallback(function () use (&$order): void {
        $order[] = 'persist';
    });
    $this->uow->setEventDispatcher(function (DomainEvent $event) use (&$order): void {
        $order[] = 'dispatch:' . $event->eventType;
    });

    $aggregate = TestAggregate::create(AggregateRootId::generate());

    $this->uow->begin();
    $this->uow->track($aggregate);
    $this->uow->commit();

    expect($order[0])->toBe('persist');
    expect($order[1])->toBe('dispatch:TestAggregateCreated');
});

it('works without persistence callback set', function (): void {
    $aggregate = TestAggregate::create(AggregateRootId::generate());

    $this->uow->begin();
    $this->uow->track($aggregate);
    $this->uow->commit();

    expect($this->uow->getCommitted())->toHaveCount(1);
});
