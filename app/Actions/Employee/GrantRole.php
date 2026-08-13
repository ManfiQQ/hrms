<?php

namespace App\Actions\Employee;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeRole;
use InvalidArgumentException;

/**
 * Granting an authority role — `employee-master.spec.md` §5.6, `adr/0003` decision 1.
 *
 * ⚠ THIS ACTION DOES NOT AUTHORISE, AND THAT IS DELIBERATE — same division as
 * ChangeEmployeeStatus, which validates BR-2's lifecycle and leaves permission to the policy.
 * Authorisation is EmployeePolicy::grantRole(); the STRUCTURAL guarantee for the four
 * restricted roles is the creating hook on EmployeeRole, which fires here as it would on any
 * other write path. Putting an authorisation check here too would be a third copy of one
 * rule, and the copy that drifts is the one that grants something nobody meant to grant.
 *
 * ⚠ NOTHING IS WRITTEN TO audit_logs. The `employee_roles` row IS the record: `assigned_by`,
 * `effective_date`, and on withdrawal `revoked_by` and `revoke_reason`. Mirroring it would be
 * two records of one fact, which is exactly why `CORE_ROLE` was kept out of
 * `employee_status_history.change_type` (`adr/0003` decision 8) — and the copy is always the
 * one that goes stale.
 */
class GrantRole
{
    /**
     * @param  string  $effectiveDate  when the role APPLIES — distinct from created_at, because
     *                                 a promotion is typically effective before HR gets to
     *                                 enter it. It must not silently default to today (§5.6).
     *
     * @throws InvalidArgumentException
     */
    public function execute(
        Employee $employee,
        string $role,
        Company $company,
        string $effectiveDate,
    ): EmployeeRole {
        if (! in_array($role, EmployeeRole::ROLES, true)) {
            throw new InvalidArgumentException(
                "{$role} is not an authority role. The six are: ".implode(', ', EmployeeRole::ROLES).'. '
                .'Ordinary staff hold NO row at all — there is no STAFF value, because defining '
                .'one for the absence of authority would be a second way to express one state '
                .'(adr/0003 decision 1).'
            );
        }

        // ⚠ Refused rather than silently deduplicated. Two live rows for one (employee,
        // company, role) would make "does this person hold ACCOUNT at AIM" answerable twice,
        // and revoking one would leave the other standing — authority that survives its own
        // withdrawal, with nothing to notice.
        //
        // The default NotRevokedScope means this asks about CURRENT holdings only, which is
        // the question: a previously revoked row is history and must not block a re-grant.
        $held = EmployeeRole::query()
            ->where('employee_id', $employee->id)
            ->where('company_id', $company->id)
            ->where('role', $role)
            ->exists();

        if ($held) {
            throw new InvalidArgumentException(
                "This employee already holds {$role} at {$company->code}. Withdraw it first if "
                .'the grant needs re-dating; re-granting after a revocation inserts a new row '
                .'and preserves the full cycle (§5.6).'
            );
        }

        return EmployeeRole::create([
            'employee_id' => $employee->id,
            'company_id' => $company->id,
            'role' => $role,
            'effective_date' => $effectiveDate,
            'assigned_by' => auth()->id(),
            'created_by' => auth()->id(),
        ]);
    }
}
