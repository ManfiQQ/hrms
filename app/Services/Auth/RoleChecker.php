<?php

namespace App\Services\Auth;

use App\Models\EmployeeRole;
use App\Models\User;

/**
 * Authority checks — auth-rbac.spec.md §5.5.
 *
 * ⚠ NO CALLER MAY QUERY employee_roles DIRECTLY. A raw query is a review failure, because it
 * is the one place the `revoked_date IS NULL` filter gets omitted — and omitting it returns
 * revoked authority as current, which is a SILENT SECURITY FAILURE, not an error (adr/0003
 * decision 1). Here the filter comes from EmployeeRole's NotRevokedScope by default, so the
 * safe query is the one you get without thinking about it.
 *
 * ⚠ AUTHORITY IS PER COMPANY, so every method takes a $companyId. "What authority does this
 * person have?" has no answer until a company is named — a person may be MANAGER at AIM and
 * hold nothing at AHS (adr/0003 decision 1). A signature without the argument is a bug, not
 * a convenience.
 *
 * Ordinary staff hold NO row at all. There is no STAFF value to look for; absence IS the
 * staff state.
 *
 * This class belongs to auth-rbac.spec.md, not to the Audit Trail module. It is written here
 * because AuditLogReader is the first consumer that needs it (audit-trail.spec.md §5.3), and
 * building it correctly once is cheaper than letting the first caller improvise a query.
 */
class RoleChecker
{
    public function hasRole(User $user, string $role, int $companyId): bool
    {
        return $this->hasAnyRole($user, [$role], $companyId);
    }

    /** @param  list<string>  $roles */
    public function hasAnyRole(User $user, array $roles, int $companyId): bool
    {
        // Master Admin and Director hold no employee record, so they can hold no role row at
        // all (adr/0003 decision 1, BR-11). That is not a special case to work around — it is
        // why role checks alone can never answer for them, and why canReadSalary() below
        // consults system_access separately.
        if ($user->employee_id === null) {
            return false;
        }

        return EmployeeRole::query()
            ->where('employee_id', $user->employee_id)
            ->where('company_id', $companyId)
            ->whereIn('role', $roles)
            ->exists();
    }

    /**
     * May this account read salary data at this company? (BR-A12, adr/0003 decision 5.)
     *
     * ⚠ THE HR ROLE NEVER GRANTS SALARY ACCESS, AT ANY SCOPE — however many HR staff exist,
     * and however wide their read scope. Enforcement is structural rather than declarative:
     * ACCOUNT is a hardcoded restricted role only Master Admin may grant, so HR cannot grant
     * it to itself (adr/0003 decision 3).
     *
     * system_access FULL and VIEW_ONLY pass because adr/0004 decision 3 widens the rule for
     * accounts holding no roles at all. Master Admin and the Director were never the target
     * of the restriction — what HR must not see is salary — and a role-only check could
     * never pass for them, since they hold no employee_roles rows.
     *
     * This is the ONE place the question is answered. A caller that re-implements it is the
     * caller that gets it wrong.
     */
    public function canReadSalary(User $user, int $companyId): bool
    {
        if (in_array($user->system_access, ['FULL', 'VIEW_ONLY'], true)) {
            return true;
        }

        return $this->hasRole($user, 'ACCOUNT', $companyId);
    }

    /**
     * Companies where this account holds the given role, currently.
     *
     * @return list<int>
     */
    public function companiesWithRole(User $user, string $role): array
    {
        if ($user->employee_id === null) {
            return [];
        }

        return EmployeeRole::query()
            ->where('employee_id', $user->employee_id)
            ->where('role', $role)
            ->pluck('company_id')
            ->unique()
            ->values()
            ->all();
    }
}
