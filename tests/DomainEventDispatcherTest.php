<?php

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
