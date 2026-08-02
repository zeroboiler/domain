<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace Tests;

use Exception;
use ZeroBoiler\Domain\Exceptions\AggregateNotFoundException;
use ZeroBoiler\Domain\Exceptions\ConflictDomainException;
use ZeroBoiler\Domain\Exceptions\DomainException;
use ZeroBoiler\Domain\Exceptions\InvalidAggregateRootException;
use ZeroBoiler\Domain\Exceptions\InvalidArgumentDomainException;
use ZeroBoiler\Domain\Exceptions\InvalidStateDomainException;
use ZeroBoiler\Domain\Exceptions\InvalidStateException;
use ZeroBoiler\Domain\Exceptions\NotFoundDomainException;
use ZeroBoiler\Domain\Exceptions\OptimisticLockException;

describe('DomainException hierarchy', function (): void {
    it('DomainException is abstract and extends Exception', function (): void {
        $reflection = new \ReflectionClass(DomainException::class);

        expect($reflection->isAbstract())->toBeTrue()
            ->and($reflection->getParentClass()->getName())->toBe(Exception::class);
    });

    it('ConflictDomainException extends DomainException', function (): void {
        $exception = ConflictDomainException::because('conflict reason');

        expect($exception)->toBeInstanceOf(DomainException::class)
            ->and($exception)->toBeInstanceOf(Exception::class)
            ->and($exception->getMessage())->toBe('conflict reason');
    });

    it('InvalidAggregateRootException extends DomainException', function (): void {
        $obj = new \stdClass;
        $exception = InvalidAggregateRootException::notAnAggregate($obj);

        expect($exception)->toBeInstanceOf(DomainException::class)
            ->and($exception->getMessage())->toContain('stdClass')
            ->and($exception->getMessage())->toContain('AggregateRoot');
    });

    it('InvalidAggregateRootException message includes class name', function (): void {
        $obj = new class {};

        $exception = InvalidAggregateRootException::notAnAggregate($obj);

        // Anonymous class names contain "class@anonymous"
        expect($exception->getMessage())->toContain('class@anonymous');
    });

    it('InvalidArgumentDomainException extends DomainException', function (): void {
        $exception = InvalidArgumentDomainException::because('bad argument');

        expect($exception)->toBeInstanceOf(DomainException::class)
            ->and($exception->getMessage())->toBe('bad argument');
    });

    it('InvalidStateDomainException extends DomainException', function (): void {
        $exception = InvalidStateDomainException::because('bad state');

        expect($exception)->toBeInstanceOf(DomainException::class)
            ->and($exception->getMessage())->toBe('bad state');
    });

    it('NotFoundDomainException extends DomainException', function (): void {
        $exception = NotFoundDomainException::because('not here');

        expect($exception)->toBeInstanceOf(DomainException::class)
            ->and($exception->getMessage())->toBe('not here');
    });

    it('ConflictDomainException can be caught as DomainException', function (): void {
        try {
            throw ConflictDomainException::because('test conflict');
        } catch (DomainException $domainException) {
            expect($domainException->getMessage())->toBe('test conflict');
        }
    });

    it('ConflictDomainException::because() returns new instance each time', function (): void {
        $first = ConflictDomainException::because('reason');
        $second = ConflictDomainException::because('reason');

        expect($first)->not->toBe($second)
            ->and($first->getMessage())->toBe($second->getMessage());
    });
});

describe('AggregateNotFoundException', function (): void {
    it('constructs with aggregate type and id in message', function (): void {
        $exception = new AggregateNotFoundException('UserAggregate', 'usr-123');

        expect($exception->getMessage())->toContain('UserAggregate')
            ->and($exception->getMessage())->toContain('usr-123')
            ->and($exception->getMessage())->toContain('not found');
    });

    it('extends Exception directly (not DomainException)', function (): void {
        $exception = new AggregateNotFoundException('Test', '1');

        expect($exception)->toBeInstanceOf(Exception::class)
            ->and($exception)->not->toBeInstanceOf(DomainException::class);
    });

    it('is final class', function (): void {
        $reflection = new \ReflectionClass(AggregateNotFoundException::class);

        expect($reflection->isFinal())->toBeTrue();
    });
});

