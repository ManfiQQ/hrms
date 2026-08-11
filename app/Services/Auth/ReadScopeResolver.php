<?php

namespace App\Services\Auth;

use App\Models\Company;
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
     * The company ids this account may read. Empty means: nothing.
     *
     * @return list<int>
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

        $employee = $user->employee;

        // Fail closed. A STANDARD account with no employee record should not exist — every
        // staff account is created alongside its employee (BR-A20), and the two account
        // types that legitimately have no employee are FULL and VIEW_ONLY, handled above.
        // If it happens anyway, reading nothing is the safe answer: too few rows is
        // visible to the user, too many is a silent leak.
        if ($employee === null) {
            return [];
        }

        $employer = $employee->company;

        if ($employer === null) {
            return [];
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
