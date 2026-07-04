<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Domain\DomainEvent;
use ZeroBoiler\Domain\DomainEventDispatcher;

beforeEach(function (): void {
    $this->dispatcher = new DomainEventDispatcher;
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

it('clears deferred events even when a listener throws during release', function (): void {
    // IMP-3 R33: releaseDeferred() should clear the collection in a finally block
    // so that a throwing listener doesn't leave events stuck in memory.
    $this->dispatcher->subscribe('Boom', function (): never {
        throw new RuntimeException('listener failed');
    });

    $this->dispatcher->subscribe('AfterBoom', function () {
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
