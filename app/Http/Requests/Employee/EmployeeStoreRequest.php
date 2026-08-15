<?php

namespace App\Http\Requests\Employee;

use App\Models\Employee;
use App\Services\Auth\ReadScopeResolver;
use App\Support\Auth\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Registering an employee — `employee-master.spec.md` §5.1.
 *
 * ⚠ ALL VALIDATION LIVES HERE, never inline in a controller (conventions.md §1). What does
 * NOT live here is the BR-2 status lifecycle: permitted transitions are enforced in the
 * service layer, because a rule enforced only at the edge is one an importer, a seeder or a
 * future API route walks straight past.
 */
class EmployeeStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', [Employee::class, (int) $this->input('company_id')]) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // ⚠ BOUNDED BY READ SCOPE, NOT TAKEN FROM INPUT ON TRUST. §5.1 says company_id
            // comes from the authenticated context and is never accepted from the request —
            // written when scope was assumed to be one company per account. adr/0004
            // decision 1 made it derived, so an AHS-employed HR legitimately registers into
            // any of the six and MUST choose. The rule is preserved by bounding the choice to
            // the resolved scope rather than trusting the field, and EmployeePolicy::create()
            // checks the same thing again on the authorisation side.
            'company_id' => ['required', 'integer', Rule::in(app(ReadScopeResolver::class)->resolve($this->user()))],

            // ⚠ NOT NULL. Every other record in the system identifies a person by this; an
            // employee master where the name is optional cannot do its one job.
            'full_name' => ['required', 'string', 'max:255'],
            'nickname' => ['nullable', 'string', 'max:255'],

            // Nullable and frequently absent — much of this workforce has no company email,
            // which is exactly why login runs on the phone number instead (adr/0004
            // decision 6).
            'email' => ['nullable', 'email', 'max:255'],

            // ⚠ THE LOGIN USERNAME, and it belongs to the ACCOUNT, not to this record
            // (adr/0006). It is required because BR-A20 creates an account in the same
            // transaction, and decision 7 requires every employee to hold one so they can
            // verify their own attendance — an employee with no account blocks payroll.
            //
            // ⚠ A placeholder is not the workaround. It would occupy the unique index and
            // hand one employee's username to another (BR-A1).
            'phone_no' => ['required', 'string', function (string $attribute, mixed $value, callable $fail) {
                if (! PhoneNumber::isValid(PhoneNumber::normalise((string) $value))) {
                    $fail('The phone number must be '.PhoneNumber::MIN_DIGITS.'–'.PhoneNumber::MAX_DIGITS
                        .' digits after normalisation. It is the login username (BR-A1).');
                }
            }],

            // ⚠ THE THREE NOT NULL IDENTITY COLUMNS — adr/0013 decision 1. These are here
            // because the DATABASE now refuses the row without them: a request that omitted
            // them would reach the insert and come back as a raw constraint violation, which
            // is a 500 to the user rather than a message naming the field.
            //
            // ⚠ The conditional "at least one of ic_no or passport_no" rule (decision 2) is
            // deliberately NOT here yet, and the difference is not arbitrary. These three
            // restate something the schema already enforces; that one enforces something the
            // database cannot know, and it needs the form to be tested meaningfully. adr/0013
            // records the deferral as a dated amendment.
            'date_of_birth' => ['required', 'date', 'before:today'],

            // ⚠ NO MINIMUM AGE RULE, AND ITS ABSENCE IS DELIBERATE RATHER THAN AN OVERSIGHT.
            // The Employment Act sets a minimum working age, but the exact bound — and what
            // happens to a legitimate under-18 apprentice record — is a business rule nobody
            // has decided here. `before:today` is the one bound that needs no decision: a date
            // of birth in the future is not a policy question, it is incoherent data.
            'gender' => ['required', Rule::in(['MALE', 'FEMALE'])],

            // ⚠ WITHDRAWN NATIONALITIES ARE REFUSED ON REGISTRATION. Deactivation is the soft
            // delete, and its whole purpose is to remove a value from the picker; a new record
            // that could still select one would make the withdrawal decorative. The edit path
            // answers this differently on purpose — see EmployeeUpdateRequest.
            'nationality_id' => ['required', 'integer', Rule::exists('nationalities', 'id')->whereNull('deleted_at')],

            // ⚠ NO `exists:companies` COUPLING BETWEEN THESE AND company_id, DELIBERATELY.
            // BR-12: an employee of TURSENIA sitting in the shared Logistics branch is a
            // CORRECT record, and validation must not reject it. Org placement is independent
            // of the payroll employer (adr/0002 decisions 2–3).
            'department_id' => ['required', 'integer', 'exists:departments,id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'position_id' => ['nullable', 'integer', 'exists:positions,id'],

            'fingerprint_id' => ['nullable', 'string', 'max:255', 'unique:employees,fingerprint_id'],

            // Display only — never an authorization or routing input (BR-9).
            'level' => ['required', Rule::in(Employee::LEVELS)],

            'employment_type' => ['required', Rule::in(Employee::EMPLOYMENT_TYPES)],

            // ⚠ The terminal statuses are EXCLUDED, and the exclusion is the rule rather than
            // an oversight: RESIGNED and TERMINATED freeze the account in the same transaction
            // and are reached through ChangeEmployeeStatus. A registration form is not where an
            // employment ends (BR-2, adr/0004 decision 5).
            'staff_status' => ['required', Rule::in(array_values(array_diff(Employee::STAFF_STATUSES, Employee::TERMINAL_STATUSES)))],

            'join_date' => ['nullable', 'date'],
            'probation_end_date' => ['nullable', 'date'],

            // BR-3: confirmation must not precede the end of probation.
            'confirmation_date' => ['nullable', 'date', 'after_or_equal:probation_end_date'],

            'direct_supervisor_id' => ['nullable', 'integer', 'exists:employees,id'],
            'manager_id' => ['nullable', 'integer', 'exists:employees,id'],

            'attendance_type' => ['required', Rule::in(['FIXED', 'FLEXIBLE'])],

            // Structured columns, never free text — the direct fix for the legacy
            // "9.00 AM - 5.00 PM" strings (conventions.md §4).
            'work_start_time' => ['required', 'date_format:H:i:s,H:i'],
            'work_end_time' => ['required', 'date_format:H:i:s,H:i', 'after:work_start_time'],

            // ⚠ REQUIRED WHEN FIXED, AND NULL OTHERWISE — schema.md rules out a DATABASE
            // constraint for this, because it is conditional on another column. It does not
            // rule out validation: a FormRequest IS the validation layer (conventions.md §1),
            // and required_if is exactly the shape of the rule.
            //
            // NULL means NOT APPLICABLE, not "unknown" and not "zero". Forcing a value for a
            // FLEXIBLE employee would put a real-looking time where future code reads a
            // genuine OT threshold, silently computing overtime for someone whose OT is
            // decided by a human.
            'ot_after_time' => ['nullable', 'required_if:attendance_type,FIXED', 'date_format:H:i:s,H:i'],

            'working_days' => ['required', 'array', 'min:1'],
            'working_days.*' => [Rule::in(['MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT', 'SUN'])],
            'offday' => ['required', 'array'],
            'offday.*' => [Rule::in(['MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT', 'SUN'])],

            'hours_enabled' => ['boolean'],
        ];
    }

    /**
     * ⚠ `employee_no` IS ABSENT FROM THE RULES ABOVE ON PURPOSE, and adding it would be a
     * defect rather than a feature.
     *
     * The number comes from the `sequences` row taken with lockForUpdate() inside the
     * employee insert's own transaction (BR-13). Accepting one from the request would let two
     * concurrent registrations claim the same number, which is the exact collision the locked
     * sequence exists to prevent — and `CreateEmployee` discards any caller-supplied value for
     * the same reason.
     *
     * `staff_status` above also omits RESIGNED and TERMINATED: they are terminal, they freeze
     * the account in the same transaction, and a registration form is not where an employment
     * ends (BR-2, adr/0004 decision 5).
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ot_after_time.required_if' => 'An OT threshold is required for FIXED attendance. '
                .'It is left empty only for FLEXIBLE, where overtime is applied by a human.',
        ];
    }
}
