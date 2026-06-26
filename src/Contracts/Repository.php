<?php

declare(strict_types=1);

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

namespace ZeroBoiler\Domain\Contracts;

use ZeroBoiler\Domain\AggregateRoot;

interface Repository
{
    /**
     * @template T of AggregateRoot
     *
     * @param  T  $aggregate
     */
    public function save(AggregateRoot $aggregate): void;

    /**
     * @template T of AggregateRoot
     *
     * @param  T  $aggregate
     */
    public function delete(AggregateRoot $aggregate): void;

    /**
     * @template T of AggregateRoot
     *
     * @param  string  $id
     * @return T|null
     */
    public function find(string $id): ?object;
}