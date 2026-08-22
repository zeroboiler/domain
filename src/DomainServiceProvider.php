<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain;

use Illuminate\Support\ServiceProvider;
use ZeroBoiler\Domain\Application\CommandBus;
use ZeroBoiler\Domain\Application\QueryBus;
use ZeroBoiler\Domain\Commands\DomainAggregateCommand;
use ZeroBoiler\Domain\Commands\DomainListCommand;
use ZeroBoiler\Domain\Commands\DomainRepositoryCommand;
use ZeroBoiler\Domain\Commands\MakeValueObjectCommand;
use ZeroBoiler\Domain\Context\ContextRegistry;
use ZeroBoiler\Events\Domain\DomainEvent;
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
 *
 * @since 1.0.0
 */
final class DomainServiceProvider extends ServiceProvider
{
    /**
     * Optional dispatcher class — checked via app->bound() at runtime.
     *
     * @var string
     */
    private const string DISPATCHER_CLASS = 'ZeroBoiler\\Events\\Domain\\DomainEventDispatcher';

    public function register(): void
    {
        $this->registerUnitOfWork();
        $this->registerSnapshotStore();
        $this->registerContextRegistry();
        $this->registerBuses();
    }

    /**
     * Register the bounded context registry.
     *
     * Applications register their contexts from context service providers;
     * the singleton keeps one registry across the request.
     * @return void
     */
    private function registerContextRegistry(): void
    {
        $this->app->singleton(ContextRegistry::class);
    }

    /**
     * Register the command and query buses with container-backed resolvers.
     *
     * Handlers conventionally live at App\Contexts\{Context}\Application\Handlers\
     * {Message}Handler; the resolver tries that binding first so most apps
     * need no manual registration.
     * @return void
     */
    private function registerBuses(): void
    {
        $this->app->singleton(CommandBus::class, function (\Illuminate\Contracts\Foundation\Application $app): CommandBus {
            return new CommandBus(
                fn (string $commandClass): ?\ZeroBoiler\Domain\Application\CommandHandler
                    => $app->bound($commandClass . 'Handler')
                        ? $app->make($commandClass . 'Handler')
                        : null,
            );
        });

        $this->app->singleton(QueryBus::class, function (\Illuminate\Contracts\Foundation\Application $app): QueryBus {
            return new QueryBus(
                fn (string $queryClass): ?\ZeroBoiler\Domain\Application\QueryHandler
                    => $app->bound($queryClass . 'Handler')
                        ? $app->make($queryClass . 'Handler')
                        : null,
            );
        });
    }

    /**
     * Register the in-memory Unit of Work with optional event dispatching.
     *
     * When the Events package is installed and the DomainEventDispatcher
     * is bound in the container, events queued in the UoW are dispatched
     * after a successful commit.
     * @return void
     */
    private function registerUnitOfWork(): void
    {
        $this->app->singleton(
            UnitOfWorkContract::class,
            function (): InMemoryUnitOfWork {
                $uow = new InMemoryUnitOfWork;

                $uow->setEventDispatcher(
                    function (DomainEvent $event): void {
                        $dispatcherClass = self::DISPATCHER_CLASS;

                        if ($this->app->bound($dispatcherClass)) {
                            $dispatcher = $this->app->make($dispatcherClass);
                            // @phpstan-ignore method.nonObject
                            $dispatcher->dispatch($event);
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
     * @return void
     */
    private function registerSnapshotStore(): void
    {
        $this->app->singleton(SnapshotStore::class, function (): SnapshotStore {
            $config = $this->app->make('config');
            // @phpstan-ignore method.nonObject
            $domainConfig = $config->get('domain', []);

            return match (is_array($domainConfig) ? ($domainConfig['snapshot_driver'] ?? 'memory') : 'memory') {
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
     * @return void
     */
    public function boot(): void
    {
        $this->registerOctaneReset();

        if ($this->app->runningInConsole()) {
            $this->commands([
                DomainAggregateCommand::class,
                DomainRepositoryCommand::class,
                DomainListCommand::class,
                MakeValueObjectCommand::class,
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
     * @return void
     */
    private function registerOctaneReset(): void
    {
        if (! $this->app->bound('octane')) {
            return;
        }

        $events = $this->app->make('events');
        // @phpstan-ignore method.nonObject
        $events->listen('octane.request.terminate', function (): void {
            if ($this->app->bound(self::DISPATCHER_CLASS)) {
                $dispatcher = $this->app->make(self::DISPATCHER_CLASS);
                // @phpstan-ignore method.nonObject
                $dispatcher->clearListeners();
            }
        });
    }
}
