<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Php83\Rector\ClassMethod\AddOverrideAttributeToOverriddenMethodsRector;
use Rector\Set\ValueObject\SetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])->withRootFiles()
    ->withPhpSets(php85: true)
    ->withSets([
        SetList::CODE_QUALITY,
        SetList::DEAD_CODE,
        SetList::PRIVATIZATION,
        SetList::TYPE_DECLARATION,
        SetList::EARLY_RETURN,
        SetList::CODING_STYLE,
    ])
    ->withSkip([
        AddOverrideAttributeToOverriddenMethodsRector::class => [
            __DIR__ . '/src/Console/Commands',
        ],
    ])
    ->withImportNames(importShortClasses: false, removeUnusedImports: true)
    ->withParallel(timeoutSeconds: 120, maxNumberOfProcess: 16, jobSize: 2);
