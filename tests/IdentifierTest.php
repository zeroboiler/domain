<?php

declare(strict_types=1);

use ZeroBoiler\Domain\Identifiers\Identifier;
use ZeroBoiler\Domain\Identifiers\IntegerIdentifier;
use ZeroBoiler\Domain\Identifiers\StringIdentifier;

it('can create identifier with default UUID', function (): void {
    $id = new class extends Identifier {};

    expect($id->value)->toBeInstanceOf(\Ramsey\Uuid\UuidInterface::class);
});

it('can create identifier from string', function (): void {
    $uuid = \Ramsey\Uuid\Uuid::uuid4();
    $id = new class extends Identifier {};

    $id2 = $id::fromString($uuid->toString());

    expect($id2->value->toString())->toBe($uuid->toString());
});

it('has equality based on UUID', function (): void {
    $uuid = \Ramsey\Uuid\Uuid::uuid4();

    $id1 = new class($uuid) extends Identifier {};
    $id2 = new class($uuid) extends Identifier {};

    expect($id1->equals($id2))->toBeTrue();
});

it('converts to string', function (): void {
    $uuid = \Ramsey\Uuid\Uuid::uuid4();
    $id = new class($uuid) extends Identifier {};

    expect((string) $id)->toBe($uuid->toString());
});

it('IntegerIdentifier creates from int', function (): void {
    $id = IntegerIdentifier::from(42);

    expect($id->toInt())->toBe(42);
});

it('IntegerIdentifier has equality', function (): void {
    $id1 = IntegerIdentifier::from(42);
    $id2 = IntegerIdentifier::from(42);
    $id3 = IntegerIdentifier::from(43);

    expect($id1->equals($id2))->toBeTrue();
    expect($id1->equals($id3))->toBeFalse();
});

it('IntegerIdentifier converts to string', function (): void {
    $id = IntegerIdentifier::from(42);

    expect((string) $id)->toBe('42');
});

it('StringIdentifier creates from string', function (): void {
    $id = StringIdentifier::from('test-id');

    expect($id->toString())->toBe('test-id');
});

it('StringIdentifier has equality', function (): void {
    $id1 = StringIdentifier::from('test-id');
    $id2 = StringIdentifier::from('test-id');
    $id3 = StringIdentifier::from('other-id');

    expect($id1->equals($id2))->toBeTrue();
    expect($id1->equals($id3))->toBeFalse();
});

it('StringIdentifier converts to string', function (): void {
    $id = StringIdentifier::from('test-id');

    expect((string) $id)->toBe('test-id');
});