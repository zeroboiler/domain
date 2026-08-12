<?php

declare(strict_types=1);

/**
 * Tests for DomainException hierarchy.
 *
 * @covers \ZeroBoiler\Domain\Exceptions\DomainException
 * @covers \ZeroBoiler\Domain\Exceptions\InvalidStateDomainException
 * @covers \ZeroBoiler\Domain\Exceptions\InvalidArgumentDomainException
 * @covers \ZeroBoiler\Domain\Exceptions\NotFoundDomainException
 * @covers \ZeroBoiler\Domain\Exceptions\ConflictDomainException
 * @covers \ZeroBoiler\Domain\Exceptions\OptimisticLockException
 * @covers \ZeroBoiler\Domain\Exceptions\AggregateNotFoundException
 * @covers \ZeroBoiler\Domain\Exceptions\InvalidAggregateRootException
 * @covers \ZeroBoiler\Domain\Exceptions\InvalidStateException
 */
describe('DomainException', function (): void {
    it('creates with message and error code', function (): void {
        $e = new \ZeroBoiler\Domain\Exceptions\DomainException('Test error');
        expect($e->getMessage())->toBe('Test error');
        expect($e->errorCode())->toBeString()->toBeNotEmpty();
    });

    it('serializes to error array', function (): void {
        $e = new \ZeroBoiler\Domain\Exceptions\DomainException('Something went wrong');
        $array = $e->toErrorArray();

        expect($array)->toHaveKeys(['title', 'detail', 'code']);
        expect($array['detail'])->toBe('Something went wrong');
    });

    it('implements JsonSerializable', function (): void {
        $e = new \ZeroBoiler\Domain\Exceptions\DomainException('JSON test');
        $json = json_encode($e);

        expect($json)->toBeString()->toBeJson();
    });
});

describe('InvalidStateDomainException', function (): void {
    it('has correct error code', function (): void {
        $e = new \ZeroBoiler\Domain\Exceptions\InvalidStateDomainException('Invalid state');
        expect($e->errorCode())->toBe('INVALID_STATE');
    });

    it('creates via because()', function (): void {
        $e = \ZeroBoiler\Domain\Exceptions\InvalidStateDomainException::because('Cannot transition');
        expect($e->getMessage())->toBe('Cannot transition');
        expect($e->toErrorArray()['detail'])->toBe('Cannot transition');
    });
});

describe('InvalidArgumentDomainException', function (): void {
    it('has correct error code', function (): void {
        $e = new \ZeroBoiler\Domain\Exceptions\InvalidArgumentDomainException('Bad arg');
        expect($e->errorCode())->toBe('INVALID_ARGUMENT');
    });

    it('creates via because()', function (): void {
        $e = \ZeroBoiler\Domain\Exceptions\InvalidArgumentDomainException::because('Negative amount');
        expect($e->getMessage())->toBe('Negative amount');
    });
});

describe('NotFoundDomainException', function (): void {
    it('has correct error code', function (): void {
        $e = new \ZeroBoiler\Domain\Exceptions\NotFoundDomainException('Not found');
        expect($e->errorCode())->toBe('NOT_FOUND');
    });

    it('creates via forAggregate()', function (): void {
        $e = \ZeroBoiler\Domain\Exceptions\NotFoundDomainException::forAggregate('Order', 'order-123');
        expect($e->getMessage())->toContain('Order');
        expect($e->getMessage())->toContain('order-123');
    });
});

describe('ConflictDomainException', function (): void {
    it('has correct error code', function (): void {
        $e = new \ZeroBoiler\Domain\Exceptions\ConflictDomainException('Conflict');
        expect($e->errorCode())->toBe('CONFLICT');
    });
});

describe('OptimisticLockException', function (): void {
    it('has correct error code', function (): void {
        $e = new \ZeroBoiler\Domain\Exceptions\OptimisticLockException('Lock error');
        expect($e->errorCode())->toBe('OPTIMISTIC_LOCK');
    });

    it('creates via forAggregate()', function (): void {
        $e = \ZeroBoiler\Domain\Exceptions\OptimisticLockException::forAggregate('Order', 3, 5);
        expect($e->getMessage())->toContain('Order');
        expect($e->getMessage())->toContain('3');
        expect($e->getMessage())->toContain('5');
    });
});

describe('AggregateNotFoundException', function (): void {
    it('has correct error code', function (): void {
        $e = new \ZeroBoiler\Domain\Exceptions\AggregateNotFoundException('Aggregate');
        expect($e->errorCode())->toBe('AGGREGATE_NOT_FOUND');
    });
});

describe('InvalidAggregateRootException', function (): void {
    it('has correct error code', function (): void {
        $e = new \ZeroBoiler\Domain\Exceptions\InvalidAggregateRootException('Bad root');
        expect($e->errorCode())->toBe('INVALID_AGGREGATE_ROOT');
    });
});

describe('InvalidStateException', function (): void {
    it('has correct error code', function (): void {
        $e = new \ZeroBoiler\Domain\Exceptions\InvalidStateException('System error');
        expect($e->errorCode())->toBe('INVALID_STATE_SYSTEM');
    });

    it('creates via because()', function (): void {
        $e = \ZeroBoiler\Domain\Exceptions\InvalidStateException::because('Config invalid');
        expect($e->getMessage())->toBe('Config invalid');
    });
});
