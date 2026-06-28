<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\DomainEvent;
use ZeroBoiler\Domain\Tests\Fixtures\TestAggregate;

beforeEach(function (): void {
    $this->aggregateId = AggregateRootId::generate();
});

it('can be created with an ID', function (): void {
    $aggregate = TestAggregate::create($this->aggregateId);

    expect($aggregate->id)->toBe($this->aggregateId);
    expect($aggregate->getVersion())->toBe(1);
});

it('records domain events', function (): void {
    $aggregate = TestAggregate::create($this->aggregateId);

    expect($aggregate->hasUncommittedEvents())->toBeTrue();

    $events = $aggregate->releaseEvents();

    expect($events)->toHaveCount(1);
    expect($events[0])->toBeInstanceOf(DomainEvent::class);
    expect($events[0]->eventType)->toBe('TestAggregateCreated');
    expect($aggregate->hasUncommittedEvents())->toBeFalse();
});

it('increments version on event application', function (): void {
    $aggregate = TestAggregate::create($this->aggregateId);

    expect($aggregate->getVersion())->toBe(1);

    $aggregate->releaseEvents();

    expect($aggregate->getVersion())->toBe(1);
});

it('clears events after release', function (): void {
    $aggregate = TestAggregate::create($this->aggregateId);

    $aggregate->releaseEvents();

    expect($aggregate->hasUncommittedEvents())->toBeFalse();
});

it('clears events manually', function (): void {
    $aggregate = TestAggregate::create($this->aggregateId);

    $aggregate->clearEvents();

    expect($aggregate->hasUncommittedEvents())->toBeFalse();
});
