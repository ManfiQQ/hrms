{{--
    Editing an employee — §5.1, §6.4.

    ⚠ TWO FIELDS ARE ABSENT FROM THIS FORM AND THEIR ABSENCE IS THE DESIGN, not an omission:

    - `phone_no` — the login username, a credential changed from the account management screen
      alone (§6.4, `adr/0006` decision 7). It is not rendered here under any condition.
    - `staff_status` — it has its own Action, `ChangeEmployeeStatus`, which validates the BR-2
      transition, writes the ledger row and performs the BR-A15 freeze together. A second path
      writing the same fact is the shape this project refuses.

    ⚠ EVERY FIELD IS RENDERED FROM `$editable`, WHICH IS THE POLICY'S ANSWER. Nothing here is a
    fixed list, so a tier that reads four fields is offered four to write.
--}}
<form wire:submit="save" class="space-y-10">

    <section class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
        <h2 class="mb-1 text-sm font-semibold tracking-tight text-slate-900">Assignment</h2>

        {{--
            ⚠ THE DATE APPLIES TO THE THREE LEDGER FIELDS BELOW, and it is deliberately not
            "today". A promotion is typically effective before HR enters it, and
            `employee_status_history` carries both dates precisely so rules can use the right one.
        --}}
        <p class="mb-4 text-xs text-slate-500">
            Changing the department, position or level writes a dated row to this employee's
            history. It cannot be undone by editing — a correction is a new row.
        </p>

        <div class="grid gap-x-6 sm:grid-cols-2">
            <x-employee-field name="effective_date" label="Effective date" type="date" :required="true"
                hint="When the change APPLIES — not the day you are entering it." />

            @if (in_array('department_id', $editable, true))
                <x-employee-field name="department_id" model="form.department_id" label="Department" type="select"
                    :options="$departmentOptions->pluck('name', 'id')->all()" />
            @endif
            @if (in_array('position_id', $editable, true))
                <x-employee-field name="position_id" model="form.position_id" label="Position" type="select"
                    :options="$positionOptions->pluck('title', 'id')->all()" />
            @endif
            @if (in_array('level', $editable, true))
                <x-employee-field name="level" model="form.level" label="Level" type="select"
                    :options="['STAFF' => 'STAFF', 'SUPERVISOR' => 'SUPERVISOR', 'MANAGER' => 'MANAGER', 'HOD' => 'HOD']"
                    hint="Display only — never an authorisation or routing input (BR-9)." />
            @endif
            @if (in_array('employment_type', $editable, true))
                <x-employee-field name="employment_type" model="form.employment_type" label="Employment type" type="select"
                    :options="['FULL-TIME' => 'FULL-TIME', 'PART-TIME' => 'PART-TIME', 'CONTRACT' => 'CONTRACT', 'INTERN' => 'INTERN', 'FREELANCE' => 'FREELANCE']" />
            @endif
            @if (in_array('fingerprint_id', $editable, true))
                <x-employee-field name="fingerprint_id" model="form.fingerprint_id" label="Fingerprint ID" />
            @endif
            @if (in_array('join_date', $editable, true))
                <x-employee-field name="join_date" model="form.join_date" label="Join date" type="date" />
            @endif
            @if (in_array('probation_end_date', $editable, true))
                <x-employee-field name="probation_end_date" model="form.probation_end_date" label="Probation ends" type="date" />
            @endif
            @if (in_array('confirmation_date', $editable, true))
                <x-employee-field name="confirmation_date" model="form.confirmation_date" label="Confirmation date" type="date" />
            @endif
        </div>
    </section>

    <section class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
        <h2 class="mb-4 text-sm font-semibold tracking-tight text-slate-900">Personal</h2>

        {{--
            ⚠ NO PHONE FIELD, AND THIS COMMENT IS HERE SO NOBODY ADDS ONE. `users.phone_no` is
            both the personal number and the login username (`adr/0006`). A field here would be
            a second place to change one credential, and the employee would be locked out of
            their own account with nothing to notice.
        --}}
        <div class="grid gap-x-6 sm:grid-cols-2">
            @if (in_array('full_name', $editable, true))
                <x-employee-field name="full_name" model="form.full_name" label="Full name" :required="true" />
            @endif
            @if (in_array('nickname', $editable, true))
                <x-employee-field name="nickname" model="form.nickname" label="Nickname" />
            @endif
            @if (in_array('email', $editable, true))
                <x-employee-field name="email" model="form.email" label="Email" />
            @endif
            @if (in_array('ic_no', $editable, true))
                <x-employee-field name="ic_no" model="form.ic_no" label="IC number"
                    hint="12 digits, no dashes (`adr/0015` decision 3)." />
            @endif
            @if (in_array('passport_no', $editable, true))
                <x-employee-field name="passport_no" model="form.passport_no" label="Passport number"
                    hint="Letters and digits, no separators." />
            @endif
            @if (in_array('permit_expiry', $editable, true))
                <x-employee-field name="permit_expiry" model="form.permit_expiry" label="Permit expiry" type="date" />
            @endif
            @if (in_array('date_of_birth', $editable, true))
                <x-employee-field name="date_of_birth" model="form.date_of_birth" label="Date of birth" type="date" />
            @endif
            @if (in_array('gender', $editable, true))
                <x-employee-field name="gender" model="form.gender" label="Gender" type="select"
                    :options="['MALE' => 'MALE', 'FEMALE' => 'FEMALE']" />
            @endif
            {{-- ⚠ `nationality_id`, the COLUMN — the policy's display key is `nationality` and the
                 component translates it once. Binding the display key here would validate against
                 nothing, intersect to a non-column, and be dropped by fill() without error.

                 ⚠ The options include a WITHDRAWN nationality if this employee holds one, which
                 the create form deliberately does not offer (`adr/0013` decision 6). --}}
            @if (in_array('nationality_id', $editable, true))
                <x-employee-field name="nationality_id" model="form.nationality_id" label="Nationality" type="select"
                    :options="$nationalityOptions->pluck('name', 'id')->all()" />
            @endif
            @if (in_array('epf_no', $editable, true))
                <x-employee-field name="epf_no" model="form.epf_no" label="EPF number" />
            @endif
            @if (in_array('socso_no', $editable, true))
                <x-employee-field name="socso_no" model="form.socso_no" label="SOCSO number" />
            @endif
            @if (in_array('tax_no', $editable, true))
                <x-employee-field name="tax_no" model="form.tax_no" label="Tax number (LHDN)" />
            @endif
            @if (in_array('bank_name', $editable, true))
                <x-employee-field name="bank_name" model="form.bank_name" label="Bank" />
            @endif
            @if (in_array('bank_account_no', $editable, true))
                <x-employee-field name="bank_account_no" model="form.bank_account_no" label="Bank account number" />
            @endif
        </div>

        @if (in_array('address', $editable, true))
            <x-employee-field name="address" model="form.address" label="Address" type="textarea" />
        @endif
    </section>

    <div class="flex items-center gap-3">
        <button type="submit"
                class="rounded-md bg-slate-800 px-4 py-2 text-sm font-medium text-white hover:bg-slate-900 focus:outline-none focus:ring-2 focus:ring-slate-900 focus:ring-offset-2">
            Save changes
        </button>
        <a href="{{ route('employees.show', $employee) }}" wire:navigate
           class="text-sm font-medium text-slate-600 hover:text-slate-900">Cancel</a>
    </div>
</form>
