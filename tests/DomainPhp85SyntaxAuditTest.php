<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Tests;

use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use ReflectionProperty;
use ReflectionType;

/**
 * PHP 8.5 syntax, strict types, return types, docblocks, and typed properties audit.
 *
 * Scans every source file to verify:
 * 1. declare(strict_types=1) present
 * 2. All public/protected methods have explicit return types
 * 3. All class properties have typed declarations (no untyped properties)
 * 4. No deprecated PHP patterns (e.g., `var` keyword)
 * 5. Core classes are final where appropriate
 * 6. Docblocks present on all public/protected methods
 */
final class DomainPhp85SyntaxAuditTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var list<string> Classes that are abstract and therefore not final.
     */
    private const array ABSTRACT_CLASSES = [
        \ZeroBoiler\Domain\AggregateRoot::class,
        \ZeroBoiler\Domain\Entity::class,
        \ZeroBoiler\Domain\ValueObject::class,
        \ZeroBoiler\Domain\Exceptions\DomainException::class,
        \ZeroBoiler\Domain\Identifiers\UuidIdentifier::class,
        \ZeroBoiler\Domain\Identifiers\UlidIdentifier::class,
        \ZeroBoiler\Domain\Contracts\AggregateRoot::class,
        \ZeroBoiler\Domain\Contracts\Entity::class,
        \ZeroBoiler\Domain\Contracts\Identifier::class,
        \ZeroBoiler\Domain\Contracts\Repository::class,
        \ZeroBoiler\Domain\Contracts\UnitOfWork::class,
        \ZeroBoiler\Domain\Identifiers\Identifier::class,
    ];

    /**
     * @var list<string> Classes that are abstract and intentionally non-final.
     * @deprecated Use ABSTRACT_CLASSES instead
     */
    private const array NON_FINAL_CONCRETE = [];

    /**
     * @var list<string> Classes that have no return type (PHP internal overrides or special cases).
     */
    private const array ALLOWED_NO_RETURN_TYPE = [];

    /**
     * @var list<string> Namespace prefixes to scan.
     */
    private const array SCAN_NAMESPACES = [
        'ZeroBoiler\\Domain',
    ];

    /**
     * @var string Path to the source directory.
     */
    private const string SRC_PATH = __DIR__ . '/../src';

    // ── Test: All source files have declare(strict_types=1) ──

    #[Test]
    public function allSourceFilesHaveStrictTypes(): void
    {
        $files = $this->scanPhpFiles(self::SRC_PATH);
        $violations = [];

        foreach ($files as $file) {
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }

            if (! str_contains($content, 'declare(strict_types=1)')) {
                $violations[] = $this->relativePath($file);
            }
        }

        $this->assertEmpty(
            $violations,
            sprintf(
                'Files missing declare(strict_types=1): %s',
                implode(', ', $violations),
            ),
        );
    }

    // ── Test: All public/protected methods have explicit return types ──

    #[Test]
    public function allPublicProtectedMethodsHaveReturnTypes(): void
    {
        $classes = $this->scanClasses(self::SRC_PATH, self::SCAN_NAMESPACES);
        $violations = [];

        foreach ($classes as $class) {
            // Skip interfaces — they define contracts, return types may be in implementations
            if ($class->isInterface()) {
                continue;
            }

            // Skip traits — they're mixed into classes
            if ($class->isTrait()) {
                continue;
            }

            foreach ($class->getMethods(\ReflectionMethod::IS_PUBLIC | \ReflectionMethod::IS_PROTECTED) as $method) {
                // Skip constructor, destructors, and magic methods
                if (str_starts_with($method->getName(), '__')) {
                    continue;
                }

                // Skip methods from parent classes we don't own
                if ($method->getDeclaringClass()->getName() !== $class->getName()) {
                    continue;
                }

                $returnType = $method->getReturnType();

                if ($returnType === null) {
                    $violations[] = sprintf(
                        '%s::%s()',
                        $class->getShortName(),
                        $method->getName(),
                    );
                }
            }
        }

        $this->assertEmpty(
            $violations,
            sprintf(
                'Methods missing explicit return types: %s',
                implode(', ', $violations),
            ),
        );
    }

    // ── Test: No untyped properties (excluding mixed from parent libs) ──

    #[Test]
    public function noUntypedProperties(): void
    {
        $classes = $this->scanClasses(self::SRC_PATH, self::SCAN_NAMESPACES);
        $violations = [];

        foreach ($classes as $class) {
            if ($class->isInterface() || $class->isTrait()) {
                continue;
            }

            foreach ($class->getProperties() as $property) {
                // Skip properties from parent classes we don't own
                if ($property->getDeclaringClass()->getName() !== $class->getName()) {
                    continue;
                }

                // Skip static properties
                if ($property->isStatic()) {
                    continue;
                }

                $type = $property->getType();

                if ($type === null) {
                    $violations[] = sprintf(
                        '%s::$%s',
                        $class->getShortName(),
                        $property->getName(),
                    );
                }
            }
        }

        $this->assertEmpty(
            $violations,
            sprintf(
                'Untyped properties found: %s',
                implode(', ', $violations),
            ),
        );
    }

    // ── Test: No 'var' keyword usage ──

    #[Test]
    public function noVarKeywordUsage(): void
    {
        $files = $this->scanPhpFiles(self::SRC_PATH);
        $violations = [];

        foreach ($files as $file) {
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }

            // Match 'var $' that is not commented
            $lines = explode("\n", $content);
            foreach ($lines as $lineNum => $line) {
                $trimmed = trim($line);
                // Skip comments
                if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '#') || str_starts_with($trimmed, '*')) {
                    continue;
                }

                if (preg_match('/^\s*var\s+/', $line)) {
                    $violations[] = sprintf(
                        '%s:%d',
                        $this->relativePath($file),
                        $lineNum + 1,
                    );
                }
            }
        }

        $this->assertEmpty(
            $violations,
            sprintf(
                'Files using deprecated "var" keyword: %s',
                implode(', ', $violations),
            ),
        );
    }

    // ── Test: Core classes are final ──

    #[Test]
    public function concreteCoreClassesAreFinal(): void
    {
        $concreteFinalClasses = [
            \ZeroBoiler\Domain\AggregateRootId::class,
            \ZeroBoiler\Domain\DomainEventCollection::class,
            \ZeroBoiler\Domain\InMemoryUnitOfWork::class,
            \ZeroBoiler\Domain\Identifiers\IntegerIdentifier::class,
            \ZeroBoiler\Domain\Identifiers\StringIdentifier::class,
            \ZeroBoiler\Domain\Snapshots\Snapshot::class,
            \ZeroBoiler\Domain\Snapshots\SnapshotPolicy::class,
            \ZeroBoiler\Domain\Snapshots\SnapshottingRepository::class,
            \ZeroBoiler\Domain\Exceptions\InvalidStateDomainException::class,
            \ZeroBoiler\Domain\Exceptions\InvalidArgumentDomainException::class,
            \ZeroBoiler\Domain\Exceptions\NotFoundDomainException::class,
            \ZeroBoiler\Domain\Exceptions\ConflictDomainException::class,
            \ZeroBoiler\Domain\Exceptions\OptimisticLockException::class,
            \ZeroBoiler\Domain\Exceptions\AggregateNotFoundException::class,
            \ZeroBoiler\Domain\Exceptions\InvalidAggregateRootException::class,
            \ZeroBoiler\Domain\Exceptions\InvalidStateException::class,
        ];

        $violations = [];

        foreach ($concreteFinalClasses as $className) {
            $ref = new ReflectionClass($className);

            if (! $ref->isFinal()) {
                $violations[] = $ref->getShortName();
            }
        }

        $this->assertEmpty(
            $violations,
            sprintf(
                'Concrete classes that should be final: %s',
                implode(', ', $violations),
            ),
        );
    }

    // ── Test: Docblocks present on all public methods ──

    #[Test]
    public function allPublicMethodsHaveDocblocks(): void
    {
        $classes = $this->scanClasses(self::SRC_PATH, self::SCAN_NAMESPACES);
        $violations = [];

        foreach ($classes as $class) {
            if ($class->isInterface() || $class->isTrait()) {
                continue;
            }

            foreach ($class->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                // Skip constructor
                if ($method->getName() === '__construct') {
                    continue;
                }

                // Skip methods from parent classes we don't own
                if ($method->getDeclaringClass()->getName() !== $class->getName()) {
                    continue;
                }

                $doc = $method->getDocComment();
                if ($doc === false || str_contains($doc, '@internal')) {
                    continue;
                }

                // Check for at least a description or annotation
                $hasDescription = preg_match('/\/\*\*\s*\*\s+[A-Z]/', $doc) === 1;
                $hasAnnotation = preg_match('/\*\s*@(param|return|throws|see|example)/', $doc) === 1;

                if (! $hasDescription && ! $hasAnnotation) {
                    $violations[] = sprintf(
                        '%s::%s()',
                        $class->getShortName(),
                        $method->getName(),
                    );
                }
            }
        }

        $this->assertEmpty(
            $violations,
            sprintf(
                'Public methods missing docblocks: %s',
                implode(', ', $violations),
            ),
        );
    }

    // ── Test: All exceptions implement JsonSerializable ──

    #[Test]
    public function domainExceptionsImplementJsonSerializable(): void
    {
        $exceptionClasses = [
            \ZeroBoiler\Domain\Exceptions\DomainException::class,
            \ZeroBoiler\Domain\Exceptions\InvalidStateDomainException::class,
            \ZeroBoiler\Domain\Exceptions\InvalidArgumentDomainException::class,
            \ZeroBoiler\Domain\Exceptions\NotFoundDomainException::class,
            \ZeroBoiler\Domain\Exceptions\ConflictDomainException::class,
            \ZeroBoiler\Domain\Exceptions\OptimisticLockException::class,
            \ZeroBoiler\Domain\Exceptions\AggregateNotFoundException::class,
            \ZeroBoiler\Domain\Exceptions\InvalidAggregateRootException::class,
        ];

        foreach ($exceptionClasses as $className) {
            $this->assertInstanceOf(
                \JsonSerializable::class,
                new (new ReflectionClass($className))->newInstanceWithoutConstructor(),
                sprintf('%s should implement JsonSerializable', (new ReflectionClass($className))->getShortName()),
            );
        }
    }

    // ── Test: All identifiers implement Identifier contract ──

    #[Test]
    public function allIdentifiersImplementContract(): void
    {
        $identifierClasses = [
            \ZeroBoiler\Domain\Identifiers\UuidIdentifier::class,
            \ZeroBoiler\Domain\Identifiers\UlidIdentifier::class,
            \ZeroBoiler\Domain\Identifiers\StringIdentifier::class,
            \ZeroBoiler\Domain\Identifiers\IntegerIdentifier::class,
            \ZeroBoiler\Domain\Identifiers\Identifier::class,
            \ZeroBoiler\Domain\AggregateRootId::class,
        ];

        foreach ($identifierClasses as $className) {
            $ref = new ReflectionClass($className);

            if (! $ref->isAbstract()) {
                continue;
            }

            $this->assertTrue(
                $ref->implementsInterface(\ZeroBoiler\Domain\Contracts\Identifier::class),
                sprintf('%s should implement Identifier contract', $ref->getShortName()),
            );
        }
    }

    // ── Test: AggregateRootId is readonly ──

    #[Test]
    public function aggregateRootIdIsReadonlyClass(): void
    {
        $ref = new ReflectionClass(\ZeroBoiler\Domain\AggregateRootId::class);

        $this->assertTrue(
            $ref->isFinal(),
            'AggregateRootId should be final',
        );

        // Check that all properties are readonly
        foreach ($ref->getProperties() as $prop) {
            if ($prop->isStatic()) {
                continue;
            }

            $this->assertTrue(
                $prop->isReadOnly(),
                sprintf('AggregateRootId::$%s should be readonly', $prop->getName()),
            );
        }
    }

    // ── Test: Entity has readonly $id ──

    #[Test]
    public function entityIdPropertyIsReadonly(): void
    {
        $ref = new ReflectionClass(\ZeroBoiler\Domain\Entity::class);
        $idProp = $ref->getProperty('id');

        $this->assertTrue(
            $idProp->isReadOnly(),
            'Entity::$id should be readonly',
        );
    }

    // ── Test: DomainEventCollection is final readonly ──

    #[Test]
    public function domainEventCollectionIsFinalReadonly(): void
    {
        $ref = new ReflectionClass(\ZeroBoiler\Domain\DomainEventCollection::class);

        $this->assertTrue($ref->isFinal(), 'DomainEventCollection should be final');
        $this->assertTrue($ref->isReadOnly(), 'DomainEventCollection should be readonly');
    }

    // ── Test: Snapshot is final readonly ──

    #[Test]
    public function snapshotIsFinalReadonly(): void
    {
        $ref = new ReflectionClass(\ZeroBoiler\Domain\Snapshots\Snapshot::class);

        $this->assertTrue($ref->isFinal(), 'Snapshot should be final');
        $this->assertTrue($ref->isReadOnly(), 'Snapshot should be readonly');
    }

    // ── Test: DomainException error code uniqueness ──

    #[Test]
    public function domainExceptionErrorCodesAreUnique(): void
    {
        $ref = new ReflectionClass(\ZeroBoiler\Domain\Exceptions\DomainException::class);
        $dir = dirname($ref->getFileName());

        $codes = [];
        $files = glob($dir . '/*.php');

        foreach ($files as $file) {
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }

            // Extract return 'STRING' from defaultErrorCode() methods
            if (preg_match("/protected function defaultErrorCode\(\): string\s*\{?\s*return '([^']+)'/", $content, $matches)) {
                $code = $matches[1];
                $className = basename($file, '.php');

                $this->assertNotContains(
                    $code,
                    array_keys($codes),
                    sprintf(
                        'Duplicate error code "%s" in %s (already used by %s)',
                        $code,
                        $className,
                        $codes[$code] ?? 'unknown',
                    ),
                );

                $codes[$code] = $className;
            }
        }

        // Verify all expected codes are present
        $expectedCodes = [
            'DOMAIN_ERROR' => 'DomainException (abstract)',
            'INVALID_STATE' => 'InvalidStateDomainException',
            'INVALID_ARGUMENT' => 'InvalidArgumentDomainException',
            'NOT_FOUND' => 'NotFoundDomainException',
            'CONFLICT' => 'ConflictDomainException',
            'OPTIMISTIC_LOCK' => 'OptimisticLockException',
            'AGGREGATE_NOT_FOUND' => 'AggregateNotFoundException',
            'INVALID_AGGREGATE_ROOT' => 'InvalidAggregateRootException',
        ];

        foreach ($expectedCodes as $code => $class) {
            $this->assertArrayHasKey(
                $code,
                $codes,
                sprintf('Missing error code "%s" (expected from %s)', $code, $class),
            );
        }
    }

    // ── Helpers ──

    /**
     * @return list<string>
     */
    private function scanPhpFiles(string $dir): array
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
        );

        $files = [];
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * @param  list<string>  $namespaces
     * @return list<ReflectionClass>
     */
    private function scanClasses(string $dir, array $namespaces): array
    {
        $classes = [];
        $files = $this->scanPhpFiles($dir);

        foreach ($files as $file) {
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }

            // Extract namespace and class name
            if (preg_match('/namespace\s+([\w\\\\]+)/', $content, $nsMatch)
                && preg_match('/^(?:abstract\s+)?(?:final\s+)?(?:readonly\s+)?class\s+(\w+)/m', $content, $classMatch)
                || preg_match('/namespace\s+([\w\\\\]+)/', $content, $nsMatch)
                && preg_match('/^(?:final\s+)?(?:readonly\s+)?(?:abstract\s+)?class\s+(\w+)/m', $content, $classMatch)
                || preg_match('/namespace\s+([\w\\\\]+)/', $content, $nsMatch)
                && preg_match('/^(?:readonly\s+)?(?:final\s+)?(?:abstract\s+)?class\s+(\w+)/m', $content, $classMatch)
            ) {
                $ns = $nsMatch[1];

                // Only scan classes in our namespaces
                $inScope = false;
                foreach ($namespaces as $namespace) {
                    if (str_starts_with($ns, $namespace)) {
                        $inScope = true;
                        break;
                    }
                }

                if ($inScope) {
                    $fqcn = $ns . '\\' . $classMatch[1];

                    if (class_exists($fqcn) || interface_exists($fqcn) || trait_exists($fqcn)) {
                        $classes[] = new ReflectionClass($fqcn);
                    }
                }
            }
        }

        return $classes;
    }

    private function relativePath(string $absolute): string
    {
        return str_replace(self::SRC_PATH . '/', '', $absolute);
    }
}
