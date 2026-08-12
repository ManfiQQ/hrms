<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Services\Auth\ReadScopeResolver;
use App\Services\Auth\RoleChecker;
use App\Support\Audit\SalaryFields;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

/**
 * The only permitted reader of audit_logs and security_events
 * (audit-trail.spec.md §5.3, BR-AT9, BR-AT10).
 *
 * ⚠ A query built anywhere else is a review failure. The salary filter is only as good as
 * the number of ways into the table, and BR-AT10 requires it applied LAST and
 * UNCONDITIONALLY — so no list, export, count or aggregate can reach a salary row by taking
 * a different route.
 *
 * The two tables arrive at the same place by different routes, which is the reason this
 * service exists at all: audit_logs is narrowed by SystemTenantScope on the model, and
 * security_events carries NO scope and must be narrowed here, explicitly. A caller querying
 * either model directly would get one of them wrong.
 */
class AuditLogReader
{
    /** Roles that may read the trail at a company at all (BR-AT9). */
    private const READER_ROLES = ['HR', 'ASSISTANT_DIRECTOR', 'ACCOUNT'];

    public function __construct(
        private readonly RoleChecker $roles,
        private readonly ReadScopeResolver $scope,
        private readonly SalaryFields $salaryFields,
    ) {}

    /**
     * Audit rows this account may read.
     *
     * SystemTenantScope has already narrowed the query to the account's read scope, and
     * shown the company_id IS NULL rows to Master Admin alone (§11). What is left for this
     * method is authorization and the salary filter.
     */
    public function auditLogs(User $user): Builder
    {
        $this->authorize($user);

        $query = AuditLog::query();

        // ⚠ Applied LAST and UNCONDITIONALLY. Not redacted, not masked, not counted —
        // ABSENT. Showing the row with its values blanked would still disclose THAT this
        // employee's salary changed, on what date and by whom, which is material and which
        // ACCOUNT-only means HR does not get.
        $this->applySalaryFilter($query, $user);

        return $query;
    }

    /**
     * Security events this account may read.
     *
     * ⚠ security_events carries no tenant scope — its rows are written before
     * authentication, so there is no account from which to resolve one, and there may be no
     * account at all. Access control is therefore HERE, at read time, and nowhere else.
     */
    public function securityEvents(User $user): Builder
    {
        $this->authorize($user);

        $query = SecurityEvent::query();

        if ($user->isMasterAdmin()) {
            return $query;
        }

        // ACCOUNT reads the most data in the system and administers none of it; account
        // security is administration (auth-rbac.spec.md §6, BR-AT9).
        if (! $this->holdsAnywhere($user, ['HR', 'ASSISTANT_DIRECTOR'])) {
            return $query->whereRaw('1 = 0');
        }

        $companyIds = $this->scope->resolve($user);

        // ⚠ An event with a null user_id has no account, therefore no employer, therefore no
        // company — so it falls inside NO narrower read scope and is Master Admin only. That
        // follows from BR-AT9 rather than adding to it, and it lines up with BR-AT11: those
        // are exactly the rows that expire at 90 days.
        return $query->whereNotNull('company_id')->whereIn('company_id', $companyIds);
    }

    /**
     * Hide salary rows except at companies where this account may read salary.
     *
     * ⚠ SALARY VISIBILITY IS PER COMPANY, because the ACCOUNT role is held per company
     * (adr/0003 decision 5). An ACCOUNT at AHS whose read scope is the whole group reads
     * AHS's salary rows and NOT a subsidiary's — the scope says which companies they may see
     * at all, the role says what kind of data within them, and conflating the two is the
     * merge conventions.md §2 warns about.
     *
     * ⚠ The salary question itself is delegated to RoleChecker::canReadSalary — the ONE
     * place it is answered (auth-rbac.spec.md §5.5). Re-implementing the
     * ACCOUNT / FULL / VIEW_ONLY logic here would be the second copy, and the second copy is
     * the one that drifts.
     */
    private function applySalaryFilter(Builder $query, User $user): void
    {
        $pairs = $this->salaryFields->pairs();

        if ($pairs === []) {
            return;
        }

        // FULL and VIEW_ONLY read salary everywhere, including the system-level rows whose
        // company_id is NULL — a per-company IN () could never match those (adr/0004
        // decision 3). Master Admin and the Director were never the target of the
        // restriction; what HR must not see is salary.
        if (in_array($user->system_access, ['FULL', 'VIEW_ONLY'], true)) {
            return;
        }

        $salaryCompanies = array_values(array_filter(
            $this->scope->resolve($user),
            fn (int $companyId) => $this->roles->canReadSalary($user, $companyId)
        ));

        // A row survives if it is NOT a salary pair, or if it belongs to a company where
        // this account holds ACCOUNT. Everything else is gone from the result set entirely.
        $query->where(function (Builder $q) use ($pairs, $salaryCompanies) {
            $q->where(function (Builder $notSalary) use ($pairs) {
                foreach ($pairs as $pair) {
                    $notSalary->whereNot(function (Builder $inner) use ($pair) {
                        $inner->where('auditable_type', $pair['model'])
                            ->where('field', $pair['field']);
                    });
                }
            });

            if ($salaryCompanies !== []) {
                $q->orWhereIn('company_id', $salaryCompanies);
            }
        });
    }

    /** True when the account holds any of these roles at any company in its read scope. */
    private function holdsAnywhere(User $user, array $roles): bool
    {
        foreach ($this->scope->resolve($user) as $companyId) {
            if ($this->roles->hasAnyRole($user, $roles, $companyId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * BR-AT9: Master Admin, or one of the three reader roles somewhere in scope.
     *
     * Everyone else gets nothing — an ordinary employee has no business in the audit log,
     * and a supervisor's need to know who changed a record is served by the record's own
     * history, not by this table.
     */
    private function authorize(User $user): void
    {
        if ($user->isMasterAdmin() || $this->holdsAnywhere($user, self::READER_ROLES)) {
            return;
        }

        throw new AuthorizationException(
            'This account may not read the audit trail. Master Admin, HR, '
            .'ASSISTANT_DIRECTOR and ACCOUNT only (audit-trail.spec.md BR-AT9).'
        );
    }
}
