<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\GeneratorCommand;

#[Description('Generate a new Domain AggregateRoot class')]
final class DomainAggregateCommand extends GeneratorCommand
{
    #[\Override]
    protected $name = 'zeroboiler:domain:aggregate';

    #[\Override]
    protected $type = 'Aggregate';

    #[\Override]
    protected function getStub(): string
    {
        return __DIR__ . '/../stubs/aggregate.stub';
    }

    #[\Override]
    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace . '\\Domain\\Aggregates';
    }

    #[\Override]
    protected function getNameInput(): string
    {
        $name = parent::getNameInput();

        if (! str_ends_with($name, 'Aggregate')) {
            $name .= 'Aggregate';
        }

        return $name;
    }
}
