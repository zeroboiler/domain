<?php

declare(strict_types=1);

namespace ZeroBoiler\Domain;

use ZeroBoiler\Domain\Collections\DomainEventCollection;
use ZeroBoiler\Domain\Contracts\DomainEvent;

class EventDispatcher
{
    private static ?self $instance = null;

    private array $listeners = [];

    private function __construct() {}

    public static function getInstance(): self
    {
        if (! self::$instance instanceof EventDispatcher) {
            self::$instance = new self;
        }

        return self::$instance;
    }

    public function listen(string $event, callable $listener): void
    {
        $this->listeners[$event][] = $listener;
    }

    public function dispatch(DomainEvent $event): void
    {
        $eventType = $event::class;

        foreach ($this->getListenersForEvent($eventType) as $listener) {
            $listener($event);
        }
    }

    public function dispatchCollection(DomainEventCollection $events): void
    {
        foreach ($events as $event) {
            $this->dispatch($event);
        }
    }

    public function clearListeners(): void
    {
        $this->listeners = [];
    }

    private function getListenersForEvent(string $event): array
    {
        return $this->listeners[$event] ?? [];
    }

    public function reset(): void
    {
        self::$instance = null;
    }
}
