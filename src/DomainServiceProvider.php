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
/**
 * Domain service provider.
 *
 * Registers core domain infrastructure (UnitOfWork, SnapshotStore)
 * and integrates with optional ZeroBoiler packages via runtime guards:
 *
 * - **Events package** (`zeroboiler/events`): Domain event dispatcher wired into UoW
 * - **Observability package** (`zeroboiler/observability`): #[Trace] auto-instrumentation (stubbed when absent)
 * - **DTO package** (`zeroboiler/dto`): DataTransferObject base class (used by domain commands)
 * - **Enums package** (`zeroboiler/enums`): Enum metadata (used by domain commands)
 */
class DomainServiceProvider extends ServiceProvider
{
    /**
     * Optional dispatcher class — checked via app->bound() at runtime.
     * @var class-string
     */
    private const string DISPATCHER_CLASS = 'ZeroBoiler\\Events\\Domain\\DomainEventDispatcher';

    /**
     * Optional enum manager class — checked via class_exists() at runtime.
     * @var class-string
     */
    private const string ENUM_MANAGER_CLASS = 'ZeroBoiler\\Enums\\EnumManager';

    #[\Override]
    public function register(): void
    {
        $this->registerUnitOfWork();
        $this->registerSnapshotStore();
    }

    /**
     * Register the in-memory Unit of Work with optional event dispatching.
     *
     * When the Events package is installed and the DomainEventDispatcher
     * is bound in the container, events queued in the UoW are dispatched
     * after a successful commit.
     */
    private function registerUnitOfWork(): void
    {
        $this->app->singleton(
            UnitOfWorkContract::class,
            function (): InMemoryUnitOfWork {
                $uow = new InMemoryUnitOfWork;

                $uow->setEventDispatcher(
                    function (object $event): void {
                        $dispatcherClass = self::DISPATCHER_CLASS;

                        if ($this->app->bound($dispatcherClass)) {
                            $this->app->make($dispatcherClass)
                                ->dispatch($event);
                        }
                    }
                );

                return $uow;
            }
        );
    }

    /**
     * Register the snapshot store (in-memory by default).
     *
     * Override via `config.domain.snapshot_driver` in your app config.
     */
    private function registerSnapshotStore(): void
    {
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
