<?php

namespace App\Actions\Employee;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeRole;
use InvalidArgumentException;

/**
 * Withdrawing an authority role — `employee-master.spec.md` §5.6, `adr/0003` decision 1.
 *
 * ⚠ SETS A DATE. NEVER DELETES, NEVER CLEARS A FLAG, NEVER "REACTIVATES" A ROW.
 *
 * `employee_roles` carries no soft deletes and no `is_enabled`, and neither may be added: a
 * second mechanism meaning "revoked" would force every authority check to test two
 * conditions, and the check that tests only one is a silent security hole rather than an
 * error. `revoked_date` is the single mechanism.
 *
 * Re-granting later inserts a NEW row (GrantRole), which preserves the full cycle — held
 * Jan–Aug, revoked Aug, re-granted November — that a boolean toggle cannot express. A service
 * that cleared `revoked_date` on the original row would erase the middle of that history
 * while appearing to restore it.
 *
 * ⚠ Nothing is written to `audit_logs`: this row already records `revoked_by` and
 * `revoke_reason`. See GrantRole for the full reasoning.
 */
class RevokeRole
{
    /**
     * @throws InvalidArgumentException
     */
    public function execute(
        Employee $employee,
        string $role,
        Company $company,
        ?string $reason = null,
    ): EmployeeRole {
        // The default NotRevokedScope means this finds only a CURRENTLY HELD row, which is
        // the only kind that can be withdrawn. An already-revoked row is history.
        $held = EmployeeRole::query()
            ->where('employee_id', $employee->id)
            ->where('company_id', $company->id)
            ->where('role', $role)
            ->first();

        if ($held === null) {
            throw new InvalidArgumentException(
                "This employee does not currently hold {$role} at {$company->code}. An "
                .'already-revoked row stays as history and is never re-revoked; ordinary staff '
                .'hold no row at all (adr/0003 decision 1).'
            );
        }

        $held->update([
            'revoked_date' => now()->toDateString(),
            'revoked_by' => auth()->id(),
            'revoke_reason' => $reason,
            'updated_by' => auth()->id(),
        ]);

        return $held;
    }
}
