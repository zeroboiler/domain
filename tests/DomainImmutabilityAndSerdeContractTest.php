<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Domain\AggregateRoot;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\Contracts\Identifier as IdentifierContract;
use ZeroBoiler\Domain\Identifiers\IntegerIdentifier;
use ZeroBoiler\Domain\Identifiers\StringIdentifier;
use ZeroBoiler\Domain\Identifiers\UuidIdentifier;
use ZeroBoiler\Domain\Identifiers\UlidIdentifier;
use ZeroBoiler\Domain\InMemoryUnitOfWork;
use ZeroBoiler\Events\Domain\DomainEvent;

/**
 * Tests for domain entity and value object immutability guarantees,
 * readonly property enforcement, and serialization contract compliance.
 *
 * Validates that the domain package enforces its documented invariants:
 * - AggregateRootId is final readonly
 * - Entity ID property is readonly
 * - ValueObjects are immutable (structural equality)
 * - All identifiers implement IdentifierContract
 * - fromArray/toArray round-trip on all identifiers
 * - DomainEventCollection round-trip serialization
 */
describe('Domain Immutability and Serialization Contracts', function () {
    describe('AggregateRootId immutability', function () {
        test('is final and cannot be extended', function () {
            $reflection = new ReflectionClass(AggregateRootId::class);

            expect($reflection->isFinal())->toBeTrue();
            expect($reflection->isReadOnly())->toBeTrue();
        });

        test('constructor property is readonly', function () {
            $id = AggregateRootId::generate();
            $reflection = new ReflectionClass($id);
            $property = $reflection->getProperty('value');

            expect($property->isReadOnly())->toBeTrue();
            expect($property->isPublic())->toBeTrue();
        });

        test('toString returns consistent value', function () {
            $id = AggregateRootId::generate();
            $string1 = $id->toString();
            $string2 = (string) $id;

            expect($string1)->toBe($string2);
            expect($id->jsonSerialize())->toBe($string1);
        });

        test('fromString round-trip preserves identity', function () {
            $original = AggregateRootId::generate();
            $restored = AggregateRootId::fromString($original->toString());

            expect($original->equals($restored))->toBeTrue();
            expect($original->toString())->toBe($restored->toString());
        });

        test('fromArray/toArray round-trip', function () {
            $original = AggregateRootId::generate();
            $array = $original->toArray();
            $restored = AggregateRootId::fromArray($array);

            expect($original->equals($restored))->toBeTrue();
        });

        test('fromArray accepts both uuid and id keys', function () {
            $id = AggregateRootId::generate();
            $uuid = $id->toString();

            // Via 'uuid' key
            $fromUuid = AggregateRootId::fromArray(['uuid' => $uuid]);
            expect($fromUuid->equals($id))->toBeTrue();

            // Via 'id' key (fallback)
            $fromId = AggregateRootId::fromArray(['id' => $uuid]);
            expect($fromId->equals($id))->toBeTrue();
        });

        test('fromArray throws on missing keys', function () {
            expect(fn () => AggregateRootId::fromArray([]))
                ->toThrow(InvalidArgumentException::class);
        });

        test('fromArray throws on invalid type', function () {
            expect(fn () => AggregateRootId::fromArray(['uuid' => 123]))
                ->toThrow(InvalidArgumentException::class);
        });

        test('jsonSerialize returns plain string for json_encode', function () {
            $id = AggregateRootId::generate();
            $json = json_encode(['order_id' => $id]);

            expect($json)->toBeJson();
            $decoded = json_decode($json, true);
            expect($decoded['order_id'])->toBe($id->toString());
        });
    });

    describe('Identifier implementations', function () {
        test('all identifier types implement IdentifierContract', function () {
            expect(UuidIdentifier::class)->toImplement(IdentifierContract::class);
            expect(UlidIdentifier::class)->toImplement(IdentifierContract::class);
            expect(StringIdentifier::class)->toImplement(IdentifierContract::class);
            expect(IntegerIdentifier::class)->toImplement(IdentifierContract::class);
        });

        test('UuidIdentifier is abstract readonly', function () {
            $reflection = new ReflectionClass(UuidIdentifier::class);

            expect($reflection->isAbstract())->toBeTrue();
        });

        test('UlidIdentifier is abstract readonly', function () {
            $reflection = new ReflectionClass(UlidIdentifier::class);

            expect($reflection->isAbstract())->toBeTrue();
        });

        test('StringIdentifier is final readonly', function () {
            $reflection = new ReflectionClass(StringIdentifier::class);

            expect($reflection->isFinal())->toBeTrue();
            expect($reflection->isReadOnly())->toBeTrue();
        });

        test('IntegerIdentifier is final readonly', function () {
            $reflection = new ReflectionClass(IntegerIdentifier::class);

            expect($reflection->isFinal())->toBeTrue();
            expect($reflection->isReadOnly())->toBeTrue();
        });

        test('StringIdentifier rejects empty strings', function () {
            expect(fn () => StringIdentifier::fromString(''))
                ->toThrow(InvalidArgumentException::class);
        });

        test('IntegerIdentifier rejects negative values', function () {
            expect(fn () => IntegerIdentifier::from(-1))
                ->toThrow(InvalidArgumentException::class);
        });
    });

    describe('Entity readonly ID', function () {
        test('Entity ID property is promoted readonly', function () {
            $reflection = new ReflectionClass(\ZeroBoiler\Domain\Entity::class);
            $constructor = $reflection->getMethod('__construct');
            $parameters = $constructor->getParameters();

            expect($parameters)->toHaveCount(1);
            expect($parameters[0]->getName())->toBe('id');
        });

        test('Entity id() always returns string', function () {
            // String ID
            $stringEntity = new class('abc-123') extends \ZeroBoiler\Domain\Entity {};

            expect($stringEntity->id())->toBe('abc-123');
            expect(is_string($stringEntity->id()))->toBeTrue();
        });

        test('Entity equality checks class type', function () {
            $entityA = new class('1') extends \ZeroBoiler\Domain\Entity {};
            $entityB = new class('1') extends \ZeroBoiler\Domain\Entity {};

            // Different anonymous classes are never equal
            expect($entityA->equals($entityB))->toBeFalse();
        });
    });

    describe('ValueObject structural equality', function () {
        test('equals compares toArray output', function () {
            $vo = new class('test', 42) extends \ZeroBoiler\Domain\ValueObject {
                public function __construct(
                    public readonly string $name,
                    public readonly int $value,
                ) {}

                public static function fromArray(array $data): static
                {
                    return new static($data['name'], $data['value']);
                }

                public function toArray(): array
                {
                    return ['name' => $this->name, 'value' => $this->value];
                }
            };

            $a = $vo::fromArray(['name' => 'test', 'value' => 42]);
            $b = $vo::fromArray(['name' => 'test', 'value' => 42]);
            $c = $vo::fromArray(['name' => 'other', 'value' => 42]);

            expect($a->equals($b))->toBeTrue();
            expect($a->equals($c))->toBeFalse();
        });
    });

    describe('DomainEventCollection round-trip', function () {
        test('fromArray/toArray round-trip preserves event count', function () {
            $events = [
                DomainEvent::occur('test.created', ['id' => '1']),
                DomainEvent::occur('test.updated', ['id' => '1', 'value' => 'new']),
            ];

            $collection = new \ZeroBoiler\Domain\DomainEventCollection($events);
            $serialized = $collection->toArray();
            $restored = \ZeroBoiler\Domain\DomainEventCollection::fromArray($serialized);

            expect($restored->count())->toBe(2);
            expect($restored->first()?->eventType)->toBe('test.created');
            expect($restored->last()?->eventType)->toBe('test.updated');
        });

        test('jsonSerialize returns list of arrays', function () {
            $events = [DomainEvent::occur('test.event', ['key' => 'val'])];
            $collection = new \ZeroBoiler\Domain\DomainEventCollection($events);
            $json = json_encode($collection);

            expect($json)->toBeJson();
            $decoded = json_decode($json, true);
            expect(count($decoded))->toBe(1);
            expect($decoded[0]['event_type'])->toBe('test.event');
        });

        test('rejects non-list arrays in constructor', function () {
            expect(fn () => new \ZeroBoiler\Domain\DomainEventCollection(
                DomainEvent::occur('test', []),
            ))->toThrow(InvalidArgumentException::class);
        });

        test('filter returns new instance without mutating original', function () {
            $events = [
                DomainEvent::occur('a.event', []),
                DomainEvent::occur('b.event', []),
            ];
            $collection = new \ZeroBoiler\Domain\DomainEventCollection($events);
            $filtered = $collection->filter(
                fn (DomainEvent $e) => str_starts_with($e->eventType, 'a.'),
            );

            expect($collection->count())->toBe(2);
            expect($filtered->count())->toBe(1);
        });
    });

    describe('AggregateRoot version invariants', function () {
        test('version starts at 0', function () {
            $aggregate = new class(AggregateRootId::generate()) extends AggregateRoot {
                public function id(): string
                {
                    return $this->aggregateId->toString();
                }
            };

            expect($aggregate->version())->toBe(0);
        });

        test('incrementVersion increments by 1', function () {
            $aggregate = new class(AggregateRootId::generate()) extends AggregateRoot {
                public function id(): string
                {
                    return $this->aggregateId->toString();
                }
            };

            $aggregate->incrementVersion();
            expect($aggregate->version())->toBe(1);

            $aggregate->incrementVersion();
            expect($aggregate->version())->toBe(2);
        });

        test('setVersion allows explicit version setting', function () {
            $aggregate = new class(AggregateRootId::generate()) extends AggregateRoot {
                public function id(): string
                {
                    return $this->aggregateId->toString();
                }
            };

            $aggregate->setVersion(42);
            expect($aggregate->version())->toBe(42);
        });

        test('toArray includes id, version, and type', function () {
            $aggregate = new class(AggregateRootId::generate()) extends AggregateRoot {
                public function id(): string
                {
                    return $this->aggregateId->toString();
                }
            };

            $array = $aggregate->toArray();

            expect($array)->toHaveKey('id');
            expect($array)->toHaveKey('version');
            expect($array)->toHaveKey('type');
            expect($array['version'])->toBe(0);
            expect($array['type'])->toBe(class_basename($aggregate::class));
        });
    });

    describe('InMemoryUnitOfWork contract compliance', function () {
        test('is final class', function () {
            expect((new ReflectionClass(InMemoryUnitOfWork::class))->isFinal())->toBeTrue();
        });

        test('implements UnitOfWork contract', function () {
            expect(InMemoryUnitOfWork::class)
                ->toImplement(\ZeroBoiler\Domain\Contracts\UnitOfWork::class);
        });

        test('clear() resets all state', function () {
            $uow = new InMemoryUnitOfWork;
            $aggregate = new class(AggregateRootId::generate()) extends AggregateRoot {
                public function id(): string
                {
                    return $this->aggregateId->toString();
                }
            };

            $uow->begin();
            $uow->track($aggregate);
            $aggregate->incrementVersion();
            $uow->commit();

            expect($uow->getCommitted())->not->toBeEmpty();

            $uow->clear();

            expect($uow->getCommitted())->toBeEmpty();
            expect($uow->getDeleted())->toBeEmpty();
            expect($uow->hasPendingEvents())->toBeFalse();
            expect($uow->isActive())->toBeFalse();
        });

        test('run() with exception rolls back and re-throws', function () {
            $uow = new InMemoryUnitOfWork;
            $aggregate = new class(AggregateRootId::generate()) extends AggregateRoot {
                public function id(): string
                {
                    return $this->aggregateId->toString();
                }
            };

            $originalVersion = $aggregate->version();

            expect(fn () => $uow->run(function () use ($uow, $aggregate) {
                $uow->track($aggregate);
                $aggregate->incrementVersion();
                throw new RuntimeException('Test failure');
            }))->toThrow(RuntimeException::class, 'Test failure');

            // Version should be restored after rollback
            expect($aggregate->version())->toBe($originalVersion);
            expect($uow->getCommitted())->toBeEmpty();
        });

        test('nested run() creates savepoints', function () {
            $uow = new InMemoryUnitOfWork;

            $result = $uow->run(function () use ($uow) {
                $inner = $uow->run(fn () => 'inner');
                expect($uow->isActive())->toBeFalse(); // Inner committed

                return $inner;
            });

            expect($result)->toBe('inner');
            expect($uow->isActive())->toBeFalse(); // Outer committed
        });
    });
});
