<?php

declare(strict_types=1);

namespace ZeroBoiler\Domain;

use Illuminate\Support\ServiceProvider;
use ZeroBoiler\Domain\Console\Commands\MakeAggregateCommand;
use ZeroBoiler\Domain\Console\Commands\MakeEventCommand;
use ZeroBoiler\Domain\Console\Commands\MakeRepositoryCommand;
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
            EventDispatcher::class,
            fn (): EventDispatcher => EventDispatcher::getInstance()
        );
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                MakeAggregateCommand::class,
                MakeEventCommand::class,
                MakeRepositoryCommand::class,
            ]);
        }
    }
}
