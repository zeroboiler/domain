<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\GeneratorCommand;

/**
 * Generate a new Domain Value Object class.
 *
 * Creates a typed, immutable value object stub extending `ValueObject`
 * with equality, serialization, and optional from/to factory methods.
 *
 * Usage:
 *   ```bash
 *   php artisan zeroboiler:domain:value-object Email
 *   php artisan zeroboiler:domain:value-object Money --type=float,int
 *   ```
 *
 * @since 1.0.0
 */
#[Description('Generate a new Domain Value Object class')]
final class MakeValueObjectCommand extends GeneratorCommand
{
    protected $name = 'zeroboiler:domain:value-object';

    protected $type = 'ValueObject';

    #[\Override]
    protected function getStub(): string
    {
        return __DIR__ . '/../stubs/value-object.stub';
    }

    #[\Override]
    protected function getDefaultNamespace(string $rootNamespace): string
    {
        return $rootNamespace . '\\Domain\\ValueObjects';
    }

    #[\Override]
    protected function getNameInput(): string
    {
        $name = parent::getNameInput();

        // Don't auto-append "ValueObject" — let the user control naming
        // (e.g., "Email", "Money", "Address" are natural value object names)
        return $name;
    }
}
