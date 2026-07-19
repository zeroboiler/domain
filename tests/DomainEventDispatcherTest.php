<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Domain\DomainEvent;
use ZeroBoiler\Domain\DomainEventDispatcher;
use ZeroBoiler\Domain\Exceptions\ListenerException;

beforeEach(function (): void {
    $this->dispatcher = new DomainEventDispatcher;
});

afterEach(function (): void {
    // Ensure complete test isolation — no listener leakage between tests.
    $this->dispatcher->flush();
});

it('can subscribe to events', function (): void {
    $called = false;

    $this->dispatcher->subscribe('TestEvent', function () use (&$called): void {
        $called = true;
    });

    $this->dispatcher->dispatch(DomainEvent::occur('TestEvent'));

    expect($called)->toBeTrue();
});

it('calls all listeners for an event', function (): void {
    $count = 0;

    $this->dispatcher->subscribe('TestEvent', function () use (&$count): void {
        $count++;
    });

    $this->dispatcher->subscribe('TestEvent', function () use (&$count): void {
        $count++;
    });

    $this->dispatcher->dispatch(DomainEvent::occur('TestEvent'));

    expect($count)->toBe(2);
});

it('does not call listeners for different event types', function (): void {
    $called = false;

    $this->dispatcher->subscribe('EventA', function () use (&$called): void {
        $called = true;
    });

    $this->dispatcher->dispatch(DomainEvent::occur('EventB'));

    expect($called)->toBeFalse();
});

it('can defer events', function (): void {
    $called = false;

    $this->dispatcher->subscribe('TestEvent', function () use (&$called): void {
        $called = true;
    });

    $this->dispatcher->defer(DomainEvent::occur('TestEvent'));

    expect($called)->toBeFalse();
});

it('can release deferred events', function (): void {
    $count = 0;

    $this->dispatcher->subscribe('TestEvent', function () use (&$count): void {
        $count++;
    });

    $this->dispatcher->defer(DomainEvent::occur('TestEvent'));
    $this->dispatcher->defer(DomainEvent::occur('TestEvent'));

    $this->dispatcher->releaseDeferred();

    expect($count)->toBe(2);
});

it('clears deferred events after release', function (): void {
    $this->dispatcher->defer(DomainEvent::occur('TestEvent'));

    $this->dispatcher->releaseDeferred();

    expect($this->dispatcher->hasDeferredEvents())->toBeFalse();
});

it('can clear deferred events manually', function (): void {
    $this->dispatcher->defer(DomainEvent::occur('TestEvent'));

    $this->dispatcher->clearDeferred();

    expect($this->dispatcher->hasDeferredEvents())->toBeFalse();
});

it('reports deferred events count', function (): void {
    expect($this->dispatcher->getDeferredEventsCount())->toBe(0);

    $this->dispatcher->defer(DomainEvent::occur('TestEvent'));
    $this->dispatcher->defer(DomainEvent::occur('TestEvent'));

    expect($this->dispatcher->getDeferredEventsCount())->toBe(2);
});

it('forwards events to an external forwarder when set', function (): void {
    $forwarded = [];

    $this->dispatcher->setEventForwarder(
        function (string $eventType, array $payload) use (&$forwarded): void {
            $forwarded[] = ['type' => $eventType, 'payload' => $payload];
        }
    );

    $this->dispatcher->dispatch(DomainEvent::occur('OrderPlaced', ['id' => 42]));

    expect($forwarded)->toHaveCount(1)
        ->and($forwarded[0]['type'])->toBe('OrderPlaced')
        ->and($forwarded[0]['payload'])->toBe(['id' => 42]);
});

it('forwards events with payload data', function (): void {
    $forwardedPayload = null;

    $this->dispatcher->setEventForwarder(
        function (string $eventType, array $payload) use (&$forwardedPayload): void {
            $forwardedPayload = $payload;
        }
    );

    $payload = ['user_id' => 1, 'action' => 'login', 'meta' => ['ip' => '127.0.0.1']];
    $this->dispatcher->dispatch(DomainEvent::occur('UserLogin', $payload));

    expect($forwardedPayload)->toBe($payload);
});

it('does not forward when forwarder is not set', function (): void {
    $forwarded = false;

    // No forwarder set — dispatch should still work normally
    $this->dispatcher->dispatch(DomainEvent::occur('TestEvent'));

    expect($forwarded)->toBeFalse();
});

it('can remove the event forwarder', function (): void {
    $forwarded = [];

    $this->dispatcher->setEventForwarder(
        function (string $eventType, array $payload) use (&$forwarded): void {
            $forwarded[] = $eventType;
        }
    );

    $this->dispatcher->dispatch(DomainEvent::occur('First'));

    $this->dispatcher->setEventForwarder(null);

    $this->dispatcher->dispatch(DomainEvent::occur('Second'));

    expect($forwarded)->toBe(['First']);
});

