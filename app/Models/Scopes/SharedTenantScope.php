<?php

namespace App\Models\Scopes;

use App\Services\Auth\MasterAdminContext;
use App\Services\Auth\ReadScopeResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Narrows to the account's read scope while KEEPING rows where `company_id IS NULL`
 * (adr/0005 decision 3).
 *
 * Applied to `branches` and `departments`, and to nothing else without an ADR. On those two
 * tables `NULL` is a meaningful value meaning **shared across all companies**, not missing
 * data (adr/0002 decision 1).
 *
 * ⚠ This is the bug the carve-out invites, and the reason this class exists separately: a
 * plain equality or IN check drops every shared row and **returns fewer rows rather than
 * erroring**. It presents as "the Logistics branch disappeared", not as a fault, and no
 * happy-path test catches it.
 *
 * Not a relaxation of Principle #4. These two tables are org-structure references holding no
 * personal or financial data; sensitive employee data stays scoped to `employees.company_id`,
 * which is NOT NULL. Shared structure, scoped data.
 */
class SharedTenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (app(MasterAdminContext::class)->isActive()) {
            return;
        }

        $user = auth()->user();

        if ($user === null) {
            return;
        }

        $companyIds = app(ReadScopeResolver::class)->resolve($user);
        $column = $model->getTable().'.company_id';

        $builder->where(function (Builder $query) use ($column, $companyIds) {
            $query->whereNull($column)->orWhereIn($column, $companyIds);
        });
    }
}
