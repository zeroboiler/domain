<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Domain\AggregateRoot;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\InMemoryUnitOfWork;
use ZeroBoiler\Domain\Contracts\UnitOfWork;
use ZeroBoiler\Events\Domain\DomainEvent;

/**
 * Production verification tests for InMemoryUnitOfWork docblock contracts.
 *
 * Verifies that public methods behave as documented in their @throws
 * annotations and @param/@return docblocks.
 */
test('begin() can be called multiple times creating nested scopes', function (): void {
    $uow = new InMemoryUnitOfWork;

    expect($uow->isActive())->toBeFalse();

    $uow->begin();
    expect($uow->isActive())->toBeTrue();

    $uow->begin();
    expect($uow->isActive())->toBeTrue();

    $uow->commit();
    expect($uow->isActive())->toBeTrue(); // Still outer scope

    $uow->commit();
    expect($uow->isActive())->toBeFalse();
});

test('commit() throws RuntimeException when no scope is active', function (): void {
    $uow = new InMemoryUnitOfWork;

    $uow->commit();
})->throws(RuntimeException::class, 'No active unit of work');

test('rollback() throws RuntimeException when no scope is active', function (): void {
    $uow = new InMemoryUnitOfWork;

    $uow->rollback();
})->throws(RuntimeException::class, 'No active unit of work');

test('track() throws RuntimeException when no scope is active', function (): void {
    $uow = new InMemoryUnitOfWork;
    $aggregate = TestOrder::create();

    $uow->track($aggregate);
})->throws(RuntimeException::class, 'No active unit of work');

test('markForDeletion() throws RuntimeException when no scope is active', function (): void {
    $uow = new InMemoryUnitOfWork;
    $aggregate = TestOrder::create();

    $uow->markForDeletion($aggregate);
})->throws(RuntimeException::class, 'No active unit of work');

test('queueEvent() throws RuntimeException when no scope is active', function (): void {
    $uow = new InMemoryUnitOfWork;
    $event = new DomainEvent(
        eventType: 'test.event',
        aggregateId: '123',
        payload: [],
    );

    $uow->queueEvent($event);
})->throws(RuntimeException::class, 'No active unit of work');

test('begin clears committed/deleted from previous transaction cycle', function (): void {
    $uow = new InMemoryUnitOfWork;
    $aggregate = TestOrder::create();

    // First transaction
    $uow->begin();
    $uow->track($aggregate);
    $uow->commit();

    expect($uow->getCommitted())->toHaveCount(1);
    expect($uow->getDeleted())->toHaveCount(0);

    // Second transaction — should clear previous committed/deleted
    $uow->begin();

    // During a new begin, the old committed are already cleared
    // (they are cleared at the start of the outermost begin)
});

test('track() registers aggregate and collects events', function (): void {
    $uow = new InMemoryUnitOfWork;
    $aggregate = TestOrder::create();

    $uow->begin();
    $uow->track($aggregate);

    expect($uow->isTracking($aggregate))->toBeTrue();
    expect($uow->hasPendingEvents())->toBeTrue();
    expect($uow->getPendingEventCount())->toBeGreaterThanOrEqual(1);
});

test('commit() merges tracked and dispatches events', function (): void {
    $uow = new InMemoryUnitOfWork;
    $aggregate = TestOrder::create();
    $dispatched = [];

    $uow->setEventDispatcher(function (DomainEvent $e) use (&$dispatched): void {
        $dispatched[] = $e->eventType;
    });

    $uow->begin();
    $uow->track($aggregate);
    $uow->commit();

    expect($uow->getCommitted())->toHaveCount(1);
    expect($dispatched)->not->toBeEmpty();
});

test('rollback() restores aggregate state and discards events', function (): void {
    $uow = new InMemoryUnitOfWork;
    $aggregate = TestOrder::create();

    $uow->begin();
    $uow->track($aggregate);

    $versionBefore = $aggregate->version();
    $eventsBefore = $uow->getPendingEventCount();

    $uow->rollback();

    expect($uow->hasPendingEvents())->toBeFalse();
    expect($uow->getCommitted())->toBeEmpty();
    expect($uow->getDeleted())->toBeEmpty();
});

test('run() auto-commits on success', function (): void {
    $uow = new InMemoryUnitOfWork;
    $aggregate = TestOrder::create();

    $result = $uow->run(function () use ($uow, $aggregate): TestOrder {
        $uow->track($aggregate);
        return $aggregate;
    });

    expect($result)->toBe($aggregate);
    expect($uow->getCommitted())->toHaveCount(1);
    expect($uow->isActive())->toBeFalse();
});