it('forwards deferred events when released', function (): void {
    $forwarded = [];

    $this->dispatcher->setEventForwarder(
        function (string $eventType, array $payload) use (&$forwarded): void {
            $forwarded[] = $eventType;
        }
    );

    $this->dispatcher->defer(DomainEvent::occur('DeferredEvent'));

    expect($forwarded)->toBeEmpty();

    $this->dispatcher->releaseDeferred();

    expect($forwarded)->toBe(['DeferredEvent']);
});

it('calls both listeners and forwarder on dispatch', function (): void {
    $listenerCalled = false;
    $forwarderCalled = false;

    $this->dispatcher->subscribe('TestEvent', function () use (&$listenerCalled): void {
        $listenerCalled = true;
    });

    $this->dispatcher->setEventForwarder(
        function (string $eventType, array $payload) use (&$forwarderCalled): void {
            $forwarderCalled = true;
        }
    );

    $this->dispatcher->dispatch(DomainEvent::occur('TestEvent'));

    expect($listenerCalled)->toBeTrue()
        ->and($forwarderCalled)->toBeTrue();
});

it('continues calling remaining listeners when one throws', function (): void {
    // BUG #6: A failing listener should not silently skip subsequent listeners.
    $results = [];

    $this->dispatcher->subscribe('TestEvent', function () use (&$results): void {
        $results[] = 'first';
    });

    $this->dispatcher->subscribe('TestEvent', function () use (&$results): void {
        $results[] = 'throwing';

        throw new RuntimeException('listener failed');
    });

    $this->dispatcher->subscribe('TestEvent', function () use (&$results): void {
        $results[] = 'third';
    });

    try {
        $this->dispatcher->dispatch(DomainEvent::occur('TestEvent'));
    } catch (ListenerException $e) {
        // Expected
    }

    expect($results)->toBe(['first', 'throwing', 'third']);
});

it('throws ListenerException with all failures after all listeners run', function (): void {
    $first = new RuntimeException('boom-1');
    $second = new RuntimeException('boom-2');

    $this->dispatcher->subscribe('TestEvent', function () use ($first): void {
        throw $first;
    });
    $this->dispatcher->subscribe('TestEvent', function () use ($second): void {
        throw $second;
    });

    try {
        $this->dispatcher->dispatch(DomainEvent::occur('TestEvent'));
        $thrown = null;
    } catch (ListenerException $thrown) {
        // Expected
    }

    expect($thrown)->not->toBeNull()
        ->and($thrown->throwables())->toHaveCount(2)
        ->and($thrown->throwables()[0])->toBe($first)
        ->and($thrown->throwables()[1])->toBe($second)
        ->and($thrown->getMessage())->toContain('2 listener(s) failed');
});

it('does not throw when no listeners fail', function (): void {
    $called = false;

    $this->dispatcher->subscribe('TestEvent', function () use (&$called): void {
        $called = true;
    });

    $this->dispatcher->dispatch(DomainEvent::occur('TestEvent'));

    expect($called)->toBeTrue();
});

it('dispatchQuietly swallows listener exceptions', function (): void {
    $secondCalled = false;

    $this->dispatcher->subscribe('TestEvent', function (): void {
        throw new RuntimeException('fail');
    });
    $this->dispatcher->subscribe('TestEvent', function () use (&$secondCalled): void {
        $secondCalled = true;
    });

    // Should not throw
    $this->dispatcher->dispatchQuietly(DomainEvent::occur('TestEvent'));

    expect($secondCalled)->toBeTrue();
});

it('dispatchQuietly works normally when no listeners fail', function (): void {
    $called = false;

    $this->dispatcher->subscribe('TestEvent', function () use (&$called): void {
        $called = true;
    });

    $this->dispatcher->dispatchQuietly(DomainEvent::occur('TestEvent'));

    expect($called)->toBeTrue();
});

it('clears deferred events even when a listener throws during release', function (): void {
    // IMP-3 R33: releaseDeferred() should clear the collection in a finally block
    // so that a throwing listener doesn't leave events stuck in memory.
    $this->dispatcher->subscribe('Boom', function (): never {
        throw new RuntimeException('listener failed');
    });

    $this->dispatcher->subscribe('AfterBoom', function (): void {
        // This listener should never be called because the first dispatch throws
    });

    $this->dispatcher->defer(DomainEvent::occur('Boom'));
    $this->dispatcher->defer(DomainEvent::occur('AfterBoom'));

    try {
        $this->dispatcher->releaseDeferred();
    } catch (RuntimeException) {
        // Expected
    }

    // The deferred collection must be cleared despite the exception
    expect($this->dispatcher->hasDeferredEvents())->toBeFalse()
        ->and($this->dispatcher->getDeferredEventsCount())->toBe(0);
});

