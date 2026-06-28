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
use ZeroBoiler\Domain\Contracts\UnitOfWork as UnitOfWorkContract;

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
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                DomainAggregateCommand::class,
                DomainEventCommand::class,
                DomainRepositoryCommand::class,
                DomainListCommand::class,
            ]);
        }
    }
}
