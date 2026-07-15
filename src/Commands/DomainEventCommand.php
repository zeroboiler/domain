<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\GeneratorCommand;

#[Description('Generate a new Domain Event class')]
final class DomainEventCommand extends GeneratorCommand
{
    protected $name = 'zeroboiler:domain:event';

    protected $type = 'Event';

    protected function getStub(): string
    {
        return __DIR__ . '/../stubs/event.stub';
    }

    #[\Override]
    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace . '\\Domain\\Events';
    }

    #[\Override]
    protected function getNameInput(): string
    {
        $name = parent::getNameInput();

        if (! str_ends_with($name, 'Event')) {
            $name .= 'Event';
        }

        return $name;
    }
}
