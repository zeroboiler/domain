<?php

declare(strict_types=1);

namespace ZeroBoiler\Domain\Console\Commands;

use Illuminate\Console\GeneratorCommand;

class MakeAggregateCommand extends GeneratorCommand
{
    protected $name = 'domain:aggregate';

    protected $description = 'Generate a new aggregate root class';

    protected $type = 'Aggregate';

    protected function getStub(): string
    {
        return __DIR__ . '/../../../stubs/aggregate.stub';
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace . '\\Domain\\Aggregates';
    }

    protected function getNameInput(): string
    {
        return trim($this->argument('name'));
    }
}
