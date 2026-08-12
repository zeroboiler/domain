<?php

declare(strict_types=1);

/**
 * Tests for AggregateRootId value object.
 *
 * @covers \ZeroBoiler\Domain\AggregateRootId
 */
describe('AggregateRootId', function (): void {
    it('generates a new UUID-based identifier', function (): void {
        $id = \ZeroBoiler\Domain\AggregateRootId::generate();
        expect($id)->toBeInstanceOf(\ZeroBoiler\Domain\AggregateRootId::class);
        expect($id->toString())->toBeString()->toBeNotEmpty();
    });

    it('creates an identifier from a string', function (): void {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $id = \ZeroBoiler\Domain\AggregateRootId::fromString($uuid);
        expect($id->toString())->toBe($uuid);
    });

    it('is equal when the same UUID', function (): void {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $a = \ZeroBoiler\Domain\AggregateRootId::fromString($uuid);
        $b = \ZeroBoiler\Domain\AggregateRootId::fromString($uuid);
        expect($a->equals($b))->toBeTrue();
    });

    it('is not equal when different UUIDs', function (): void {
        $a = \ZeroBoiler\Domain\AggregateRootId::fromString('550e8400-e29b-41d4-a716-446655440000');
        $b = \ZeroBoiler\Domain\AggregateRootId::fromString('660e8400-e29b-41d4-a716-446655440001');
        expect($a->equals($b))->toBeFalse();
    });

    it('serializes to array', function (): void {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $id = \ZeroBoiler\Domain\AggregateRootId::fromString($uuid);
        $array = $id->toArray();
        expect($array)->toHaveKey('value');
        expect($array['value'])->toBe($uuid);
    });

    it('round-trips through fromArray/toArray', function (): void {
        $id = \ZeroBoiler\Domain\AggregateRootId::generate();
        $restored = \ZeroBoiler\Domain\AggregateRootId::fromArray($id->toArray());
        expect($id->equals($restored))->toBeTrue();
    });

    it('implements Stringable via __toString', function (): void {
        $id = \ZeroBoiler\Domain\AggregateRootId::generate();
        expect((string) $id)->toBe($id->toString());
    });

    it('implements JsonSerializable', function (): void {
        $id = \ZeroBoiler\Domain\AggregateRootId::generate();
        $json = json_encode($id);
        expect($json)->toBeString()->toBeJson();
        $decoded = json_decode($json, true);
        expect($decoded['value'])->toBe($id->toString());
    });
});