describe('InvalidStateException', function (): void {
    it('constructs with reason via because()', function (): void {
        $exception = InvalidStateException::because('invalid operation');

        expect($exception->getMessage())->toBe('invalid operation');
    });

    it('extends Exception directly', function (): void {
        $exception = InvalidStateException::because('test');

        expect($exception)->toBeInstanceOf(Exception::class);
    });

    it('is final class', function (): void {
        $reflection = new \ReflectionClass(InvalidStateException::class);

        expect($reflection->isFinal())->toBeTrue();
    });

    it('supports exception chaining via because() + previous', function (): void {
        $previous = new \RuntimeException('root cause');
        $exception = new InvalidStateException('wrapper', 0, $previous);

        expect($exception->getPrevious())->toBe($previous);
    });
});

describe('OptimisticLockException', function (): void {
    it('constructs with aggregate id, expected and actual version', function (): void {
        $exception = OptimisticLockException::for('agg-001', 5, 7);

        expect($exception->getMessage())->toContain('agg-001')
            ->and($exception->getMessage())->toContain('5')
            ->and($exception->getMessage())->toContain('7')
            ->and($exception->getMessage())->toContain('expected version')
            ->and($exception->getMessage())->toContain('current version');
    });

    it('extends ConflictDomainException', function (): void {
        $exception = OptimisticLockException::for('agg-1', 1, 2);

        expect($exception)->toBeInstanceOf(ConflictDomainException::class)
            ->and($exception)->toBeInstanceOf(DomainException::class)
            ->and($exception)->toBeInstanceOf(Exception::class);
    });

    it('can be caught as ConflictDomainException', function (): void {
        $caught = false;

        try {
            throw OptimisticLockException::for('agg-1', 1, 2);
        } catch (ConflictDomainException $conflictDomainException) {
            $caught = true;
            expect($conflictDomainException->getMessage())->toContain('agg-1');
        }

        expect($caught)->toBeTrue();
    });

    it('handles zero versions correctly', function (): void {
        $exception = OptimisticLockException::for('agg-0', 0, 1);

        expect($exception->getMessage())->toContain('expected version 0')
            ->and($exception->getMessage())->toContain('current version 1');
    });

    it('handles equal expected and actual versions', function (): void {
        $exception = OptimisticLockException::for('agg-x', 3, 3);

        expect($exception->getMessage())->toContain('expected version 3')
            ->and($exception->getMessage())->toContain('current version 3');
    });
});

describe('Exception factory methods consistency', function (): void {
    it('all because() methods return correct type', function (): void {
        expect(ConflictDomainException::because('x'))->toBeInstanceOf(ConflictDomainException::class)
            ->and(InvalidArgumentDomainException::because('x'))->toBeInstanceOf(InvalidArgumentDomainException::class)
            ->and(InvalidStateDomainException::because('x'))->toBeInstanceOf(InvalidStateDomainException::class)
            ->and(NotFoundDomainException::because('x'))->toBeInstanceOf(NotFoundDomainException::class)
            ->and(InvalidStateException::because('x'))->toBeInstanceOf(InvalidStateException::class);
    });

    it('final exception classes are correctly marked', function (): void {
        expect(new \ReflectionClass(InvalidArgumentDomainException::class)->isFinal())->toBeTrue()
            ->and(new \ReflectionClass(InvalidStateDomainException::class)->isFinal())->toBeTrue()
            ->and(new \ReflectionClass(NotFoundDomainException::class)->isFinal())->toBeTrue()
            ->and(new \ReflectionClass(InvalidStateException::class)->isFinal())->toBeTrue()
            ->and(new \ReflectionClass(AggregateNotFoundException::class)->isFinal())->toBeTrue();
    });

    it('non-final exception classes', function (): void {
        // ConflictDomainException and InvalidAggregateRootException are not final
        // (OptimisticLockException extends ConflictDomainException)
        expect(new \ReflectionClass(ConflictDomainException::class)->isFinal())->toBeFalse()
            ->and(new \ReflectionClass(InvalidAggregateRootException::class)->isFinal())->toBeFalse();
    });
});
