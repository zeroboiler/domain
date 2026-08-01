<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Concerns;

use RuntimeException;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Events\Domain\DomainEvent;

/**
 * Provides event sourcing capabilities to aggregate roots.
 *
 * When used on an AggregateRoot, enables:
 * - `fromHistory(DomainEvent ...$events)` — reconstitute from event stream
 * - `applyEvent(DomainEvent $event, bool $isReplay)` — apply/replay single event
 *
 * The trait resolves handler methods using dot/hyphen/underscore convention:
 *   'aggregate.created' → applyAggregateCreated()
 *   'order-shipped'     → applyOrderShipped()
 */
trait EventSourced
{
    /**
     * Reconstitute an aggregate from its event history.
     *
     *
     * @throws RuntimeException If events are empty or first event lacks an ID.
     */
    public static function fromHistory(DomainEvent ...$events): static
    {
        if ($events === []) {
            throw new RuntimeException('Cannot reconstitute from empty event history');
        }

        $firstEvent = $events[0];
        $payload = $firstEvent->payload;
        $idString = $payload['id'] ?? $payload['aggregate_id'] ?? null;

        if ($idString === null) {
            throw new RuntimeException(
                'Cannot reconstitute: first event must contain an "id" or "aggregate_id" key in its payload'
            );
        }

        $aggregateId = AggregateRootId::fromString((string) $idString);

        /** @var static $aggregate */
        $aggregate = new \ReflectionClass(static::class)->newInstanceWithoutConstructor();

        // Walk up the class hierarchy to find and set properties
        // since aggregateId is private on AggregateRoot and id is on Entity
        $reflection = new \ReflectionClass($aggregate);

        // Find and set aggregateId (declared on AggregateRoot, private readonly)
        $target = $reflection;

        while ($target !== false) {
            if ($target->hasProperty('aggregateId')) {
                $idProp = $target->getProperty('aggregateId');
                $idProp->setValue($aggregate, $aggregateId);
                break;
            }

            $target = $target->getParentClass();
        }

        // Find and set id (declared on Entity, public readonly)
        $target = $reflection;

        while ($target !== false) {
            if ($target->hasProperty('id')) {
                $idProp = $target->getProperty('id');
                $idProp->setValue($aggregate, $aggregateId);
                break;
            }

            $target = $target->getParentClass();
        }

        // Replay all events in lenient mode
        foreach ($events as $event) {
            $aggregate->applyEvent($event, true);
        }

        // Clear any events recorded during reconstitution
        $aggregate->clearEvents();

        return $aggregate;
    }

    /**
     * Apply a domain event to this aggregate.
     *
     * In replay mode ($isReplay = true), only apply* handlers are invoked
     * and version increments for every event (matching the original event count).
     * In normal mode ($isReplay = false), the event is recorded and version
     * is incremented (delegates to AggregateRoot::apply()).
     *
     * @param  bool  $isReplay  When true, event is replayed without recording.
     */
    public function applyEvent(DomainEvent $event, bool $isReplay = false): void
    {
        if ($isReplay) {
            // Resolve handler method name from event type
            $parts = preg_split('/[._-]/', $event->eventType);
            $method = 'apply' . implode('', array_map(ucfirst(...), $parts ?? []));

            // Replay mode: invoke handler only (if exists).
            // Version increments only for handled events — events without
            // apply* handlers are informational and do not affect version.
            if (method_exists($this, $method)) {
                $this->$method($event);

                if (method_exists($this, 'incrementVersion')) {
                    $this->incrementVersion();
                }
            }

            return;
        }

        // Normal mode: delegate to AggregateRoot::apply()
        if (method_exists($this, 'apply')) {
            // @phpstan-ignore-next-line
            $this->apply($event);
        }
    }
}
