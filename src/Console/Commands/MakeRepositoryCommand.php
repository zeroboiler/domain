<?php

declare(strict_types=1);

namespace ZeroBoiler\Domain\Console\Commands;

use Illuminate\Console\GeneratorCommand;

class MakeRepositoryCommand extends GeneratorCommand
{
    protected $name = 'domain:repository';

    protected $description = 'Generate a new repository interface';

    protected $type = 'Repository';

    protected function getStub(): string
    {
        return __DIR__ . '/../../../stubs/repository.stub';
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace . '\\Domain\\Repositories';
    }

    protected function getNameInput(): string
    {
        return trim($this->argument('name'));
    }
}
