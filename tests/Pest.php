<?php

declare(strict_types=1);

use Pest\PHPUnit\PHPUnit;
use PHPUnit\Framework\TestCase;

test('pest phpunit bridge', function () {
    expect(PHPUnit::getTestCaseClass())->toBe(TestCase::class);
});
