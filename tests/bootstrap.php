<?php

declare(strict_types=1);

$autoloadPath = __DIR__ . '/../vendor/autoload.php';

if (! file_exists($autoloadPath)) {
    echo 'Autoload file not found. Run `composer install` first.' . PHP_EOL;
    exit(1);
}

require_once $autoloadPath;
