<?php

declare(strict_types=1);

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

namespace ZeroBoiler\Domain;

use Illuminate\Support\ServiceProvider;
use ZeroBoiler\Domain\Commands\DomainAggregateCommand;
use ZeroBoiler\Domain\Commands\DomainEventCommand;
use ZeroBoiler\Domain\Commands\DomainListCommand;
use ZeroBoiler\Domain\Commands\DomainRepositoryCommand;
use ZeroBoiler\Domain\Commands\DomainValueObjectCommand;

final class DomainServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DomainEventDispatcher::class, static fn (): DomainEventDispatcher => new DomainEventDispatcher);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                DomainAggregateCommand::class,
                DomainEventCommand::class,
                DomainRepositoryCommand::class,
                DomainValueObjectCommand::class,
                DomainListCommand::class,
            ]);
        }
    }
}