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

            // ⚠ THE REJOINER LINK — BR-13, adr/0003 decision 9. A rejoining employee gets a
            // NEW record with a NEW employee_no, never a reactivated one, and this column is
            // what ties the two together. BR-2 has required that reference since before the
            // column existed; until this rule landed there was no way to supply it.
            //
            // ⚠ NEITHER TENANT-SCOPED NOR FILTERED ON deleted_at, both deliberately. `exists`
            // runs on the query builder, so TenantScope does not apply — a rejoiner may return
            // to a different group entity, and the prior record belongs to whoever employed
            // them then. An archived prior record is the ordinary case rather than an error.
            //
            // ⚠ NOTHING REQUIRES THE PRIOR RECORD TO HOLD A TERMINAL STATUS, and nothing makes
            // the link unique. Both are real questions — whether a rejoiner from an ACTIVE
            // record is a contradiction, whether two records may claim one predecessor — and
            // neither is decided anywhere. Inventing either here would put a business rule
            // nobody agreed to in the validation layer.
            'previous_employee_id' => ['nullable', 'integer', 'exists:employees,id'],

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
            // ⚠ AT LEAST ONE OF THE TWO — adr/0013 decision 2, whose 2026-08-15 amendment
            // deferred this rule to "the registration form" and recorded the cost of its
            // absence: *"an employee can be registered with neither an IC nor a passport, and
            // nothing anywhere objects."* That is closed here. It is not a timing rule — it is
            // the requirement that every person carry one form of identification.
            //
            // ⚠ IMPLICIT, AND THAT IS WHY IT IS `required_without` RATHER THAN A CLOSURE.
            // `required_without` sits in Validator::$implicitRules, so it fires even though
            // `nullable` stands beside it and even when the value arrives as null or as the
            // empty string a form posts for an untouched box. A closure would not fire at all
            // in those cases — `nullable` skips non-implicit rules on a null value — and the
            // null submission is precisely the case worth catching.
            //
            // Symmetric on purpose: with both missing, both boxes carry the message, because
            // either one satisfies the rule and the form should say so on both.
            //
            // ⚠ NO FORMAT RULE AND NO NORMALISATION, AND THE SECOND IS A KNOWN HOLE. An IC is
            // written with dashes about as often as without, and nothing normalises it the way
            // App\Support\Auth\PhoneNumber normalises the login username — so `900101-14-5501`
            // and `900101145501` are two values that both pass the unique index and are one
            // person. Recorded in conventions.md §9; fixing it changes stored values, which is
            // a migration and an ADR rather than a rule.
            //
            // ⚠ THE UNIQUE RULE BLOCKS EVERY REJOINER, AND IT IS NOT THE CAUSE. adr/0003
            // decision 9 gives a rejoiner a new record; they bring the same IC; the unique
            // INDEX refuses it with or without this line. All this rule changes is a raw
            // constraint violation into a message naming the field. See schema.md under
            // `ic_no` and adr/0003 decision 9 — the contradiction needs an ADR and has none.
            'ic_no' => ['nullable', 'required_without:passport_no', 'string', 'max:255', 'unique:employees,ic_no'],
            'passport_no' => ['nullable', 'required_without:ic_no', 'string', 'max:255', 'unique:employees,passport_no'],

            // ⚠ NO `after:today` BOUND, AND ADDING ONE WOULD BREAK A DECISION. An expired
            // permit blocks nothing, suspends nobody, and stops no record being used — it
            // raises a flag and, once the Notification Engine exists, notifies HR and the
            // employee (adr/0013 decision 4). Renewal is the response. A future-date rule here
            // would make a record that legitimately exists impossible to save.
            'permit_expiry' => ['nullable', 'date'],

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

            // ⚠ SIX NULLABLE COLUMNS, ON BOTH FORMS, AND THE REASON IS OPERATIONAL RATHER THAN
            // TECHNICAL. This information arrives IN STAGES: a bank number in the first week,
            // an EPF number a month later, SOCSO a week after that — three separate visits to
            // the edit form, each filling one field. Any one of them marked required would
            // block the HR clerk who came to enter a SOCSO number because the tax number is
            // still empty. adr/0013 decision 3 says the same thing from the schema's side: a
            // record without these is CORRECT, not incomplete.
            //
            // ⚠ AND THEY ARE ON THE REGISTRATION FORM, NOT ONLY THE EDIT FORM. EPF and SOCSO
            // numbers do not change with employer, so an experienced hire holds both on day
            // one; it is the first-time employee who has neither. Withholding the five until
            // the edit form would block the majority to accommodate the minority.
            //
            // ⚠ NO UNIQUENESS ON epf_no OR socso_no. The table carries no unique index on
            // either, and a uniqueness rule the database does not share holds only where it is
            // called — which is nowhere the importer or a seeder goes.
            //
            // ⚠ NO FORMAT RULES. EPF, SOCSO and LHDN formats vary by era and by registration
            // route, and nobody has decided which shapes are legitimate. A guessed regex here
            // rejects real numbers, which is worse than accepting an odd one.
            //
            // `address` is one text column and never parsed into components (adr/0013
            // decision 1). ⚠ `max:65535` restates the TEXT column, and the two do not measure
            // the same thing: MySQL bounds TEXT in BYTES, this rule counts CHARACTERS, so a
            // multibyte address at the extreme passes here and fails on insert. Real addresses
            // are nowhere near it, and a lower invented bound would be a business rule nobody
            // decided.
            'address' => ['nullable', 'string', 'max:65535'],
            'epf_no' => ['nullable', 'string', 'max:255'],
            'socso_no' => ['nullable', 'string', 'max:255'],
            'tax_no' => ['nullable', 'string', 'max:255'],

            // Where salary is SENT, never how much — Employee Master holds no salary at all
            // (§10 decision 3, adr/0003 decision 5), and neither column may become an opening
            // for some.
            'bank_name' => ['nullable', 'string', 'max:255'],
            'bank_account_no' => ['nullable', 'string', 'max:255'],

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

            // ⚠ Both keys carry the SAME sentence, and it names the pair rather than the
            // field. Laravel's own wording — "the ic no field is required when passport no is
            // not present" — states the mechanism and hides the rule; what HR needs to read is
            // that either box will do.
            'ic_no.required_without' => 'An employee must hold at least one form of '
                .'identification: fill in either the IC number or the passport number '
                .'(adr/0013 decision 2).',
            'passport_no.required_without' => 'An employee must hold at least one form of '
                .'identification: fill in either the IC number or the passport number '
                .'(adr/0013 decision 2).',
        ];
    }
}
