<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain\Application;

/**
 * Handles exactly one Query type and returns its result.
 *
 * Query handlers must be side-effect-free: no writes, no recorded domain
 * events — reads may hit read models directly and bypass the domain.
 *
 * @template TQuery of Query
 * @template TResult
 *
 * @since 1.13.0
 *
 * @example
 * ```php
 * final class GetUserByEmailHandler implements QueryHandler
 * {
 *     /* @use HandlesQueries<GetUserByEmail, User> *\/
 *     use HandlesQueries;
 *
 *     public function __construct(
 *         private readonly UserRepository $users,
 *     ) {}
 *
 *     public function handle(GetUserByEmail $query): ?User
 *     {
 *         return $this->users->findByEmail($query->email);
 *     }
 * }
 * ```
 */
interface QueryHandler
{
    /**
     * Answer the query.
     *
     * @param  Query  $query  The query message to answer.
     * @return mixed The query result.
     */
    public function handle(Query $query): mixed;
}