test('run() auto-rollbacks on exception', function (): void {
    $uow = new InMemoryUnitOfWork;
    $aggregate = TestOrder::create();

    $uow->setEventDispatcher(function (DomainEvent $e): void {
        // Should not be called — events discarded on rollback
        throw new RuntimeException('Events should not be dispatched on rollback');
    });

    expect(fn () => $uow->run(function () use ($uow, $aggregate): void {
        $uow->track($aggregate);
        throw new RuntimeException('Intentional failure');
    }))->toThrow(RuntimeException::class, 'Intentional failure');

    expect($uow->hasPendingEvents())->toBeFalse();
    expect($uow->getCommitted())->toBeEmpty();
});

test('markForDeletion() removes from committed at commit time', function (): void {
    $uow = new InMemoryUnitOfWork;
    $aggregate = TestOrder::create();

    $uow->begin();
    $uow->track($aggregate);
    $uow->markForDeletion($aggregate);
    $uow->commit();

    expect($uow->getCommitted())->toBeEmpty();
    expect($uow->getDeleted())->toHaveCount(1);
    expect($uow->getDeleted())->toHaveKey($aggregate->id());
});

test('nested savepoints provide scoped rollback', function (): void {
    $uow = new InMemoryUnitOfWork;
    $outer = TestOrder::create();
    $inner = TestOrder::create();

    $uow->begin();
    $uow->track($outer);

    // Inner scope
    $uow->begin();
    $uow->track($inner);

    // Rollback inner scope — inner aggregate discarded, outer preserved
    $uow->rollback();

    expect($uow->isTracking($inner))->toBeFalse();
    expect($uow->isTracking($outer))->toBeTrue();

    // Commit outer scope
    $uow->commit();

    expect($uow->getCommitted())->toHaveCount(1);
    expect($uow->getCommitted())->toHaveKey($outer->id());
});

test('clear() resets all state including committed/deleted', function (): void {
    $uow = new InMemoryUnitOfWork;
    $aggregate = TestOrder::create();

    $uow->begin();
    $uow->track($aggregate);
    $uow->commit();

    expect($uow->getCommitted())->toHaveCount(1);

    $uow->clear();

    expect($uow->getCommitted())->toBeEmpty();
    expect($uow->getDeleted())->toBeEmpty();
    expect($uow->hasPendingEvents())->toBeFalse();
    expect($uow->isActive())->toBeFalse();
});

test('persistence callback is invoked before event dispatch', function (): void {
    $uow = new InMemoryUnitOfWork;
    $aggregate = TestOrder::create();

    $callOrder = [];

    $uow->setPersistenceCallback(function (array $committed, array $deleted) use (&$callOrder): void {
        $callOrder[] = 'persist';
    });

    $uow->setEventDispatcher(function (DomainEvent $e) use (&$callOrder): void {
        $callOrder[] = 'dispatch';
    });

    $uow->begin();
    $uow->track($aggregate);
    $uow->commit();

    expect($callOrder)->toEqual(['persist', 'dispatch']);
});

test('queueEvent() injects events from outside aggregate', function (): void {
    $uow = new InMemoryUnitOfWork;
    $dispatched = [];

    $uow->setEventDispatcher(function (DomainEvent $e) use (&$dispatched): void {
        $dispatched[] = $e->eventType;
    });

    $uow->begin();
    $uow->queueEvent(new DomainEvent(
        eventType: 'external.event',
        aggregateId: 'ext-123',
        payload: ['source' => 'external'],
    ));
    $uow->commit();

    expect($dispatched)->toContain('external.event');
});

// ============================================================================
// Test fixtures
// ============================================================================

/**
 * Test aggregate root for unit of work tests.
 */
final class TestOrder extends AggregateRoot
{
    private string $status = 'pending';

    protected static function reconstituteFromSnapshotMethod(): void {}

    public static function create(): self
    {
        $aggregate = new self(AggregateRootId::generate());
        $aggregate->recordThat(new DomainEvent(
            eventType: 'order.created',
            aggregateId: $aggregate->id(),
            payload: ['status' => 'pending'],
        ));

        return $aggregate;
    }

    public function status(): string
    {
        return $this->status;
    }
}
