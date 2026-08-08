<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Domain\AggregateRootId;

describe('AggregateRootId round-trip serialization', function () {
    it('serializes to array with uuid key', function () {
        $id = AggregateRootId::generate();

        $array = $id->toArray();

        expect($array)->toBeArray()
            ->toHaveKey('uuid')
            ->and($array['uuid'])->toBeString()
            ->toBe($id->toString());
    });

    it('round-trips through toArray/fromArray', function () {
        $id = AggregateRootId::generate();

        $restored = AggregateRootId::fromArray($id->toArray());

        expect($restored)->toBeInstanceOf(AggregateRootId::class);
        expect($id->equals($restored))->toBeTrue();
        expect($restored->toString())->toBe($id->toString());
    });

    it('accepts uuid key in fromArray', function () {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $restored = AggregateRootId::fromArray(['uuid' => $uuid]);

        expect($restored->toString())->toBe($uuid);
    });

    it('accepts id key as fallback in fromArray', function () {
        $uuid = '6ba7b810-9dad-11d1-80b4-00c04fd430c8';
        $restored = AggregateRootId::fromArray(['id' => $uuid]);

        expect($restored->toString())->toBe($uuid);
    });

    it('prefers uuid key over id key', function () {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $restored = AggregateRootId::fromArray([
            'uuid' => $uuid,
            'id' => '00000000-0000-0000-0000-000000000000',
        ]);

        expect($restored->toString())->toBe($uuid);
    });

    it('throws on empty array', function () {
        AggregateRootId::fromArray([]);
    })->throws(InvalidArgumentException::class);

    it('throws on invalid key type', function () {
        AggregateRootId::fromArray(['uuid' => 12345]);
    })->throws(InvalidArgumentException::class);

    it('throws on invalid UUID string', function () {
        AggregateRootId::fromArray(['uuid' => 'not-a-uuid']);
    })->throws(\Ramsey\Uuid\Exception\InvalidUuidStringException::class);

    it('toArray output is JSON serializable', function () {
        $id = AggregateRootId::generate();

        $json = json_encode($id->toArray());

        expect($json)->toBeJson();
        expect(json_decode($json, true))->toHaveKey('uuid');
    });

    it('supports multiple round-trips without data loss', function () {
        $original = AggregateRootId::generate();
        $cycle1 = AggregateRootId::fromArray($original->toArray());
        $cycle2 = AggregateRootId::fromArray($cycle1->toArray());
        $cycle3 = AggregateRootId::fromArray($cycle2->toArray());

        expect($original->equals($cycle3))->toBeTrue();
        expect($original->toString())->toBe($cycle3->toString());
    });

    it('works with fromString-created IDs', function () {
        $id = AggregateRootId::fromString('550e8400-e29b-41d4-a716-446655440000');
        $restored = AggregateRootId::fromArray($id->toArray());

        expect($id->equals($restored))->toBeTrue();
    });
});
