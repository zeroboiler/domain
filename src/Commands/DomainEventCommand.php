<?php

declare(strict_types=1);

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

namespace ZeroBoiler\Domain\Commands;

use Illuminate\Console\GeneratorCommand;

final class DomainEventCommand extends GeneratorCommand
{
    protected $name = 'zeroboiler:domain:event';

    protected $description = 'Generate a new Domain Event class';

    protected $type = 'Event';

    protected function getStub(): string
    {
        return __DIR__.'/../stubs/event.stub';
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\\Domain\\Events';
    }

    protected function getNameInput(): string
    {
        $name = parent::getNameInput();

        if (! str_ends_with($name, 'Event')) {
            $name .= 'Event';
        }

        return $name;
    }
}
