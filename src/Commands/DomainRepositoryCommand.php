<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\GeneratorCommand;
use Illuminate\Support\Str;

/**
 * Generate a new Domain Repository interface and Eloquent implementation.
 *
 * Creates both the repository interface in Domain/Repositories and
 * a concrete Eloquent implementation in Domain/Repositories/Eloquent.
 *
 * Usage:
 *   ```bash
 *   php artisan zeroboiler:domain:repository Order
 *   php artisan zeroboiler:domain:repository Invoice --force
 *   ```
 *
 * @since 1.0.0
 */
#[Description('Generate a new Domain Repository interface and Eloquent implementation')]
final class DomainRepositoryCommand extends GeneratorCommand
{
    protected $name = 'zeroboiler:domain:repository';

    protected $type = 'Repository';

    #[\Override]
    protected function getStub(): string
    {
        return __DIR__ . '/../stubs/repository.stub';
    }

    #[\Override]
    protected function getDefaultNamespace(string $rootNamespace): string
    {
        return $rootNamespace . '\\Domain\\Repositories';
    }

    #[\Override]
    protected function getNameInput(): string
    {
        $name = parent::getNameInput();

        if (! str_ends_with($name, 'Repository')) {
            $name .= 'Repository';
        }

        return $name;
    }

    #[\Override]
    public function handle(): int
    {
        $result = parent::handle();

        if ($result === self::FAILURE || $result === false) {
            return self::FAILURE;
        }

        // Generate the Eloquent implementation alongside the interface
        $this->generateImplementation();

        return self::SUCCESS;
    }

    /**
     * Generate an Eloquent implementation of the repository interface.
     *
     * @return void
     */
    private function generateImplementation(): void
    {
        $name = $this->getNameInput();
        $rootNamespace = $this->laravel->getNamespace();

        $implementationName = Str::replace('Repository', 'EloquentRepository', $name);
        $implementationClass = $implementationName;
        $implementationNamespace = $rootNamespace . 'Domain\\Repositories\\Eloquent';
        $interfaceFqcn = $rootNamespace . 'Domain\\Repositories\\' . $name;

        // Derive the aggregate name from the repository name
        $aggregateName = Str::replaceLast('Repository', '', $name);
        $aggregateFqcn = $rootNamespace . 'Domain\\Aggregates\\' . $aggregateName;

        $path = $this->laravel['path'] . '/Domain/Repositories/Eloquent/' . $implementationClass . '.php';

        // Don't overwrite if the file already exists
        if (! $this->option('force') && file_exists($path)) {
            $this->components->info(sprintf('Eloquent implementation %s already exists.', $implementationClass));

            return;
        }

        // Ensure directory exists
        if (! is_dir(dirname($path))) {
            @mkdir(dirname($path), 0755, true);
        }

        $stub = $this->buildImplementationStub(
            $implementationNamespace,
            $implementationClass,
            $interfaceFqcn,
            $aggregateFqcn,
            $aggregateName,
        );

        file_put_contents($path, $stub);

        $this->components->info(sprintf('Eloquent implementation %s created successfully.', $implementationClass));
    }

    /**
     * Build the Eloquent repository implementation from a stub.
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
        $aggregateVar = lcfirst($aggregateName);

        return <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

use {$interfaceFqcn};
use {$aggregateFqcn};
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Collection;

/**
 * Eloquent implementation of {$aggregateName}Repository.
 *
 * Handles persistence and reconstitution of {$aggregateName} aggregates.
 * Add custom query methods as needed.
 */
final class {$className} implements {$aggregateName}Repository
{
    public function __construct(
        private readonly Model \${$aggregateVar}Model,
    ) {}

    public function findById(string \$id): ?{$aggregateName}
    {
        \$model = \$this->{$aggregateVar}Model->newQuery()->find(\$id);

        if (\$model === null) {
            return null;
        }

        // Map model to domain aggregate — adjust mapping as needed
        return {$aggregateName}::fromArray(\$model->toArray());
    }

    public function save({$aggregateName} \${$aggregateVar}): void
    {
        \$this->{$aggregateVar}Model->newQuery()->updateOrCreate(
            ['id' => \${$aggregateVar}->id()],
            \${$aggregateVar}->toArray(),
        );
    }

    public function delete(string \$id): void
    {
        \$this->{$aggregateVar}Model->newQuery()->where('id', \$id)->delete();
    }

    /**
     * @return Collection<int, {$aggregateName}>
     */
    public function all(): Collection
    {
        return \$this->{$aggregateVar}Model->newQuery()->get()
            ->map(fn (Model \$model) => {$aggregateName}::fromArray(\$model->toArray()));
    }
}
PHP;
    }
}
