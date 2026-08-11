<?php

namespace App\Services\Auth;

use RuntimeException;

/**
 * The explicit Master Admin bypass of tenant scope.
 *
 * adr/0005 decision 5 requires this bypass to be **explicit and never ambient**: a request
 * runs in Master Admin context because something said so, not because the account happens
 * to hold `system_access = FULL`. A scope that simply returned early for FULL accounts,
 * leaving no record, is expressly not that decision — it would make the most powerful read
 * in the system the one that leaves no trace.
 *
 * Note what this is NOT needed for. A FULL account's read scope already resolves to every
 * company through the ordinary path (ReadScopeResolver), so Master Admin reads across the
 * group without entering this context at all. The bypass exists for the narrower case of
 * lifting the scope mechanism itself — data repair reaching rows the ordinary WHERE cannot
 * express. It is an escape hatch, not the daily path.
 *
 * ⚠ INCOMPLETE — the audit half is not implemented.
 *
 * adr/0005 decision 5 also requires every bypass to be written to `audit_logs`. That table
 * has no migration yet (schema.md lists it as draft), so the reason is captured here and
 * goes nowhere. The seam is deliberate: when audit_logs exists, enter() writes the reason
 * and the actor, and nothing else about this class changes. Recorded as a known gap in
 * adr/0005 and auth-rbac.spec.md rather than left to be discovered.
 */
class MasterAdminContext
{
    private bool $active = false;

    private ?string $reason = null;

    /**
     * Run a callback with tenant scope lifted.
     *
     * The reason is mandatory and non-empty by design — a bypass nobody can explain is one
     * that should not have happened, and this argument is what the audit entry will carry.
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
                'A Master Admin tenant-scope bypass requires a stated reason. '
                .'It is written to audit_logs, and a bypass nobody can explain is one that '
                .'should not have happened (adr/0005 decision 5).'
            );
        }

        $previousActive = $this->active;
        $previousReason = $this->reason;

        $this->active = true;
        $this->reason = $reason;

        // TODO(audit_logs): write { actor, reason, timestamp } once the table is migrated.

        try {
            return $callback();
        } finally {
            $this->active = $previousActive;
            $this->reason = $previousReason;
        }
    }

    /** True only inside run(). Tenant scopes consult this and nothing else. */
    public function isActive(): bool
    {
        return $this->active;
    }

    /** The reason for the bypass currently in effect, if any. */
    public function reason(): ?string
    {
        return $this->reason;
    }
}
