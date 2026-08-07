<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Tests;

use PHPUnit\Framework\TestCase;
use ZeroBoiler\Domain\Identifiers\IntegerIdentifier;
use ZeroBoiler\Domain\Identifiers\StringIdentifier;
use ZeroBoiler\Domain\Identifiers\UlidIdentifier;
use ZeroBoiler\Domain\Identifiers\UuidIdentifier;

/**
 * Verify strict type-safe equality across all identifier types.
 *
 * Domain invariant: two identifiers of different concrete types must NEVER
 * be equal, even if they hold the same underlying value. This prevents
 * cross-aggregate identity confusion (e.g., OrderId == UserId).
 *
 * @see UuidIdentifier::equals()
 * @see UlidIdentifier::equals()
 * @see StringIdentifier::equals()
 * @see IntegerIdentifier::equals()
 */
final class IdentifierStrictTypeEqualityTest extends TestCase
{
    // ---------------------------------------------------------------
    // UuidIdentifier: cross-type inequality
    // ---------------------------------------------------------------

    public function test_uuid_identifiers_of_different_concrete_types_are_never_equal(): void
    {
        $id = UuidIdentifier::generate();

        $orderId = new class($id->value) extends UuidIdentifier {};
        $productId = new class($id->value) extends UuidIdentifier {};

        // Same value, different concrete types → NOT equal
        $this->assertFalse($orderId->equals($productId));
        $this->assertFalse($productId->equals($orderId));
    }

    public function test_uuid_identifiers_of_same_concrete_type_with_same_value_are_equal(): void
    {
        $value = UuidIdentifier::generate()->value;

        $idType = new class($value) extends UuidIdentifier {};
        $a = new $idType($value);
        $b = new $idType($value);

        $this->assertTrue($a->equals($b));
        $this->assertTrue($b->equals($a));
    }

    public function test_uuid_identifiers_of_same_concrete_type_with_different_values_are_not_equal(): void
    {
        $idType = new class(UuidIdentifier::generate()->value) extends UuidIdentifier {};
        $a = new $idType(UuidIdentifier::generate()->value);
        $b = new $idType(UuidIdentifier::generate()->value);

        $this->assertFalse($a->equals($b));
    }

    // ---------------------------------------------------------------
    // UlidIdentifier: cross-type inequality
    // ---------------------------------------------------------------

    public function test_ulid_identifiers_of_different_concrete_types_are_never_equal(): void
    {
        $id = UlidIdentifier::generate();

        $typeA = new class($id->value) extends UlidIdentifier {};
        $typeB = new class($id->value) extends UlidIdentifier {};

        $a = new $typeA($id->value);
        $b = new $typeB($id->value);

        $this->assertFalse($a->equals($b));
        $this->assertFalse($b->equals($a));
    }

    public function test_ulid_identifiers_of_same_concrete_type_with_same_value_are_equal(): void
    {
        $value = UlidIdentifier::generate()->value;

        $idType = new class($value) extends UlidIdentifier {};
        $a = new $idType($value);
        $b = new $idType($value);

        $this->assertTrue($a->equals($b));
    }

    // ---------------------------------------------------------------
    // StringIdentifier: cross-type inequality
    // ---------------------------------------------------------------

    public function test_string_identifiers_of_different_concrete_types_are_never_equal(): void
    {
        $value = 'shared-slug';

        $slugA = new class($value) extends StringIdentifier {};
        $slugB = new class($value) extends StringIdentifier {};

        $a = new $slugA($value);
        $b = new $slugB($value);

        $this->assertFalse($a->equals($b));
        $this->assertFalse($b->equals($a));
    }

    public function test_string_identifiers_of_same_concrete_type_with_same_value_are_equal(): void
    {
        $idType = new class('test') extends StringIdentifier {};
        $a = new $idType('my-slug');
        $b = new $idType('my-slug');

        $this->assertTrue($a->equals($b));
        $this->assertTrue($b->equals($a));
    }

