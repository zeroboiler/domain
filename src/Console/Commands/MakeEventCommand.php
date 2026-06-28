<?php

declare(strict_types=1);

namespace ZeroBoiler\Domain\Console\Commands;

use Illuminate\Console\GeneratorCommand;

class MakeEventCommand extends GeneratorCommand
{
    protected $name = 'domain:event';

    protected $description = 'Generate a new domain event class';

    protected $type = 'Domain Event';

    protected function getStub(): string
    {
        return __DIR__ . '/../../../stubs/event.stub';
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace . '\\Domain\\Events';
    }

    protected function getNameInput(): string
    {
        return trim($this->argument('name'));
    }
}
