<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Tests;

use ZeroBoiler\Domain\Identifiers\IntegerIdentifier;
use ZeroBoiler\Domain\Identifiers\StringIdentifier;
use ZeroBoiler\Domain\Identifiers\UlidIdentifier;
use ZeroBoiler\Domain\Identifiers\UuidIdentifier;

/**
 * Tests for identifier toArray()/fromArray() round-trip serialization.
 *
 * Verifies that all identifier types support full serialization round-trips
 * for caching, persistence, and cross-package data exchange.
 *
 * Run with: vendor/bin/pest tests/IdentifierRoundTripTest.php
 */
final class IdentifierRoundTripTest
{
    // ── UuidIdentifier ──────────────────────────────────────────────

    public function test_uuid_identifier_to_array_returns_uuid_key(): void
    {
        $id = TestUuidId::generate();

        $array = $id->toArray();

        expect($array)->toHaveKey('uuid')
            ->and($array['uuid'])->toBe($id->toString());
    }

    public function test_uuid_identifier_from_array_with_uuid_key(): void
    {
        $original = TestUuidId::generate();
        $restored = TestUuidId::fromArray(['uuid' => $original->toString()]);

        expect($restored)->toBeInstanceOf(TestUuidId::class)
            ->and($original->equals($restored))->toBeTrue();
    }

    public function test_uuid_identifier_from_array_with_id_key(): void
    {
        $original = TestUuidId::generate();
        $restored = TestUuidId::fromArray(['id' => $original->toString()]);

        expect($original->equals($restored))->toBeTrue();
    }

    public function test_uuid_identifier_full_round_trip(): void
    {
        $original = TestUuidId::generate();
        $restored = TestUuidId::fromArray($original->toArray());

        expect($original->equals($restored))->toBeTrue()
            ->and($original->toString())->toBe($restored->toString());
    }

    public function test_uuid_identifier_from_array_throws_on_missing_key(): void
    {
        expect(fn () => TestUuidId::fromArray(['foo' => 'bar']))
            ->toThrow(\InvalidArgumentException::class);
    }

    public function test_uuid_identifier_from_array_throws_on_invalid_type(): void
    {
        expect(fn () => TestUuidId::fromArray(['uuid' => 123]))
            ->toThrow(\InvalidArgumentException::class);
    }

    public function test_uuid_identifier_to_array_json_compatibility(): void
    {
        $id = TestUuidId::generate();
        $json = json_encode($id->toArray());

        expect($json)->toBeJson();
        $decoded = json_decode($json, true);
        $restored = TestUuidId::fromArray($decoded);

        expect($id->equals($restored))->toBeTrue();
    }

    // ── UlidIdentifier ──────────────────────────────────────────────

    public function test_ulid_identifier_to_array_returns_ulid_key(): void
    {
        $id = TestUlidId::generate();

        $array = $id->toArray();

        expect($array)->toHaveKey('ulid')
            ->and($array['ulid'])->toBe($id->toString());
    }

    public function test_ulid_identifier_from_array_with_ulid_key(): void
    {
        $original = TestUlidId::generate();
        $restored = TestUlidId::fromArray(['ulid' => $original->toString()]);

        expect($restored)->toBeInstanceOf(TestUlidId::class)
            ->and($original->equals($restored))->toBeTrue();
    }

    public function test_ulid_identifier_from_array_with_id_key(): void
    {
        $original = TestUlidId::generate();
        $restored = TestUlidId::fromArray(['id' => $original->toString()]);

        expect($original->equals($restored))->toBeTrue();
    }

    public function test_ulid_identifier_full_round_trip(): void
    {
        $original = TestUlidId::generate();
        $restored = TestUlidId::fromArray($original->toArray());

        expect($original->equals($restored))->toBeTrue()
            ->and($original->toString())->toBe($restored->toString());
    }

    public function test_ulid_identifier_from_array_throws_on_missing_key(): void
    {
        expect(fn () => TestUlidId::fromArray(['foo' => 'bar']))
            ->toThrow(\InvalidArgumentException::class);
    }

    public function test_ulid_identifier_to_array_json_compatibility(): void
    {
        $id = TestUlidId::generate();
        $json = json_encode($id->toArray());

        expect($json)->toBeJson();
        $decoded = json_decode($json, true);
        $restored = TestUlidId::fromArray($decoded);

        expect($id->equals($restored))->toBeTrue();
    }

    // ── StringIdentifier ──────────────────────────────────────────────

    public function test_string_identifier_to_array_returns_string_key(): void
    {
        $slug = StringIdentifier::from('my-blog-post');

        expect($slug->toArray())->toBe(['string' => 'my-blog-post']);
    }

    public function test_string_identifier_from_array_with_string_key(): void
    {
        $original = StringIdentifier::from('my-blog-post');
        $restored = StringIdentifier::fromArray(['string' => 'my-blog-post']);

        expect($original->equals($restored))->toBeTrue();
    }