    public function test_string_identifiers_base_class_instances_are_equal_to_each_other(): void
    {
        // StringIdentifier is NOT abstract and NOT final, so direct usage
        // should still satisfy same-type equality
        $a = StringIdentifier::from('my-value');
        $b = StringIdentifier::from('my-value');

        $this->assertTrue($a->equals($b));
    }

    // ---------------------------------------------------------------
    // IntegerIdentifier: cross-type inequality
    // ---------------------------------------------------------------

    public function test_integer_identifiers_of_same_concrete_type_with_same_value_are_equal(): void
    {
        // IntegerIdentifier is final, so all instances are same type
        $a = IntegerIdentifier::from(42);
        $b = IntegerIdentifier::from(42);

        $this->assertTrue($a->equals($b));
    }

    public function test_integer_identifiers_with_different_values_are_not_equal(): void
    {
        $a = IntegerIdentifier::from(42);
        $b = IntegerIdentifier::from(99);

        $this->assertFalse($a->equals($b));
    }

    // ---------------------------------------------------------------
    // Cross-family inequality (UUID vs ULID vs String vs Integer)
    // ---------------------------------------------------------------

    public function test_uuid_identifier_does_not_equal_ulid_identifier(): void
    {
        $uuid = UuidIdentifier::generate();
        // ULID with a value that would be invalid as UUID — but we're testing
        // the type check, not value format
        $ulid = UlidIdentifier::generate();

        // Even though both implement IdentifierContract, they're different
        // base classes, so instanceof check prevents equality
        $this->assertFalse($uuid->equals($ulid));
        $this->assertFalse($ulid->equals($uuid));
    }

    public function test_string_identifier_does_not_equal_integer_identifier(): void
    {
        $str = StringIdentifier::from('42');
        $int = IntegerIdentifier::from(42);

        // Different base classes → instanceof fails → not equal
        $this->assertFalse($str->equals($int));
        $this->assertFalse($int->equals($str));
    }

    // ---------------------------------------------------------------
    // Reflexivity: identifier always equals itself
    // ---------------------------------------------------------------

    public function test_all_identifiers_are_reflexive(): void
    {
        $uuid = UuidIdentifier::generate();
        $ulid = UlidIdentifier::generate();
        $str = StringIdentifier::from('test');
        $int = IntegerIdentifier::from(1);

        $this->assertTrue($uuid->equals($uuid));
        $this->assertTrue($ulid->equals($ulid));
        $this->assertTrue($str->equals($str));
        $this->assertTrue($int->equals($int));
    }

    // ---------------------------------------------------------------
    // Symmetry: if a.equals(b) then b.equals(a)
    // ---------------------------------------------------------------

    public function test_uuid_identifier_equality_is_symmetric(): void
    {
        $value = UuidIdentifier::generate()->value;
        $idType = new class($value) extends UuidIdentifier {};

        $a = new $idType($value);
        $b = new $idType($value);

        $this->assertEquals($a->equals($b), $b->equals($a));
    }

    // ---------------------------------------------------------------
    // Immutability: equals() does not mutate state
    // ---------------------------------------------------------------

    public function test_equals_does_not_mutate_identifiers(): void
    {
        $uuid = UuidIdentifier::generate();
        $ulid = UlidIdentifier::generate();
        $str = StringIdentifier::from('immutable-test');
        $int = IntegerIdentifier::from(7);

        $uuidBefore = $uuid->toString();
        $ulidBefore = $ulid->toString();
        $strBefore = $str->toString();
        $intBefore = $int->toInt();

        // Call equals with various identifiers — should not change state
        $uuid->equals($ulid);
        $uuid->equals($str);
        $ulid->equals($uuid);
        $str->equals($int);
        $int->equals($str);

        $this->assertSame($uuidBefore, $uuid->toString());
        $this->assertSame($ulidBefore, $ulid->toString());
        $this->assertSame($strBefore, $str->toString());
        $this->assertSame($intBefore, $int->toInt());
    }
}
