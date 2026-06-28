<?php

declare(strict_types=1);

namespace ZeroBoiler\Domain;

use RuntimeException;
use ZeroBoiler\Domain\Contracts\AggregateRoot;
use ZeroBoiler\Domain\Contracts\UnitOfWork as UnitOfWorkContract;

class InMemoryUnitOfWork implements UnitOfWorkContract
{
    private bool $active = false;

    private array $tracked = [];

    private array $committed = [];

    private array $deleted = [];

    public function begin(): void
    {
        if ($this->active) {
            throw new RuntimeException('Unit of work already active');
        }

        $this->active = true;
        $this->tracked = [];
        $this->committed = [];
        $this->deleted = [];
    }

    public function commit(): void
    {
        if (! $this->active) {
            throw new RuntimeException('No active unit of work');
        }

        $this->committed = [...$this->committed, ...$this->tracked];
        $this->tracked = [];
        $this->active = false;
    }

    public function rollback(): void
    {
        if (! $this->active) {
            throw new RuntimeException('No active unit of work');
        }

        $this->tracked = [];
        $this->deleted = [];
        $this->active = false;
    }

    public function track(AggregateRoot $aggregate): void
    {
        if (! $this->active) {
            throw new RuntimeException('No active unit of work');
        }

        $id = (string) $aggregate->id();

        if ($this->isTracked($aggregate)) {
            return;
        }

        $this->tracked[$id] = $aggregate;
    }

    public function isTracking(AggregateRoot $aggregate): bool
    {
        $id = (string) $aggregate->id();

        return isset($this->tracked[$id]);
    }

    public function markForDeletion(AggregateRoot $aggregate): void
    {
        if (! $this->active) {
            throw new RuntimeException('No active unit of work');
        }

        $id = (string) $aggregate->id();
        $this->deleted[$id] = $aggregate;
    }

    public function getCommitted(): array
    {
        return $this->committed;
    }

    public function getDeleted(): array
    {
        return $this->deleted;
    }
}
