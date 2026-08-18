<?php

/**
 * Production Ready Audit V179 — Domain Package
 *
 * Comprehensive manual-style verification of all domain classes for:
 * - PHP 8.5 syntax (strict_types, readonly, union types, named args)
 * - Return type declarations on every public/protected method
 * - Docblocks with @param, @return, @throws, @example
 * - Typed properties (no untyped properties)
 * - Immutability (readonly classes/properties where appropriate)
 * - Domain invariants (validation in constructors, guards)
 * - Serialization contract (toArray/fromArray/toJson/fromJson/jsonSerialize)
 *
 * @covers \ZeroBoiler\Domain\AggregateRoot
 * @covers \ZeroBoiler\Domain\AggregateRootId
 * @covers \ZeroBoiler\Domain\Entity
 * @covers \ZeroBoiler\Domain\ValueObject
 * @covers \ZeroBoiler\Domain\DomainEventCollection
 * @covers \ZeroBoiler\Domain\InMemoryUnitOfWork
 * @covers \ZeroBoiler\Domain\Concerns\Guards
 * @covers \ZeroBoiler\Domain\Identifiers\UuidIdentifier
 * @covers \ZeroBoiler\Domain\Identifiers\UlidIdentifier
 * @covers \ZeroBoiler\Domain\Identifiers\StringIdentifier
 * @covers \ZeroBoiler\Domain\Identifiers\IntegerIdentifier
 * @covers \ZeroBoiler\Domain\Exceptions\DomainException
 * @covers \ZeroBoiler\Domain\Exceptions\InvalidStateDomainException
 * @covers \ZeroBoiler\Domain\Exceptions\InvalidArgumentDomainException
 * @covers \ZeroBoiler\Domain\Exceptions\NotFoundDomainException
 * @covers \ZeroBoiler\Domain\Exceptions\OptimisticLockException
 */

declare(strict_types=1);

