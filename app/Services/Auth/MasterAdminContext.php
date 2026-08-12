<?php

namespace App\Services\Auth;

use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
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
 * ✅ COMPLETE since 2026-08-12 — the audit half is implemented.
 *
 * adr/0005 decision 5 requires every bypass to be written to `audit_logs`, and it is: the
 * reason is no longer captured and dropped. Both halves of that decision now hold —
 * "explicit, never ambient" AND "audited". The write happens BEFORE the callback runs, and
 * in its own transaction, because the bypass HAPPENED whether or not the work inside it
 * succeeded; a record that disappears when the callback throws would lose exactly the case
 * worth reviewing.
 *
 * ⚠ An authenticated user is REQUIRED to enter the context, and that follows from the audit
 * requirement rather than being a new restriction. A bypass nobody can be attributed to is
 * precisely what decision 5 forbids, and audit_logs.auditable_type/_id are NOT NULL — there
 * would be no subject to record. Console contexts lose nothing: with no authenticated user
 * the tenant scopes already run unscoped, so there is nothing there for this class to lift.
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

        $actor = auth()->user();

        // ⚠ Not a convenience check. audit_logs needs a subject and an actor, and a bypass
        // that cannot be attributed to anyone is the ambient bypass decision 5 rejects.
        if ($actor === null) {
            throw new RuntimeException(
                'A Master Admin tenant-scope bypass requires an authenticated account: the '
                .'bypass is written to audit_logs and must be attributable (adr/0005 '
                .'decision 5). Console and queue contexts have no user, and need no bypass — '
                .'the tenant scopes already run unscoped there.'
            );
        }

        $previousActive = $this->active;
        $previousReason = $this->reason;

        // ⚠ Written BEFORE the callback and in its own transaction, so the record survives a
        // callback that throws. The bypass happened either way, and the failed one is the
        // more interesting of the two. AuditLogger requires a transaction (BR-AT12) and never
        // opens one itself, so opening it is this caller's job.
        //
        // The subject is the ACCOUNT that bypassed — one of the users-row subjects BR-AT3's
        // polymorphic column exists for. company_id resolves to null for a Master Admin, who
        // belongs to no company, which is what makes this a system-level row visible to
        // Master Admin alone (§11).
        DB::transaction(fn () => app(AuditLogger::class)->record(
            action: 'master_admin.scope_bypass',
            subject: $actor,
            field: 'tenant_scope',
            old: 'scoped',
            new: 'bypassed',
            reason: $reason,
        ));

        $this->active = true;
        $this->reason = $reason;

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
