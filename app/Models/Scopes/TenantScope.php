<?php

namespace App\Models\Scopes;

use App\Services\Auth\MasterAdminContext;
use App\Services\Auth\ReadScopeResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Narrows a query to the account's read scope (adr/0005 decision 2).
 *
 * The default for every business table. For the two shared org-structure tables, use
 * SharedTenantScope instead — they are separate classes on purpose, so that forgetting is a
 * MISSING scope the guard test catches, rather than a silently narrowing default that drops
 * shared rows with no error (adr/0005 decision 1).
 *
 * ⚠ Scope BOUNDS visibility; it never GRANTS it. A row surviving this scope is not thereby
 * readable — the module's own permission check still applies (adr/0004 decision 1).
 */
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        // The explicit Master Admin bypass. Deliberate entry only — never merely because
        // the account holds system_access = FULL (adr/0005 decision 5).
        if (app(MasterAdminContext::class)->isActive()) {
            return;
        }

        $user = auth()->user();

        // No authenticated user: console commands, seeders, queue workers, migrations.
        // These run unscoped by necessity — a seeder cannot resolve a read scope, and
        // throwing here would break every artisan command.
        //
        // ⚠ This is not a security decision for HTTP. Unauthenticated requests never reach
        // model queries, because route middleware stops them first. If a route is ever left
        // unauthenticated, this scope will not save it — the middleware is what does.
        if ($user === null) {
            return;
        }

        $companyIds = app(ReadScopeResolver::class)->resolve($user);

        $builder->whereIn($model->getTable().'.company_id', $companyIds);
    }
}
