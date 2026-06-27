<?php

declare(strict_types=1);

/**
 * Shared Rector configuration for all ZeroBoiler packages.
 *
 * Copy this file to each package root as `rector.php` and adjust paths.
 */

use Rector\Config\RectorConfig;
use Rector\Doctrine\Set\DoctrineSetList;
use Rector\Laravel\Set\LaravelLevelSetList;
use Rector\Laravel\Set\LaravelSetList;
use Rector\PHPUnit\Set\PHPUnitSetList;
use Rector\Set\ValueObject\LevelSetList;
use Rector\Set\ValueObject\SetList;

return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->paths([
        __DIR__.'/src',
        __DIR__.'/tests',
    ]);

    $rectorConfig->skip([
        __DIR__.'/tests/Fixtures',
    ]);

    // PHP upgrades
    $rectorConfig->sets([
        LevelSetList::UP_TO_PHP_85,
        SetList::CODE_QUALITY,
        SetList::DEAD_CODE,
        SetList::EARLY_RETURN,
        SetList::INSTANCEOF,
        SetList::TYPE_DECLARATION,
        SetList::PRIVATIZATION,
    ]);

    // Laravel
    $rectorConfig->sets([
        LaravelSetList::LARAVEL_130,
        LaravelLevelSetList::UP_TO_LARAVEL_130,
    ]);

    // PHPUnit → Pest
    $rectorConfig->sets([
        PHPUnitSetList::PHPUNIT_110,
        PHPUnitSetList::PHPUNIT_CODE_QUALITY,
    ]);

    // Doctrine (if used)
    $rectorConfig->sets([
        DoctrineSetList::DOCTRINE_CODE_QUALITY,
    ]);

    // Auto-import
    $rectorConfig->importNames();
    $rectorConfig->importShortClasses(false);

    // Parallel
    $rectorConfig->parallel();
};
