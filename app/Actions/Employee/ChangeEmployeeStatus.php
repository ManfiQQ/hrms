<?php

namespace App\Actions\Employee;

use App\Events\Auth\AccountFrozen;
use App\Exceptions\Employee\InvalidStatusTransitionException;
use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\EmployeeStatusHistory;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\AccountExpiry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * The one way an employee's `staff_status` changes — employee-master.spec.md §5.3, BR-2, and
 * auth-rbac.spec.md BR-A15.
 *
 * ⚠ EVERYTHING BELOW HAPPENS IN ONE TRANSACTION, and that is the whole design. The ledger
 * row, the role revocations, the session kill and the audit rows either all land or none do.
 * A caller cannot forget any of them, because the caller does not perform any of them — this
 * Action does, which is exactly what §5.3 means by "the service does it, not the controller".
 *
 * ⚠ AND THE LEDGER ROW IS NOT OPTIONAL. BR-A17's account expiry counts ten days from
 * `employee_status_history.effective_date`; a terminal status written WITHOUT its ledger row
 * has nothing to count from, so the account never expires and keeps read access
 * indefinitely — wider than the rule allows, with nothing to notice. That failure is the
 * reason this Action exists rather than an `$employee->update()` call in a controller.
 */
class ChangeEmployeeStatus
{
    /**
     * ⚠ BR-AT13's declaration. Every pair here must also appear in
     * App\Support\Audit\AuditedFields, which is the canonical list, and the architecture test
     * fails in either direction — a registry entry with no Action behind it, or an Action
     * auditing something nobody wrote down.
     */
    public const AUDITS = [
        Employee::class => ['staff_status'],
    ];

