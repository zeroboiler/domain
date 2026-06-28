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

        $id = $this->resolveId($aggregate);

        if ($this->isTracking($aggregate)) {
            return;
        }

        $this->tracked[$id] = $aggregate;
    }

    public function isTracking(AggregateRoot $aggregate): bool
    {
        $id = $this->resolveId($aggregate);

        return isset($this->tracked[$id]);
    }

    public function markForDeletion(AggregateRoot $aggregate): void
    {
        if (! $this->active) {
            throw new RuntimeException('No active unit of work');
        }

        $id = $this->resolveId($aggregate);
        $this->deleted[$id] = $aggregate;
    }

    /**
     * Resolve a stable string identifier for an aggregate.
     *
     * Uses the aggregate's own domain identity when available, falling back
     * to a WeakMap-based object identity to avoid spl_object_id reuse
     * after garbage collection.
     */
    private function resolveId(AggregateRoot $aggregate): string
    {
        // Prefer the aggregate's own domain identity
        try {
            $aggregateId = $aggregate->id();

            if ($aggregateId !== '') {
                return (string) $aggregateId;
            }
        } catch (\Throwable) {
            // Aggregate may not have an ID yet (newly created, not persisted)
        }

        // Fall back to stable object identity via WeakMap (avoids spl_object_id reuse)
        static $idMap = null;
        static $nextId = 0;

        if ($idMap === null) {
            $idMap = new \WeakMap;
        }

        if (! isset($idMap[$aggregate])) {
            $idMap[$aggregate] = 'obj_' . (++$nextId);
        }

        return $idMap[$aggregate];
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
