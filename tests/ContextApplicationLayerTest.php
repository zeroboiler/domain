<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Domain\Application\Command;
use ZeroBoiler\Domain\Application\CommandBus;
use ZeroBoiler\Domain\Application\CommandHandler;
use ZeroBoiler\Domain\Application\Query;
use ZeroBoiler\Domain\Application\QueryBus;
use ZeroBoiler\Domain\Application\QueryHandler;
use ZeroBoiler\Domain\Context\BoundedContext;
use ZeroBoiler\Domain\Context\ContextRegistry;
use ZeroBoiler\Domain\Exceptions\ConflictDomainException;
use ZeroBoiler\Domain\Exceptions\NotFoundDomainException;

// ─── BoundedContext ────────────────────────────────────────────────────────

describe('BoundedContext', function (): void {
    it('resolves the app convention from a bare name', function (): void {
        $context = BoundedContext::named('Identity');

        expect($context->name)->toBe('Identity')
            ->and($context->namespace)->toBe('App\\Contexts\\Identity')
            ->and($context->path)->toBe('app/Contexts/Identity');
    });

    it('supports an explicit base path', function (): void {
        $context = BoundedContext::named('Habits', 'src/Contexts');

        expect($context->path)->toBe('src/Contexts/Habits');
    });

    it('builds explicit locations', function (): void {
        $context = BoundedContext::located('Billing', 'Vendor\\Billing', 'lib/billing');

        expect($context->namespace)->toBe('Vendor\\Billing')
            ->and($context->path)->toBe('lib/billing');
    });

    it('compares by name only', function (): void {
        $a = BoundedContext::named('Identity');
        $b = BoundedContext::located('Identity', 'Other\\Ns', 'other/path');

        expect($a->equals($b))->toBeTrue();
    });

    it('builds member FQCNs from slash notation', function (): void {
        $context = BoundedContext::named('Identity');

        expect($context->fqcn('Domain/User'))->toBe('App\\Contexts\\Identity\\Domain\\User');
    });

    it('serializes to a primitive array', function (): void {
        $context = BoundedContext::named('Identity');

        expect($context->toArray())->toBe([
            'name' => 'Identity',
            'namespace' => 'App\\Contexts\\Identity',
            'path' => 'app/Contexts/Identity',
        ]);
    });

    it('casts to its name', function (): void {
        expect((string) BoundedContext::named('Habits'))->toBe('Habits');
    });
});

// ─── ContextRegistry ───────────────────────────────────────────────────────

describe('ContextRegistry', function (): void {
    it('registers and resolves contexts by name', function (): void {
        $registry = new ContextRegistry;
        $registry->register(BoundedContext::named('Identity'));

        expect($registry->has('Identity'))->toBeTrue()
            ->and($registry->get('Identity')->namespace())->toBe('App\\Contexts\\Identity');
    });

    it('ignores duplicate registration (first wins)', function (): void {
        $registry = new ContextRegistry;
        $registry->register(BoundedContext::named('Identity'));
        $registry->register(BoundedContext::located('Identity', 'Other\\Ns', 'other'));

        expect($registry->get('Identity')->namespace())->toBe('App\\Contexts\\Identity');
    });

    it('throws a domain exception for unknown contexts', function (): void {
        $registry = new ContextRegistry;

        $registry->get('Nope');
    })->throws(NotFoundDomainException::class, 'Bounded context "Nope" is not registered.');

    it('lists names and contexts in registration order', function (): void {
        $registry = new ContextRegistry;
        $registry->register(BoundedContext::named('Identity'));
        $registry->register(BoundedContext::named('Habits'));

        expect($registry->names())->toBe(['Identity', 'Habits'])
            ->and($registry->all())->each->toBeInstanceOf(BoundedContext::class);
    });

    it('flushes all contexts', function (): void {
        $registry = new ContextRegistry;
        $registry->register(BoundedContext::named('Identity'));
        $registry->flush();

        expect($registry->names())->toBe([]);
    });

    it('serializes registry state', function (): void {
        $registry = new ContextRegistry;
        $registry->register(BoundedContext::named('Identity'));

        expect($registry->toArray())->toBe([
            'Identity' => [
                'name' => 'Identity',
                'namespace' => 'App\\Contexts\\Identity',
                'path' => 'app/Contexts/Identity',
            ],
        ]);
    });
});

