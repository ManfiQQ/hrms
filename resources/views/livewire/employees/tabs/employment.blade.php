{{--
    Employment — §7.1. Read by every tier that can open the record, which is why the two
    adr/0013 flags live here rather than on Personal: after adr/0014 a supervisor no longer
    sees epf_no, socso_no or permit_expiry, so a flag on that tab would be invisible to the
    tier most likely to act on it.
--}}
<div class="space-y-6">

    {{--
        ⚠ FLAGS, NOT GATES. An expired permit does not stop anyone working and a missing
        statutory number does not stop payroll (adr/0013 decisions 4 and 5). Nothing on this
        screen or behind it may act on them.

        ⚠ The statutory flag states that a gap EXISTS. The numbers themselves are Personal-tab
        data and are not printed here — an administrative fact about a record is not the same
        disclosure as the record's contents.
    --}}
    @if ($employee->hasExpiredPermit() || $employee->hasIncompleteStatutoryRegistration())
        <div class="space-y-2">
            @if ($employee->hasExpiredPermit())
                <p class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                    <span class="font-semibold">Work permit expired</span>
                    — {{ $employee->permit_expiry->format('d M Y') }}. Renewal is the response; nothing is blocked.
                </p>
            @endif

            @if ($employee->hasIncompleteStatutoryRegistration())
                <p class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                    <span class="font-semibold">Statutory registration incomplete</span>
                    — confirmed without an EPF or SOCSO number. Contributions accrue meanwhile and are settled by Payroll.
                </p>
            @endif
        </div>
    @endif

    {{-- The payroll employer and BR-12's cross-company line — §7's own example. --}}
    <section class="rounded-lg bg-slate-50 p-4 text-sm">
        <p><span class="font-semibold">Employer (payroll):</span> {{ $employee->company?->name ?? '—' }}</p>

        @if ($this->crossCompanyService !== [])
            <p class="mt-2">
                <span class="font-semibold">Also serving at:</span>
                @foreach ($this->crossCompanyService as $company => $labels)
                    <span class="whitespace-nowrap">{{ $company }} — {{ implode(', ', $labels) }}</span>{{ ! $loop->last ? ' · ' : '' }}
                @endforeach
            </p>
        @endif
    </section>

    <dl class="grid gap-x-8 gap-y-3 sm:grid-cols-2">
        @foreach ([
            'Branch' => $employee->branch?->name,
            'Department' => $employee->department?->name,
            'Position' => $employee->position?->title,
            'Level' => $employee->level,
            'Employment type' => $employee->employment_type,
            'Status' => $employee->staff_status,
            'Joined' => $employee->join_date?->format('d M Y'),
            'Probation ends' => $employee->probation_end_date?->format('d M Y'),
            'Confirmed' => $employee->confirmation_date?->format('d M Y'),
            'Direct supervisor' => $employee->directSupervisor?->full_name,
            'Manager' => $employee->manager?->full_name,
            'Fingerprint ID' => $employee->fingerprint_id,
            'Attendance' => $employee->attendance_type,
            'Working hours' => $employee->work_start_time.' – '.$employee->work_end_time,
            'OT after' => $employee->ot_after_time,
            'Working days' => implode(', ', $employee->working_days ?? []),
            'Off days' => implode(', ', $employee->offday ?? []),
            'Saturday hours banking' => $employee->hours_enabled ? 'Enabled' : 'Not enabled',
        ] as $label => $value)
            <div>
                <dt class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ $label }}</dt>
                <dd class="mt-0.5 text-sm">{{ filled($value) ? $value : '—' }}</dd>
            </div>
        @endforeach
    </dl>

    {{-- BR-13's rejoiner link: RESIGNED and TERMINATED are terminal, so a returning employee
         gets a new record and this is the only thread back. --}}
    @if ($employee->previousEmployee)
        <p class="text-sm text-slate-600">
            Rejoiner — previous record
            <a href="{{ route('employees.show', $employee->previousEmployee) }}"
               class="font-medium underline underline-offset-2">{{ $employee->previousEmployee->employee_no }}</a>.
        </p>
    @endif

    {{--
        ⚠ §6.2's deliberate exception: name and number only, on THIS tab rather than behind
        Family, because a supervisor is likely the first person present at an accident. The
        rest of the family row stays behind a tab they may not read.
    --}}
    <section>
        <h2 class="text-xs font-semibold uppercase tracking-wide text-slate-500">Emergency contact</h2>

        @forelse ($employee->emergencyContacts as $contact)
            <p class="mt-1 text-sm">{{ $contact->name }} — {{ $contact->contact_no ?? 'no number recorded' }}</p>
        @empty
            <p class="mt-1 text-sm text-slate-500">None recorded.</p>
        @endforelse
    </section>
</div>
