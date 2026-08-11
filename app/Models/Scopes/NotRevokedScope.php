<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Restricts a query to rows that have not been revoked.
 *
 * Applied by default to EmployeeRole. Every authority query must filter
 * `WHERE revoked_date IS NULL` — omitting it returns revoked authority as current, which is
 * a silent security failure rather than an error. conventions.md §3 and adr/0003 decision 1
 * both require this to live in a default model scope instead of being repeated at each call
 * site, precisely because a repeated filter is one that eventually gets forgotten.
 *
 * To read revoked rows deliberately — the role-history timeline in adr/0003 decision 8 is
 * the intended case — use EmployeeRole::withRevoked(), which removes this scope explicitly.
 */
class NotRevokedScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $builder->whereNull($model->getTable().'.revoked_date');
    }
}
