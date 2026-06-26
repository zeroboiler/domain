<?php

declare(strict_types=1);

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

namespace ZeroBoiler\Domain\Commands;

use Illuminate\Console\GeneratorCommand;

final class DomainValueObjectCommand extends GeneratorCommand
{
    protected $name = 'zeroboiler:domain:value-object';

    protected $description = 'Generate a new Domain ValueObject class';

    protected $type = 'ValueObject';

    protected function getStub(): string
    {
        return __DIR__.'/../stubs/value-object.stub';
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\\Domain\\ValueObjects';
    }

    protected function getNameInput(): string
    {
        $name = parent::getNameInput();

        if (! str_ends_with($name, 'ValueObject')) {
            $name .= 'ValueObject';
        }

        return $name;
    }
}