<?php

namespace App\Services\Auth;

use App\Models\Employee;
use App\Models\Scopes\TenantScope;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * BR-A17 — an account expires ten days after the employee's last working day.
 *
 * ⚠ COUNTED FROM `effective_date`, NEVER FROM WHEN HR TYPED THE CHANGE. A resignation
 * entered three weeks late would otherwise hand the person three extra weeks of access, and
 * one entered early would cut them off before they had finished working. The date the change
 * APPLIES and the date it was RECORDED are different facts, and `employee_status_history`
 * carries both precisely so this rule can use the right one.
 *
 * All data remains in the system. Expiry closes the account, not the record — the employment
 * history, documents and payroll rows a terminated employee leaves behind are subject to
 * statutory retention (`CLAUDE.md` §3) and are never touched by this.
 *
 * ⚠ This class decides ONLY whether an account has run out of time. The freeze itself
 * (BR-A15 — revoking roles, killing sessions on TERMINATED, notifying the Approval Engine)
 * belongs to the status-change Action, which does not exist yet.
 */
class AccountExpiry
{
    /** Statuses that start the countdown (BR-A2: both are terminal). */
    public const TERMINAL_STATUSES = ['RESIGNED', 'TERMINATED'];

    private const WINDOW_KEY = 'auth.account.expiry_days';

    public function __construct(private readonly AuthPolicy $policy) {}

    /**
     * Has this account run out of its post-employment window?
     *
     * ⚠ The window is INCLUSIVE of its last day. With a ten-day window and a last working
     * day of the 1st, the account works through the 11th and is gone on the 12th — ten whole
     * days of access after the employment ended, which is what "ten days after" means to the
     * person living it. The boundary is asserted on both sides in the tests, because an
     * off-by-one here either cuts somebody off a day early or leaves an ex-employee reading
     * records a day too long.
     */
    public function hasExpired(User $user): bool
    {
        $expiresAfter = $this->expiresAfter($user);

        return $expiresAfter !== null && now()->startOfDay()->greaterThan($expiresAfter);
    }

    /**
     * The last day this account still works, or null if it is not counting down.
     *
     * Null means the account has no terminal status — the ordinary case, and also Master
     * Admin and the Director, who hold no employee record and therefore never expire
     * (adr/0001 decision 4).
     */
    public function expiresAfter(User $user): ?CarbonInterface
    {
        $effectiveDate = $this->terminalEffectiveDate($user);

        if ($effectiveDate === null) {
            return null;
        }

        return $effectiveDate->copy()
            ->addDays($this->policy->int(self::WINDOW_KEY, $user))
            ->startOfDay();
    }

    /**
     * The `effective_date` of the most recent terminal status change.
     *
     * ⚠ Read through the employee relationship, which RELEASES the tenant scope
     * (conventions.md §2's second carve-out). That is not an optimisation: on an event table
     * `company_id` is frozen at the employer of the day, so a person who transferred and then
     * resigned has rows under two companies. A scoped read could miss the very row the
     * countdown depends on, and would then report the account as not expiring at all.
     *
     * ⚠ The LATEST such row wins, not the first. A status set in error and corrected is a new
     * row (the ledger is append-only), so the correction must be the one that counts.
     */
    private function terminalEffectiveDate(User $user): ?CarbonInterface
    {
        $employee = $this->employeeFor($user);

        if ($employee === null || ! in_array($employee->staff_status, self::TERMINAL_STATUSES, true)) {
            return null;
        }

        // ⚠ If the employee holds a terminal status but the ledger has no row for it, this
        // returns null and the account NEVER EXPIRES — it stays frozen and readable instead.
        // That is wider than BR-A17 requires, and it is a real dependency rather than an
        // oversight: employee-master.spec.md §5.3 makes the status-change service write the
        // ledger row, so a terminal status set through the application always has one.
        // Asserted by test so it is a known state, not a surprise.
        //
        // ⚠ Amended 2026-08-19 — two sentences were withdrawn here, not any behaviour. This
        // comment read that §5.3 writes the ledger row "in the same transaction as the
        // change, so the two cannot come apart", and that "until that Action exists no
        // terminal status can be set through the application at all". `ChangeEmployeeStatus`
        // has existed since 2026-08-12, and §5.3.1 now splits the write by date: a terminal
        // status effective LATER THAN TODAY writes the ledger row and deliberately leaves
        // `staff_status` untouched until the scheduled task completes it. The two therefore
        // DO come apart, by design, for a bounded window.
        //
        // This method is unaffected, and §5.3.3 is why: throughout that window `staff_status`
        // is not yet terminal, so the early return above fires and nothing below is reached —
        // expiresAfter() is null and the account does not expire, which is the right answer
        // for an employee who has not left. Do not build on the withdrawn wording.
        return $employee->statusHistory()
            ->where('change_type', 'STAFF_STATUS')
            ->whereIn('new_value', self::TERMINAL_STATUSES)
            // ⚠ reorder(), not orderByDesc(). Employee::statusHistory() already applies
            // `orderBy('effective_date')` ascending for the history tab, and an appended
            // descending clause does not replace it — the first ORDER BY wins, so value()
            // would quietly return the OLDEST terminal row. That reads as an account
            // expiring from a superseded status, which is exactly the correction case below.
            ->reorder('effective_date', 'desc')
            ->orderByDesc('id')          // same-day correction: the later row wins
            ->value('effective_date');
    }

    /**
     * ⚠ Loaded WITHOUT TenantScope, the same carve-out ReadScopeResolver takes. This runs on
     * every authenticated request, and the account being evaluated may be one whose own read
     * scope is about to be denied — resolving *who you are* must not depend on *what you may
     * see*.
     */
    private function employeeFor(User $user): ?Employee
    {
        if ($user->employee_id === null) {
            return null;
        }

        return Employee::withoutGlobalScope(TenantScope::class)->find($user->employee_id);
    }
}
