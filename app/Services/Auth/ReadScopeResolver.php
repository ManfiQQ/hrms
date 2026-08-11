<?php

namespace App\Services\Auth;

use App\Exceptions\Auth\OrphanedAccountException;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Scopes\TenantScope;
use App\Models\User;

/**
 * Resolves the set of `company_id` values an account may read.
 *
 * Read scope is DERIVED, never stored (adr/0004 decision 1). It comes from where the
 * account's employer sits in `companies.parent_company_id`:
 *
 *   system_access = FULL or VIEW_ONLY  → every company
 *   STANDARD, employer is the parent   → every company
 *   STANDARD, employer is a subsidiary → that company only
 *
 * There is no manual override and none may be added. A stored override would be a second
 * answer to a question the hierarchy already answers, and the two would eventually disagree
 * — the reasoning that rejected `secondary_company_id` and the `is_enabled` flag.
 *
 * ⚠ This is load-bearing for every scoped read in the system. It answers *which companies*;
 * it does NOT grant permission to read anything within them. Scope bounds the visibility a
 * role has already granted, and never confers any (adr/0004 decision 1, adr/0005 decision 2).
 *
 * ⚠ It also depends on the company hierarchy being seeded correctly. A subsidiary left with
 * a null `parent_company_id` is indistinguishable from the parent here, and its staff get
 * group-wide reads. No amount of logic can detect that — the hierarchy is input, not logic —
 * which is why adr/0004 decision 1 requires it covered by a test.
 *
 * See auth-rbac.spec.md §5.4.
 */
class ReadScopeResolver
{
    /**
     * Resolved scopes for this request only.
     *
     * The service is bound as a singleton, and a Laravel singleton lives exactly one
     * request — so this cache is per request, never per session. That distinction is the
     * requirement: a transfer or a hierarchy correction must take effect on the account's
     * NEXT REQUEST, not on their next login. Caching in the session would leave someone
     * reading the wrong companies until they logged out.
     *
     * @var array<int|string, list<int>>
     */
    private array $cache = [];

    /**
     * The company ids this account may read.
     *
     * @return list<int>
     *
     * @throws OrphanedAccountException when a STANDARD account cannot be resolved to an
     *                                  employer. That state is impossible under BR-A20, so
     *                                  it is thrown rather than answered with an empty
     *                                  scope, which would read as "no data yet".
     */
    public function resolve(User $user): array
    {
        $key = $user->getKey() ?? spl_object_id($user);

        return $this->cache[$key] ??= $this->compute($user);
    }

    /** True when this account may read every company in the group. */
    public function isGroupWide(User $user): bool
    {
        return count($this->resolve($user)) === Company::query()->count();
    }

    /**
     * Discard the cache.
     *
     * For tests and long-running processes (queue workers, Octane) where one PHP process
     * outlives one logical request. Ordinary web requests never need this — the container,
     * and with it this instance, is rebuilt on every request.
     */
    public function flush(): void
    {
        $this->cache = [];
    }

    /**
     * @return list<int>
     */
    private function compute(User $user): array
    {
        // FULL bypasses tenant scope entirely and VIEW_ONLY reads group-wide, so both
        // resolve to every company (adr/0004 decision 2). VIEW_ONLY reaches that through
        // this ordinary path rather than by skipping the mechanism — only FULL bypasses.
        if (in_array($user->system_access, ['FULL', 'VIEW_ONLY'], true)) {
            return $this->allCompanyIds();
        }

        // ⚠ Loaded WITHOUT TenantScope, and that is not an optimisation.
        //
        // Employee carries TenantScope, which asks this resolver for the account's scope,
        // which would load the employee again — unbounded recursion. Resolving *who you
        // are* must precede *what you may see*, so this one read sits outside the
        // mechanism it feeds. It is safe because it is keyed on the account's own
        // employee_id: it can return that account's employee and nothing else.
        $employee = $user->employee_id === null
            ? null
            : Employee::withoutGlobalScope(TenantScope::class)->find($user->employee_id);

        // ⚠ Impossible under BR-A20, and therefore thrown rather than accommodated.
        //
        // An empty scope would be a valid, ordinary answer: it renders as an empty list and
        // reads as "there is no data yet". The real cause would be an account that should
        // not exist, and nothing would say so — the user cannot distinguish the two, and
        // neither can anyone they report it to.
        //
        // Same pattern as adr/0001: an impossible state is held out at the boundary with a
        // clear reason, not handled quietly.
        if ($employee === null) {
            throw OrphanedAccountException::missingEmployee($user);
        }

        // employees.company_id is NOT NULL, so a null employer means the company row is
        // missing or soft-deleted. Same reasoning: scope cannot be derived and must not be
        // guessed.
        $employer = $employee->company;

        if ($employer === null) {
            throw OrphanedAccountException::missingEmployer($user);
        }

        // The parent (AHS) reads the whole group. HR, Assistant Director and Account see
        // every entity because they are EMPLOYED BY AHS — not because of the roles they
        // hold. An HR hired by a single subsidiary reads that subsidiary only, with no code
        // change, and a seventh entity added under AHS becomes visible automatically.
        if ($employer->parent_company_id === null) {
            return $this->allCompanyIds();
        }

        return [$employer->id];
    }

    /**
     * @return list<int>
     */
    private function allCompanyIds(): array
    {
        return Company::query()->pluck('id')->all();
    }
}
