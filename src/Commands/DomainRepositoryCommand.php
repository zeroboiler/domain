<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\GeneratorCommand;
use Illuminate\Support\Str;

#[Description('Generate a new Domain Repository interface and Eloquent implementation')]
final class DomainRepositoryCommand extends GeneratorCommand
{
    #[\Override]
    protected $name = 'zeroboiler:domain:repository';

    #[\Override]
    protected $type = 'Repository';

    protected function getStub(): string
    {
        return __DIR__ . '/../stubs/repository.stub';
    }

    #[\Override]
    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace . '\\Domain\\Repositories';
    }

    #[\Override]
    protected function getNameInput(): string
    {
        $name = parent::getNameInput();

        if (! str_ends_with($name, 'Repository')) {
            $name .= 'Repository';
        }

        return $name;
    }

    #[\Override]
    public function handle(): bool
    {
        $result = parent::handle();

        if (! $result) {
            return false;
        }

        $name = $this->getNameInput();
        $rootNamespace = $this->laravel->getNamespace();

        $this->getPath(sprintf('%sDomain\Repositories\%s', $rootNamespace, $name));
        $implementationName = Str::replace('Repository', 'EloquentRepository', $name);

        $this->call('zeroboiler:domain:repository-impl', [
            'name' => $implementationName,
            '--interface' => $name,
        ]);

        return true;
    }
}
