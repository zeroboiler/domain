<?php

declare(strict_types=1);

namespace ZeroBoiler\Domain\Tests\Fixtures;

use ZeroBoiler\Domain\Entity;
use ZeroBoiler\Domain\Identifiers\Identifier;

final class TestEntity extends Entity
{
    public function __construct(public Identifier $id) {}
}