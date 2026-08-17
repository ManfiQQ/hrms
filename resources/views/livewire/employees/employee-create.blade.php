{{--
    Registration — §5.1, and `adr/0015` decision 5's rejoiner block.

    ⚠ EVERY FIELD IS wire:model.blur, through the shared partial. See partials/field.blade.php
    for why `.live` is wrong on a form this size.
--}}
<form wire:submit="save" class="space-y-10">

    {{-- ─── The rejoiner block, first, because it changes what the rest means ─────────────── --}}
    <section class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
        <h2 class="mb-1 text-sm font-semibold tracking-tight text-slate-900">Has this employee worked here before?</h2>

        {{--
            ⚠ ASKING COMES BEFORE SEARCHING, AND THE ORDER IS THE PROTECTION. With the box
            unticked a duplicate IC is simply refused — which is what catches a genuine
            duplicate, two records for one person created by accident. The box is what separates
            "the same person is returning" from "somebody typed an IC that already exists".
        --}}
        <p class="mb-4 text-xs text-slate-500">
            A returning employee gets a new record and a new number — never a reactivated one
            (BR-2, BR-13). Linking here releases their old record's claim on their IC and phone
            number, and keeps both values on it.
        </p>

        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" wire:model.live="has_worked_here_before"
                   class="rounded border-slate-300 text-slate-900 focus:ring-slate-900">
            <span>Yes — find their previous record</span>
        </label>

        @if ($has_worked_here_before)
            <div class="mt-4 flex items-end gap-3">
                <div class="flex-1">
                    <x-employee-field name="prior_identifier" label="IC, passport or phone number"
                        hint="Exact match only. An IC is stored without dashes — 900101145501." />
                </div>
                <button type="button" wire:click="findPriorEmployment"
                        class="mb-4 rounded-md bg-slate-800 px-4 py-2 text-sm font-medium text-white hover:bg-slate-900 focus:outline-none focus:ring-2 focus:ring-slate-900 focus:ring-offset-2">
                    Search
                </button>
            </div>

            @if ($priorLookupMessage)
                <p class="rounded-md bg-sky-50 px-3 py-2 text-sm text-sky-800">{{ $priorLookupMessage }}</p>
            @endif

            @if ($priorEmployment)
                {{--
                    ⚠ SIX FIELDS, AND THE EMPLOYER IS ONE OF THEM ON PURPOSE. Linking is an act,
                    not a read: it fixes prior service across employers. An HR who links an AIM
                    record without being shown "AIM" is acting across a company boundary blind.
                    Removing this on a privacy argument was proposed and withdrawn — see
                    `adr/0015`'s amendment before removing it again.
                --}}
                <div class="rounded-md border border-slate-200 bg-slate-50 px-4 py-3 text-sm">
                    <p class="font-medium text-slate-900">{{ $priorEmployment['fullName'] }}</p>
                    <p class="text-slate-600">
                        {{ $priorEmployment['employeeNo'] }} · {{ $priorEmployment['companyName'] }}
                    </p>
                    <p class="text-slate-600">
                        @if ($priorEmployment['servedFrom'] && $priorEmployment['servedTo'])
                            {{ $priorEmployment['servedFrom'] }} to {{ $priorEmployment['servedTo'] }}
                        @else
                            {{-- Both dates are nullable: join_date may be empty, and a terminal
                                 record can carry no ledger row at all. --}}
                            Service dates not recorded
                        @endif
                    </p>
                </div>
            @endif
        @endif
    </section>

    {{-- ─── Employment ────────────────────────────────────────────────────────────────────── --}}
    <section class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
        <h2 class="mb-4 text-sm font-semibold tracking-tight text-slate-900">Employment</h2>

        <div class="grid gap-x-6 sm:grid-cols-2">
            {{-- ⚠ Bounded by read scope, and every option is a company this account may create
                 into — VIEW_ONLY reads the group and writes nothing. --}}
            <x-employee-field name="company_id" label="Employer" type="select" :required="true"
                :options="$companyOptions->pluck('name', 'id')->all()"
                hint="The payroll and legal employer. Org placement below is independent of it." />

            <x-employee-field name="department_id" label="Department" type="select" :required="true"
                :options="$departmentOptions->pluck('name', 'id')->all()" />

            <x-employee-field name="position_id" label="Position" type="select"
                :options="$positionOptions->pluck('title', 'id')->all()" />

            <x-employee-field name="level" label="Level" type="select" :required="true"
                :options="['STAFF' => 'STAFF', 'SUPERVISOR' => 'SUPERVISOR', 'MANAGER' => 'MANAGER', 'HOD' => 'HOD']"
                hint="Display only — it never drives an authorisation or routing decision (BR-9)." />

            <x-employee-field name="employment_type" label="Employment type" type="select" :required="true"
                :options="['FULL-TIME' => 'FULL-TIME', 'PART-TIME' => 'PART-TIME', 'CONTRACT' => 'CONTRACT', 'INTERN' => 'INTERN', 'FREELANCE' => 'FREELANCE']" />

            {{-- ⚠ RESIGNED and TERMINATED are not offered. They are terminal, they freeze the
                 account in the same transaction, and a registration form is not where an
                 employment ends (BR-2, `adr/0004` decision 5). --}}
            <x-employee-field name="staff_status" label="Status" type="select" :required="true"
                :options="['PROBATION' => 'PROBATION', 'ACTIVE' => 'ACTIVE', 'CONFIRMED' => 'CONFIRMED', 'SUSPENDED' => 'SUSPENDED']" />

            <x-employee-field name="join_date" label="Join date" type="date" />
            <x-employee-field name="probation_end_date" label="Probation ends" type="date" />
            <x-employee-field name="confirmation_date" label="Confirmation date" type="date" />
            <x-employee-field name="fingerprint_id" label="Fingerprint ID"
                hint="Matches the NGTime attendance export." />
        </div>
    </section>

    {{-- ─── Personal — rendered from the policy's answer, never a fixed list ───────────────── --}}
    <section class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
        <h2 class="mb-4 text-sm font-semibold tracking-tight text-slate-900">Personal</h2>

        <div class="grid gap-x-6 sm:grid-cols-2">
            {{--
                ⚠ THE LOGIN USERNAME, AND IT IS NOT AN EMPLOYEE COLUMN. It lives on `users`
                (`adr/0006`), which is why it is absent from `$writable` and passed to
                `CreateEmployee` separately. There is no second contact number and none may be
                added — this is the number HR reaches them on and the one they log in with.
            --}}
            <x-employee-field name="phone_no" label="Phone number (login username)" :required="true"
                hint="9–12 digits. This is their username — there is no placeholder path (BR-A1)." />

            @if (in_array('full_name', $writable, true))
                <x-employee-field name="full_name" label="Full name" :required="true" />
            @endif
            @if (in_array('nickname', $writable, true))
                <x-employee-field name="nickname" label="Nickname" />
            @endif
            @if (in_array('email', $writable, true))
                <x-employee-field name="email" label="Email"
                    hint="Frequently absent — it authenticates nothing." />
            @endif
            @if (in_array('ic_no', $writable, true))
                <x-employee-field name="ic_no" label="IC number"
                    hint="12 digits, no dashes — 900101145501 (`adr/0015` decision 3)." />
            @endif
            @if (in_array('passport_no', $writable, true))
                <x-employee-field name="passport_no" label="Passport number"
                    hint="Letters and digits, no separators. One of IC or passport is required." />
            @endif
            @if (in_array('permit_expiry', $writable, true))
                <x-employee-field name="permit_expiry" label="Permit expiry" type="date"
                    hint="An expired permit raises a flag and blocks nothing." />
            @endif
            @if (in_array('date_of_birth', $writable, true))
                <x-employee-field name="date_of_birth" label="Date of birth" type="date" :required="true" />
            @endif
            @if (in_array('gender', $writable, true))
                <x-employee-field name="gender" label="Gender" type="select" :required="true"
                    :options="['MALE' => 'MALE', 'FEMALE' => 'FEMALE']" />
            @endif
            @if (in_array('nationality', $writable, true))
                <x-employee-field name="nationality_id" label="Nationality" type="select" :required="true"
                    :options="$nationalityOptions->pluck('name', 'id')->all()" />
            @endif
            @if (in_array('epf_no', $writable, true))
                <x-employee-field name="epf_no" label="EPF number"
                    hint="Absent until they qualify — a record without one is correct." />
            @endif
            @if (in_array('socso_no', $writable, true))
                <x-employee-field name="socso_no" label="SOCSO number" />
            @endif
            @if (in_array('tax_no', $writable, true))
                <x-employee-field name="tax_no" label="Tax number (LHDN)" />
            @endif
            @if (in_array('bank_name', $writable, true))
                <x-employee-field name="bank_name" label="Bank"
                    hint="Where salary is sent — never how much." />
            @endif
            @if (in_array('bank_account_no', $writable, true))
                <x-employee-field name="bank_account_no" label="Bank account number" />
            @endif
        </div>

        @if (in_array('address', $writable, true))
            <x-employee-field name="address" label="Address" type="textarea"
                hint="One block, as it is written onto letters — never parsed into components." />
        @endif
    </section>

    <div class="flex items-center gap-3">
        <button type="submit"
                class="rounded-md bg-slate-800 px-4 py-2 text-sm font-medium text-white hover:bg-slate-900 focus:outline-none focus:ring-2 focus:ring-slate-900 focus:ring-offset-2">
            Register employee
        </button>
        <a href="{{ route('employees.index') }}" wire:navigate
           class="text-sm font-medium text-slate-600 hover:text-slate-900">Cancel</a>
    </div>
</form>
