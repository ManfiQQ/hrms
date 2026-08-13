<?php

namespace App\Http\Requests\Employee;

use App\Models\Employee;
use App\Models\Scopes\TenantScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Editing an employee record — `employee-master.spec.md` §5.1.
 *
 * ⚠ FOUR FIELDS ARE ABSENT FROM THESE RULES, EACH FOR ITS OWN REASON, and adding any of them
 * back would reopen something that was closed deliberately:
 *
 *   phone_no      NOT ON THIS RECORD AT ALL (§6.4, adr/0006). It is the login username and
 *                 lives on `users`. Changing it is an ACCOUNT operation, done from the
 *                 account management screen where password reset and unlock sit — because it
 *                 is a credential change, not a profile edit. The field leaves the form
 *                 entirely rather than carrying a role check: a greyed-out box invites "why
 *                 can't I edit this?" every time somebody opens the page.
 *
 *   employee_no   Master Admin only, audited, and it is not an ordinary field edit (BR-13).
 *                 A number vacated by a correction is burned, never reissued.
 *
 *   staff_status  Goes through App\Actions\Employee\ChangeEmployeeStatus, which validates the
 *                 BR-2 lifecycle and writes the ledger row, the audit row and — for a
 *                 terminal status — the account freeze, all in one transaction. An
 *                 $employee->update() here would skip every one of them, and BR-A17's expiry
 *                 would have nothing to count from.
 *
 *   company_id    A transfer cascades four child tables and is audited with the actor's
 *                 identity, because it reassigns statutory responsibility for EPF, SOCSO and
 *                 the EA Form between two legal entities (§5.7, BR-17). An edit path that let
 *                 it change like any other column would miss the cascade entirely.
 */
class EmployeeUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $employee = $this->route('employee');

        return $employee instanceof Employee
            && ($this->user()?->can('update', $employee) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $employee = $this->route('employee');
        $id = $employee instanceof Employee ? $employee->id : null;

        return [
            'full_name' => ['sometimes', 'required', 'string', 'max:255'],
            'nickname' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],

            // Org placement stays independent of the employer — BR-12 again, on the edit path
            // as much as on creation.
            'department_id' => ['sometimes', 'required', 'integer', 'exists:departments,id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'position_id' => ['nullable', 'integer', 'exists:positions,id'],

            'fingerprint_id' => ['nullable', 'string', 'max:255', Rule::unique('employees', 'fingerprint_id')->ignore($id)],

            'level' => ['sometimes', 'required', Rule::in(['STAFF', 'SUPERVISOR', 'MANAGER', 'HOD'])],
            'employment_type' => ['sometimes', 'required', Rule::in(['FULL-TIME', 'PART-TIME', 'CONTRACT', 'INTERN', 'FREELANCE'])],

            'join_date' => ['nullable', 'date'],
            'probation_end_date' => ['nullable', 'date'],
            'confirmation_date' => ['nullable', 'date', 'after_or_equal:probation_end_date'],

            // BR-8 — neither may be the employee themselves, and the chain must not cycle.
            'direct_supervisor_id' => ['nullable', 'integer', 'exists:employees,id', $this->notSelf($id), $this->noCycle($id)],
            'manager_id' => ['nullable', 'integer', 'exists:employees,id', $this->notSelf($id)],

            'attendance_type' => ['sometimes', 'required', Rule::in(['FIXED', 'FLEXIBLE'])],
            'work_start_time' => ['sometimes', 'required', 'date_format:H:i:s,H:i'],
            'work_end_time' => ['sometimes', 'required', 'date_format:H:i:s,H:i', 'after:work_start_time'],
            'ot_after_time' => ['nullable', 'required_if:attendance_type,FIXED', 'date_format:H:i:s,H:i'],

            'working_days' => ['sometimes', 'required', 'array', 'min:1'],
            'working_days.*' => [Rule::in(['MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT', 'SUN'])],
            'offday' => ['sometimes', 'required', 'array'],
            'offday.*' => [Rule::in(['MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT', 'SUN'])],

            'hours_enabled' => ['boolean'],
        ];
    }

    /** BR-8 — nobody reports to themselves. */
    private function notSelf(?int $id): callable
    {
        return function (string $attribute, mixed $value, callable $fail) use ($id) {
            if ($id !== null && (int) $value === $id) {
                $fail('An employee cannot be their own supervisor or manager (BR-8).');
            }
        };
    }

    /**
     * BR-8 — the supervisor chain must not form a cycle.
     *
     * ⚠ A cycle does not error anywhere on its own. It produces an approval chain that never
     * terminates, and the routing engine would follow it until something else gave way —
     * which is why the rule says "validated on save" rather than leaving it to the consumer.
     *
     * Walks upward from the proposed supervisor. Scope is lifted because the chain may cross
     * companies through a shared department, and a cycle hidden by the reader's scope is
     * still a cycle. The step counter bounds the walk against a cycle that already exists in
     * the data rather than trusting the data to be sound.
     */
    private function noCycle(?int $id): callable
    {
        return function (string $attribute, mixed $value, callable $fail) use ($id) {
            if ($id === null || $value === null) {
                return;
            }

            $seen = [];
            $current = (int) $value;

            while ($current !== 0 && ! isset($seen[$current])) {
                if ($current === $id) {
                    $fail('This supervisor is already below the employee in the reporting '
                        .'chain, which would form a cycle (BR-8).');

                    return;
                }

                $seen[$current] = true;

                $current = (int) (Employee::withoutGlobalScope(TenantScope::class)
                    ->whereKey($current)
                    ->value('direct_supervisor_id') ?? 0);
            }
        };
    }
}