// ============================================================================
// clearListeners() tests — issue #2: listener accumulation prevention
// ============================================================================

it('can clear all listeners', function (): void {
    $this->dispatcher->subscribe('EventA', fn () => true);
    $this->dispatcher->subscribe('EventB', fn () => true);

    expect($this->dispatcher->getListenerCount())->toBe(2);

    $this->dispatcher->clearListeners();

    expect($this->dispatcher->getListenerCount())->toBe(0)
        ->and($this->dispatcher->hasListeners('EventA'))->toBeFalse()
        ->and($this->dispatcher->hasListeners('EventB'))->toBeFalse();
});

it('can clear listeners for a specific event type only', function (): void {
    $this->dispatcher->subscribe('EventA', fn () => true);
    $this->dispatcher->subscribe('EventA', fn () => true);
    $this->dispatcher->subscribe('EventB', fn () => true);

    $this->dispatcher->clearListeners('EventA');

    expect($this->dispatcher->hasListeners('EventA'))->toBeFalse()
        ->and($this->dispatcher->hasListeners('EventB'))->toBeTrue()
        ->and($this->dispatcher->getListenerCount())->toBe(1);
});

it('does not dispatch to cleared listeners', function (): void {
    $called = false;

    $this->dispatcher->subscribe('TestEvent', function () use (&$called): void {
        $called = true;
    });

    $this->dispatcher->clearListeners();
    $this->dispatcher->dispatch(DomainEvent::occur('TestEvent'));

    expect($called)->toBeFalse();
});

it('can clear listeners for non-existent event type without error', function (): void {
    $this->dispatcher->clearListeners('NonExistent');

    expect($this->dispatcher->getListenerCount())->toBe(0);
});

// ============================================================================
// flush() tests — full reset for test isolation
// ============================================================================

it('can flush all state completely', function (): void {
    $this->dispatcher->subscribe('TestEvent', fn () => true);
    $this->dispatcher->setEventForwarder(fn () => true);
    $this->dispatcher->defer(DomainEvent::occur('TestEvent'));

    $this->dispatcher->flush();

    expect($this->dispatcher->getListenerCount())->toBe(0)
        ->and($this->dispatcher->hasListeners('TestEvent'))->toBeFalse()
        ->and($this->dispatcher->hasDeferredEvents())->toBeFalse();
});

it('flush removes event forwarder', function (): void {
    $forwarded = [];

    $this->dispatcher->setEventForwarder(function () use (&$forwarded): void {
        $forwarded[] = 'called';
    });

    $this->dispatcher->flush();
    $this->dispatcher->dispatch(DomainEvent::occur('TestEvent'));

    expect($forwarded)->toBeEmpty();
});

it('flush allows clean re-subscription after reset', function (): void {
    $callCount = 0;

    $this->dispatcher->subscribe('TestEvent', function () use (&$callCount): void {
        $callCount++;
    });

    $this->dispatcher->dispatch(DomainEvent::occur('TestEvent'));
    expect($callCount)->toBe(1);

    $this->dispatcher->flush();

    $this->dispatcher->subscribe('TestEvent', function () use (&$callCount): void {
        $callCount++;
    });

    $this->dispatcher->dispatch(DomainEvent::occur('TestEvent'));
    expect($callCount)->toBe(2);
});

// ============================================================================
// hasListeners() and getListenerCount() tests
// ============================================================================

it('reports hasListeners correctly', function (): void {
    expect($this->dispatcher->hasListeners('TestEvent'))->toBeFalse();

    $this->dispatcher->subscribe('TestEvent', fn () => true);

    expect($this->dispatcher->hasListeners('TestEvent'))->toBeTrue();
});

it('reports getListenerCount correctly across multiple subscriptions', function (): void {
    expect($this->dispatcher->getListenerCount())->toBe(0);

    $this->dispatcher->subscribe('EventA', fn () => true);
    $this->dispatcher->subscribe('EventA', fn () => true);
    $this->dispatcher->subscribe('EventB', fn () => true);

    expect($this->dispatcher->getListenerCount())->toBe(3);
});

it('decrements listener count when clearing specific event type', function (): void {
    $this->dispatcher->subscribe('EventA', fn () => true);
    $this->dispatcher->subscribe('EventA', fn () => true);
    $this->dispatcher->subscribe('EventB', fn () => true);

    $this->dispatcher->clearListeners('EventA');

    expect($this->dispatcher->getListenerCount())->toBe(1);
});

it('hasListeners returns false after clearing all listeners', function (): void {
    $this->dispatcher->subscribe('TestEvent', fn () => true);

    $this->dispatcher->clearListeners();

    expect($this->dispatcher->hasListeners('TestEvent'))->toBeFalse();
});
