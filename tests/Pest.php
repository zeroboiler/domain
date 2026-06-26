<?php

declare(strict_types=1);

use Pest\PHPUnit\PHPUnit;

test('pest phpunit bridge', function () {
    expect(PHPUnit::getTestCaseClass())->toBe(\PHPUnit\Framework\TestCase::class);
});