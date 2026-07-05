<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain;

use Illuminate\Contracts\Events\Dispatcher as LaravelDispatcher;
use Illuminate\Support\ServiceProvider;
use ZeroBoiler\Domain\Commands\DomainAggregateCommand;
use ZeroBoiler\Domain\Commands\DomainEventCommand;
use ZeroBoiler\Domain\Commands\DomainListCommand;
use ZeroBoiler\Domain\Commands\DomainRepositoryCommand;
use ZeroBoiler\Domain\Console\Commands\SnapshotCommand;
use ZeroBoiler\Domain\Contracts\UnitOfWork as UnitOfWorkContract;
use ZeroBoiler\Domain\Snapshots\InMemorySnapshotStore;
use ZeroBoiler\Domain\Snapshots\SnapshotStore;

class DomainServiceProvider extends ServiceProvider
{
    #[\Override]
    public function register(): void
    {
        $this->app->singleton(
            UnitOfWorkContract::class,
            InMemoryUnitOfWork::class
        );

        $this->app->singleton(
            DomainEventDispatcher::class,
            fn (): DomainEventDispatcher => new DomainEventDispatcher(
                $this->app->bound(LaravelDispatcher::class)
                    ? $this->app->make(LaravelDispatcher::class)
                    : null
            )
        );

        // Register snapshot store (in-memory by default; override in config)
        $this->app->singleton(SnapshotStore::class, function (): SnapshotStore {
            $config = $this->app['config']['domain'] ?? [];

            return match ($config['snapshot_driver'] ?? 'memory') {
                'memory' => new InMemorySnapshotStore(),
                default => new InMemorySnapshotStore(),
            };
        });
    }

    /**
     * Boot domain services and wire cross-package integrations.
     *
     * When the Events package is installed, domain events are
     * automatically forwarded to the EventManager so that
     * DB-driven triggers fire on domain event dispatch.
     */
    public function boot(): void
    {
        $this->wireEventForwarder();

        if ($this->app->runningInConsole()) {
            $this->commands([
                DomainAggregateCommand::class,
                DomainEventCommand::class,
                DomainRepositoryCommand::class,
                DomainListCommand::class,
                SnapshotCommand::class,
            ]);
        }
    }

    /**
     * Wire domain events to the Events package's EventManager
     * when the package is available at runtime.
     *
     * This keeps `domain` (Layer 0) free of a hard dependency
     * on `events` (Layer 1) while enabling seamless integration.
     */
    private function wireEventForwarder(): void
    {
        $eventManagerClass = '\\ZeroBoiler\\Events\\EventManager';

        if (! class_exists($eventManagerClass)) {
            return;
        }

        $dispatcher = $this->app->make(DomainEventDispatcher::class);

        $dispatcher->setEventForwarder(
            function (string $eventType, array $payload) use ($eventManagerClass): void {
                try {
                    $this->app->make($eventManagerClass)->fire($eventType, $payload);
                } catch (\Throwable) {
                    // Silently fail — domain events should not break
                    // if the event system has issues.
                }
            }
        );
    }
}