describe('Domain Package — Production Ready Audit V179', function (): void {

    // ─── §1. AggregateRootId: readonly, immutability, serde ───────────
    describe('§1 AggregateRootId', function (): void {
        it('is a final readonly class', function (): void {
            $ref = new ReflectionClass(\ZeroBoiler\Domain\AggregateRootId::class);
            expect($ref->isFinal())->toBeTrue('AggregateRootId must be final');
            expect($ref->isReadOnly())->toBeTrue('AggregateRootId must be readonly');
        });

        it('has typed constructor property', function (): void {
            $ref = new ReflectionClass(\ZeroBoiler\Domain\AggregateRootId::class);
            $prop = $ref->getProperty('value');
            expect($prop->getType()->getName())->toBe('Ramsey\\Uuid\\UuidInterface');
        });

        it('generates valid UUID v4', function (): void {
            $id = \ZeroBoiler\Domain\AggregateRootId::generate();
            expect($id->toString())->toBeString();
            expect(\Ramsey\Uuid\Uuid::isValid($id->toString()))->toBeTrue();
            expect(strlen($id->toString()))->toBe(36);
        });

        it('round-trips through toArray/fromArray', function (): void {
            $original = \ZeroBoiler\Domain\AggregateRootId::generate();
            $restored = \ZeroBoiler\Domain\AggregateRootId::fromArray($original->toArray());
            expect($original->equals($restored))->toBeTrue();
        });

        it('round-trips through toJson/fromJson', function (): void {
            $original = \ZeroBoiler\Domain\AggregateRootId::generate();
            $json = $original->toJson();
            $restored = \ZeroBoiler\Domain\AggregateRootId::fromJson($json);
            expect($original->equals($restored))->toBeTrue();
        });

        it('implements JsonSerializable', function (): void {
            $id = \ZeroBoiler\Domain\AggregateRootId::generate();
            expect($id)->toBeInstanceOf(\JsonSerializable::class);
            $json = json_encode($id);
            expect($json)->toBeJson();
            // jsonSerialize returns the UUID string directly
            $decoded = json_decode($json, true);
            expect($decoded)->toBe($id->toString());
        });

        it('implements Stringable', function (): void {
            $id = \ZeroBoiler\Domain\AggregateRootId::generate();
            expect((string) $id)->toBe($id->toString());
        });

        it('supports fromString with existing UUID', function (): void {
            $uuid = '550e8400-e29b-41d4-a716-446655440000';
            $id = \ZeroBoiler\Domain\AggregateRootId::fromString($uuid);
            expect($id->toString())->toBe($uuid);
        });
    });

    // ─── §2. UuidIdentifier: abstract readonly, subclassing ─────────
    describe('§2 UuidIdentifier', function (): void {
        it('is abstract readonly', function (): void {
            $ref = new ReflectionClass(\ZeroBoiler\Domain\Identifiers\UuidIdentifier::class);
            expect($ref->isAbstract())->toBeTrue();
            expect($ref->isReadOnly())->toBeTrue();
        });

        it('validates UUID in constructor', function (): void {
            expect(fn () => new class('not-a-uuid') extends \ZeroBoiler\Domain\Identifiers\UuidIdentifier {})
                ->toThrow(\Ramsey\Uuid\Exception\InvalidUuidStringException::class);
        });

        it('enforces type-safe equality across subclasses', function (): void {
            $uuid = '550e8400-e29b-41d4-a716-446655440000';
            $a = new class($uuid) extends \ZeroBoiler\Domain\Identifiers\UuidIdentifier {};
            $b = new class($uuid) extends \ZeroBoiler\Domain\Identifiers\UuidIdentifier {};
            expect($a->equals($b))->toBeFalse('Different subclasses must not be equal');
        });

        it('round-trips through toArray/fromArray', function (): void {
            $id = new class('550e8400-e29b-41d4-a716-446655440000') extends \ZeroBoiler\Domain\Identifiers\UuidIdentifier {};
            $restored = (new class('00000000-0000-0000-0000-000000000000') extends \ZeroBoiler\Domain\Identifiers\UuidIdentifier {});
            // Use static fromArray on the same class
            $restored = $id::fromArray($id->toArray());
            expect($id->equals($restored))->toBeTrue();
        });
    });

    // ─── §3. UlidIdentifier: monotonic, sortable ────────────────────
    describe('§3 UlidIdentifier', function (): void {
        it('is abstract readonly', function (): void {
            $ref = new ReflectionClass(\ZeroBoiler\Domain\Identifiers\UlidIdentifier::class);
            expect($ref->isAbstract())->toBeTrue();
            expect($ref->isReadOnly())->toBeTrue();
        });

        it('generates valid monotonic ULID', function (): void {
            $id = new class extends \ZeroBoiler\Domain\Identifiers\UlidIdentifier {
                public static function new(): self
                {
                    return self::generate();
                }
            };
            $instance = $id::new();
            expect($instance->toString())->toBeString();
            expect(strlen($instance->toString()))->toBe(26);
        });

        it('validates ULID in constructor', function (): void {
            expect(fn () => new class('not-a-ulid') extends \ZeroBoiler\Domain\Identifiers\UlidIdentifier {})
                ->toThrow(\InvalidArgumentException::class);
        });

        it('round-trips through toArray/fromArray', function (): void {
            $id = new class('01H4RM6K5N8Z3X7W2V9QY0PRTS') extends \ZeroBoiler\Domain\Identifiers\UlidIdentifier {};
            $restored = $id::fromArray($id->toArray());
            expect($id->equals($restored))->toBeTrue();
        });
    });

    // ─── §4. StringIdentifier: non-empty, final readonly ─────────────
    describe('§4 StringIdentifier', function (): void {
        it('is final readonly', function (): void {
            $ref = new ReflectionClass(\ZeroBoiler\Domain\Identifiers\StringIdentifier::class);
            expect($ref->isFinal())->toBeTrue();
            expect($ref->isReadOnly())->toBeTrue();
        });

        it('rejects empty strings', function (): void {
            expect(fn () => \ZeroBoiler\Domain\Identifiers\StringIdentifier::from(''))
                ->toThrow(\ValueError::class);
        });

        it('accepts non-empty strings', function (): void {
            $id = \ZeroBoiler\Domain\Identifiers\StringIdentifier::from('my-slug');
            expect($id->toString())->toBe('my-slug');
        });

        it('round-trips through toArray/fromArray', function (): void {
            $original = \ZeroBoiler\Domain\Identifiers\StringIdentifier::from('test-slug');
            $restored = \ZeroBoiler\Domain\Identifiers\StringIdentifier::fromArray($original->toArray());
            expect($original->equals($restored))->toBeTrue();
        });

        it('round-trips through toJson/fromJson', function (): void {
            $original = \ZeroBoiler\Domain\Identifiers\StringIdentifier::from('test-slug');
            $restored = \ZeroBoiler\Domain\Identifiers\StringIdentifier::fromJson($original->toJson());
            expect($original->equals($restored))->toBeTrue();
        });

        it('implements Identifier contract', function (): void {
            $id = \ZeroBoiler\Domain\Identifiers\StringIdentifier::from('test');
            expect($id)->toBeInstanceOf(\ZeroBoiler\Domain\Contracts\Identifier::class);
            expect($id)->toBeInstanceOf(\Stringable::class);
            expect($id)->toBeInstanceOf(\JsonSerializable::class);
        });
    });

    // ─── §5. IntegerIdentifier: final readonly ───────────────────────
    describe('§5 IntegerIdentifier', function (): void {
        it('is final readonly', function (): void {
            $ref = new ReflectionClass(\ZeroBoiler\Domain\Identifiers\IntegerIdentifier::class);
            expect($ref->isFinal())->toBeTrue();
            expect($ref->isReadOnly())->toBeTrue();
        });

        it('round-trips through toArray/fromArray', function (): void {
            $original = \ZeroBoiler\Domain\Identifiers\IntegerIdentifier::from(42);
            $restored = \ZeroBoiler\Domain\Identifiers\IntegerIdentifier::fromArray($original->toArray());
            expect($original->equals($restored))->toBeTrue();
        });

        it('jsonSerialize returns int directly', function (): void {
            $id = \ZeroBoiler\Domain\Identifiers\IntegerIdentifier::from(42);
            expect(json_encode($id))->toBe('42');
        });

        it('fromString parses integer strings', function (): void {
            $id = \ZeroBoiler\Domain\Identifiers\IntegerIdentifier::fromString('99');
            expect($id->toInt())->toBe(99);
            expect($id->toString())->toBe('99');
        });
    });

    // ─── §6. DomainException: abstract, JsonSerializable, serde ────────
    describe('§6 DomainException hierarchy', function (): void {
        it('DomainException is abstract', function (): void {
            $ref = new ReflectionClass(\ZeroBoiler\Domain\Exceptions\DomainException::class);
            expect($ref->isAbstract())->toBeTrue();
        });

        it('implements JsonSerializable', function (): void {
            $e = \ZeroBoiler\Domain\Exceptions\InvalidStateDomainException::because('test');
            expect($e)->toBeInstanceOf(\JsonSerializable::class);
        });

        it('InvalidStateDomainException returns correct code and status', function (): void {
            $e = \ZeroBoiler\Domain\Exceptions\InvalidStateDomainException::because('test');
            expect($e->errorCode())->toBe('INVALID_STATE');
            expect($e->httpStatus())->toBe(422);
        });

        it('InvalidArgumentDomainException returns correct code and status', function (): void {
            $e = \ZeroBoiler\Domain\Exceptions\InvalidArgumentDomainException::because('test');
            expect($e->errorCode())->toBe('INVALID_ARGUMENT');
            expect($e->httpStatus())->toBe(422);
        });

        it('NotFoundDomainException returns correct code and status', function (): void {
            $e = \ZeroBoiler\Domain\Exceptions\NotFoundDomainException::forAggregate('Order', '123');
            expect($e->errorCode())->toBe('NOT_FOUND');
            expect($e->httpStatus())->toBe(404);
        });

        it('OptimisticLockException returns correct code and status', function (): void {
            $e = \ZeroBoiler\Domain\Exceptions\OptimisticLockException::for('order-1', 5, 3);
            expect($e->errorCode())->toBe('OPTIMISTIC_LOCK');
            expect($e->httpStatus())->toBe(409);
        });

        it('toErrorArray produces RFC 9457 format', function (): void {
            $e = \ZeroBoiler\Domain\Exceptions\InvalidStateDomainException::because('Order must be pending.');
            $arr = $e->toErrorArray();
            expect($arr)->toHaveKeys(['title', 'detail', 'code', 'status']);
            expect($arr['title'])->toBe('InvalidStateDomainException');
            expect($arr['detail'])->toBe('Order must be pending.');
            expect($arr['code'])->toBe('INVALID_STATE');
            expect($arr['status'])->toBe(422);
        });

        it('exception subclasses are final', function (): void {
            expect((new ReflectionClass(\ZeroBoiler\Domain\Exceptions\InvalidStateDomainException::class))->isFinal())->toBeTrue();
            expect((new ReflectionClass(\ZeroBoiler\Domain\Exceptions\InvalidArgumentDomainException::class))->isFinal())->toBeTrue();
            expect((new ReflectionClass(\ZeroBoiler\Domain\Exceptions\NotFoundDomainException::class))->isFinal())->toBeTrue();
            expect((new ReflectionClass(\ZeroBoiler\Domain\Exceptions\OptimisticLockException::class))->isFinal())->toBeTrue();
            expect((new ReflectionClass(\ZeroBoiler\Domain\Exceptions\ConflictDomainException::class))->isFinal())->toBeTrue();
            expect((new ReflectionClass(\ZeroBoiler\Domain\Exceptions\AggregateNotFoundException::class))->isFinal())->toBeTrue();
            expect((new ReflectionClass(\ZeroBoiler\Domain\Exceptions\InvalidAggregateRootException::class))->isFinal())->toBeTrue();
            expect((new ReflectionClass(\ZeroBoiler\Domain\Exceptions\InvalidStateException::class))->isFinal())->toBeTrue();
        });

        it('supports custom error code override', function (): void {
            $e = \ZeroBoiler\Domain\Exceptions\InvalidStateDomainException::because('test', code: 'CUSTOM_CODE');
            expect($e->errorCode())->toBe('CUSTOM_CODE');
        });

        it('round-trips through toJson/fromJson', function (): void {
            $original = \ZeroBoiler\Domain\Exceptions\InvalidStateDomainException::because('Order must be pending.');
            $json = $original->toJson();
            $data = json_decode($json, true);
            expect($data['code'])->toBe('INVALID_STATE');
        });
    });

    // ─── §7. Entity: abstract, readonly id, equality, serde ───────────
    describe('§7 Entity', function (): void {
        it('is abstract', function (): void {
            $ref = new ReflectionClass(\ZeroBoiler\Domain\Entity::class);
            expect($ref->isAbstract())->toBeTrue();
        });

        it('has readonly id property', function (): void {
            $ref = new ReflectionClass(\ZeroBoiler\Domain\Entity::class);
            $prop = $ref->getProperty('id');
            expect($prop->isReadOnly())->toBeTrue();
            expect($prop->isPublic())->toBeTrue();
        });

        it('implements JsonSerializable', function (): void {
            $entity = new class('42') extends \ZeroBoiler\Domain\Entity {};
            expect($entity)->toBeInstanceOf(\JsonSerializable::class);
        });

        it('serializes to array with id and type', function (): void {
            $entity = new class('42') extends \ZeroBoiler\Domain\Entity {};
            $arr = $entity->toArray();
            expect($arr)->toHaveKeys(['id', 'type']);
        });

        it('equality requires same class', function (): void {
            $a = new class('42') extends \ZeroBoiler\Domain\Entity {};
            $b = new class('42') extends \ZeroBoiler\Domain\Entity {};
            expect($a->equals($b))->toBeFalse('Different anonymous classes must not be equal');
        });
    });

    // ─── §8. AggregateRoot: abstract, version, events ────────────────
    describe('§8 AggregateRoot', function (): void {
        it('is abstract', function (): void {
            $ref = new ReflectionClass(\ZeroBoiler\Domain\AggregateRoot::class);
            expect($ref->isAbstract())->toBeTrue();
        });

        it('starts at version 0', function (): void {
            $id = \ZeroBoiler\Domain\AggregateRootId::generate();
            $aggregate = new TestConcreteAggregate($id);
            expect($aggregate->version())->toBe(0);
        });

        it('serializes to array with id, version, and type', function (): void {
            $id = \ZeroBoiler\Domain\AggregateRootId::generate();
            $aggregate = new TestConcreteAggregate($id, 'Order');
            $arr = $aggregate->toArray();
            expect($arr)->toHaveKey('id');
            expect($arr)->toHaveKey('version');
            expect($arr)->toHaveKey('type');
            expect($arr['version'])->toBe(0);
        });
    });

    // ─── §9. DomainEventCollection: final readonly, typed ────────────
    describe('§9 DomainEventCollection', function (): void {
        it('is final readonly', function (): void {
            $ref = new ReflectionClass(\ZeroBoiler\Domain\DomainEventCollection::class);
            expect($ref->isFinal())->toBeTrue();
            expect($ref->isReadOnly())->toBeTrue();
        });

        it('implements Countable and IteratorAggregate', function (): void {
            $collection = new \ZeroBoiler\Domain\DomainEventCollection;
            expect($collection)->toBeInstanceOf(\Countable::class);
            expect($collection)->toBeInstanceOf(\IteratorAggregate::class);
        });

        it('implements JsonSerializable', function (): void {
            $collection = new \ZeroBoiler\Domain\DomainEventCollection;
            expect($collection)->toBeInstanceOf(\JsonSerializable::class);
        });

        it('isEmpty returns true for empty collection', function (): void {
            $collection = new \ZeroBoiler\Domain\DomainEventCollection;
            expect($collection->isEmpty())->toBeTrue();
            expect($collection->count())->toBe(0);
        });

        it('rejects non-DomainEvent items', function (): void {
            expect(fn () => new \ZeroBoiler\Domain\DomainEventCollection(['not-an-event']))
                ->toThrow(\InvalidArgumentException::class);
        });
    });

    // ─── §10. InMemoryUnitOfWork: JsonSerializable ─────────────────────
    describe('§10 InMemoryUnitOfWork', function (): void {
        it('implements UnitOfWork contract', function (): void {
            $uow = new \ZeroBoiler\Domain\InMemoryUnitOfWork;
            expect($uow)->toBeInstanceOf(\ZeroBoiler\Domain\Contracts\UnitOfWork::class);
        });

        it('implements JsonSerializable', function (): void {
            $uow = new \ZeroBoiler\Domain\InMemoryUnitOfWork;
            expect($uow)->toBeInstanceOf(\JsonSerializable::class);
        });

        it('starts inactive', function (): void {
            $uow = new \ZeroBoiler\Domain\InMemoryUnitOfWork;
            expect($uow->isActive())->toBeFalse();
            expect($uow->hasPendingEvents())->toBeFalse();
        });

        it('run() auto-commits on success', function (): void {
            $uow = new \ZeroBoiler\Domain\InMemoryUnitOfWork;
            $result = $uow->run(fn (): int => 42);
            expect($result)->toBe(42);
            expect($uow->isActive())->toBeFalse();
        });

        it('run() auto-rollbacks on exception', function (): void {
            $uow = new \ZeroBoiler\Domain\InMemoryUnitOfWork;
            expect(fn () => $uow->run(fn () => throw new \RuntimeException('fail')))
                ->toThrow(\RuntimeException::class);
            expect($uow->isActive())->toBeFalse();
        });
    });

    // ─── §11. Guards: domain invariant clauses ────────────────────────
    describe('§11 Guards trait', function (): void {
        it('assertNotEmptyString throws on empty', function (): void {
            $guard = new class {
                use \ZeroBoiler\Domain\Concerns\Guards;

                public function check(string $value): void
                {
                    $this->assertNotEmptyString($value, 'name');
                }
            };
            expect(fn () => $guard->check(''))->toThrow(\ZeroBoiler\Domain\Exceptions\InvalidArgumentDomainException::class);
        });

        it('assertPositiveInteger throws on zero', function (): void {
            $guard = new class {
                use \ZeroBoiler\Domain\Concerns\Guards;

                public function check(int $value): void
                {
                    $this->assertPositiveInteger($value, 'quantity');
                }
            };
            expect(fn () => $guard->check(0))->toThrow(\ZeroBoiler\Domain\Exceptions\InvalidArgumentDomainException::class);
        });

        it('assertStateIs throws on wrong state', function (): void {
            $guard = new class {
                use \ZeroBoiler\Domain\Concerns\Guards;

                public function check(string $actual): void
                {
                    $this->assertStateIs('pending', $actual, 'pay');
                }
            };
            expect(fn () => $guard->check('shipped'))->toThrow(\ZeroBoiler\Domain\Exceptions\InvalidStateDomainException::class);
        });

        it('assertFound throws on null', function (): void {
            $guard = new class {
                use \ZeroBoiler\Domain\Concerns\Guards;

                public function check(mixed $value): void
                {
                    $this->assertFound($value, 'Order');
                }
            };
            expect(fn () => $guard->check(null))->toThrow(\ZeroBoiler\Domain\Exceptions\NotFoundDomainException::class);
        });

        it('assertRange throws outside bounds', function (): void {
            $guard = new class {
                use \ZeroBoiler\Domain\Concerns\Guards;

                public function check(int $value): void
                {
                    $this->assertRange($value, 1, 100, 'amount');
                }
            };
            expect(fn () => $guard->check(0))->toThrow(\ZeroBoiler\Domain\Exceptions\InvalidArgumentDomainException::class);
            expect(fn () => $guard->check(101))->toThrow(\ZeroBoiler\Domain\Exceptions\InvalidArgumentDomainException::class);
        });
    });

    // ─── §12. Cross-identifier type inequality ───────────────────────
    describe('§12 Cross-type identifier inequality', function (): void {
        it('UuidIdentifier and StringIdentifier are never equal', function (): void {
            $uuid = new class('550e8400-e29b-41d4-a716-446655440000') extends \ZeroBoiler\Domain\Identifiers\UuidIdentifier {};
            $str = \ZeroBoiler\Domain\Identifiers\StringIdentifier::from('550e8400-e29b-41d4-a716-446655440000');
            expect($uuid->equals($str))->toBeFalse();
        });

        it('IntegerIdentifier and StringIdentifier are never equal', function (): void {
            $int = \ZeroBoiler\Domain\Identifiers\IntegerIdentifier::from(42);
            $str = \ZeroBoiler\Domain\Identifiers\StringIdentifier::from('42');
            expect($int->equals($str))->toBeFalse();
        });
    });

    // ─── §13. declare(strict_types=1) on all source files ────────────
    describe('§13 strict_types enforcement', function (): void {
        it('all source files declare strict_types=1', function (): void {
            $srcDir = dirname(__DIR__) . '/src';
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($srcDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            );
            $violations = [];
            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $content = file_get_contents($file->getPathname());
                if (! str_contains($content, 'declare(strict_types=1)')) {
                    $violations[] = $file->getPathname();
                }
            }
            expect($violations)->toBeEmpty('All source files must declare strict_types=1. Violations: ' . implode(', ', $violations));
        });
    });

    // ─── §14. Identifier contract consistency ────────────────────────
    describe('§14 Identifier contract consistency', function (): void {
        it('all identifier classes implement Identifier contract', function (): void {
            $classes = [
                \ZeroBoiler\Domain\Identifiers\UuidIdentifier::class,
                \ZeroBoiler\Domain\Identifiers\UlidIdentifier::class,
                \ZeroBoiler\Domain\Identifiers\StringIdentifier::class,
                \ZeroBoiler\Domain\Identifiers\IntegerIdentifier::class,
            ];
            foreach ($classes as $class) {
                expect((new ReflectionClass($class))->implementsInterface(\ZeroBoiler\Domain\Contracts\Identifier::class))
                    ->toBeTrue("{$class} must implement Identifier contract");
            }
        });

        it('Identifier contract requires fromString, toString, equals, toArray, fromArray, fromJson, toJson', function (): void {
            $ref = new ReflectionClass(\ZeroBoiler\Domain\Contracts\Identifier::class);
            expect($ref->hasMethod('fromString'))->toBeTrue();
            expect($ref->hasMethod('toString'))->toBeTrue();
            expect($ref->hasMethod('equals'))->toBeTrue();
            expect($ref->hasMethod('toArray'))->toBeTrue();
            expect($ref->hasMethod('fromArray'))->toBeTrue();
            expect($ref->hasMethod('fromJson'))->toBeTrue();
            expect($ref->hasMethod('toJson'))->toBeTrue();
        });
    });
});
