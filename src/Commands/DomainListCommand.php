<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Command;
use Symfony\Component\Finder\Finder;

/**
 * List all domain classes (Aggregates, Events, Repositories, ValueObjects).
 *
 * Scans the `app/Domain` directory and groups classes by subdirectory.
 * Useful for auditing the domain model structure in a project.
 */
#[Description('List all Domain classes (Aggregates, Events, Repositories, ValueObjects)')]
final class DomainListCommand extends Command
{
    protected $name = 'zeroboiler:domain:list';

    #[\Override]
    public function handle(): int
    {
        $domainPath = app_path('Domain');

        if (! is_dir($domainPath)) {
            $this->info('No domain classes found. Domain directory does not exist.');

            return Command::SUCCESS;
        }

        $this->info('Domain Classes:');
        $this->newLine();

        $this->listDirectory($domainPath . '/Aggregates', 'Aggregates');
        $this->listDirectory($domainPath . '/Events', 'Events');
        $this->listDirectory($domainPath . '/Repositories', 'Repositories');
        $this->listDirectory($domainPath . '/ValueObjects', 'ValueObjects');

        return Command::SUCCESS;
    }

    private function listDirectory(string $path, string $label): void
    {
        if (! is_dir($path)) {
            return;
        }

        $finder = (new Finder)
            ->files()
            ->in($path)
            ->name('*.php')
            ->notName('*.stub')
            ->sortByName();

        $files = iterator_to_array($finder);

        if ($files === []) {
            return;
        }

        $this->comment(sprintf('  %s:', $label));

        foreach ($files as $file) {
            $class = str_replace(
                [app_path(), '/', '.php'],
                ['', '\\', ''],
                $file->getRealPath()
            );
            $this->line('    - ' . $class);
        }

        $this->newLine();
    }
}
