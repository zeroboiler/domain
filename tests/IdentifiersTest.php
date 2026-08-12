<?php

declare(strict_types=1);

/**
 * Tests for Identifier value objects.
 *
 * @covers \ZeroBoiler\Domain\Identifiers\UuidIdentifier
 * @covers \ZeroBoiler\Domain\Identifiers\UlidIdentifier
 * @covers \ZeroBoiler\Domain\Identifiers\StringIdentifier
 * @covers \ZeroBoiler\Domain\Identifiers\IntegerIdentifier
 */
describe('UuidIdentifier', function (): void {
    it('generates a new UUID v4 identifier', function (): void {
        $id = \ZeroBoiler\Domain\Identifiers\UuidIdentifier::generate();
        expect($id->toString())->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[0-9a-f]{4}-[0-9a-f]{12}$/');
    });

    it('creates from string', function (): void {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $id = \ZeroBoiler\Domain\Identifiers\UuidIdentifier::fromString($uuid);
        expect($id->toString())->toBe($uuid);
    });

    it('compares equality correctly', function (): void {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $a = \ZeroBoiler\Domain\Identifiers\UuidIdentifier::fromString($uuid);
        $b = \ZeroBoiler\Domain\Identifiers\UuidIdentifier::fromString($uuid);
        $c = \ZeroBoiler\Domain\Identifiers\UuidIdentifier::generate();

        expect($a->equals($b))->toBeTrue();
        expect($a->equals($c))->toBeFalse();
    });

    it('round-trips through fromArray/toArray', function (): void {
        $id = \ZeroBoiler\Domain\Identifiers\UuidIdentifier::generate();
        $restored = \ZeroBoiler\Domain\Identifiers\UuidIdentifier::fromArray($id->toArray());
        expect($id->equals($restored))->toBeTrue();
    });
});

describe('UlidIdentifier', function (): void {
    it('generates a new ULID identifier', function (): void {
        $id = \ZeroBoiler\Domain\Identifiers\UlidIdentifier::generate();
        expect($id->toString())->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/');
    });

    it('creates from string', function (): void {
        $ulid = '01H4X2K5P7Q8R9S0T1U2V3W4X5';
        $id = \ZeroBoiler\Domain\Identifiers\UlidIdentifier::fromString($ulid);
        expect($id->toString())->toBe($ulid);
    });

    it('round-trips through fromArray/toArray', function (): void {
        $id = \ZeroBoiler\Domain\Identifiers\UlidIdentifier::generate();
        $restored = \ZeroBoiler\Domain\Identifiers\UlidIdentifier::fromArray($id->toArray());
        expect($id->equals($restored))->toBeTrue();
    });
});

describe('StringIdentifier', function (): void {
    it('creates from string', function (): void {
        $id = \ZeroBoiler\Domain\Identifiers\StringIdentifier::fromString('order-123');
        expect($id->toString())->toBe('order-123');
    });

    it('compares equality correctly', function (): void {
        $a = \ZeroBoiler\Domain\Identifiers\StringIdentifier::fromString('same');
        $b = \ZeroBoiler\Domain\Identifiers\StringIdentifier::fromString('same');
        $c = \ZeroBoiler\Domain\Identifiers\StringIdentifier::fromString('different');

        expect($a->equals($b))->toBeTrue();
        expect($a->equals($c))->toBeFalse();
    });

    it('round-trips through fromArray/toArray', function (): void {
        $id = \ZeroBoiler\Domain\Identifiers\StringIdentifier::fromString('test-abc');
        $restored = \ZeroBoiler\Domain\Identifiers\StringIdentifier::fromArray($id->toArray());
        expect($id->equals($restored))->toBeTrue();
    });
});

describe('IntegerIdentifier', function (): void {
    it('creates from integer', function (): void {
        $id = \ZeroBoiler\Domain\Identifiers\IntegerIdentifier::fromInt(42);
        expect($id->toString())->toBe('42');
    });

    it('compares equality correctly', function (): void {
        $a = \ZeroBoiler\Domain\Identifiers\IntegerIdentifier::fromInt(42);
        $b = \ZeroBoiler\Domain\Identifiers\IntegerIdentifier::fromInt(42);
        $c = \ZeroBoiler\Domain\Identifiers\IntegerIdentifier::fromInt(99);

        expect($a->equals($b))->toBeTrue();
        expect($a->equals($c))->toBeFalse();
    });

    it('round-trips through fromArray/toArray', function (): void {
        $id = \ZeroBoiler\Domain\Identifiers\IntegerIdentifier::fromInt(42);
        $restored = \ZeroBoiler\Domain\Identifiers\IntegerIdentifier::fromArray($id->toArray());
        expect($id->equals($restored))->toBeTrue();
    });
});
