<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\Contracts\Identifier;
use ZeroBoiler\Domain\DomainEventCollection;
use ZeroBoiler\Domain\Entity;
use ZeroBoiler\Domain\Identifiers\IntegerIdentifier;
use ZeroBoiler\Domain\Identifiers\StringIdentifier;
use ZeroBoiler\Domain\Identifiers\UuidIdentifier;
use ZeroBoiler\Domain\Tests\Fixtures\TestAggregate;
use ZeroBoiler\Domain\Tests\Fixtures\TestEntity;
use ZeroBoiler\Domain\Tests\Fixtures\TestUuidIdentifier;
use ZeroBoiler\Domain\Tests\Fixtures\TestValueObject;
use ZeroBoiler\Domain\ValueObject;
use ZeroBoiler\Events\Domain\DomainEvent;

describe('Domain Package — Production Contract Verification', function (): void {
    describe('AggregateRootId', function (): void {
        test('generates unique UUID v4 identifiers', function (): void {
            $id1 = AggregateRootId::generate();
            $id2 = AggregateRootId::generate();

            expect($id1->toString())->toBeString()
                ->and($id2->toString())->toBeString()
                ->and($id1->equals($id2))->toBeFalse();
        });

        test('round-trips through fromString/toString', function (): void {
            $uuid = '550e8400-e29b-41d4-a716-446655440000';
            $id = AggregateRootId::fromString($uuid);

            expect($id->toString())->toBe($uuid);
        });

        test('implements Identifier contract', function (): void {
            $id = AggregateRootId::generate();

            expect($id)->toBeInstanceOf(Identifier::class);
        });

        test('serializes to JSON as string', function (): void {
            $id = AggregateRootId::generate();

            $json = json_encode($id);

            expect($json)->toBeJson()
                ->and(json_decode($json, true))->toBe($id->toString());
        });

        test('round-trips through toArray/fromArray', function (): void {
            $id = AggregateRootId::generate();
            $array = $id->toArray();
            $restored = AggregateRootId::fromArray($array);

            expect($id->equals($restored))->toBeTrue();
        });
    });

    describe('Entity identity', function (): void {
        test('string identity equality', function (): void {
            $e1 = new TestEntity('abc');
            $e2 = new TestEntity('abc');
            $e3 = new TestEntity('xyz');

            expect($e1->equals($e2))->toBeTrue()
                ->and($e1->equals($e3))->toBeFalse();
        });

        test('int identity equality', function (): void {
            $e1 = new TestEntity(42);
            $e2 = new TestEntity(42);

            expect($e1->equals($e2))->toBeTrue();
        });

        test('cross-class entities are never equal', function (): void {
            $e1 = new TestEntity('abc');

            // Different concrete class with same ID → false
            $other = new class ('abc') extends Entity {};

            expect($e1->equals($other))->toBeFalse();
        });

        test('toArray includes id and type', function (): void {
            $entity = new TestEntity('123');
            $array = $entity->toArray();

            expect($array)->toHaveKey('id')
                ->and($array)->toHaveKey('type')
                ->and($array['id'])->toBe('123')
                ->and($array['type'])->toBe('TestEntity');
        });

        test('fromArray reconstructs entity', function (): void {
            $entity = new TestEntity('123', 'Test');
            $array = $entity->toArray();
            $restored = TestEntity::fromArray($array);

            expect($restored->equals($entity))->toBeTrue()
                ->and($restored->name)->toBe('Test');
        });

        test('jsonSerialize delegates to toArray', function (): void {
            $entity = new TestEntity('456');
            $json = json_encode($entity);

            expect($json)->toBeJson()
                ->and(json_decode($json, true)['id'])->toBe('456');
        });
    });

    describe('AggregateRoot event lifecycle', function (): void {
        test('records domain events on apply', function (): void {
            $id = AggregateRootId::generate();
            $aggregate = TestAggregate::create($id);

            $events = $aggregate->pullDomainEvents();

            expect($events)->toBeInstanceOf(DomainEventCollection::class)
                ->and($events->count())->toBe(1)
                ->and($events->first()->eventType)->toBe('TestAggregateCreated');
        });

        test('pullDomainEvents clears the buffer', function (): void {
            $id = AggregateRootId::generate();
            $aggregate = TestAggregate::create($id);

            $aggregate->pullDomainEvents();
            $events = $aggregate->pullDomainEvents();

            expect($events->count())->toBe(0);
        });

        test('peekDomainEvents does NOT clear the buffer', function (): void {
            $id = AggregateRootId::generate();
            $aggregate = TestAggregate::create($id);

            $peeked = $aggregate->peekDomainEvents();
            $pulled = $aggregate->pullDomainEvents();

            expect($peeked->count())->toBe(1)
                ->and($pulled->count())->toBe(1);
        });

        test('version increments on each apply', function (): void {
            $id = AggregateRootId::generate();
            $aggregate = TestAggregate::create($id);

            expect($aggregate->version())->toBe(1);

            $aggregate->rename('Updated');
            expect($aggregate->version())->toBe(2);
        });

        test('toArray includes version', function (): void {
            $id = AggregateRootId::generate();
            $aggregate = TestAggregate::create($id);

            $array = $aggregate->toArray();

            expect($array)->toHaveKey('version')
                ->and($array['version'])->toBe(1);
        });
    });

    describe('ValueObject equality', function (): void {
        test('structural equality based on toArray', function (): void {
            $vo1 = TestValueObject::from('hello');
            $vo2 = TestValueObject::from('hello');
            $vo3 = TestValueObject::from('world');

            expect($vo1->equals($vo2))->toBeTrue()
                ->and($vo1->equals($vo3))->toBeFalse();
        });

        test('null is never equal', function (): void {
            $vo = TestValueObject::from('hello');

            expect($vo->equals(null))->toBeFalse();
        });

        test('different concrete classes are never equal', function (): void {
            $vo1 = TestValueObject::from('hello');

            $other = new class ('hello') extends ValueObject implements \Stringable {
                public function __construct(public string $value) {}

                public function toArray(): array
                {
                    return ['value' => $this->value];
                }

                public function __toString(): string
                {
                    return $this->value;
                }
            };

            expect($vo1->equals($other))->toBeFalse();
        });

        test('round-trip fromArray/toArray', function (): void {
            $vo = TestValueObject::from('test');
            $restored = TestValueObject::from($vo->toArray()['value']);

            expect($vo->equals($restored))->toBeTrue();
        });
    });

    describe('DomainEventCollection', function (): void {
        test('filters events by predicate', function (): void {
            $e1 = DomainEvent::occur('order.created', []);
            $e2 = DomainEvent::occur('order.paid', []);
            $e3 = DomainEvent::occur('order.shipped', []);

            $collection = new DomainEventCollection([$e1, $e2, $e3]);
            $paid = $collection->filter(fn (DomainEvent $e): bool => $e->eventType === 'order.paid');

            expect($paid->count())->toBe(1)
                ->and($paid->first()->eventType)->toBe('order.paid');
        });

        test('maps events to transformed values', function (): void {
            $e1 = DomainEvent::occur('order.created', []);
            $e2 = DomainEvent::occur('order.paid', []);

            $collection = new DomainEventCollection([$e1, $e2]);
            $types = $collection->map(fn (DomainEvent $e): string => $e->eventType);

            expect($types)->toBe(['order.created', 'order.paid']);
        });

        test('merges collections', function (): void {
            $c1 = new DomainEventCollection([DomainEvent::occur('a', [])]);
            $c2 = new DomainEventCollection([DomainEvent::occur('b', [])]);

            $merged = $c1->merge($c2);

            expect($merged->count())->toBe(2);
        });

        test('jsonSerialize returns list of arrays', function (): void {
            $event = DomainEvent::occur('test.event', ['key' => 'value']);
            $collection = new DomainEventCollection([$event]);

            $json = json_encode($collection);

            expect($json)->toBeJson();
            $decoded = json_decode($json, true);
            expect($decoded)->toBeArray()
                ->and(count($decoded))->toBe(1)
                ->and($decoded[0]['eventType'])->toBe('test.event');
        });

        test('fromArray round-trips correctly', function (): void {
            $e1 = DomainEvent::occur('order.created', ['id' => '123']);
            $e2 = DomainEvent::occur('order.paid', ['amount' => 99.99]);

            $original = new DomainEventCollection([$e1, $e2]);
            $array = $original->toArray();
            $restored = DomainEventCollection::fromArray($array);

            expect($restored->count())->toBe(2);

            $types = $restored->map(fn (DomainEvent $e): string => $e->eventType);
            expect($types)->toBe(['order.created', 'order.paid']);
        });

        test('get returns event by index', function (): void {
            $e1 = DomainEvent::occur('a', []);
            $e2 = DomainEvent::occur('b', []);

            $collection = new DomainEventCollection([$e1, $e2]);

            expect($collection->get(0)->eventType)->toBe('a')
                ->and($collection->get(1)->eventType)->toBe('b')
                ->and($collection->get(99))->toBeNull();
        });
    });

    describe('UuidIdentifier', function (): void {
        test('generates unique identifiers', function (): void {
            $id1 = TestUuidIdentifier::generate();
            $id2 = TestUuidIdentifier::generate();

            expect($id1->equals($id2))->toBeFalse();
        });

        test('fromString validates UUID format', function (): void {
            $id = TestUuidIdentifier::fromString('550e8400-e29b-41d4-a716-446655440000');

            expect($id->toString())->toBe('550e8400-e29b-41d4-a716-446655440000');
        });

        test('round-trips through toArray/fromArray', function (): void {
            $id = TestUuidIdentifier::generate();
            $restored = TestUuidIdentifier::fromArray($id->toArray());

            expect($id->equals($restored))->toBeTrue();
        });

        test('fromArray accepts id key as fallback', function (): void {
            $uuid = TestUuidIdentifier::generate();
            $restored = TestUuidIdentifier::fromArray(['id' => $uuid->toString()]);

            expect($uuid->equals($restored))->toBeTrue();
        });
    });

    describe('IntegerIdentifier', function (): void {
        test('creates from integer', function (): void {
            $id = IntegerIdentifier::from(42);

            expect($id->toString())->toBe('42')
                ->and($id->toInt())->toBe(42);
        });

        test('fromString parses integer string', function (): void {
            $id = IntegerIdentifier::fromString('99');

            expect($id->toInt())->toBe(99);
        });

        test('round-trips through toArray/fromArray', function (): void {
            $id = IntegerIdentifier::from(7);
            $restored = IntegerIdentifier::fromArray($id->toArray());

            expect($id->equals($restored))->toBeTrue();
        });

        test('fromArray accepts id key as fallback', function (): void {
            $id = IntegerIdentifier::fromArray(['id' => 42]);

            expect($id->toInt())->toBe(42);
        });

        test('jsonSerialize returns integer directly', function (): void {
            $id = IntegerIdentifier::from(42);

            $json = json_encode($id);

            expect($json)->toBe('42');
        });
    });

    describe('StringIdentifier', function (): void {
        test('rejects empty string', function (): void {
            expect(fn (): mixed => StringIdentifier::from(''))
                ->toThrow(ValueError::class);
        });

        test('round-trips through toArray/fromArray', function (): void {
            $id = StringIdentifier::from('my-slug');
            $restored = StringIdentifier::fromArray($id->toArray());

            expect($id->equals($restored))->toBeTrue();
        });

        test('fromArray accepts id key as fallback', function (): void {
            $id = StringIdentifier::fromArray(['id' => 'test']);

            expect($id->toString())->toBe('test');
        });
    });
});
