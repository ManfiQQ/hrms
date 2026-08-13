<?php

namespace App\Services\Auth;

use RuntimeException;

/**
 * The explicit escape hatch for granting a restricted `employee_roles.role` without an
 * authenticated Master Admin — BR-16, `adr/0003` decision 3.
 *
 * ⚠ WHY THIS CLASS EXISTS AT ALL, stated plainly, because it is a hole and holes need
 * justifying. `EmployeeRole`'s creating hook refuses a restricted grant unless the actor is
 * Master Admin. Seeders, console commands, queue jobs and the legacy importer have **no
 * authenticated user**, so without a deliberate way in, either
 *
 *   - the hook throws and the first broken seeder gets "fixed" with a
 *     `runningInConsole()` exception, which opens the door permanently and silently, or
 *   - the hook lets a null user through, and `null` becomes the shortcut every job, command
 *     and `tinker` session takes without anyone deciding it should.
 *
 * Both are the ambient bypass `adr/0005` decision 5 rejects for tenant scope, arriving by a
 * different route. This class is the same answer that decision gave: **the shortcut must be
 * entered on purpose, and it must carry a reason.**
 *
 * ⚠ HOW IT DIFFERS FROM MasterAdminContext, and the difference is not an oversight. That
 * class requires an authenticated account because it WRITES TO audit_logs and a bypass nobody
 * can be attributed to is exactly what it exists to prevent. This one writes nothing, because
 * a role grant is already recorded in full by the `employee_roles` row itself —
 * `assigned_by`, `effective_date`, `revoked_by`, `revoke_reason`. Mirroring it into
 * `audit_logs` would be two records of one fact, which is the reasoning that kept `CORE_ROLE`
 * out of `employee_status_history.change_type` (`adr/0003` decision 8).
 *
 * So requiring a user here would be requiring one for an audit row that is never written —
 * and it would make the class useless for the very callers it exists for.
 *
 * ⚠ THIS DOES NOT MAKE A GRANT ANONYMOUS, and it cannot: `employee_roles.assigned_by` is
 * NOT NULL. Entering this context lifts BR-16's *authority* check; it does not lift the
 * schema's requirement that somebody be named as the granter. A seeder must still pass an
 * `assigned_by` — the Master Admin it created moments earlier — and the importer must name
 * whoever it is running as.
 *
 * That combination is the right one and is worth stating rather than discovering: the
 * shortcut is for *"no authenticated session"*, never for *"no accountable actor"*. A grant
 * nobody can be pointed at is not a grant this system records.
 */
class RestrictedRoleContext
{
    private bool $active = false;

    private ?string $reason = null;

    /**
     * Run a callback with the restricted-role guard lifted.
     *
     * The reason is mandatory and non-empty, for the same purpose it serves in
     * MasterAdminContext: a grant nobody can explain is one that should not have happened.
     * It is not persisted — see the class note above — but it is required at the call site,
     * where it forces the author to state what they are doing before they do it.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function run(string $reason, callable $callback): mixed
    {
        if (trim($reason) === '') {
            throw new RuntimeException(
                'Granting a restricted role outside Master Admin requires a stated reason. '
                .'The shortcut is deliberate or it is not taken (BR-16, adr/0003 decision 3).'
            );
        }

        $previousActive = $this->active;
        $previousReason = $this->reason;

        $this->active = true;
        $this->reason = $reason;

        try {
            return $callback();
        } finally {
            // Restored rather than reset, so a nested call cannot end its parent's context.
            $this->active = $previousActive;
            $this->reason = $previousReason;
        }
    }

    /** True only inside run(). The EmployeeRole hook consults this and nothing else. */
    public function isActive(): bool
    {
        return $this->active;
    }

    public function reason(): ?string
    {
        return $this->reason;
    }
}