// ─── CommandBus / QueryBus ─────────────────────────────────────────────────

final readonly class RegisterHabit implements Command
{
    public function __construct(
        public string $name,
    ) {}
}

final class RegisterHabitHandler implements CommandHandler
{
    public bool $handled = false;

    public string $lastName = '';

    public function handle(Command $command): void
    {
        $this->handled = true;
        $this->lastName = $command->name;
    }
}

final readonly class HabitNameExists implements Query
{
    public function __construct(
        public string $name,
    ) {}
}

final class HabitNameExistsHandler implements QueryHandler
{
    public function handle(Query $query): mixed
    {
        return $query->name === 'Meditate';
    }
}

describe('CommandBus', function (): void {
    it('dispatches to the registered handler', function (): void {
        $handler = new RegisterHabitHandler;
        $bus = (new CommandBus)->register(RegisterHabit::class, $handler);

        $bus->dispatch(new RegisterHabit('Read'));

        expect($handler->handled)->toBeTrue()
            ->and($handler->lastName)->toBe('Read');
    });

    it('rejects a second handler for the same command', function (): void {
        $bus = (new CommandBus)->register(RegisterHabit::class, new RegisterHabitHandler);

        $bus->register(RegisterHabit::class, new RegisterHabitHandler);
    })->throws(ConflictDomainException::class);

    it('throws when no handler is registered and no resolver exists', function (): void {
        (new CommandBus)->dispatch(new RegisterHabit('Read'));
    })->throws(NotFoundDomainException::class, 'No handler available for command');

    it('resolves handlers through the resolver and memoizes them', function (): void {
        $handler = new RegisterHabitHandler;
        $resolverCalls = 0;

        $bus = new CommandBus(function (string $commandClass) use (&$resolverCalls, $handler): ?CommandHandler {
            $resolverCalls++;

            return $commandClass === RegisterHabit::class ? $handler : null;
        });

        expect($bus->hasHandler(RegisterHabit::class))->toBeTrue();

        $bus->dispatch(new RegisterHabit('Meditate'));
        $bus->dispatch(new RegisterHabit('Read'));

        expect($resolverCalls)->toBe(1)
            ->and($handler->lastName)->toBe('Read');
    });

    it('throws when the resolver returns null', function (): void {
        $bus = new CommandBus(fn (string $commandClass): ?CommandHandler => null);

        $bus->dispatch(new RegisterHabit('Read'));
    })->throws(NotFoundDomainException::class, 'No handler available for command');
});

describe('QueryBus', function (): void {
    it('asks the registered handler and returns its result', function (): void {
        $bus = (new QueryBus)->register(HabitNameExists::class, new HabitNameExistsHandler);

        expect($bus->ask(new HabitNameExists('Meditate')))->toBeTrue()
            ->and($bus->ask(new HabitNameExists('Read')))->toBeFalse();
    });

    it('rejects a second handler for the same query', function (): void {
        $bus = (new QueryBus)->register(HabitNameExists::class, new HabitNameExistsHandler);

        $bus->register(HabitNameExists::class, new HabitNameExistsHandler);
    })->throws(ConflictDomainException::class);

    it('throws when no handler is registered and no resolver exists', function (): void {
        (new QueryBus)->ask(new HabitNameExists('Meditate'));
    })->throws(NotFoundDomainException::class, 'No handler available for query');

    it('resolves handlers through the resolver and memoizes them', function (): void {
        $resolverCalls = 0;

        $bus = new QueryBus(function (string $queryClass) use (&$resolverCalls): ?QueryHandler {
            $resolverCalls++;

            return $queryClass === HabitNameExists::class ? new HabitNameExistsHandler : null;
        });

        expect($bus->hasHandler(HabitNameExists::class))->toBeTrue()
            ->and($bus->ask(new HabitNameExists('Meditate')))->toBeTrue();

        $bus->ask(new HabitNameExists('Read'));

        expect($resolverCalls)->toBe(1);
    });
});
