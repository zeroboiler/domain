<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain;

use Illuminate\Support\ServiceProvider;
use ZeroBoiler\Domain\Commands\DomainAggregateCommand;
use ZeroBoiler\Domain\Commands\DomainListCommand;
use ZeroBoiler\Domain\Commands\DomainRepositoryCommand;
use ZeroBoiler\Domain\Console\Commands\SnapshotCommand;
use ZeroBoiler\Domain\Contracts\UnitOfWork as UnitOfWorkContract;
use ZeroBoiler\Domain\Snapshots\InMemorySnapshotStore;
use ZeroBoiler\Domain\Snapshots\SnapshotStore;
use ZeroBoiler\Events\Domain\DomainEvent;

class DomainServiceProvider extends ServiceProvider
{
    /**
     * The dispatcher class to use for domain event dispatch.
     * Checked via app->bound() at runtime so the Events package is optional.
     */
    private const string DISPATCHER_CLASS = 'ZeroBoiler\\Events\\Domain\\DomainEventDispatcher';

    #[\Override]
    public function register(): void
    {
        $this->app->singleton(
            UnitOfWorkContract::class,
            function (): InMemoryUnitOfWork {
                $uow = new InMemoryUnitOfWork;

                // Wire event dispatching: when a DomainEventDispatcher is
                // bound in the container (optional, from the Events package),
                // events queued in the UoW are dispatched after commit.
                $uow->setEventDispatcher(
                    function (DomainEvent $event): void {
                        if ($this->app->bound(self::DISPATCHER_CLASS)) {
                            $this->app->make(self::DISPATCHER_CLASS)
                                ->dispatch($event);
                        }
                    }
                );

                return $uow;
            }
        );

        // Register snapshot store (in-memory by default; override in config)
        $this->app->singleton(SnapshotStore::class, function (): SnapshotStore {
            $config = $this->app['config']['domain'] ?? [];

            return match ($config['snapshot_driver'] ?? 'memory') {
                'memory' => new InMemorySnapshotStore,
                default => new InMemorySnapshotStore,
            };
        });
    }

    /**
     * Boot domain services.
     *
     * Domain event dispatching is optionally handled by the Events package's
     * DomainEventDispatcher, which may be auto-wired in EventsServiceProvider.
     */
    public function boot(): void
    {
        $this->registerOctaneReset();

        if ($this->app->runningInConsole()) {
            $this->commands([
                DomainAggregateCommand::class,
                DomainRepositoryCommand::class,
                DomainListCommand::class,
                SnapshotCommand::class,
            ]);
        }
    }

    /**
     * Register Octane request-reset hook to clear listeners between requests.
     *
     * In long-running processes (Octane, Swoole, RoadRunner), a singleton
     * DomainEventDispatcher persists across requests. Without clearing,
     * listeners accumulate and cause memory leaks and cross-request
     * contamination.
     */
    private function registerOctaneReset(): void
    {
        if (! $this->app->bound('octane')) {
            return;
        }

        $this->app['events']->listen('octane.request.terminate', function (): void {
            if ($this->app->bound(self::DISPATCHER_CLASS)) {
                $this->app->make(self::DISPATCHER_CLASS)
                    ->clearListeners();
            }
        });
    }
}
