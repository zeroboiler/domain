<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\GeneratorCommand;

/**
 * Generate a new Domain Repository interface and an in-memory implementation.
 *
 * Creates the repository interface in Domain/Repositories plus a
 * persistence-agnostic in-memory implementation in Domain/Repositories/InMemory.
 * The domain package stays free of Eloquent; swap the in-memory implementation
 * with a real adapter in your persistence layer (zeroboiler/persistence).
 *
 * The generated code is built from a nowdoc template with strtr placeholders —
 * no heredoc interpolation, so emitted PHP never suffers escaping corruption.
 *
 * Usage:
 *   ```bash
 *   php artisan zeroboiler:domain:repository Order
 *   php artisan zeroboiler:domain:repository Invoice --force
 *   ```
 *
 * @since 1.13.0
 */
#[Description('Generate a new Domain Repository interface and in-memory implementation')]
final class DomainRepositoryCommand extends GeneratorCommand
{
    protected $name = 'zeroboiler:domain:repository';

    protected $type = 'Repository';

    protected function getStub(): string
    {
        return __DIR__ . '/../stubs/repository.stub';
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace . '\\Domain\\Repositories';
    }

    protected function getNameInput(): string
    {
        $name = parent::getNameInput();

        if (! str_ends_with($name, 'Repository')) {
            $name .= 'Repository';
        }

        return $name;
    }

    /**
     * Replace stub placeholders, including the aggregate {{ name }} token.
     *
     * The base GeneratorCommand only replaces namespace/rootNamespace/class;
     * the repository stub additionally references the aggregate short name
     * (class minus the Repository suffix), which is resolved here.
     *
     * @param  string  $name  The fully-qualified class name being built.
     * @return string The populated stub contents.
     */
    protected function buildClass($name): string
    {
        $basename = substr($name, (int) strrpos($name, '\\') + 1);

        return str_replace(
            '{{ name }}',
            str_replace('Repository', '', $basename),
            parent::buildClass($name),
        );
    }

    public function handle(): int
    {
        $result = parent::handle();

        if ($result === self::FAILURE || $result === false) {
            return self::FAILURE;
        }

        $this->generateImplementation();

        return self::SUCCESS;
    }

    /**
     * Generate a persistence-agnostic in-memory implementation of the repository interface.
     *
     * @return void
     */
    private function generateImplementation(): void
    {
        $name = $this->getNameInput();
        $rootNamespace = $this->laravel->getNamespace();

        $aggregateName = str_replace('Repository', '', $name);
        $implementationClass = $aggregateName . 'InMemoryRepository';
        $path = $this->laravel['path'] . '/Domain/Repositories/InMemory/' . $implementationClass . '.php';

        if (! $this->option('force') && file_exists($path)) {
            $this->components->info(sprintf('In-memory implementation %s already exists.', $implementationClass));

            return;
        }

        if (! is_dir(dirname($path))) {
            @mkdir(dirname($path), 0755, true);
        }

        file_put_contents($path, $this->buildImplementationStub(
            $rootNamespace . 'Domain\\Repositories\\InMemory',
            $implementationClass,
            $rootNamespace . 'Domain\\Repositories\\' . $name,
            $rootNamespace . 'Domain\\Aggregates\\' . $aggregateName,
            $aggregateName,
        ));

        $this->components->info(sprintf('In-memory implementation %s created successfully.', $implementationClass));
    }

    /**
     * Build the in-memory repository implementation from a nowdoc template.
     *
     * Placeholders use the %%NAME%% form so no PHP interpolation can ever
     * touch the emitted source.
     *
     * @param  string  $namespace  The implementation namespace (FQCN).
     * @param  string  $className  The implementation class name.
     * @param  string  $interfaceFqcn  The repository interface FQCN.
     * @param  string  $aggregateFqcn  The aggregate root FQCN.
     * @param  string  $aggregateName  The aggregate root short name.
     * @return string The generated PHP source code.
     */
    private function buildImplementationStub(
        string $namespace,
        string $className,
        string $interfaceFqcn,
        string $aggregateFqcn,
        string $aggregateName,
    ): string {
        $template = <<<'PHP'
<?php

declare(strict_types=1);

namespace %%NAMESPACE%%;

use %%AGG_FQCN%%;
use %%INTERFACE_FQCN%%;
use ZeroBoiler\Domain\AggregateRoot;
use ZeroBoiler\Domain\Exceptions\OptimisticLockException;

/**
 * In-memory implementation of %%AGG%%Repository.
 *
 * Persistence-agnostic store keyed by aggregate identity — use in tests and
 * as a reference for real persistence adapters (see zeroboiler/persistence).
 * save() enforces optimistic locking via aggregate version comparison.
 */
final class %%CLASS%% implements %%AGG%%Repository
{
    /** @var array<string, AggregateRoot> */
    private array $stored = [];

    public function find(string|int $id): ?AggregateRoot
    {
        return $this->stored[(string) $id] ?? null;
    }

    public function findById(string $id): ?%%AGG%%
    {
        $aggregate = $this->find($id);

        return $aggregate instanceof %%AGG%% ? $aggregate : null;
    }

    public function save(AggregateRoot $aggregate): void
    {
        $persisted = $this->stored[$aggregate->id()] ?? null;

        if ($persisted !== null && $persisted->version() !== $aggregate->version()) {
            throw OptimisticLockException::for(
                $aggregate->id(),
                expectedVersion: $aggregate->version(),
                actualVersion: $persisted->version(),
            );
        }

        $this->stored[$aggregate->id()] = $aggregate;
        $aggregate->incrementVersion();
    }

    public function delete(string|int $id): void
    {
        unset($this->stored[(string) $id]);
    }
}
PHP;

        return strtr($template, [
            '%%NAMESPACE%%' => $namespace,
            '%%CLASS%%' => $className,
            '%%INTERFACE_FQCN%%' => $interfaceFqcn,
            '%%AGG_FQCN%%' => $aggregateFqcn,
            '%%AGG%%' => $aggregateName,
        ]);
    }
}
