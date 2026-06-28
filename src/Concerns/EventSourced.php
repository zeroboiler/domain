<?php

declare(strict_types=1);

namespace ZeroBoiler\Domain\Concerns;

use ReflectionClass;
use RuntimeException;
use ZeroBoiler\Domain\DomainEvent;

trait EventSourced
{
    public static function fromHistory(DomainEvent ...$events): static
    {
        $instance = (new ReflectionClass(static::class))->newInstanceWithoutConstructor();

        foreach ($events as $event) {
            $instance->applyEvent($event);
        }

        return $instance;
    }

    protected function applyEvent(DomainEvent $event): void
    {
        $method = 'apply' . (new ReflectionClass($event))->getShortName();

        if (! method_exists($this, $method)) {
            throw new RuntimeException(
                sprintf(
                    'Method %s not found in %s',
                    $method,
                    static::class
                )
            );
        }

        $this->$method($event);
        $this->incrementVersion();
    }
}
