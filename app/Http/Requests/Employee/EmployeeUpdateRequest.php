<?php

namespace App\Http\Requests\Employee;

use App\Models\Employee;
use App\Models\Scopes\TenantScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

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

            // The rejoiner link (BR-13, adr/0003 decision 9) — editable because it is
            // routinely discovered after registration, when somebody recognises the returning
            // employee. See EmployeeStoreRequest for why it is neither tenant-scoped nor
            // filtered on deleted_at, and for the two rules deliberately not written.
            'previous_employee_id' => ['nullable', 'integer', 'exists:employees,id', $this->notPreviouslyThemselves($id)],

            // ⚠ AT LEAST ONE OF THE TWO, EVALUATED AGAINST THE RECORD'S FINAL STATE — adr/0013
            // decision 2. See identityDocumentRule() below for the whole of the reasoning; the
            // short version is that this form PATCHES, so the rule cannot ask what the payload
            // contains. It has to ask what the record will hold once the payload is applied.
            'ic_no' => [$this->identityDocumentRule($employee), 'string', 'max:255', Rule::unique('employees', 'ic_no')->ignore($id)],
            'passport_no' => [$this->identityDocumentRule($employee), 'string', 'max:255', Rule::unique('employees', 'passport_no')->ignore($id)],

            // No `after:today` bound — an expired permit is a flag, never a block (adr/0013
            // decision 4). Same reasoning as EmployeeStoreRequest.
            'permit_expiry' => ['nullable', 'date'],

            // The six that arrive in stages, over weeks — see EmployeeStoreRequest. This is
            // the form they arrive ON: a bank number one week, EPF the next month, SOCSO after
            // that, each a separate visit filling one field.
            'address' => ['nullable', 'string', 'max:65535'],
            'epf_no' => ['nullable', 'string', 'max:255'],
            'socso_no' => ['nullable', 'string', 'max:255'],
            'tax_no' => ['nullable', 'string', 'max:255'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'bank_account_no' => ['nullable', 'string', 'max:255'],

            // The three NOT NULL identity columns (adr/0013 decision 1). `sometimes` because
            // this form patches — but once present they may not be emptied, since the column
            // refuses null and a blank field would return a raw constraint violation.
            'date_of_birth' => ['sometimes', 'required', 'date', 'before:today'],
            'gender' => ['sometimes', 'required', Rule::in(['MALE', 'FEMALE'])],

            // ⚠ THE ONE VALUE A WITHDRAWN NATIONALITY IS STILL ACCEPTED AS IS THE ONE THIS
            // EMPLOYEE ALREADY HOLDS, and the asymmetry with EmployeeStoreRequest is the whole
            // point. Refusing withdrawn values outright here would mean that the moment HR
            // withdraws `Myanmar`, every employee holding it becomes UNEDITABLE — an edit to
            // their bank account or address resubmits the nationality they already have and is
            // rejected on a field the user never touched, with nothing on screen explaining
            // why. Accepting withdrawn values outright would make the withdrawal decorative on
            // this path instead.
            //
            // So: keep what you hold, never move to one that has been withdrawn.
            'nationality_id' => ['sometimes', 'required', 'integer', $this->selectableNationality($employee)],

            // Org placement stays independent of the employer — BR-12 again, on the edit path
            // as much as on creation.
            'department_id' => ['sometimes', 'required', 'integer', 'exists:departments,id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'position_id' => ['nullable', 'integer', 'exists:positions,id'],

            'fingerprint_id' => ['nullable', 'string', 'max:255', Rule::unique('employees', 'fingerprint_id')->ignore($id)],

            'level' => ['sometimes', 'required', Rule::in(Employee::LEVELS)],
            'employment_type' => ['sometimes', 'required', Rule::in(Employee::EMPLOYMENT_TYPES)],

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

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            // ⚠ `required` is the MECHANISM, not the rule, so the default wording — "the ic no
            // field is required" — would be a lie about a nullable column. Both keys carry the
            // same sentence, and it names the pair.
            'ic_no.required' => 'An employee must hold at least one form of identification: '
                .'this record would be left with neither an IC number nor a passport number '
                .'(adr/0013 decision 2).',
            'passport_no.required' => 'An employee must hold at least one form of '
                .'identification: this record would be left with neither an IC number nor a '
                .'passport number (adr/0013 decision 2).',
        ];
    }

    /**
     * `required` or `nullable` for `ic_no` and `passport_no` — adr/0013 decision 2, on a form
     * that patches.
     *
     * ⚠ THE RULE IS ABOUT THE RECORD'S FINAL STATE, NOT ABOUT THE PAYLOAD. `required_without`,
     * which is what EmployeeStoreRequest uses, reads the payload alone — correct there, because
     * a registration IS the whole record. Here it would reject an edit to a bank account number
     * for holding no IC, on a record that has held one for a year.
     *
     * So the final state is computed first: the submitted value where a key is present, the
     * STORED value where it is not. The stored half is read from the ROUTE MODEL, never from
     * the request — the same defence selectableNationality() makes, and for the same reason.
     *
     * ⚠ IT RETURNS `required` RATHER THAN A CLOSURE BECAUSE `required` IS IMPLICIT. Laravel
     * skips a non-implicit rule when the value is null and `nullable` is present, and it skips
     * it entirely when the key is absent — which are the two cases that matter here. A closure
     * would be silent in exactly the situation it was written for: HR clearing the last
     * identity document by submitting it empty.
     *
     * ⚠ AND IT FIRES ONLY WHEN THE PAYLOAD TOUCHES ONE OF THE TWO. An edit that names neither
     * passes, even on a record holding neither. That is deliberate, and it is two arguments:
     *
     *   A record can only ENTER that state by having a document emptied, which means the field
     *   was submitted, which means this rule ran. Every good → bad transition is covered.
     *
     *   Checking unconditionally would make a record that already holds neither — a legacy
     *   import, `CLAUDE.md` §10 question (f) — permanently UNEDITABLE, rejecting an address
     *   correction on a field the user never touched with nothing on screen to explain it. That
     *   is the failure selectableNationality() exists to avoid, one column over.
     *
     * Validation constrains what arrives next; it does not repair what is already stored.
     */
    private function identityDocumentRule(?Employee $employee): string
    {
        if (! $this->has('ic_no') && ! $this->has('passport_no')) {
            return 'nullable';
        }

        $ic = $this->has('ic_no') ? $this->input('ic_no') : $employee?->ic_no;
        $passport = $this->has('passport_no') ? $this->input('passport_no') : $employee?->passport_no;

        return blank($ic) && blank($passport) ? 'required' : 'nullable';
    }

    /**
     * BR-13 — a rejoiner's new record points at their OLD one, so it can never point at itself.
     *
     * ⚠ notSelf() is NOT reused here despite doing the same comparison. Its message reads "an
     * employee cannot be their own supervisor or manager (BR-8)", which would be a false
     * statement about a different rule shown to whoever typed this field.
     */
    private function notPreviouslyThemselves(?int $id): callable
    {
        return function (string $attribute, mixed $value, callable $fail) use ($id) {
            if ($id !== null && (int) $value === $id) {
                $fail('An employee cannot be their own previous record. A rejoiner is a new '
                    .'record referencing the old one (BR-13, adr/0003 decision 9).');
            }
        };
    }

    /**
     * A nationality this employee may be saved with: any that is not withdrawn, plus the one
     * they already hold even if it has been (adr/0013 decision 6).
     *
     * ⚠ The current value is read from the ROUTE MODEL, never from the request. Taking it from
     * the payload would let a crafted request name any withdrawn row as "the one I already
     * hold" and the exception would swallow the rule whole.
     */
    private function selectableNationality(?Employee $employee): Exists
    {
        $current = $employee?->nationality_id;

        return Rule::exists('nationalities', 'id')->where(function ($query) use ($current) {
            $query->where(function ($inner) use ($current) {
                $inner->whereNull('deleted_at');

                if ($current !== null) {
                    $inner->orWhere('id', $current);
                }
            });
        });
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
