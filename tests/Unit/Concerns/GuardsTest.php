<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Tests\Unit\Concerns;

use ZeroBoiler\Domain\AggregateRoot;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\Concerns\Guards;
use ZeroBoiler\Domain\Exceptions\InvalidArgumentDomainException;
use ZeroBoiler\Domain\Exceptions\InvalidStateDomainException;
use ZeroBoiler\Domain\Exceptions\NotFoundDomainException;

beforeEach(function (): void {
    $this->order = new class(AggregateRootId::generate(), 'pending', 5, 99.99) extends AggregateRoot {
        use Guards;

        public function __construct(
            AggregateRootId $id,
            public readonly string $status,
            public readonly int $quantity,
            public readonly float $total,
        ) {
            parent::__construct($id);
        }
    };
});

describe('assertNotEmptyString', function (): void {
    it('passes for non-empty strings', function (): void {
        $this->order->assertNotEmptyString('hello', 'name');
        expect(true)->toBeTrue(); // No exception = pass
    });

    it('throws InvalidArgumentDomainException for empty string', function (): void {
        $this->order->assertNotEmptyString('', 'name');
    })->throws(InvalidArgumentDomainException::class, '"name" must not be empty.');

    it('throws with EMPTY_STRING error code', function (): void {
        try {
            $this->order->assertNotEmptyString('', 'field');
        } catch (InvalidArgumentDomainException $e) {
            expect($e->errorCode())->toBe('EMPTY_STRING');
        }
    });
});

describe('assertMaxLength', function (): void {
    it('passes for strings within limit', function (): void {
        $this->order->assertMaxLength('abc', 5, 'name');
        expect(true)->toBeTrue();
    });

    it('passes for exact length', function (): void {
        $this->order->assertMaxLength('abc', 3, 'name');
        expect(true)->toBeTrue();
    });

    it('throws for strings exceeding limit', function (): void {
        $this->order->assertMaxLength('abcdef', 5, 'name');
    })->throws(InvalidArgumentDomainException::class);
});

describe('assertPositiveInteger', function (): void {
    it('passes for positive integers', function (): void {
        $this->order->assertPositiveInteger(1, 'count');
        $this->order->assertPositiveInteger(PHP_INT_MAX, 'count');
        expect(true)->toBeTrue();
    });

    it('throws for zero', function (): void {
        $this->order->assertPositiveInteger(0, 'count');
    })->throws(InvalidArgumentDomainException::class, '"count" must be a positive integer');

    it('throws for negative integers', function (): void {
        $this->order->assertPositiveInteger(-1, 'count');
    })->throws(InvalidArgumentDomainException::class);
});

describe('assertNonNegativeInteger', function (): void {
    it('passes for zero and positive', function (): void {
        $this->order->assertNonNegativeInteger(0, 'balance');
        $this->order->assertNonNegativeInteger(1, 'balance');
        expect(true)->toBeTrue();
    });

    it('throws for negative', function (): void {
        $this->order->assertNonNegativeInteger(-1, 'balance');
    })->throws(InvalidArgumentDomainException::class, '"balance" must be zero or positive');
});

describe('assertNotNull', function (): void {
    it('passes for non-null values', function (): void {
        $this->order->assertNotNull('value', 'field');
        $this->order->assertNotNull(0, 'field');
        $this->order->assertNotNull(false, 'field');
        expect(true)->toBeTrue();
    });

    it('throws for null', function (): void {
        $this->order->assertNotNull(null, 'field');
    })->throws(InvalidArgumentDomainException::class, '"field" must not be null.');
});

describe('assertFound', function (): void {
    it('passes for non-null values', function (): void {
        $this->order->assertFound(new \stdClass, 'Order');
        expect(true)->toBeTrue();
    });

    it('throws NotFoundDomainException for null', function (): void {
        $this->order->assertFound(null, 'Order');
    })->throws(NotFoundDomainException::class, 'Order was not found.');
});

describe('assertStateIs', function (): void {
    it('passes when state matches', function (): void {
        $this->order->assertStateIs('pending', 'pending', 'pay');
        expect(true)->toBeTrue();
    });

    it('throws when state does not match', function (): void {
        $this->order->assertStateIs('confirmed', 'pending', 'ship');
    })->throws(InvalidStateDomainException::class, 'Cannot ship — must be in "confirmed" state, got "pending".');
});

describe('assertStateIn', function (): void {
    it('passes when state is in allowed list', function (): void {
        $this->order->assertStateIn(['pending', 'confirmed'], 'pending', 'cancel');
        expect(true)->toBeTrue();
    });

    it('throws when state is not in allowed list', function (): void {
        $this->order->assertStateIn(['pending', 'confirmed'], 'shipped', 'cancel');
    })->throws(InvalidStateDomainException::class);
});

describe('assertStateIsNot', function (): void {
    it('passes when state is not the disallowed value', function (): void {
        $this->order->assertStateIsNot('cancelled', 'pending', 'ship');
        expect(true)->toBeTrue();
    });

    it('throws when state matches disallowed value', function (): void {
        $this->order->assertStateIsNot('cancelled', 'cancelled', 'ship');
    })->throws(InvalidStateDomainException::class, 'Cannot ship — must not be in "cancelled" state.');
});

describe('assertRange', function (): void {
    it('passes for int within range', function (): void {
        $this->order->assertRange(5, 1, 10, 'count');
        expect(true)->toBeTrue();
    });

    it('passes for float within range', function (): void {
        $this->order->assertRange(99.99, 0.01, 99999.99, 'amount');
        expect(true)->toBeTrue();
    });

    it('passes for exact boundaries', function (): void {
        $this->order->assertRange(1, 1, 10, 'count');
        $this->order->assertRange(10, 1, 10, 'count');
        expect(true)->toBeTrue();
    });

    it('throws for value below min', function (): void {
        $this->order->assertRange(0, 1, 10, 'count');
    })->throws(InvalidArgumentDomainException::class);

    it('throws for value above max', function (): void {
        $this->order->assertRange(11, 1, 10, 'count');
    })->throws(InvalidArgumentDomainException::class);
});

describe('assertIn', function (): void {
    it('passes when value is in allowed list', function (): void {
        $this->order->assertIn(['USD', 'EUR', 'GBP'], 'EUR', 'currency');
        expect(true)->toBeTrue();
    });

    it('throws when value is not in allowed list', function (): void {
        $this->order->assertIn(['USD', 'EUR', 'GBP'], 'JPY', 'currency');
    })->throws(InvalidArgumentDomainException::class, '"currency" must be one of ["USD", "EUR", "GBP"]');
});
