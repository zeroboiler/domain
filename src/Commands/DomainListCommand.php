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
 * List all domain classes (Aggregates, Events, Repositories, ValueObjects, Contexts).
 *
 * Scans the `app/Domain` directory and groups classes by subdirectory.
 * Bounded contexts following the `app/Contexts/{Name}` convention are listed
 * first so both layouts are visible in one audit view.
 *
 * Usage:
 *   ```bash
 *   php artisan zeroboiler:domain:list
 *   ```
 *
 * @since 1.0.0
 */
#[Description('List all Domain classes (Aggregates, Events, Repositories, ValueObjects, Contexts)')]
final class DomainListCommand extends Command
{
    protected $name = 'zeroboiler:domain:list';

    public function handle(): int
    {
        $this->listContexts(app_path('Contexts'));

        $domainPath = app_path('Domain');

        if (! is_dir($domainPath)) {
            if (! is_dir(app_path('Contexts'))) {
                $this->info('No domain classes found. Neither Domain nor Contexts directory exists.');
            }

            return self::SUCCESS;
        }

        $this->info('Domain Classes:');
        $this->newLine();

        $this->listDirectory($domainPath . '/Aggregates', 'Aggregates');
        $this->listDirectory($domainPath . '/Events', 'Events');
        $this->listDirectory($domainPath . '/Repositories', 'Repositories');
        $this->listDirectory($domainPath . '/ValueObjects', 'ValueObjects');

        return self::SUCCESS;
    }

    /**
     * List bounded contexts using the app/Contexts/{Name} convention.
     *
     * Each immediate subdirectory is treated as a context; its class files
     * are listed under the context name.
     *
     * @param  string  $contextsPath  The absolute app/Contexts path.
     * @return void
     */
    private function listContexts(string $contextsPath): void
    {
        if (! is_dir($contextsPath)) {
            return;
        }

        $contexts = glob($contextsPath . '/*', GLOB_ONLYDIR);

        if ($contexts === false || $contexts === []) {
            return;
        }

        $this->info('Bounded Contexts:');
        $this->newLine();

        sort($contexts);

        foreach ($contexts as $contextDir) {
            $this->comment('  ' . basename($contextDir) . ':');
            $this->listDirectoryRecursive($contextDir);
            $this->newLine();
        }
    }

    /**
     * Recursively list PHP class files under a context directory.
     *
     * @param  string  $path  The absolute context directory path.
     * @return void
     */
    private function listDirectoryRecursive(string $path): void
    {
        $finder = (new Finder)
            ->files()
            ->in($path)
            ->name('*.php')
            ->notName('*.stub')
            ->sortByName();

        foreach ($finder as $file) {
            $class = str_replace(
                [app_path(), '/', '.php'],
                ['', '\\', ''],
                $file->getRealPath()
            );
            $this->line('    - ' . $class);
        }
    }

    /**
     * List PHP files in a directory, grouped under a label.
     *
     * @param  string  $path  The absolute directory path to scan.
     * @param  string  $label  The display label for the group (e.g. 'Aggregates').
     * @return void
     */
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