    public function test_string_identifier_from_array_with_id_key(): void
    {
        $original = StringIdentifier::from('my-blog-post');
        $restored = StringIdentifier::fromArray(['id' => 'my-blog-post']);

        expect($original->equals($restored))->toBeTrue();
    }

    public function test_string_identifier_full_round_trip(): void
    {
        $original = StringIdentifier::from('my-blog-post');
        $restored = StringIdentifier::fromArray($original->toArray());

        expect($original->equals($restored))->toBeTrue()
            ->and($original->toString())->toBe($restored->toString());
    }

    public function test_string_identifier_from_array_throws_on_missing_key(): void
    {
        expect(fn () => StringIdentifier::fromArray(['foo' => 'bar']))
            ->toThrow(\InvalidArgumentException::class);
    }

    public function test_string_identifier_from_array_throws_on_empty_string(): void
    {
        expect(fn () => StringIdentifier::fromArray(['string' => '']))
            ->toThrow(\ValueError::class);
    }

    public function test_string_identifier_to_array_json_compatibility(): void
    {
        $slug = StringIdentifier::from('my-blog-post');
        $json = json_encode($slug->toArray());

        expect($json)->toBeJson();
        $decoded = json_decode($json, true);
        $restored = StringIdentifier::fromArray($decoded);

        expect($slug->equals($restored))->toBeTrue();
    }

    // ── IntegerIdentifier ──────────────────────────────────────────────

    public function test_integer_identifier_to_array_returns_integer_key(): void
    {
        $id = IntegerIdentifier::from(42);

        expect($id->toArray())->toBe(['integer' => 42]);
    }

    public function test_integer_identifier_from_array_with_integer_key(): void
    {
        $original = IntegerIdentifier::from(42);
        $restored = IntegerIdentifier::fromArray(['integer' => 42]);

        expect($original->equals($restored))->toBeTrue();
    }

    public function test_integer_identifier_from_array_with_int_id_key(): void
    {
        $original = IntegerIdentifier::from(42);
        $restored = IntegerIdentifier::fromArray(['id' => 42]);

        expect($original->equals($restored))->toBeTrue();
    }

    public function test_integer_identifier_from_array_with_string_id_key(): void
    {
        $original = IntegerIdentifier::from(42);
        $restored = IntegerIdentifier::fromArray(['id' => '42']);

        expect($original->equals($restored))->toBeTrue();
    }

    public function test_integer_identifier_full_round_trip(): void
    {
        $original = IntegerIdentifier::from(42);
        $restored = IntegerIdentifier::fromArray($original->toArray());

        expect($original->equals($restored))->toBeTrue()
            ->and($original->toInt())->toBe($restored->toInt());
    }

    public function test_integer_identifier_from_array_throws_on_missing_key(): void
    {
        expect(fn () => IntegerIdentifier::fromArray(['foo' => 'bar']))
            ->toThrow(\InvalidArgumentException::class);
    }

    public function test_integer_identifier_from_array_throws_on_invalid_type(): void
    {
        expect(fn () => IntegerIdentifier::fromArray(['integer' => 'not-a-number']))
            ->toThrow(\InvalidArgumentException::class);
    }

    public function test_integer_identifier_from_array_accepts_negative(): void
    {
        $id = IntegerIdentifier::fromArray(['integer' => -5]);

        expect($id->toInt())->toBe(-5);
    }

    public function test_integer_identifier_to_array_json_compatibility(): void
    {
        $id = IntegerIdentifier::from(42);
        $json = json_encode($id->toArray());

        expect($json)->toBeJson();
        $decoded = json_decode($json, true);
        $restored = IntegerIdentifier::fromArray($decoded);

        expect($id->equals($restored))->toBeTrue();
    }

    // ── Cross-identifier type safety ──────────────────────────────────

    public function test_different_identifier_types_never_equal(): void
    {
        $uuid = TestUuidId::generate();
        $ulid = TestUlidId::generate();
        $string = StringIdentifier::from('slug');
        $integer = IntegerIdentifier::from(42);

        expect($uuid->equals($ulid))->toBeFalse()
            ->and($uuid->equals($string))->toBeFalse()
            ->and($uuid->equals($integer))->toBeFalse()
            ->and($ulid->equals($string))->toBeFalse()
            ->and($ulid->equals($integer))->toBeFalse()
            ->and($string->equals($integer))->toBeFalse();
    }

    public function test_concrete_class_equality_not_just_value(): void
    {
        $orderId = TestUuidId::generate();
        $productId = TestProductId::fromString($orderId->toString());

        // Same UUID value, different concrete classes — NOT equal
        expect($orderId->equals($productId))->toBeFalse();
    }
}

// ── Test concrete subclasses ──────────────────────────────────────────

/** @internal Test-only UUID identifier */
class TestUuidId extends UuidIdentifier {}

/** @internal Test-only ULID identifier */
class TestUlidId extends UlidIdentifier {}

/** @internal Test-only second UUID identifier for cross-class equality test */
class TestProductId extends UuidIdentifier {}
