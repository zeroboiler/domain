<?php

declare(strict_types=1);

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

namespace ZeroBoiler\Domain\Commands;

use Illuminate\Console\GeneratorCommand;
use Illuminate\Support\Str;

final class DomainRepositoryCommand extends GeneratorCommand
{
    protected $name = 'zeroboiler:domain:repository';

    protected $description = 'Generate a new Domain Repository interface and Eloquent implementation';

    protected $type = 'Repository';

    protected function getStub(): string
    {
        return __DIR__.'/../stubs/repository.stub';
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\\Domain\\Repositories';
    }

    protected function getNameInput(): string
    {
        $name = parent::getNameInput();

        if (! str_ends_with($name, 'Repository')) {
            $name .= 'Repository';
        }

        return $name;
    }

    public function handle(): bool
    {
        $result = parent::handle();

        if (! $result) {
            return false;
        }

        $name = $this->getNameInput();
        $rootNamespace = $this->laravel->getNamespace();

        $interfacePath = $this->getPath("{$rootNamespace}Domain\\Repositories\\{$name}");
        $implementationName = Str::replace('Repository', 'EloquentRepository', $name);

        $this->call('zeroboiler:domain:repository-impl', [
            'name' => $implementationName,
            '--interface' => $name,
        ]);

        return true;
    }
}