    /** BR-2's lifecycle: PROBATION → ACTIVE/CONFIRMED → SUSPENDED → RESIGNED/TERMINATED. */
    private const PERMITTED = [
        'PROBATION' => ['ACTIVE', 'CONFIRMED', 'SUSPENDED', 'RESIGNED', 'TERMINATED'],
        'ACTIVE' => ['CONFIRMED', 'SUSPENDED', 'RESIGNED', 'TERMINATED'],
        'CONFIRMED' => ['SUSPENDED', 'RESIGNED', 'TERMINATED'],
        'SUSPENDED' => ['ACTIVE', 'CONFIRMED', 'RESIGNED', 'TERMINATED'],

        // ⚠ Terminal. Empty on purpose, and it must stay empty: reinstatement is a NEW
        // employee record referencing the old one, never a status flip back (BR-2, BR-A18).
        'RESIGNED' => [],
        'TERMINATED' => [],
    ];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly AccountExpiry $expiry,
    ) {}

    /**
     * @param  string  $effectiveDate  the date the change APPLIES — the last working day for a
     *                                 terminal status. Distinct from today: a resignation is
     *                                 often entered after the fact, and BR-A17 counts from
     *                                 this, not from when HR typed it.
     *
     * @throws InvalidStatusTransitionException
     */
    public function execute(
        Employee $employee,
        string $newStatus,
        string $effectiveDate,
        ?string $reason = null,
    ): Employee {
        $oldStatus = $employee->staff_status;

        // Refused before the transaction opens: nothing to roll back, and the caller gets
        // the same answer whether or not a database is reachable.
        $this->assertPermitted($oldStatus, $newStatus);

        return DB::transaction(function () use ($employee, $oldStatus, $newStatus, $effectiveDate, $reason) {
            $employee->staff_status = $newStatus;
            $employee->save();

            // §5.3 — written by the service so the caller cannot forget it. Rows are never
            // edited or deleted; a correction is a new row.
            EmployeeStatusHistory::create([
                'company_id' => $employee->company_id,   // frozen at today's employer
                'employee_id' => $employee->id,
                'change_type' => 'STAFF_STATUS',
                'old_value' => $oldStatus,
                'old_label' => $oldStatus,
                'new_value' => $newStatus,
                'new_label' => $newStatus,
                'effective_date' => $effectiveDate,
                'reason' => $reason,
                'changed_by' => auth()->id(),
            ]);

            // ⚠ NOT a mirror of the ledger row (audit-trail.spec.md BR-AT5). The ledger
            // answers "what was this employee's status on that date"; the audit log answers
            // "who changed it and why". adr/0003 decision 8 forbids writing the same FACT
            // twice — these are two different facts about one event, and the audit report
            // merges them on display rather than storing either one twice.
            $this->audit->record(
                action: 'employee.status_change',
                subject: $employee,
                field: 'staff_status',
                old: $oldStatus,
                new: $newStatus,
                reason: $reason,
            );

            if (in_array($newStatus, AccountExpiry::TERMINAL_STATUSES, true)) {
                $this->freeze($employee, $newStatus, $reason);
            }

            return $employee;
        });
    }

    /**
     * BR-A15 — a terminal status freezes the account immediately, in this same transaction.
     *
     * ⚠ Not a queued job and not an observer side effect. If the status change rolls back,
     * so does all of this — otherwise a failed transition could leave an employee with their
     * status intact and every one of their roles revoked.
     */
    private function freeze(Employee $employee, string $status, ?string $reason): void
    {
        // Every role revoked. Rows are never deleted — revocation is `revoked_date` alone,
        // and the history stays readable (adr/0003 decision 1).
        EmployeeRole::query()
            ->where('employee_id', $employee->id)
            ->update([
                'revoked_date' => now()->toDateString(),
                'revoked_by' => auth()->id(),
                'revoke_reason' => $reason ?? "Employment ended: {$status}.",
            ]);

        // ⚠ SESSIONS ARE KILLED FOR TERMINATED ONLY, and the asymmetry is deliberate
        // (adr/0004 decision 5). Termination may follow serious misconduct, and waiting for
        // the person's next request — which may never come while a screen sits open — leaves
        // access in their hands. A resigning employee is typically still working, and cutting
        // their session mid-task achieves nothing, since they may log back in as a frozen
        // account regardless.
        if ($status === 'TERMINATED') {
            $this->terminateSessions($employee);
        }

        // ⚠ The trigger is this module's; the ROUTING is the Approval Engine's, and that spec
        // does not exist. Nothing listens yet (BR-A16).
        Event::dispatch(new AccountFrozen($employee, $status));
    }

    /**
     * `DELETE FROM sessions WHERE user_id = ?` — the reason BR-A5 chose database sessions.
     * File sessions cannot be located by user without reading every file, so "immediately"
     * would in practice mean "on their next request".
     */
    private function terminateSessions(Employee $employee): void
    {
        $userId = $employee->user()->value('id');

        if ($userId === null) {
            return;
        }

        $deleted = DB::table(config('session.table', 'sessions'))
            ->where('user_id', $userId)
            ->delete();

        if ($deleted === 0) {
            return;
        }

        // BR-A15 requires the deletion itself to be audited: it is the moment someone's
        // access was taken away, and it happened without their involvement.
        $this->audit->record(
            action: 'employee.sessions_terminated',
            subject: $employee,
            field: 'sessions',
            old: (string) $deleted,
            new: '0',
            reason: 'Employment terminated: active sessions ended immediately (BR-A15).',
        );
    }

    /**
     * @throws InvalidStatusTransitionException
     */
    private function assertPermitted(string $from, string $to): void
    {
        if ($from === $to) {
            // A no-op is not a transition, and writing a ledger row for one would put an
            // event in the record that never happened.
            throw InvalidStatusTransitionException::between($from, $to);
        }

        if (self::PERMITTED[$from] === []) {
            throw InvalidStatusTransitionException::fromTerminal($from, $to);
        }

        if (! in_array($to, self::PERMITTED[$from] ?? [], true)) {
            throw InvalidStatusTransitionException::between($from, $to);
        }
    }
}
