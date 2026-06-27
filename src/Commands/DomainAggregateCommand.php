<?php

declare(strict_types=1);

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

namespace ZeroBoiler\Domain\Commands;

use Illuminate\Console\GeneratorCommand;

final class DomainAggregateCommand extends GeneratorCommand
{
    protected $name = 'zeroboiler:domain:aggregate';

    protected $description = 'Generate a new Domain AggregateRoot class';

    protected $type = 'Aggregate';

    protected function getStub(): string
    {
        return __DIR__.'/../stubs/aggregate.stub';
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\\Domain\\Aggregates';
    }

    protected function getNameInput(): string
    {
        $name = parent::getNameInput();

        if (! str_ends_with($name, 'Aggregate')) {
            $name .= 'Aggregate';
        }

        return $name;
    }
}
