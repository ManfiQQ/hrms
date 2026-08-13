<?php

namespace App\Actions\Employee;

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeStatusHistory;
use App\Models\Position;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The three remaining `change_type` values — POSITION, DEPARTMENT and LEVEL
 * (`employee-master.spec.md` §5.3). STAFF_STATUS is ChangeEmployeeStatus.
 *
 * ⚠ ONE ACTION FOR THREE FIELDS, because the mechanic is identical and three copies would
 * drift. What differs between them is only how the LABEL is resolved, which is a three-line
 * map rather than three classes.
 *
 * ⚠ THE LEDGER ROW IS WRITTEN HERE, IN THE SAME TRANSACTION AS THE UPDATE, so the caller
 * cannot forget it — which is what §5.3 means by "the service does it, not the controller".
 * An `$employee->update(['department_id' => 7])` in a controller writes no history at all,
 * and the loss is invisible: the record simply has no answer to "when did this person move
 * to Logistics", exactly as the legacy system's flat fields had none.
 *
 * ⚠ DEPARTMENT IS NOT A COMPANY TRANSFER. Moving departments leaves `employees.company_id`
 * alone and cascades nothing; a transfer between group entities is App\Actions\Employee\
 * TransferCompany, which does not exist yet (§5.7, BR-17).
 *
 * ⚠ AND `CORE_ROLE` IS NOT AMONG THE FOUR TYPES. A role change writes ONLY the
 * `employee_roles` pivot row — a service that also appended a ledger row here would be
 * wrong (`adr/0003` decision 8).
 */
class ChangeEmployeeAssignment
{
    /**
     * ⚠ BR-AT13's declaration. Every pair must also appear in App\Support\Audit\AuditedFields,
     * and the architecture test fails in both directions.
     */
    public const AUDITS = [
        Employee::class => ['position_id', 'department_id', 'level'],
    ];

    /** change_type => the column it moves. */
    private const FIELDS = [
        'POSITION' => 'position_id',
        'DEPARTMENT' => 'department_id',
        'LEVEL' => 'level',
    ];

    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  string  $changeType  POSITION, DEPARTMENT or LEVEL
     * @param  int|string|null  $newValue  the new position id, department id, or level enum
     * @param  string  $effectiveDate  when the change APPLIES — distinct from today, because a
     *                                 promotion is typically effective before HR enters it
     *
     * @throws InvalidArgumentException
     */
    public function execute(
        Employee $employee,
        string $changeType,
        int|string|null $newValue,
        string $effectiveDate,
        ?string $reason = null,
    ): Employee {
        if (! array_key_exists($changeType, self::FIELDS)) {
            throw new InvalidArgumentException(
                "{$changeType} is not one of this Action's change types: "
                .implode(', ', array_keys(self::FIELDS)).'. STAFF_STATUS goes through '
                .'ChangeEmployeeStatus, which also handles the BR-2 lifecycle and the account '
                .'freeze. CORE_ROLE is deliberately not a change type at all (adr/0003 '
                .'decision 8).'
            );
        }

        $field = self::FIELDS[$changeType];
        $oldValue = $employee->{$field};

        // A no-op is not a change, and writing a ledger row for one would put an event in the
        // record that never happened — the same refusal ChangeEmployeeStatus makes.
        if ((string) $oldValue === (string) $newValue) {
            throw new InvalidArgumentException(
                "This employee's {$field} is already ".var_export($newValue, true).'. A no-op '
                .'is not a change, and the ledger records only what happened.'
            );
        }

        if ($changeType === 'LEVEL' && ! in_array($newValue, ['STAFF', 'SUPERVISOR', 'MANAGER', 'HOD'], true)) {
            throw new InvalidArgumentException(
                var_export($newValue, true).' is not a level. The four are STAFF, SUPERVISOR, '
                .'MANAGER, HOD — and level is DISPLAY ONLY: it never drives an authorization '
                .'or routing decision, which is employee_roles (BR-9, adr/0001 decision 1).'
            );
        }

        return DB::transaction(function () use ($employee, $field, $changeType, $oldValue, $newValue, $effectiveDate, $reason) {
            $employee->{$field} = $newValue;
            $employee->updated_by = auth()->id();
            $employee->save();

            EmployeeStatusHistory::create([
                // Frozen at today's employer — an event fact, never cascaded on a later
                // transfer (adr/0003 decision 7).
                'company_id' => $employee->company_id,
                'employee_id' => $employee->id,
                'change_type' => $changeType,
                'old_value' => $oldValue === null ? null : (string) $oldValue,
                'new_value' => (string) $newValue,

                // ⚠ A SNAPSHOT OF THE DISPLAY TEXT AT THE TIME. Storing department_id = 7 alone
                // would need a join to render, and that join shows the department's name
                // TODAY, not its name THEN — so renaming a department would retroactively
                // rewrite history, and a ledger that changes retroactively is not a ledger.
                'old_label' => $this->label($changeType, $oldValue),
                'new_label' => $this->label($changeType, $newValue) ?? (string) $newValue,

                'effective_date' => $effectiveDate,
                'reason' => $reason,
                'changed_by' => auth()->id(),
            ]);

            // ⚠ NOT a mirror of the ledger row. The ledger answers "what was this employee's
            // department on that date"; the audit log answers "who changed it and why" —
            // two different facts about one event, which the audit report merges on display
            // rather than storing either twice (audit-trail.spec.md BR-AT5).
            $this->audit->record(
                action: 'employee.assignment_change',
                subject: $employee,
                field: $field,
                old: $oldValue === null ? null : (string) $oldValue,
                new: (string) $newValue,
                reason: $reason,
            );

            return $employee;
        });
    }

    /**
     * The display text for a value, resolved NOW so it can be frozen.
     *
     * Scope is lifted for the lookups: a shared department belongs to no single company and a
     * position may sit under one, so the reader's tenant scope must not decide whether the
     * ledger gets a readable label (`adr/0002`).
     */
    private function label(string $changeType, int|string|null $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return match ($changeType) {
            // Redundant for an enum — LEVEL's label equals its value. Accepted: one uniform
            // row shape costs a few bytes and avoids per-type branching in every reader.
            'LEVEL' => (string) $value,

            'POSITION' => Position::withoutGlobalScopes()->whereKey($value)->value('title'),
            'DEPARTMENT' => Department::withoutGlobalScopes()->whereKey($value)->value('name'),

            default => null,
        };
    }
}
