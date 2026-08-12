<?php

namespace App\Models\Scopes;

use App\Services\Auth\MasterAdminContext;
use App\Services\Auth\ReadScopeResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Narrows to the account's read scope, and additionally shows rows where
 * `company_id IS NULL` to Master Admin ALONE (adr/0005 decision 6, amendment note).
 *
 * Applied to `audit_logs`, and to nothing else without an ADR — the same restriction
 * SharedTenantScope carries. On that table `NULL` is a meaningful value meaning
 * **a system-level event**: an audited action whose subject belongs to no company, such as a
 * Master Admin changing another Master Admin's `system_access`, or a tenant-scope bypass
 * entered through MasterAdminContext.
 *
 * ⚠ `NULL` does NOT mean here what it means on `branches` and `departments`. There it is
 * "available to all companies"; here it is "attributable to no company" — the opposite. This
 * class exists because both existing scopes are wrong for that meaning, in OPPOSITE
 * directions:
 *
 *   TenantScope        hides the NULL rows from everyone, Master Admin included — concealing
 *                      precisely the rows that exist to hold the most powerful account to
 *                      account.
 *   SharedTenantScope  shows them to everyone in any scope — a subsidiary-employed HR would
 *                      read every group-level administrative action.
 *
 * Never pick a scope class because a column happens to be nullable. Pick it from what the
 * NULL means.
 *
 * ⚠ Scope BOUNDS visibility; it never GRANTS it. A row surviving this scope is not thereby
 * readable — the module's own permission check still applies (adr/0004 decision 1), and for
 * audit_logs that includes the salary filter, which is a separate pass this scope knows
 * nothing about (audit-trail.spec.md BR-AT10).
 */
class SystemTenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        // The explicit Master Admin bypass. Deliberate entry only — never merely because
        // the account holds system_access = FULL (adr/0005 decision 5).
        if (app(MasterAdminContext::class)->isActive()) {
            return;
        }

        $user = auth()->user();

        // No authenticated user: console commands, seeders, queue workers, migrations. Same
        // reasoning as TenantScope — a seeder cannot resolve a read scope, and throwing here
        // would break every artisan command. Route middleware is what protects HTTP.
        if ($user === null) {
            return;
        }

        $companyIds = app(ReadScopeResolver::class)->resolve($user);
        $column = $model->getTable().'.company_id';

        // ⚠ The FULL check is named DIRECTLY and does not go through read scope. This is the
        // case adr/0005 decision 5 already anticipates — "the two come apart the moment read
        // scope cannot express something". A FULL account's read scope resolves to every
        // COMPANY, and a NULL row belongs to none, so no set of company ids, however
        // complete, can contain it.
        //
        // This is also why a group-wide read scope is NOT sufficient here: an HR employed by
        // AHS reads every company and must still not see the system-level rows. Testing that
        // negative is what separates this class from SharedTenantScope.
        $seesSystemRows = $user->isMasterAdmin();

        $builder->where(function (Builder $query) use ($column, $companyIds, $seesSystemRows) {
            $query->whereIn($column, $companyIds);

            if ($seesSystemRows) {
                $query->orWhereNull($column);
            }
        });
    }
}
